"""Keeps the line finder (Kraken) loaded between pages.

Run as a script, ml/line_markers.py starts Python, imports Kraken, loads its
model and starts its outline helpers for every page: about 8 s of a register
page's 20 s on the laptop this was measured on. This small local service does
that once and then finds the lines of one page after another, with the very
same code, so the lines and crops are the same as the script's.

    ml\\.venv-kraken\\Scripts\\python.exe ml\\line_markers_service.py --port 8002

(serve.ps1 -Only lines, or the "CRMS: line finder" task, runs it.) Laravel uses
it for Scan and Smoke test when LINE_MARKERS_SERVICE_URL is set and it answers;
otherwise it runs the script as before, so nothing breaks when it is not running.

    GET  /health            is it up, and is Kraken loaded yet?
    POST /jobs              {"argv": ["process", "--page", ...]} -> {"id": ...}
    GET  /jobs/<id>         {"state": "queued" | "running" | "done", "summary": {...}}
    POST /jobs/<id>/cancel  the page was cancelled: its result is not wanted

Pages are done one at a time, in the order they came. Only 127.0.0.1, and every
call but /health needs the key Laravel shares with the OCR service
(X-CRMS-Service-Key: OCR_UPLOAD_SECRET, else APP_KEY).

After changing ml/line_markers.py, restart this service: it runs the code it
started with.
"""

import argparse
import hmac
import json
import os
import queue
import sys
import threading
import time
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

HERE = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT = os.path.dirname(HERE)
sys.path.insert(0, HERE)

import line_markers  # noqa: E402  (after the path, so the helpers find it too)

# Only these go through the service: the slow ones, which load Kraken. Snap,
# crop and grid take about a second as a script and are asked for while the
# user works; queued behind a page here, they would wait for it.
COMMANDS = ("process", "detect")

# A finished job's result waits this long for Laravel to collect it.
KEEP_DONE_SECONDS = 600

_jobs = {}
_jobs_lock = threading.Lock()
_queue = queue.Queue()
_state = {"loaded": False, "error": None, "device": None, "started": time.time()}


def env_value(name):
    """One setting from the environment, else from Laravel's .env (as the OCR service reads it)."""
    value = os.environ.get(name)
    if value:
        return value
    try:
        with open(os.path.join(REPO_ROOT, ".env"), encoding="utf-8-sig") as env_file:
            for raw in env_file:
                line = raw.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, candidate = line.split("=", 1)
                if key.strip() != name:
                    continue
                candidate = candidate.strip()
                if len(candidate) >= 2 and candidate[0] == candidate[-1] and candidate[0] in "\"'":
                    candidate = candidate[1:-1]
                return candidate or None
    except OSError:
        pass
    return None


def _service_key():
    return (env_value("OCR_UPLOAD_SECRET") or env_value("APP_KEY") or "").encode("utf-8")


def _load():
    """Kraken and its outline helpers, once. Pages wait for this, then run."""
    try:
        started = time.time()
        line_markers.keep_loaded()
        _state["device"] = line_markers.detector_device()
        _state["loaded"] = True
        print(f"[line finder] Kraken loaded in {time.time() - started:.1f} s "
              f"({_state['device']}, {line_markers.outline_workers()} outline helpers).", flush=True)
    except Exception as error:  # each page then loads Kraken itself, as the script does
        _state["error"] = f"{type(error).__name__}: {error}"
        print(f"[line finder] Could not keep Kraken loaded: {_state['error']}", flush=True)


def _free_gpu_memory():
    """Give the card back what the page used, so TrOCR has it while this waits."""
    try:
        import torch

        if torch.cuda.is_available():
            torch.cuda.empty_cache()
    except Exception:
        pass


def _work():
    _load()
    while True:
        job_id = _queue.get()
        with _jobs_lock:
            job = _jobs.get(job_id)
            if job is None or job["cancelled"]:
                continue
            job["state"] = "running"
        started = time.time()
        try:
            _, summary = line_markers.run_command(job["argv"])
        except BaseException as error:  # argparse exits on bad arguments
            summary = {"ok": False, "error": f"{type(error).__name__}: {error}"}
        _free_gpu_memory()
        with _jobs_lock:
            job.update(state="done", summary=summary, finished=time.time())
            for old in [key for key, other in _jobs.items()
                        if other.get("finished") and time.time() - other["finished"] > KEEP_DONE_SECONDS]:
                del _jobs[old]
        print(f"[line finder] {job['argv'][0]} {'ok' if summary.get('ok') else 'failed'} "
              f"in {time.time() - started:.1f} s{' (cancelled)' if job['cancelled'] else ''}", flush=True)


class Handler(BaseHTTPRequestHandler):
    server_version = "CRMSLineFinder/1"

    def log_message(self, *args):  # pages are logged by _work, not every status check
        pass

    def _send(self, status, body):
        data = json.dumps(body).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _allowed(self):
        supplied = self.headers.get("X-CRMS-Service-Key", "").encode("utf-8")
        key = _service_key()
        if key and hmac.compare_digest(supplied, key):
            return True
        self._send(401, {"error": "This call needs the CRMS service key."})
        return False

    def _job(self):
        parts = self.path.strip("/").split("/")
        with _jobs_lock:
            return _jobs.get(parts[1]) if len(parts) >= 2 else None

    def do_GET(self):
        if self.path == "/health":
            with _jobs_lock:
                waiting = sum(1 for job in _jobs.values() if job["state"] != "done")
            self._send(200, {"ok": True, "loaded": _state["loaded"], "error": _state["error"],
                             "device": _state["device"], "waiting": waiting})
            return
        if not self.path.startswith("/jobs/"):
            self._send(404, {"error": "Not found."})
            return
        if not self._allowed():
            return
        job = self._job()
        if job is None:
            self._send(404, {"error": "No such job: the service may have restarted."})
            return
        self._send(200, {"state": job["state"], "summary": job.get("summary")})

    def do_POST(self):
        if not self._allowed():
            return
        if self.path == "/jobs":
            try:
                length = int(self.headers.get("Content-Length", "0"))
                argv = json.loads(self.rfile.read(length) or b"{}").get("argv")
            except (ValueError, AttributeError):
                argv = None
            if not isinstance(argv, list) or not argv or argv[0] not in COMMANDS \
                    or not all(isinstance(arg, str) for arg in argv):
                self._send(422, {"error": f"argv must be a command line starting with one of {COMMANDS}."})
                return
            job_id = uuid.uuid4().hex
            with _jobs_lock:
                _jobs[job_id] = {"argv": argv, "state": "queued", "cancelled": False}
            _queue.put(job_id)
            self._send(202, {"id": job_id})
            return
        if self.path.startswith("/jobs/") and self.path.endswith("/cancel"):
            job = self._job()
            if job is not None:
                job["cancelled"] = True  # a queued page is skipped; a running one finishes unseen
            self._send(200, {"cancelled": job is not None})
            return
        self._send(404, {"error": "Not found."})


def main():
    parser = argparse.ArgumentParser(description="Keeps the line finder (Kraken) loaded between pages.")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8002)
    args = parser.parse_args()

    line_markers._run_at_full_speed()
    # The same settings Laravel gives the script (from .env).
    for name in ("LINE_MARKERS_DEVICE", "LINE_MARKERS_WORKERS"):
        value = env_value(name)
        if value and not os.environ.get(name):
            os.environ[name] = value
    os.environ.setdefault("PYTHONWARNINGS", "ignore")
    if not _service_key():
        print("[line finder] No APP_KEY or OCR_UPLOAD_SECRET in .env: every page will be refused.", flush=True)

    threading.Thread(target=_work, name="line-finder", daemon=True).start()
    server = ThreadingHTTPServer((args.host, args.port), Handler)
    print(f"[line finder] Listening on http://{args.host}:{args.port} - loading Kraken...", flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
