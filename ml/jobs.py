"""
jobs.py
In-process job manager for the long-running GPU work.

Training takes hours and evaluation takes minutes, so neither can be a synchronous
HTTP request. FastAPI owns the job because it owns the GPU.

**One GPU job at a time.** A second start raises `JobBusy` and the API turns that
into a 409 naming what is already running, rather than queueing silently.

Job state lives here, in the service, which is why it survives a page refresh in
the Laravel workspace: the browser is polling, not holding the state.

This module is import-cheap on purpose - no torch. The runner callable brings the
heavy imports with it.
"""

import threading
import time
import uuid
from collections import OrderedDict, deque
from datetime import datetime, timezone

# Enough log to diagnose a failure without turning the status response into a
# transcript. The full stdout of a CLI run is still available in the terminal.
MAX_LOG_LINES = 300

# Finished jobs kept in memory. Laravel mirrors them into ml_jobs, which is the
# durable history; this is just enough for the page to show recent activity.
MAX_HISTORY = 30

QUEUED = "queued"
RUNNING = "running"
COMPLETED = "completed"
FAILED = "failed"
CANCELLED = "cancelled"

TERMINAL = (COMPLETED, FAILED, CANCELLED)

TRAINING = "training"
EVALUATION = "evaluation"


class JobBusy(Exception):
    """A GPU job is already running."""

    def __init__(self, active):
        self.active = active
        super().__init__(
            f"A {active['type']} job is already running ({active['id']}). "
            "Wait for it to finish or cancel it first."
        )


class JobCancelled(Exception):
    """Raised inside a runner once cancellation has been requested."""


def _now():
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


class Job:
    """One unit of GPU work, plus everything the UI needs to render it."""

    def __init__(self, job_type, config):
        self.id = uuid.uuid4().hex[:12]
        self.type = job_type
        self.config = dict(config or {})
        self.status = QUEUED
        self.metrics = None
        self.error = None
        self.result = None

        self.created_at = _now()
        self.started_at = None
        self.finished_at = None
        self._started_monotonic = None

        self.progress = {
            "stage": "queued",
            "epoch": 0,
            "total_epochs": self.config.get("epochs"),
            "step": 0,
            "total_steps": None,
            "loss": None,
            "val_loss": None,
            "percent": 0.0,
        }

        self._log = deque(maxlen=MAX_LOG_LINES)
        self._cancel = threading.Event()
        self._lock = threading.RLock()

    # ------------------------------------------------------------- from the runner

    def log(self, line):
        """Append a log line. Safe to call from the worker thread."""
        with self._lock:
            self._log.append(f"[{time.strftime('%H:%M:%S')}] {line}")

    def report(self, **fields):
        """Merge progress fields. Unknown keys are kept so a runner can add its own."""
        with self._lock:
            self.progress.update({k: v for k, v in fields.items() if v is not None})
            self._recompute_percent()

    def _recompute_percent(self):
        p = self.progress
        total_steps, step = p.get("total_steps"), p.get("step") or 0
        total_epochs, epoch = p.get("total_epochs"), p.get("epoch") or 0

        if total_epochs and total_steps:
            # Fraction of the current epoch, offset by the epochs already done.
            done = max(epoch - 1, 0) + min(step / total_steps, 1.0)
            p["percent"] = round(min(done / total_epochs, 1.0) * 100, 1)
        elif total_steps:
            p["percent"] = round(min(step / total_steps, 1.0) * 100, 1)

    def cancelled(self):
        """Runners check this between steps and stop cleanly when it is true."""
        return self._cancel.is_set()

    def raise_if_cancelled(self):
        if self._cancel.is_set():
            raise JobCancelled()

    # ------------------------------------------------------------------ lifecycle

    def request_cancel(self):
        self._cancel.set()
        self.log("Cancellation requested.")

    @property
    def elapsed(self):
        if self._started_monotonic is None:
            return 0.0
        if self.status in TERMINAL and self.finished_at:
            return round(self._elapsed_at_finish, 1)
        return round(time.monotonic() - self._started_monotonic, 1)

    def mark_running(self):
        self.status = RUNNING
        self.started_at = _now()
        self._started_monotonic = time.monotonic()
        self.progress["stage"] = "starting"

    def mark_finished(self, status, error=None):
        self._elapsed_at_finish = (
            time.monotonic() - self._started_monotonic if self._started_monotonic else 0.0
        )
        self.status = status
        self.error = error
        self.finished_at = _now()
        if status == COMPLETED:
            self.progress["stage"] = "done"
            self.progress["percent"] = 100.0

    # -------------------------------------------------------------------- reading

    def snapshot(self, log_lines=60):
        with self._lock:
            return {
                "id": self.id,
                "type": self.type,
                "status": self.status,
                "config": self.config,
                "progress": dict(self.progress),
                "metrics": self.metrics,
                "result": self.result,
                "error": self.error,
                "elapsed": self.elapsed,
                "cancel_requested": self._cancel.is_set(),
                "created_at": self.created_at,
                "started_at": self.started_at,
                "finished_at": self.finished_at,
                "log": list(self._log)[-log_lines:],
            }

    def summary(self):
        return {
            "id": self.id,
            "type": self.type,
            "status": self.status,
            "percent": self.progress.get("percent", 0.0),
            "stage": self.progress.get("stage"),
            "elapsed": self.elapsed,
        }


class JobManager:
    """Holds the single active job plus a short history of finished ones."""

    def __init__(self):
        self._jobs = OrderedDict()
        self._active_id = None
        self._lock = threading.RLock()

    # --------------------------------------------------------------------- state

    def active(self):
        with self._lock:
            job = self._jobs.get(self._active_id) if self._active_id else None
            return job if job and job.status not in TERMINAL else None

    def get(self, job_id):
        with self._lock:
            return self._jobs.get(job_id)

    def list(self):
        with self._lock:
            return [job.summary() for job in reversed(self._jobs.values())]

    def is_busy(self):
        return self.active() is not None

    # ------------------------------------------------------------------- starting

    def start(self, job_type, config, runner):
        """Queue a job and run it on a worker thread.

        `runner(job)` is called with the Job so it can report progress and poll
        `job.cancelled()`. Its return value becomes `job.result`; a `metrics` key
        in a returned dict is lifted onto `job.metrics`.

        Raises JobBusy if a GPU job is already in flight."""
        with self._lock:
            active = self.active()
            if active is not None:
                raise JobBusy(active.summary())

            job = Job(job_type, config)
            self._jobs[job.id] = job
            self._active_id = job.id
            self._prune()

        thread = threading.Thread(
            target=self._run, args=(job, runner), name=f"job-{job.id}", daemon=True
        )
        thread.start()
        return job

    def _run(self, job, runner):
        job.mark_running()
        job.log(f"{job.type.title()} job started.")
        try:
            result = runner(job)
            if job.cancelled():
                # A cooperative runner may return normally after noticing the flag.
                job.mark_finished(CANCELLED)
                job.log("Job cancelled.")
                return

            job.result = result
            if isinstance(result, dict) and "metrics" in result:
                job.metrics = result["metrics"]
            job.mark_finished(COMPLETED)
            job.log("Job completed.")
        except JobCancelled:
            job.mark_finished(CANCELLED)
            job.log("Job cancelled cleanly.")
        except Exception as e:
            # Never fail silently: the message and the log tail are what the Super
            # Admin sees on the workspace page.
            message = f"{type(e).__name__}: {e}"
            job.mark_finished(FAILED, error=message)
            job.log(f"FAILED - {message}")
        finally:
            with self._lock:
                if self._active_id == job.id:
                    self._active_id = None

    # -------------------------------------------------------------------- cancel

    def cancel(self, job_id):
        job = self.get(job_id)
        if job is None:
            return None
        if job.status in TERMINAL:
            return job
        job.request_cancel()
        return job

    def _prune(self):
        finished = [j for j in self._jobs.values() if j.status in TERMINAL]
        for job in finished[: max(len(finished) - MAX_HISTORY, 0)]:
            self._jobs.pop(job.id, None)


# The service holds exactly one manager, which is what enforces one GPU job at a
# time across every request.
manager = JobManager()
