"""
hf_quiet.py
Turns down Hugging Face / transformers noise without silencing the process.

**Import this before torch or transformers.** The environment variables below are
read once, at their import time; setting them afterwards has no effect.

Why this is a module rather than a block copied into five scripts: each of those
copies called `logging.disable(logging.WARNING)`, which is a *global, process-wide*
switch. It suppresses every log record at or below WARNING for every logger in the
interpreter, not just the ML libraries'. Inside the FastAPI service that also
silenced uvicorn, so the service started with an empty log and there was nothing in
the terminal to explain a failed start. Name the noisy loggers instead of turning
the lights off.

Our own warnings stay visible on purpose - a script that is misconfigured should
say so.
"""

import logging
import os
import warnings

# Read at import time by transformers / huggingface_hub, hence setdefault first.
# setdefault, not assignment, so an operator can override any of them in the
# environment without editing code.
os.environ.setdefault("HF_HUB_DISABLE_SYMLINKS_WARNING", "1")
os.environ.setdefault("HF_HUB_DISABLE_IMPLICIT_TOKEN", "1")
os.environ.setdefault("TRANSFORMERS_NO_ADVISORY_WARNINGS", "1")
os.environ.setdefault("TRANSFORMERS_VERBOSITY", "error")
# The fast tokenizers fork worker threads and then warn about it once the process
# forks again. Nothing here needs tokenizer parallelism.
os.environ.setdefault("TOKENIZERS_PARALLELISM", "false")

# Third-party loggers that chatter at INFO/WARNING during model loading.
_NOISY_LOGGERS = (
    "transformers",
    "huggingface_hub",
    "filelock",
    "torch",
    "PIL",
    "matplotlib",
    "matplotlib.font_manager",
)

# Known, harmless messages from inside the ML stack. Matched on text so a real
# warning from anywhere else still reaches the terminal.
_NOISY_MESSAGES = (
    r".*resume_download.*",
    r".*`do_sample`.*",
    r".*Some weights of.*were not initialized.*",
    r".*You should probably TRAIN this model.*",
    r".*The current process just got forked.*",
)


def apply(level: int = logging.ERROR) -> None:
    """Raise the log threshold on the noisy libraries and filter known messages."""
    for name in _NOISY_LOGGERS:
        logging.getLogger(name).setLevel(level)

    # transformers has its own verbosity layer on top of logging.
    try:  # pragma: no cover - depends on the installed version
        from transformers.utils import logging as hf_logging

        hf_logging.set_verbosity_error()
        hf_logging.disable_progress_bar()
    except Exception:
        pass

    for pattern in _NOISY_MESSAGES:
        warnings.filterwarnings("ignore", message=pattern)

    # Deprecation chatter raised from inside those packages, scoped by module so
    # a FutureWarning about our own code is still printed.
    for module in (r"transformers\..*", r"huggingface_hub\..*", r"torch\..*"):
        for category in (DeprecationWarning, FutureWarning, UserWarning):
            warnings.filterwarnings("ignore", category=category, module=module)


apply()
