r"""
main.py
FastAPI OCR service that serves the fine-tuned TrOCR models.

Laravel sends one or more cropped field images (as PNG data URLs) and gets back
the predicted text plus a confidence score (the model's certainty in its own
output, 0-100%) for each crop.

Run from the repo root:
  python -m uvicorn ml.api.main:app --host 127.0.0.1 --port 8001
  (or: python ml\api\main.py)

Endpoints:
  GET  /health     -> status + which models are available/loaded
  GET  /models     -> selectable models for the frontend dropdown
  POST /ocr        -> { "fields": [ { "name": "...", "image": "data:image/png;base64,..." } ],
                       "model": "<key>" }  returns { "results": [ { "name", "text", "confidence" } ] }
  POST /add_model  -> signed direct-browser multipart upload saved into
                      ml/models/<name>/. Either loose `files` or one `archive`.
  POST /delete_model -> { "model": "<key>" } removes that folder from ml/models/
  POST /rename_model -> { "model": "<key>", "newName": "<name>" } renames the folder

Every endpoint but /health and /add_model needs the X-CRMS-Service-Key header:
the secret Laravel shares with this service (OCR_UPLOAD_SECRET, else APP_KEY).

Training, evaluation, dataset preparation, and batch prediction are deliberately
NOT here. They are long-running command-line work - see ml/train_trocr.py,
ml/test_finetuned.py, ml/predict.py - and a request handler is the wrong place to
pin a GPU for hours.
"""

import os
import io
import sys
import math
import json
import time
import hmac
import hashlib
import shutil
import base64
import logging
import tempfile
import threading
import zipfile
from contextlib import asynccontextmanager
from datetime import datetime
from typing import Any, Optional

# ml/ and ml/api/ go on sys.path so the sibling modules import by bare name
# whether this file is loaded as `ml.api.main` (uvicorn, from the repo root) or
# run directly. The ML scripts import each other the same way.
_ML_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
_API_ROOT = os.path.dirname(os.path.abspath(__file__))
for _path in (_API_ROOT, _ML_ROOT):
    if _path not in sys.path:
        sys.path.insert(0, _path)

# Quiets the HF stack. Must precede torch/transformers, whose env vars are read at
# import time. Deliberately NOT `logging.disable(WARNING)`, which is process-wide
# and would silence uvicorn's own log along with everything else - leaving the
# terminal with nothing to explain a failed start.
import hf_quiet  # noqa: E402,F401

import numpy as np
import torch
from PIL import Image
from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, Request, UploadFile
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, ConfigDict, Field

# Import-cheap sibling: no torch, so /health stays responsive while the GPU is busy.
# Only `sanitise_name` is used, so model and dataset names fold to a safe path
# segment by exactly one rule rather than two copies of it.
import dataset_registry as ds
# Shared with predict.py and test_finetuned.py, so a reading is loaded and
# scored the same way everywhere.
from trocr_common import batch_confidences, eos_token_id, load_model

# The service's own messages (models loading, added, deleted, renamed). Uvicorn
# configures only its own loggers, so without a handler of its own INFO would
# vanish. Not logging.basicConfig(): that turns on the root logger and makes
# every library chatty again (see hf_quiet.py). The guard keeps one handler
# when this module is imported under two names (ml.api.main and main).
logger = logging.getLogger("ocr-api")
if not logger.handlers:
    _log_handler = logging.StreamHandler()
    _log_handler.setFormatter(logging.Formatter("[ocr-api] %(message)s"))
    logger.addHandler(_log_handler)
logger.setLevel(logging.INFO)
logger.propagate = False

# ============================================================
# CONFIG
# ============================================================
# Anchored to this file's location (<repo>/ml/api/main.py) rather than the working
# directory, so the service behaves the same however it is launched.
ML_ROOT = _ML_ROOT
REPO_ROOT = os.path.dirname(ML_ROOT)

# All fine-tuned models live in ml/models/<name>/. Drop a model folder in there
# (with config.json + model.safetensors) and it shows up automatically.
MODELS_DIR = os.path.join(ML_ROOT, "models")

# The un-fine-tuned base model, offered only once installed in ml/models/base
# (python ml/download_trocr.py, or Add model); never downloaded by the service.
BASE_MODEL_KEY = "base"
BASE_MODEL_LABEL = "TrOCR base (not fine-tuned)"
BASE_MODEL_DIR = os.path.join(MODELS_DIR, BASE_MODEL_KEY)

MAX_NEW_TOKENS = 32

# /ocr reads this many crops in one generate() call. A GPU reads a batch in
# about the time it takes to read one crop; a smaller number uses less GPU
# memory. Laravel sends 40 crops per request (PageLineReader::BATCH), so 20
# makes two full batches instead of 16 + 16 + a leftover 8.
OCR_BATCH_SIZE = 20

# How the model's numbers are kept on the GPU: OCR_PRECISION in .env, "fp16"
# (default) or "fp32". fp16 lets the card use its Tensor Cores: measured on an
# RTX 4050, 225 lines 8.1 s -> 4.0 s and about half the GPU memory, with the
# same accuracy on 517 human-corrected lines. fp32 is the way back without a
# code change. The CPU always reads in fp32. Read once, like the device, so a
# change needs a restart of the service.
DEFAULT_PRECISION = "fp16"

# After the card ran out of memory even for one crop, the service reads on the
# CPU for this long, then tries the card again. A full card is usually another
# program holding memory for a while, not a broken card.
GPU_RETRY_SECONDS = 120

# Uploaded weights are ~1.3 GB, so they are copied to disk in chunks rather
# than read into memory.
UPLOAD_CHUNK_BYTES = 8 * 1024 * 1024
EVALUATION_REPORT_FILE = "evaluation-report.json"
MAX_EVALUATION_REPORT_BYTES = 1024 * 1024

# Optional friendly labels for known folders. Any folder not listed here gets a
# label auto-generated from its name.
MODEL_LABELS = {
    "TrOCR-fine-tune-10k-samples": "TrOCR fine-tuned (10k samples)",
}

# Fallback default when the caller names no model. In CRMS this rarely applies:
# Laravel sends the model marked active in the ocr_models table, which is the
# real source of truth for what scans run against.
PREFERRED_DEFAULT = "TrOCR-fine-tune-10k-samples"

# The upload is the only browser-to-service request. Restrict response access to
# the local CRMS origins by default; a reverse-proxied deployment can override it.
DEFAULT_BROWSER_ORIGIN_REGEX = r"^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$"
# ============================================================

# Models are loaded lazily and cached by key so the server starts instantly
# and each model is only loaded once.
_device = None
_precision = None  # "fp16" / "fp32" from OCR_PRECISION, read once
_gpu_back_at = None  # time.monotonic() after which a CPU fallback tries the card again
_models = {}  # cache_key -> {"model", "processor", "eos_id", "label", "dtype"}
_evaluation_cache = {}  # model_dir -> {signature, report}; avoids re-hashing 1.3 GB on every poll

# Inference endpoints are plain `def`, so FastAPI runs them on a threadpool, and
# evaluation jobs run on their own thread. Several of those can ask for the same
# model at once: without this lock they each see an empty cache and load their own
# ~1.3 GB copy into VRAM, which is exactly how a 6 GB card runs out of memory.
_model_lock = threading.RLock()


def _get_device():
    global _device
    if _device is None:
        with _model_lock:
            if _device is None:
                # OCR_DEVICE: auto (the NVIDIA card when there is one), cuda, or cpu.
                wanted = (_env_value("OCR_DEVICE") or "auto").strip().lower()
                # A driver capability query, not a CUDA context init, so this is
                # safe to call from /health while a job owns the card. Whether
                # the card really works shows when a model is put on it
                # (_load_model), and the service falls back to the CPU then.
                gpu = wanted != "cpu" and torch.cuda.is_available()
                if wanted == "cuda" and not gpu:
                    logger.warning("OCR_DEVICE=cuda, but no NVIDIA card is usable here; reading on the CPU.")
                _device = torch.device("cuda" if gpu else "cpu")
    return _device


def _on_gpu(device):
    return str(device).startswith("cuda")


def _precision_setting():
    """"fp16" or "fp32", from OCR_PRECISION; anything unknown means the default."""
    global _precision
    if _precision is None:
        wanted = (_env_value("OCR_PRECISION") or DEFAULT_PRECISION).strip().lower()
        if wanted not in ("fp16", "fp32"):
            logger.warning("OCR_PRECISION=%s is not fp16 or fp32; using %s.", wanted, DEFAULT_PRECISION)
            wanted = DEFAULT_PRECISION
        _precision = wanted
    return _precision


def _dtype_for(device):
    """The number type models are kept in on `device`: fp16 only on the card."""
    if _on_gpu(device) and _precision_setting() == "fp16":
        return torch.float16
    return torch.float32


def _precision_name():
    """"fp16" or "fp32" as /health reports it, without touching the card."""
    try:
        return "fp16" if _dtype_for(_get_device()) == torch.float16 else "fp32"
    except Exception:
        return "fp32"


def _is_out_of_memory(error):
    return isinstance(error, torch.cuda.OutOfMemoryError) or "out of memory" in str(error).lower()


def _free_gpu_cache():
    """Hand memory torch keeps cached but unused back to the card."""
    try:
        torch.cuda.empty_cache()
    except Exception:  # a card in a bad state may refuse even this
        pass


def _maybe_return_to_gpu():
    """End a CPU fallback that a full card caused, once GPU_RETRY_SECONDS passed.

    Only a fallback for lack of memory is temporary; a card or driver that
    failed keeps the service on the CPU until it is restarted."""
    global _device, _gpu_back_at
    if _gpu_back_at is None or time.monotonic() < _gpu_back_at:
        return
    with _model_lock:
        if _gpu_back_at is None or time.monotonic() < _gpu_back_at:
            return
        logger.info("Trying the GPU again after reading on the CPU for lack of GPU memory.")
        _gpu_back_at = None
        _device = None  # resolved again on the next _get_device()
        _models.clear()  # the CPU copies; the next _load_model puts one on the card


def _check_gpu(device):
    """One small calculation on the card.

    torch.cuda.is_available() only asks the driver. A driver too old for this
    build of torch, or a card newer than its CUDA version, fails here instead,
    at the first real work."""
    x = torch.ones(64, 64, device=device)
    (x @ x).sum().item()


def _stop_using_gpu(reason, retry_after=None):
    """Read on the CPU, after the card failed.

    Every cached model is dropped, since its weights are on the card; the next
    _load_model() loads it again on the CPU. Scans keep working, only slower.
    With `retry_after` (seconds) the card is tried again after that long - for a
    card that was only full; without it the CPU is used until a restart."""
    global _device, _gpu_back_at
    with _model_lock:
        if _device is not None and not _on_gpu(_device):
            return
        if retry_after is None:
            logger.warning("The GPU failed (%s); reading on the CPU from now on.", reason)
            _gpu_back_at = None
        else:
            logger.warning("The GPU has no free memory (%s); reading on the CPU for %d s.", reason, retry_after)
            _gpu_back_at = time.monotonic() + retry_after
        _device = torch.device("cpu")
        _models.clear()
        _free_gpu_cache()


def _looks_like_model(path):
    """A folder is usable if it has a config.json and a weights file."""
    if not os.path.isdir(path):
        return False
    has_config = os.path.isfile(os.path.join(path, "config.json"))
    has_weights = any(
        os.path.isfile(os.path.join(path, w))
        for w in ("model.safetensors", "pytorch_model.bin")
    )
    return has_config and has_weights


def _discover_models():
    """Map of {folder_name: abs_path} for every valid model under Models/.

    Re-scanned on each call so newly added folders appear without a restart."""
    found = {}
    if os.path.isdir(MODELS_DIR):
        for name in sorted(os.listdir(MODELS_DIR)):
            # The built-in base checkpoint has its own stable key and descriptor
            # below. Do not expose its local folder as a duplicate custom model.
            if name == BASE_MODEL_KEY:
                continue
            path = os.path.join(MODELS_DIR, name)
            if _looks_like_model(path):
                found[name] = path
    return found


def _prettify(name):
    return name.replace("-", " ").replace("_", " ").strip()


def _label_for(key):
    if key == BASE_MODEL_KEY:
        return BASE_MODEL_LABEL
    return MODEL_LABELS.get(key, _prettify(key))


# Models are installed by hand (Add model in the OCR Workspace, or a folder copied
# into ml/models). Nothing is downloaded by itself, so with no model installed the
# service says so instead of fetching one from Hugging Face.
NO_MODEL_MESSAGE = (
    "No OCR model is installed. A Super Admin can add one in the OCR Workspace "
    "(Add model), or copy a model folder into ml/models and click Rescan models."
)


def _base_is_installed():
    return _looks_like_model(BASE_MODEL_DIR)


def _default_key():
    """The model to read with when none is asked for; None when none is installed."""
    discovered = _discover_models()
    if PREFERRED_DEFAULT in discovered:
        return PREFERRED_DEFAULT
    if discovered:
        return next(iter(discovered))
    return BASE_MODEL_KEY if _base_is_installed() else None


def _resolve_key(requested):
    """Choose an installed model key, honoring the request when possible."""
    if requested == BASE_MODEL_KEY and _base_is_installed():
        return BASE_MODEL_KEY
    if requested in _discover_models():
        return requested
    key = _default_key()
    if key is None:
        raise HTTPException(status_code=503, detail=NO_MODEL_MESSAGE)
    return key


def _model_info():
    """Descriptor list of every selectable model for /models and /health."""
    discovered = _discover_models()
    infos = []
    for key, path in discovered.items():
        info = {
            "key": key,
            "label": _label_for(key),
            "available": True,
            "loaded": key in _models,
            # Laravel uses this server-derived list for its audit record. The
            # browser's upload response is deliberately not trusted as evidence.
            "files": sorted(
                name
                for name in os.listdir(path)
                if os.path.isfile(os.path.join(path, name))
            ),
        }
        evaluation = _read_evaluation_report(path)
        if evaluation is not None:
            info["evaluation"] = evaluation
        infos.append(info)
    # The base model is listed like any other: only while ml/models/base holds it.
    # A fresh install (ml/models is not in git) therefore lists no models at all.
    if _base_is_installed():
        base_info = {
            "key": BASE_MODEL_KEY,
            "label": BASE_MODEL_LABEL,
            "available": True,
            "loaded": BASE_MODEL_KEY in _models,
            "files": sorted(
                name
                for name in os.listdir(BASE_MODEL_DIR)
                if os.path.isfile(os.path.join(BASE_MODEL_DIR, name))
            ),
        }
        base_evaluation = _read_evaluation_report(BASE_MODEL_DIR)
        if base_evaluation is not None:
            base_info["evaluation"] = base_evaluation
        infos.append(base_info)
    return infos


def _load_model(key):
    """Load (and cache) the model for `key`. Returns its cache entry.

    Only installed models are loaded. A folder that vanished since the request was
    resolved falls back to another installed model; with none, the caller gets a
    503 saying no model is installed."""
    if key == BASE_MODEL_KEY:
        if not _base_is_installed():
            fallback = _default_key()
            if fallback is None or fallback == BASE_MODEL_KEY:
                raise HTTPException(status_code=503, detail=NO_MODEL_MESSAGE)
            return _load_model(fallback)
        model_src = BASE_MODEL_DIR
        label, cache_key = BASE_MODEL_LABEL, BASE_MODEL_KEY
    else:
        model_src = _discover_models().get(key)
        if model_src is None:
            fallback = _default_key()
            if fallback is None or fallback == key:
                raise HTTPException(status_code=503, detail=NO_MODEL_MESSAGE)
            return _load_model(fallback)
        label, cache_key = _label_for(key), key

    # Fast path, no lock: a hit on an already-populated cache cannot race, and
    # /ocr must not queue behind a colleague's cold start of a different model.
    entry = _models.get(cache_key)
    if entry is not None:
        return entry

    with _model_lock:
        # Re-checked inside the lock: another thread may have finished loading
        # this exact model while we waited for it.
        entry = _models.get(cache_key)
        if entry is not None:
            return entry

        # Read inside the lock: another thread may have just given up on the card.
        device = _get_device()
        if _on_gpu(device):
            _drop_other_models(cache_key)
        dtype = _dtype_for(device)
        logger.info(f"Loading model: {label}  ({model_src})  on  {device} ({_precision_name()})")
        processor, model = load_model(model_src)
        try:
            model = _onto(model, device, dtype)
        except Exception as e:  # out of memory, a card or driver torch cannot use
            if not _on_gpu(device):
                raise
            model = None
            # Its frames hold the half-moved model; drop them so its memory can go.
            e.__traceback__ = None
            if _is_out_of_memory(e):
                # Free what torch keeps cached and try once more before the CPU.
                _free_gpu_cache()
                try:
                    processor, model = load_model(model_src)
                    model = _onto(model, device, dtype)
                except Exception as again:
                    model = None
                    again.__traceback__ = None
                    e = again
            if model is None:
                _stop_using_gpu(e, retry_after=GPU_RETRY_SECONDS if _is_out_of_memory(e) else None)
                device = _get_device()
                dtype = _dtype_for(device)
                # A fresh copy: some of the weights may already be on the card.
                processor, model = load_model(model_src)
                model.to(device)
        model.eval()

        eos_id = eos_token_id(model, processor)

        entry = {"model": model, "processor": processor, "eos_id": eos_id, "label": label, "dtype": dtype}
        _models[cache_key] = entry
        logger.info(f"Model ready: {label}")
        return entry


def _onto(model, device, dtype):
    """Put a freshly loaded model on `device` in `dtype`, checked on the card.

    Halved before it moves, so the card never holds an fp32 copy as well."""
    if dtype != torch.float32:
        model.to(dtype)
    model.to(device)
    if _on_gpu(device):
        _check_gpu(device)
    return model


def _drop_other_models(keep_key):
    """Keep one model on the card: the one about to be loaded.

    Choosing another active model would otherwise leave the old one in GPU
    memory (about 1.3 GB in fp32) until the service restarts. A request still
    reading with a dropped model finishes normally; its memory is freed after."""
    with _model_lock:
        others = [key for key in _models if key != keep_key]
        for key in others:
            _models.pop(key, None)
    if others:
        logger.info("Unloaded %s to keep one model on the GPU.", ", ".join(others))
        _free_gpu_cache()


def _unload_model(key):
    """Drop a model from the cache and release its VRAM.

    Without the empty_cache() the freed weights stay in torch's caching allocator,
    so deleting a model to make room for training frees nothing on the card."""
    with _model_lock:
        removed = _models.pop(key, None)

    if removed is None:
        return False

    del removed
    if _device is not None and _device.type == "cuda":
        torch.cuda.empty_cache()
    return True


def _safe_model_name(raw):
    """Fold a user-supplied model name into one safe path segment.

    Shares dataset_registry's implementation so `[^A-Za-z0-9._-]+ -> '-'` is
    defined once rather than copied into add_model and rename_model."""
    try:
        name = ds.sanitise_name(raw)
    except ds.DatasetError:
        raise HTTPException(status_code=400, detail="That model name is not allowed.")

    if name == BASE_MODEL_KEY:
        raise HTTPException(status_code=400, detail="That model name is not allowed.")

    return name


def _env_value(name):
    """Read one service setting from the process or Laravel's root .env file."""
    value = os.environ.get(name)
    if value:
        return value

    env_path = os.path.join(REPO_ROOT, ".env")
    try:
        with open(env_path, "r", encoding="utf-8-sig") as env_file:
            for raw_line in env_file:
                line = raw_line.strip()
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


def _upload_secret():
    secret = _env_value("OCR_UPLOAD_SECRET") or _env_value("APP_KEY")
    if not secret:
        raise HTTPException(
            status_code=503,
            detail="OCR upload signing is not configured. Set OCR_UPLOAD_SECRET or APP_KEY.",
        )
    return secret.encode("utf-8")


def require_service_key(supplied: str = Header("", alias="X-CRMS-Service-Key")) -> None:
    """Only Laravel may call the service: it sends the secret both sides share.

    Binding to 127.0.0.1 keeps other machines out, but not other programs on this
    one, such as a page in the browser. /health stays open for "is it up?", and
    /add_model has its own short-lived signed ticket instead."""
    if not hmac.compare_digest(supplied.encode("utf-8"), _upload_secret()):
        raise HTTPException(status_code=401, detail="This call needs the CRMS service key.")


def _base64_url_decode(value):
    padding = "=" * (-len(value) % 4)
    return base64.urlsafe_b64decode(value + padding)


def _authorize_model_upload(token, requested_name):
    """Verify the short-lived Laravel ticket before accepting a large body."""
    try:
        encoded, supplied_signature = token.split(".", 1)
        expected_signature = hmac.new(
            _upload_secret(), encoded.encode("ascii"), hashlib.sha256
        ).digest()
        decoded_signature = _base64_url_decode(supplied_signature)
        payload = json.loads(_base64_url_decode(encoded).decode("utf-8"))
    except (AttributeError, UnicodeError, ValueError, TypeError, json.JSONDecodeError):
        raise HTTPException(status_code=401, detail="Invalid model-upload authorization.")

    if not hmac.compare_digest(expected_signature, decoded_signature):
        raise HTTPException(status_code=401, detail="Invalid model-upload authorization.")

    if (
        payload.get("purpose") != "ocr-model-upload"
        or payload.get("name") != requested_name
        or not isinstance(payload.get("user_id"), int)
        or not isinstance(payload.get("nonce"), str)
    ):
        raise HTTPException(status_code=401, detail="Model-upload authorization does not match this request.")

    try:
        expires_at = int(payload.get("expires_at", 0))
    except (TypeError, ValueError):
        expires_at = 0

    if expires_at < int(time.time()):
        raise HTTPException(status_code=401, detail="Model-upload authorization has expired.")


def _decode_data_url(data_url):
    """Turn a 'data:image/png;base64,...' string into a PIL RGB image."""
    if "," in data_url:
        data_url = data_url.split(",", 1)[1]
    raw = base64.b64decode(data_url)
    return Image.open(io.BytesIO(raw)).convert("RGB")


# ============================================================
# Request / response schemas
# ============================================================
class ModelInfo(BaseModel):
    key: str
    label: str
    available: bool
    loaded: bool
    files: list[str] = Field(default_factory=list)
    evaluation: Optional[dict[str, Any]] = None


class HealthResponse(BaseModel):
    # `model_loaded` collides with pydantic v2's protected `model_` namespace, which
    # warns at class-definition time. The key is part of the contract Laravel reads,
    # so opt out of the namespace rather than renaming the field.
    model_config = ConfigDict(protected_namespaces=())

    status: str
    model_loaded: bool
    device: str
    precision: str  # "fp16" or "fp32": how models are kept on that device
    default: Optional[str] = None  # None: no model is installed
    models: list[ModelInfo]


class ModelsResponse(BaseModel):
    default: Optional[str] = None  # None: no model is installed
    models: list[ModelInfo]


class OcrField(BaseModel):
    # Defaults mirror the prototype's dict.get() fallbacks: a field with a
    # missing name/image still produces a result row instead of a 422.
    name: str = "field"
    image: str = ""


class OcrRequest(BaseModel):
    fields: list[OcrField] = Field(default_factory=list)
    model: Optional[str] = None


class OcrResult(BaseModel):
    name: str
    text: str
    confidence: float
    error: Optional[str] = None  # only present when that one crop failed


class OcrResponse(BaseModel):
    results: list[OcrResult]
    model: str
    modelKey: str


class DeleteModelRequest(BaseModel):
    model: Optional[str] = None


class RenameModelRequest(BaseModel):
    model: Optional[str] = None
    newName: Optional[str] = None


class AddModelResponse(BaseModel):
    ok: bool
    name: str
    saved: list[str]
    # What happened to a base-evaluation-report.json in the upload: "installed",
    # "unchanged", "skipped: <reason>", or None when there was none.
    base_report: Optional[str] = None


class DeleteModelResponse(BaseModel):
    ok: bool
    deleted: str


class RenameModelResponse(BaseModel):
    ok: bool
    name: str


# ============================================================
# App
# ============================================================
@asynccontextmanager
async def lifespan(app: FastAPI):
    # Nothing heavy here on purpose: models stay lazy so the service answers
    # /health immediately after start, and the device is only probed on the
    # first real inference.
    logger.info(f"Models directory:   {MODELS_DIR}")
    logger.info(f"Discovered models: {sorted(_discover_models()) or '(none)'}")
    yield
    _models.clear()


app = FastAPI(title="CRMS TrOCR service", lifespan=lifespan)

app.add_middleware(
    CORSMiddleware,
    allow_origin_regex=_env_value("OCR_BROWSER_ORIGIN_REGEX") or DEFAULT_BROWSER_ORIGIN_REGEX,
    allow_credentials=False,
    allow_methods=["POST"],
    allow_headers=["X-OCR-Upload-Authorization"],
)


@app.exception_handler(HTTPException)
def http_exception_handler(request: Request, exc: HTTPException) -> JSONResponse:
    """Keep the prototype's error body ({"ok": false, "error": "..."}).

    FastAPI would emit {"detail": ...}; Laravel reads `error`, so translate."""
    detail: Any = exc.detail
    body = detail if isinstance(detail, dict) else {"ok": False, "error": detail}
    return JSONResponse(body, status_code=exc.status_code)


@app.exception_handler(RequestValidationError)
def validation_exception_handler(request: Request, exc: RequestValidationError) -> JSONResponse:
    """Same translation for FastAPI's own 422s.

    Without this, a malformed body returns {"detail": [ ... ]} and OcrClient - which
    reads `error` - can only report "HTTP 422" with no explanation of what was wrong."""
    problems = []
    for error in exc.errors():
        # Drop the leading "body"/"query" segment: the field name is what matters.
        location = ".".join(str(part) for part in error.get("loc", ())[1:])
        problems.append(f"{location}: {error.get('msg')}" if location else str(error.get("msg")))

    return JSONResponse(
        {"ok": False, "error": "Invalid request. " + "; ".join(problems)},
        status_code=422,
    )


def _device_name() -> str:
    """The device this service will use, resolved without allocating on the GPU.

    Reported eagerly because the workspace's Engine status panel has to show
    cuda / cpu before anyone has scanned anything. It used to read "not-loaded"
    until the first inference, which looks like a fault rather than an idle
    service."""
    try:
        return str(_get_device())
    except Exception as e:  # a broken driver must not take /health down with it
        return f"unavailable ({type(e).__name__})"


@app.get("/health", response_model=HealthResponse)
def health() -> dict:
    # Touches no GPU, so it keeps answering while a scan is mid-flight. That is
    # what lets the workspace poll for reachability at all.
    return {
        "status": "ok",
        "model_loaded": bool(_models),
        "device": _device_name(),
        "precision": _precision_name(),
        "default": _default_key(),
        "models": _model_info(),
    }


@app.get("/models", response_model=ModelsResponse, dependencies=[Depends(require_service_key)])
def models() -> dict:
    """List selectable models so the frontend can build its dropdown."""
    return {
        "default": _default_key(),
        "models": _model_info(),
    }


# Image processors whose steps _card_pixel_values repeats: resize with Pillow,
# rescale, normalise. Even for these, _card_prep_allowed() first checks the
# result against the processor itself, because another transformers version
# may resize differently (5.14 differs by one grey level from 5.12).
_CARD_PREP_PROCESSORS = {"ViTImageProcessor", "ViTImageProcessorPil"}


def _card_prep_allowed(image_processor, device):
    """Whether _card_pixel_values gives this processor's very input in fp16.

    Checked once per processor on noise pictures in the shapes crops come in,
    scaled up and down, every pixel compared. Any difference, and the processor
    keeps preparing the crops."""
    allowed = getattr(image_processor, "_crms_card_prep_ok", None)
    if allowed is None:
        allowed = False
        try:
            seed = torch.Generator().manual_seed(0)
            probes = [
                Image.fromarray(torch.randint(0, 256, (h, w, 3), dtype=torch.uint8, generator=seed).numpy())
                for h, w in ((57, 283), (45, 31), (65, 489), (440, 60), (700, 900))
            ]
            mine = _card_pixel_values(image_processor, probes, device)
            if mine is not None:
                expected = image_processor(images=probes, return_tensors="pt").pixel_values
                allowed = torch.equal(mine.cpu().half(), expected.half())
        except Exception as e:
            logger.warning("Checking GPU crop preparation failed (%s).", e)
        if not allowed:
            logger.info("Crops are prepared by the processor (the GPU shortcut does not match it exactly).")
        try:
            image_processor._crms_card_prep_ok = allowed
        except Exception:
            pass
    return allowed


def _card_pixel_values(image_processor, images, device):
    """Pixel values with the arithmetic done on the card, or None if this
    processor is not one whose steps are repeated here exactly.

    The processor does the resize and then the rescale/normalise arithmetic in
    numpy on the CPU, about 3 ms per crop while the card waits. The resize stays
    with Pillow (the very same call); only the arithmetic moves to the card, in
    float64 like the processor, then to float32."""
    ip = image_processor
    size = getattr(ip, "size", None)
    height = getattr(size, "height", None) if size is not None else None
    width = getattr(size, "width", None) if size is not None else None
    if isinstance(size, dict):
        height, width = size.get("height"), size.get("width")
    if (
        type(ip).__name__ not in _CARD_PREP_PROCESSORS
        or not (getattr(ip, "do_resize", False) and getattr(ip, "do_rescale", False)
                and getattr(ip, "do_normalize", False))
        or getattr(ip, "do_center_crop", False) or getattr(ip, "do_pad", False)
        or not height or not width
        or len(ip.image_mean) != 3 or len(ip.image_std) != 3
        or any(image.mode != "RGB" for image in images)
    ):
        return None
    size = (int(width), int(height))
    resized = np.stack([np.asarray(image.resize(size, resample=ip.resample)) for image in images])
    x = torch.from_numpy(resized).to(device).permute(0, 3, 1, 2).double() * ip.rescale_factor
    mean = torch.tensor(ip.image_mean, device=device, dtype=torch.float64).view(1, 3, 1, 1)
    std = torch.tensor(ip.image_std, device=device, dtype=torch.float64).view(1, 3, 1, 1)
    return ((x - mean) / std).float()


def _pixel_values(processor, images, device, dtype):
    """The model's input for `images`, on `device`, in `dtype`.

    Reading in fp16 on the card, the arithmetic is done there (identical input,
    about 0.5 s less per 225-line page). Otherwise, if the check finds any
    difference, or if it fails for any reason, the processor does it as before."""
    if dtype == torch.float16 and _on_gpu(device) and _card_prep_allowed(processor.image_processor, device):
        try:
            values = _card_pixel_values(processor.image_processor, images, device)
            if values is not None:
                return values.to(dtype)
        except Exception as e:
            logger.warning("Preparing crops on the GPU failed (%s); using the processor.", e)
    values = processor(images=images, return_tensors="pt").pixel_values.to(device)
    return values if dtype == torch.float32 else values.to(dtype)


def _read_crops(model, processor, device, eos_id, images, dtype=torch.float32):
    """(text, confidence) for each image, in order, from one generate() call."""
    pixel_values = _pixel_values(processor, images, device, dtype)
    with torch.inference_mode():
        # use_cache: fine-tuned models save use_cache=false from training, so
        # each new token recomputed every earlier one. Same readings and
        # confidences, measured on 597 real crops; 215 crops 17 s -> 8 s.
        gen_output = model.generate(
            pixel_values,
            max_new_tokens=MAX_NEW_TOKENS,
            output_scores=True,
            return_dict_in_generate=True,
            use_cache=True,
        )
        confidences = batch_confidences(model, gen_output, eos_id)
    texts = processor.batch_decode(gen_output.sequences, skip_special_tokens=True)
    return [(text.strip(), confidence) for text, confidence in zip(texts, confidences)]


def _ocr_error(name, error):
    """The result row for a crop that could not be read."""
    return {"name": name, "text": "", "confidence": 0.0, "error": str(error)}


# Plain `def`, not `async def`: generate() is blocking and CPU/GPU-bound, so
# FastAPI runs it in a worker thread and /health and uploads stay responsive
# while an OCR job is running.
@app.post("/ocr", response_model=OcrResponse, response_model_exclude_none=True,
          dependencies=[Depends(require_service_key)])
def ocr(payload: OcrRequest) -> dict:
    if not payload.fields:
        raise HTTPException(status_code=400, detail="Send a non-empty 'fields' list.")

    key = _resolve_key(payload.model)
    _maybe_return_to_gpu()
    entry = _load_model(key)
    model = entry["model"]
    processor = entry["processor"]
    device = _get_device()
    eos_id = entry["eos_id"]
    dtype = entry.get("dtype", torch.float32)

    # Decode every crop first. A broken one becomes an error row, so it can't
    # fail the batch it would have been read in.
    results = [None] * len(payload.fields)
    images = []  # (position in the request, image)
    for index, field in enumerate(payload.fields):
        try:
            images.append((index, _decode_data_url(field.image)))
        except Exception as e:
            results[index] = _ocr_error(field.name, e)

    # Lines of a similar shape read together. A batch writes until its longest
    # line is done, so short lines grouped with short ones finish early instead
    # of waiting on a long one. Results go back by position, so order is kept.
    images.sort(key=lambda item: item[1].width / max(item[1].height, 1))

    for start in range(0, len(images), OCR_BATCH_SIZE):
        chunk = images[start:start + OCR_BATCH_SIZE]
        try:
            readings = _read_crops(model, processor, device, eos_id, [image for _, image in chunk], dtype)
        except Exception as e:
            # For example out of GPU memory. Read this chunk one crop at a time,
            # so only a crop that also fails on its own gets an error row.
            logger.warning("Reading %d crops together failed (%s); reading them one at a time.", len(chunk), e)
            # The traceback's frames still hold the failed batch's GPU tensors;
            # drop them, or freeing the cache cannot give that memory back.
            e.__traceback__ = None
            if _on_gpu(device) and _is_out_of_memory(e):
                _free_gpu_cache()
            readings = []
            for _, image in chunk:
                try:
                    readings.append(_read_crops(model, processor, device, eos_id, [image], dtype)[0])
                    continue
                except Exception as single:
                    failure = single
                failure.__traceback__ = None  # as above
                if not _on_gpu(device):
                    readings.append(failure)
                    continue
                if _is_out_of_memory(failure):
                    # A full card is usually another program holding memory,
                    # not a broken card: free torch's cache and try once more.
                    _free_gpu_cache()
                    try:
                        readings.append(_read_crops(model, processor, device, eos_id, [image], dtype)[0])
                        continue
                    except Exception as again:
                        failure = again
                    failure.__traceback__ = None
                # Fails on the card even on its own: a broken card or driver, or
                # still no memory. This crop and the rest go to the CPU - for
                # GPU_RETRY_SECONDS when the card was only full, else for good.
                _stop_using_gpu(failure, retry_after=GPU_RETRY_SECONDS if _is_out_of_memory(failure) else None)
                entry = _load_model(key)
                model, processor, eos_id = entry["model"], entry["processor"], entry["eos_id"]
                device = _get_device()
                dtype = entry.get("dtype", torch.float32)
                try:
                    readings.append(_read_crops(model, processor, device, eos_id, [image], dtype)[0])
                except Exception as on_cpu:
                    readings.append(on_cpu)

        for (index, _), reading in zip(chunk, readings):
            name = payload.fields[index].name
            if isinstance(reading, Exception):
                results[index] = _ocr_error(name, reading)
            else:
                text, conf = reading
                results[index] = {"name": name, "text": text, "confidence": conf}

    return {"results": results, "model": entry["label"], "modelKey": key}


# Files we accept inside a model folder.
_ALLOWED_MODEL_FILES = {
    "config.json", "generation_config.json",
    "preprocessor_config.json", "processor_config.json",
    "tokenizer.json", "tokenizer_config.json",
    "special_tokens_map.json", "added_tokens.json",
    "vocab.json", "merges.txt", "sentencepiece.bpe.model", "spm.model",
    "model.safetensors", "pytorch_model.bin", EVALUATION_REPORT_FILE,
}
_ALLOWED_MODEL_EXTS = {".json", ".safetensors", ".bin", ".txt", ".model"}

_WEIGHT_FILES = {"model.safetensors", "pytorch_model.bin"}


def _sha256_file(path):
    digest = hashlib.sha256()
    with open(path, "rb") as source:
        while chunk := source.read(UPLOAD_CHUNK_BYTES):
            digest.update(chunk)
    return digest.hexdigest()


def _read_evaluation_report(model_dir, strict=False, report_path=None):
    """Return a normalized, artifact-bound benchmark report.

    Reports are optional, but a report included in a new upload must be valid.
    The weights digest prevents metrics from one Kaggle run being attached to a
    different checkpoint by accidentally mixing files before upload.

    `report_path` checks a report that is not yet in `model_dir` (a base-model
    report carried in an upload) against the weights in `model_dir`. Such a
    check is never cached: the cache belongs to the report installed there.
    """
    installed = report_path is None
    path = os.path.join(model_dir, EVALUATION_REPORT_FILE) if installed else report_path
    if not os.path.isfile(path):
        if installed:
            _evaluation_cache.pop(model_dir, None)
        return None

    try:
        if os.path.getsize(path) > MAX_EVALUATION_REPORT_BYTES:
            raise ValueError("the report is larger than 1 MB")
        with open(path, "r", encoding="utf-8") as source:
            report = json.load(source)

        if not isinstance(report, dict) or report.get("schema_version") != 1:
            raise ValueError("schema_version must be 1")
        if report.get("split") != "test":
            raise ValueError("split must be 'test'")

        sample_count = int(report.get("sample_count", 0))
        if sample_count < 1:
            raise ValueError("sample_count must be positive")

        dataset = str(report.get("dataset", "")).strip()
        manifest_sha256 = str(report.get("manifest_sha256", "")).lower()
        weights_file = os.path.basename(str(report.get("weights_file", "")))
        expected_weights_sha256 = str(report.get("weights_sha256", "")).lower()
        evaluated_at = str(report.get("evaluated_at", "")).strip()
        if not dataset:
            raise ValueError("dataset is required")
        if len(manifest_sha256) != 64 or any(c not in "0123456789abcdef" for c in manifest_sha256):
            raise ValueError("manifest_sha256 must be a SHA-256 digest")
        if weights_file not in _WEIGHT_FILES:
            raise ValueError("weights_file must name the model weights")
        if len(expected_weights_sha256) != 64 or any(c not in "0123456789abcdef" for c in expected_weights_sha256):
            raise ValueError("weights_sha256 must be a SHA-256 digest")
        weights_path = os.path.join(model_dir, weights_file)
        if not os.path.isfile(weights_path):
            raise ValueError(f"{weights_file} is missing")

        signature = (
            os.stat(path).st_mtime_ns, os.path.getsize(path),
            os.stat(weights_path).st_mtime_ns, os.path.getsize(weights_path),
            expected_weights_sha256,
        )
        cached = _evaluation_cache.get(model_dir) if installed else None
        if not strict and cached is not None and cached["signature"] == signature:
            return cached["report"]
        if not hmac.compare_digest(_sha256_file(weights_path), expected_weights_sha256):
            raise ValueError("weights_sha256 does not match the uploaded checkpoint")

        parsed_time = datetime.fromisoformat(evaluated_at.replace("Z", "+00:00"))
        if parsed_time.tzinfo is None:
            raise ValueError("evaluated_at must include a timezone")

        metrics = report.get("metrics")
        if not isinstance(metrics, dict):
            raise ValueError("metrics is required")
        cer = float(metrics["cer"])
        wer = float(metrics["wer"])
        exact_match = float(metrics["exact_match"])
        if not all(math.isfinite(value) and value >= 0 for value in (cer, wer)):
            raise ValueError("CER and WER must be finite non-negative rates")
        if not math.isfinite(exact_match) or not 0 <= exact_match <= 1:
            raise ValueError("exact_match must be between 0 and 1")

        normalized = {
            "schema_version": 1,
            "model_key": str(report.get("model_key", "")).strip(),
            "dataset": dataset,
            "manifest_sha256": manifest_sha256,
            "split": "test",
            "sample_count": sample_count,
            "metrics": {"cer": cer, "wer": wer, "exact_match": exact_match},
            "evaluated_at": parsed_time.isoformat(),
            "weights_file": weights_file,
            "weights_sha256": expected_weights_sha256,
        }
        if installed:
            _evaluation_cache[model_dir] = {"signature": signature, "report": normalized}
        return normalized
    except (KeyError, TypeError, ValueError, OSError, json.JSONDecodeError) as error:
        if strict:
            raise HTTPException(
                status_code=400,
                detail=f"Invalid {os.path.basename(path)}: {error}.",
            )
        return None


# The fine-tuning notebook also measures the base model on the same locked test
# set and ships that result in the model ZIP under this name.
BASE_EVALUATION_REPORT_FILE = "base-evaluation-report.json"


def _install_base_report(model_dir):
    """Install the base-model report an upload carries, if it fits this computer.

    The workspace overlays the base model's scores on a fine-tuned model's, which
    is only a fair comparison when both were measured on the same test set. The
    notebook measures both in one run, so the base report travels in the ZIP.
    It is installed as the base model's evaluation-report.json only when:
      - the uploaded model has its own valid report,
      - both reports name the same dataset, manifest and sample count, and
      - its weights_sha256 matches the base model on this computer.
    Otherwise nothing changes. A bad base report never fails the upload: the
    fine-tuned model is still fine. The previous base report is kept beside it.

    Returns "installed", "unchanged", None (no base report in the upload) or the
    reason it was skipped."""
    candidate = os.path.join(model_dir, BASE_EVALUATION_REPORT_FILE)
    if not os.path.isfile(candidate):
        return None

    try:
        if not _looks_like_model(BASE_MODEL_DIR):
            raise ValueError("the base model is not installed in ml/models/base")
        model_report = _read_evaluation_report(model_dir)
        if model_report is None:
            raise ValueError("the uploaded model has no evaluation-report.json to pair it with")
        base_report = _read_evaluation_report(BASE_MODEL_DIR, strict=True, report_path=candidate)
        for field in ("dataset", "manifest_sha256", "sample_count"):
            if base_report[field] != model_report[field]:
                raise ValueError(
                    f"its {field} differs from the uploaded model's report, so it is not the same test set"
                )
    except HTTPException as error:
        reason = str(error.detail).rstrip(".")
    except ValueError as error:
        reason = str(error)
    else:
        target = os.path.join(BASE_MODEL_DIR, EVALUATION_REPORT_FILE)
        with open(candidate, "rb") as source:
            incoming = source.read()
        if os.path.isfile(target):
            with open(target, "rb") as source:
                if source.read() == incoming:
                    return "unchanged"
            shutil.copy2(target, os.path.join(BASE_MODEL_DIR, "evaluation-report.previous.json"))
        staged = target + ".tmp"
        with open(staged, "wb") as out:
            out.write(incoming)
        os.replace(staged, target)
        logger.info("Installed the base-model report from the '%s' upload.", os.path.basename(model_dir))
        return "installed"

    logger.warning("Kept the base-model report: %s.", reason)
    return f"skipped: {reason}"

# A model folder is a dozen small files plus one big weights file. An archive with
# thousands of members is not a model, and walking it would be the expensive part of
# a zip bomb.
_MAX_ARCHIVE_MEMBERS = 500

# Refuse an archive that claims to expand to more than this. TrOCR base is ~1.3 GB;
# 12 GB leaves room for a larger checkpoint without accepting an unbounded write.
_MAX_ARCHIVE_UNCOMPRESSED_BYTES = 12 * 1024 ** 3


def _model_root_in_archive(zf):
    """The directory inside the archive that holds config.json, or None.

    A zip exported from a file manager usually wraps everything in a folder, and
    sometimes in two, so the model is found rather than assumed to be at the root.
    The shallowest directory containing config.json wins; deeper ones would be
    nested checkpoints, not the model that was uploaded."""
    candidates = []

    for info in zf.infolist():
        if info.is_dir():
            continue

        # Zip entries always use forward slashes, but archives written on Windows
        # by careless tooling sometimes contain backslashes.
        path = info.filename.replace("\\", "/")

        # macOS adds a __MACOSX sidecar tree that mirrors every real file.
        if path.startswith("__MACOSX/") or os.path.basename(path).startswith("._"):
            continue

        if os.path.basename(path) == "config.json":
            prefix = path[: -len("config.json")]
            candidates.append(prefix)

    if not candidates:
        return None

    return min(candidates, key=lambda prefix: (prefix.count("/"), len(prefix)))


def _rewound(stream) -> bool:
    """Seek `stream` back to the start, reporting whether it can be read as a zip.

    Python 3.10's SpooledTemporaryFile has no seekable(), so the attribute is
    probed rather than assumed."""
    try:
        if hasattr(stream, "seekable") and not stream.seekable():
            return False
        stream.seek(0)
        return True
    except (AttributeError, OSError, ValueError):
        return False


def _extract_model_archive(source, target):
    """Unpack the model files from `source` into `target`, flattened.

    `source` is a path or an already-rewound seekable file object.

    Only the whitelisted files directly inside the archive's model directory are
    written, and each one is written by name into `target` - member paths are never
    joined onto a filesystem path, so no crafted entry ('../', 'C:/', a symlink) can
    escape. That is the whole zip-slip defence: nothing derived from the archive
    reaches os.path.join except a validated basename."""
    try:
        zf = zipfile.ZipFile(source)
    except zipfile.BadZipFile:
        raise HTTPException(status_code=400, detail="That file is not a readable .zip archive.")

    with zf:
        members = zf.infolist()

        if len(members) > _MAX_ARCHIVE_MEMBERS:
            raise HTTPException(
                status_code=400,
                detail=f"That archive has {len(members)} entries; a model has a handful. "
                       "Zip the model folder on its own.",
            )

        if sum(info.file_size for info in members) > _MAX_ARCHIVE_UNCOMPRESSED_BYTES:
            raise HTTPException(
                status_code=400,
                detail="That archive expands to more than 12 GB, which is far larger than "
                       "a TrOCR checkpoint. Refusing to unpack it.",
            )

        for info in members:
            if info.flag_bits & 0x1:
                raise HTTPException(
                    status_code=400, detail="Encrypted archives are not supported."
                )

        root = _model_root_in_archive(zf)
        if root is None:
            raise HTTPException(
                status_code=400,
                detail="No config.json anywhere in that archive, so it is not a model. "
                       "Zip the folder containing config.json and the weights.",
            )

        saved = []
        for info in members:
            if info.is_dir():
                continue

            path = info.filename.replace("\\", "/")
            if path.startswith("__MACOSX/") or not path.startswith(root):
                continue

            relative = path[len(root):]

            # Only the model directory itself. A nested subfolder is a different
            # artifact (an optimiser state, another checkpoint) and is not part of
            # what makes this model loadable.
            if "/" in relative:
                continue

            base = os.path.basename(relative)
            ext = os.path.splitext(base)[1].lower()
            if base not in _ALLOWED_MODEL_FILES and ext not in _ALLOWED_MODEL_EXTS:
                continue

            # Streamed member -> file. A 1.3 GB weights file must not be read into
            # memory to be written out.
            with zf.open(info, "r") as source, open(os.path.join(target, base), "wb") as out:
                shutil.copyfileobj(source, out, UPLOAD_CHUNK_BYTES)

            saved.append(base)

    if "config.json" not in saved:
        raise HTTPException(status_code=400, detail="Missing config.json.")
    if not (_WEIGHT_FILES & set(saved)):
        raise HTTPException(
            status_code=400,
            detail="Missing weights (model.safetensors or pytorch_model.bin).",
        )

    return sorted(saved)


def _save_model_files(uploads, target):
    """Write a folder upload - one multipart part per model file - into `target`."""
    incoming = {}
    for f in uploads:
        base = os.path.basename((f.filename or "").replace("\\", "/"))
        if not base or ".." in base:
            raise HTTPException(status_code=400, detail="Invalid file name in upload.")
        ext = os.path.splitext(base)[1].lower()
        if base not in _ALLOWED_MODEL_FILES and ext not in _ALLOWED_MODEL_EXTS:
            raise HTTPException(status_code=400, detail=f"File type not allowed: {base}")
        incoming[base] = f

    if "config.json" not in incoming:
        raise HTTPException(status_code=400, detail="Missing config.json.")
    if not (_WEIGHT_FILES & set(incoming)):
        raise HTTPException(
            status_code=400,
            detail="Missing weights (model.safetensors or pytorch_model.bin).",
        )

    saved = []
    for base, f in incoming.items():
        with open(os.path.join(target, base), "wb") as out:
            shutil.copyfileobj(f.file, out, UPLOAD_CHUNK_BYTES)
        saved.append(base)

    return sorted(saved)


@app.post("/add_model", response_model=AddModelResponse)
def add_model(
    name: str = Form(""),
    authorization: str = Header("", alias="X-OCR-Upload-Authorization"),
    files: Optional[list[UploadFile]] = File(None),
    files_bracketed: Optional[list[UploadFile]] = File(None, alias="files[]"),
    archive: Optional[UploadFile] = File(None),
) -> dict:
    """Save an uploaded model into Models/<name>/ so it becomes selectable.

    Multipart form, one of two shapes:
      name    + files    -> the model folder, one part per file
      name    + archive  -> a single .zip; the model is located inside it, so a
                            wrapping folder in the archive is fine

    Starlette spools large uploads to a temp file: loose files are copied across in
    chunks and an archive is read straight from the spool, so a ~1.3 GB weights file
    is never held in memory nor written twice."""
    raw_name = (name or "").strip()
    if not raw_name:
        raise HTTPException(status_code=400, detail="Please provide a model name.")

    _authorize_model_upload(authorization, raw_name)

    safe_name = _safe_model_name(raw_name)

    uploads = list(files or []) or list(files_bracketed or [])

    if archive is not None and uploads:
        raise HTTPException(
            status_code=400,
            detail="Send either one archive or the model files, not both.",
        )
    if archive is None and not uploads:
        raise HTTPException(status_code=400, detail="No files were uploaded.")

    os.makedirs(MODELS_DIR, exist_ok=True)
    target = os.path.join(MODELS_DIR, safe_name)
    if os.path.isdir(target):
        raise HTTPException(
            status_code=409, detail=f"A model named '{safe_name}' already exists."
        )

    os.makedirs(target)
    try:
        if archive is None:
            saved = _save_model_files(uploads, target)
        elif _rewound(archive.file):
            # Starlette spools any part over ~1 MB to a real temp file, so a 1.3 GB
            # archive is already on disk and seekable: zipfile reads it in place.
            # Copying it to a second temp file first would cost a full read and a
            # full write of the whole archive to gain nothing.
            saved = _extract_model_archive(archive.file, target)
        else:
            # Fallback for a stream that cannot be seeked: zipfile needs a seekable
            # file, so spool it to disk before reading.
            handle, staged = tempfile.mkstemp(suffix=".zip", prefix="crms-model-")
            os.close(handle)
            try:
                with open(staged, "wb") as out:
                    shutil.copyfileobj(archive.file, out, UPLOAD_CHUNK_BYTES)
                saved = _extract_model_archive(staged, target)
            finally:
                try:
                    os.remove(staged)
                except OSError:
                    pass

        # Optional, but never silently accept a malformed or mismatched report.
        # A valid report is exposed through /models for Laravel to persist.
        _read_evaluation_report(target, strict=True)
    except HTTPException:
        shutil.rmtree(target, ignore_errors=True)  # never leave a half-written model
        raise
    except Exception as e:
        shutil.rmtree(target, ignore_errors=True)
        raise HTTPException(status_code=500, detail=f"Failed to save files: {e}")

    logger.info(f"Added model '{safe_name}' with {len(saved)} files.")

    # After the model is safely in place: the base report is a bonus, never a
    # reason to undo an upload that is otherwise good.
    try:
        base_report = _install_base_report(target)
    except OSError as e:
        logger.warning("Could not install the base-model report: %s", e)
        base_report = f"skipped: {e}"

    return {"ok": True, "name": safe_name, "saved": saved, "base_report": base_report}


@app.post("/delete_model", response_model=DeleteModelResponse, dependencies=[Depends(require_service_key)])
def delete_model(payload: DeleteModelRequest) -> dict:
    """Delete a model folder from Models/. Body: { "model": "<key>" }.

    The base model's local copy (Models/base) can be deleted too. Guards against
    any path that resolves outside the Models/ directory."""
    key = (payload.model or "").strip()

    if not key:
        raise HTTPException(status_code=400, detail="No model specified.")

    # _discover_models() hides the base folder, so it is looked up on its own.
    discovered = _discover_models()
    if key == BASE_MODEL_KEY:
        if not _looks_like_model(BASE_MODEL_DIR):
            raise HTTPException(status_code=404, detail="The base model has no local copy to delete.")
        discovered = {BASE_MODEL_KEY: BASE_MODEL_DIR}
    if key not in discovered:
        raise HTTPException(status_code=404, detail=f"Model '{key}' was not found.")

    # Path-safety: the resolved folder must live directly inside Models/.
    target = os.path.realpath(discovered[key])
    models_root = os.path.realpath(MODELS_DIR)
    if os.path.dirname(target) != models_root:
        raise HTTPException(
            status_code=400, detail="Refusing to delete outside the Models folder."
        )

    # Free the weights and the VRAM they were holding, if it was loaded.
    _unload_model(key)

    try:
        shutil.rmtree(target)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Could not delete: {e}")

    logger.info(f"Deleted model '{key}'.")
    return {"ok": True, "deleted": key}


@app.post("/rename_model", response_model=RenameModelResponse, dependencies=[Depends(require_service_key)])
def rename_model(payload: RenameModelRequest) -> dict:
    """Rename a model folder in Models/. Body: { "model": "<key>", "newName": "<name>" }.

    Refuses to touch the base model and guards against paths outside Models/."""
    key = (payload.model or "").strip()
    raw_new = (payload.newName or "").strip()

    if not key:
        raise HTTPException(status_code=400, detail="No model specified.")
    if key == BASE_MODEL_KEY:
        raise HTTPException(status_code=400, detail="The base model cannot be renamed.")
    if not raw_new:
        raise HTTPException(status_code=400, detail="Please provide a new name.")

    new_name = _safe_model_name(raw_new)

    discovered = _discover_models()
    if key not in discovered:
        raise HTTPException(status_code=404, detail=f"Model '{key}' was not found.")
    if new_name == key:
        return {"ok": True, "name": new_name}  # nothing to do

    models_root = os.path.realpath(MODELS_DIR)
    src = os.path.realpath(discovered[key])
    dst = os.path.join(models_root, new_name)

    # Path-safety: source must be directly inside Models/, and so must the target.
    if os.path.dirname(src) != models_root:
        raise HTTPException(
            status_code=400, detail="Refusing to rename outside the Models folder."
        )
    if os.path.exists(dst):
        raise HTTPException(
            status_code=409, detail=f"A model named '{new_name}' already exists."
        )

    # Drop any cached copy of the old key so it reloads fresh under the new name.
    _unload_model(key)

    try:
        os.rename(src, dst)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Could not rename: {e}")

    logger.info(f"Renamed model '{key}' -> '{new_name}'.")
    return {"ok": True, "name": new_name}


if __name__ == "__main__":
    import uvicorn

    # Other model-management calls still rely on Laravel and loopback isolation.
    uvicorn.run(app, host="127.0.0.1", port=8001)
