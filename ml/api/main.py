r"""
main.py
FastAPI OCR service that serves the fine-tuned TrOCR models.

Laravel sends one or more cropped field images (as PNG data URLs) and gets back
the predicted text plus a confidence score (the model's certainty in its own
output, 0-100%) for each crop.

This is a port of the Flask prototype (api/app.py) with the same endpoints and
JSON shapes, so the migration is behavior-preserving for callers.

Run from the repo root:
  python -m uvicorn ml.api.main:app --host 127.0.0.1 --port 8001
  (or: python ml\api\main.py)

Endpoints:
  GET  /health     -> status + which models are available/loaded + active job
  GET  /models     -> selectable models for the frontend dropdown
  POST /ocr        -> { "fields": [ { "name": "...", "image": "data:image/png;base64,..." } ],
                       "model": "<key>" }  returns { "results": [ { "name", "text", "confidence" } ] }
  POST /add_model  -> multipart upload (name + files) saved into ml/models/<name>/
  POST /delete_model -> { "model": "<key>" } removes that folder from ml/models/
  POST /rename_model -> { "model": "<key>", "newName": "<name>" } renames the folder
  POST /predict    -> multipart images + model, synchronous, capped. Spot-checking.

  GET    /datasets                 -> named datasets with per-split counts and size
  POST   /datasets                 -> create one from an uploaded zip or directory drop
  GET    /datasets/{name}/validate -> pre-training sanity report
  DELETE /datasets/{name}          -> remove a dataset

  GET  /jobs             -> recent training/evaluation runs
  POST /jobs             -> start one (409 if the GPU is already busy)
  GET  /jobs/{id}        -> status, progress, metrics, log tail
  POST /jobs/{id}/cancel -> request cancellation

Training and evaluation are jobs, never synchronous requests: training runs for
hours. One GPU job at a time - see jobs.py.
"""

import os
import io
import sys
import json
import math
import ntpath
import shutil
import base64
import tempfile
import threading
import unicodedata
from contextlib import asynccontextmanager
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
# and used to silence uvicorn's own log along with everything else - leaving
# EngineProcess with an empty engine.out.log to explain a failed start.
import hf_quiet  # noqa: E402,F401

import torch
from PIL import Image
from fastapi import FastAPI, File, Form, HTTPException, Request, UploadFile
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ConfigDict, Field
from transformers import TrOCRProcessor, VisionEncoderDecoderModel

# Import-cheap siblings: no torch, so /health and /datasets stay responsive while
# the GPU is busy.
import dataset_registry as ds
import jobs
import runners

# ============================================================
# CONFIG
# ============================================================
# Anchored to this file's location (<repo>/ml/api/main.py) rather than the working
# directory, so the service behaves the same however it is launched.
ML_ROOT = _ML_ROOT

# All fine-tuned models live in ml/models/<name>/. Drop a model folder in there
# (with config.json + model.safetensors) and it shows up automatically.
MODELS_DIR = os.path.join(ML_ROOT, "models")

# The un-fine-tuned base model, always offered as an option.
FALLBACK_MODEL = "microsoft/trocr-base-handwritten"
BASE_MODEL_KEY = "base"
BASE_MODEL_LABEL = "TrOCR base (not fine-tuned)"

MAX_NEW_TOKENS = 32

# Uploaded weights are ~1.3 GB, so they are copied to disk in chunks rather
# than read into memory.
UPLOAD_CHUNK_BYTES = 8 * 1024 * 1024

# Optional friendly labels for known folders. Any folder not listed here gets a
# label auto-generated from its name.
MODEL_LABELS = {
    "TrOCR-fine-tune-10k-samples": "TrOCR fine-tuned (10k samples)",
}

# Fallback default when the caller names no model. In CRMS this rarely applies:
# Laravel sends the model marked active in the ocr_models table, which is the
# real source of truth for what Staff scan against.
PREFERRED_DEFAULT = "TrOCR-fine-tune-10k-samples"
# ============================================================

# Models are loaded lazily and cached by key so the server starts instantly
# and each model is only loaded once.
_device = None
_models = {}  # cache_key -> {"model", "processor", "eos_id", "label"}

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
                # A driver capability query, not a CUDA context init, so this is
                # safe to call from /health while a job owns the card.
                _device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    return _device


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
            path = os.path.join(MODELS_DIR, name)
            if _looks_like_model(path):
                found[name] = path
    return found


def _prettify(name):
    return name.replace("-", " ").replace("_", " ").strip().title()


def _label_for(key):
    if key == BASE_MODEL_KEY:
        return BASE_MODEL_LABEL
    return MODEL_LABELS.get(key, _prettify(key))


def _default_key():
    discovered = _discover_models()
    if PREFERRED_DEFAULT in discovered:
        return PREFERRED_DEFAULT
    if discovered:
        return next(iter(discovered))
    return BASE_MODEL_KEY


def _resolve_key(requested):
    """Choose a usable model key, honoring the request when possible."""
    if requested == BASE_MODEL_KEY:
        return BASE_MODEL_KEY
    if requested in _discover_models():
        return requested
    return _default_key()


def _model_info():
    """Descriptor list of every selectable model for /models and /health."""
    infos = [
        {
            "key": key,
            "label": _label_for(key),
            "available": True,
            "loaded": key in _models,
        }
        for key in _discover_models()
    ]
    # The base model is always available (pulled from the HF cache / hub).
    infos.append({
        "key": BASE_MODEL_KEY,
        "label": BASE_MODEL_LABEL,
        "available": True,
        "loaded": BASE_MODEL_KEY in _models,
    })
    return infos


def _load_model(key):
    """Load (and cache) the model for `key`. Returns its cache entry."""
    device = _get_device()

    if key == BASE_MODEL_KEY:
        model_src, label, cache_key = FALLBACK_MODEL, BASE_MODEL_LABEL, BASE_MODEL_KEY
    else:
        model_src = _discover_models().get(key)
        if model_src is None:
            # Requested folder vanished/invalid -> fall back to the base model.
            model_src, label, cache_key = FALLBACK_MODEL, BASE_MODEL_LABEL, BASE_MODEL_KEY
        else:
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

        print(f"[ocr-api] Loading model: {label}  ({model_src})  on  {device}")
        processor = TrOCRProcessor.from_pretrained(model_src)
        model = VisionEncoderDecoderModel.from_pretrained(model_src)
        model.to(device)
        model.eval()

        eos_id = (
            getattr(model.generation_config, "eos_token_id", None)
            or getattr(model.config, "eos_token_id", None)
            or getattr(model.config.decoder, "eos_token_id", None)
            or processor.tokenizer.sep_token_id
        )

        entry = {"model": model, "processor": processor, "eos_id": eos_id, "label": label}
        _models[cache_key] = entry
        print(f"[ocr-api] Model ready: {label}")
        return entry


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


def _guard_model_not_in_use(key, verb):
    """Refuse to rename or delete a model a running job depends on.

    Training writes into its output folder between epochs and evaluation reads the
    model it was given; moving either out from under a job corrupts it. Laravel
    blocks the *active* model separately - this is the check only the service can
    make, because the service owns the jobs."""
    active = jobs.manager.active()
    if active is None:
        return

    config = active.config or {}
    involved = {config.get("output_name"), config.get("model"), config.get("base_model")}

    if key in involved - {None}:
        raise HTTPException(
            status_code=409,
            detail=(
                f"'{key}' is in use by the running {active.type} job ({active.id}). "
                f"Cancel it or wait for it to finish before you {verb} this model."
            ),
        )


def _sequence_confidence(model, gen_output, eos_id):
    """Geometric mean of per-token probabilities up to the first EOS, as a %."""
    try:
        scores = model.compute_transition_scores(
            gen_output.sequences, gen_output.scores, normalize_logits=True
        )[0]
        gen_tokens = gen_output.sequences[0][1:1 + len(scores)]
        log_probs = []
        for tok, lp in zip(gen_tokens, scores):
            if not torch.isfinite(lp):
                continue
            log_probs.append(lp.item())
            if tok.item() == eos_id:
                break
        if not log_probs:
            return 0.0
        return round(math.exp(sum(log_probs) / len(log_probs)) * 100.0, 1)
    except Exception:
        return 0.0


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


class HealthResponse(BaseModel):
    # `model_loaded` collides with pydantic v2's protected `model_` namespace, which
    # warns at class-definition time. The key is part of the contract Laravel reads,
    # so opt out of the namespace rather than renaming the field.
    model_config = ConfigDict(protected_namespaces=())

    status: str
    model_loaded: bool
    device: str
    default: str
    models: list[ModelInfo]
    # Added for the OCR workspace. Optional so an older caller reading only the
    # original keys is unaffected.
    job: Optional[dict] = None
    busy: bool = False


class ModelsResponse(BaseModel):
    default: str
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
    print(f"[ocr-api] Models directory:   {MODELS_DIR}")
    print(f"[ocr-api] Datasets directory: {ds.DATASETS_DIR}")
    print(f"[ocr-api] Discovered models: {sorted(_discover_models()) or '(none)'}")
    print(f"[ocr-api] Discovered datasets: {[d['name'] for d in ds.list_datasets()] or '(none)'}")
    yield
    _models.clear()


app = FastAPI(title="CRMS TrOCR service", lifespan=lifespan)

# No CORS middleware on purpose. Laravel is the only client and it calls this
# service server-side over 127.0.0.1, so no browser origin ever talks to it
# directly. Adding permissive CORS would only widen the attack surface of a
# service that has no authentication of its own.


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
    # Touches no GPU, so it keeps answering while a training job saturates the
    # card. That is what lets the workspace poll for progress at all.
    active = jobs.manager.active()

    return {
        "status": "ok",
        "model_loaded": bool(_models),
        "device": _device_name(),
        "default": _default_key(),
        "models": _model_info(),
        "job": active.summary() if active else None,
        "busy": active is not None,
    }


@app.get("/models", response_model=ModelsResponse)
def models() -> dict:
    """List selectable models so the frontend can build its dropdown."""
    return {
        "default": _default_key(),
        "models": _model_info(),
    }


# Plain `def`, not `async def`: generate() is blocking and CPU/GPU-bound, so
# FastAPI runs it in a worker thread and /health and uploads stay responsive
# while an OCR job is running.
@app.post("/ocr", response_model=OcrResponse, response_model_exclude_none=True)
def ocr(payload: OcrRequest) -> dict:
    if not payload.fields:
        raise HTTPException(status_code=400, detail="Send a non-empty 'fields' list.")

    key = _resolve_key(payload.model)
    entry = _load_model(key)
    model = entry["model"]
    processor = entry["processor"]
    device = _get_device()
    eos_id = entry["eos_id"]

    results = []
    for field in payload.fields:
        name = field.name
        try:
            image = _decode_data_url(field.image)
            pixel_values = processor(images=image, return_tensors="pt").pixel_values.to(device)
            with torch.no_grad():
                gen_output = model.generate(
                    pixel_values,
                    max_new_tokens=MAX_NEW_TOKENS,
                    output_scores=True,
                    return_dict_in_generate=True,
                )
            text = processor.batch_decode(
                gen_output.sequences, skip_special_tokens=True
            )[0].strip()
            conf = _sequence_confidence(model, gen_output, eos_id)
            results.append({"name": name, "text": text, "confidence": conf})
        except Exception as e:
            # One bad crop must not fail the whole batch.
            results.append({"name": name, "text": "", "confidence": 0.0, "error": str(e)})

    return {"results": results, "model": entry["label"], "modelKey": key}


# Files we accept inside a model folder.
_ALLOWED_MODEL_FILES = {
    "config.json", "generation_config.json",
    "preprocessor_config.json", "processor_config.json",
    "tokenizer.json", "tokenizer_config.json",
    "special_tokens_map.json", "added_tokens.json",
    "vocab.json", "merges.txt", "sentencepiece.bpe.model", "spm.model",
    "model.safetensors", "pytorch_model.bin",
}
_ALLOWED_MODEL_EXTS = {".json", ".safetensors", ".bin", ".txt", ".model"}


@app.post("/add_model", response_model=AddModelResponse)
def add_model(
    name: str = Form(""),
    files: Optional[list[UploadFile]] = File(None),
    files_bracketed: Optional[list[UploadFile]] = File(None, alias="files[]"),
) -> dict:
    """Save an uploaded model folder into Models/<name>/ so it becomes selectable.

    Multipart form:
      name      -> desired model name (folder name)
      files     -> the model files (config.json, model.safetensors, ...)

    Starlette spools large uploads to a temp file and we copy them across in
    chunks, so a ~1.3 GB weights file is never held in memory."""
    raw_name = (name or "").strip()
    if not raw_name:
        raise HTTPException(status_code=400, detail="Please provide a model name.")

    safe_name = _safe_model_name(raw_name)

    uploads = list(files or []) or list(files_bracketed or [])
    if not uploads:
        raise HTTPException(status_code=400, detail="No files were uploaded.")

    # Validate names/extensions and that it looks like a real model.
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
    if not ({"model.safetensors", "pytorch_model.bin"} & set(incoming)):
        raise HTTPException(
            status_code=400,
            detail="Missing weights (model.safetensors or pytorch_model.bin).",
        )

    os.makedirs(MODELS_DIR, exist_ok=True)
    target = os.path.join(MODELS_DIR, safe_name)
    if os.path.isdir(target):
        raise HTTPException(
            status_code=409, detail=f"A model named '{safe_name}' already exists."
        )

    os.makedirs(target)
    try:
        saved = []
        for base, f in incoming.items():
            with open(os.path.join(target, base), "wb") as out:
                shutil.copyfileobj(f.file, out, UPLOAD_CHUNK_BYTES)
            saved.append(base)
    except Exception as e:
        shutil.rmtree(target, ignore_errors=True)  # roll back on failure
        raise HTTPException(status_code=500, detail=f"Failed to save files: {e}")

    print(f"[ocr-api] Added model '{safe_name}' with {len(saved)} files.")
    return {"ok": True, "name": safe_name, "saved": saved}


@app.post("/delete_model", response_model=DeleteModelResponse)
def delete_model(payload: DeleteModelRequest) -> dict:
    """Delete a model folder from Models/. Body: { "model": "<key>" }.

    Refuses to touch the built-in base model and guards against any path that
    resolves outside the Models/ directory."""
    key = (payload.model or "").strip()

    if not key:
        raise HTTPException(status_code=400, detail="No model specified.")
    if key == BASE_MODEL_KEY:
        raise HTTPException(status_code=400, detail="The base model cannot be deleted.")

    # Before the existence check, not after. A training run's output folder does not
    # exist until its first checkpoint lands, so checking existence first reports a
    # confusing 404 for a name that is very much in use - and leaves a race where
    # the folder appears between the two checks.
    _guard_model_not_in_use(key, "delete")

    discovered = _discover_models()
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

    print(f"[ocr-api] Deleted model '{key}'.")
    return {"ok": True, "deleted": key}


@app.post("/rename_model", response_model=RenameModelResponse)
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

    # Checked first, for the same reason as in delete_model: a running job's output
    # folder may not exist yet, and "not found" would be a misleading answer.
    _guard_model_not_in_use(key, "rename")
    # Also block taking over a name a job is about to write into.
    _guard_model_not_in_use(new_name, "rename onto")

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

    print(f"[ocr-api] Renamed model '{key}' -> '{new_name}'.")
    return {"ok": True, "name": new_name}


# ============================================================
# Spot-check prediction (synchronous, capped)
# ============================================================
# Loose unlabelled images, a handful at a time. Anything larger is an evaluation
# job: this call blocks a worker thread until it finishes.
MAX_PREDICT_IMAGES = 50


def _cached_loader(source):
    """Adapter so the ML scripts reuse this service's in-process model cache.

    The scripts resolve a model key to a folder path (or the HF name) and then
    call `loader(source)`. Mapping that back to a cache key avoids loading a
    second ~1.3 GB copy of a model that is already in VRAM."""
    if source == FALLBACK_MODEL:
        key = BASE_MODEL_KEY
    else:
        key = os.path.basename(os.path.normpath(source))

    entry = _load_model(key)
    return entry["processor"], entry["model"]


class PredictResponse(BaseModel):
    ok: bool
    model: str
    modelKey: str
    count: int
    average_confidence: float
    low_confidence: int
    threshold: float
    rows: list[dict]


# Plain `def`: blocking GPU work belongs on a threadpool so /health keeps answering.
@app.post("/predict", response_model=PredictResponse)
def predict(
    model: str = Form(""),
    files: Optional[list[UploadFile]] = File(None),
    files_bracketed: Optional[list[UploadFile]] = File(None, alias="files[]"),
) -> dict:
    """Predict text for a few loose images, with a confidence per image."""
    import predict as predictor

    uploads = list(files or []) or list(files_bracketed or [])
    if not uploads:
        raise HTTPException(status_code=400, detail="No images were uploaded.")

    if len(uploads) > MAX_PREDICT_IMAGES:
        raise HTTPException(
            status_code=400,
            detail=f"Up to {MAX_PREDICT_IMAGES} images at a time. "
                   "For a larger run, start an evaluation job.",
        )

    # Not _resolve_key(): that silently substitutes the default when a model is
    # missing, which is the right call for /ocr (a Staff scan should still produce
    # something) and the wrong one here. A Super Admin spot-checking model X to
    # decide whether to promote it must never be shown model Y's output under X's
    # name. Say the model is gone instead.
    key = (model or "").strip() or _default_key()
    if key != BASE_MODEL_KEY and key not in _discover_models():
        raise HTTPException(
            status_code=404,
            detail=f"The service cannot see a model named '{key}'. Rescan the model list.",
        )

    # Written to a temp folder and removed straight after, so a spot-check never
    # leaves images on the server.
    with tempfile.TemporaryDirectory(prefix="crms-predict-") as staging:
        paths = []
        for upload in uploads:
            base = os.path.basename((upload.filename or "").replace("\\", "/"))
            if not base or ".." in base:
                raise HTTPException(status_code=400, detail="Invalid file name in upload.")
            if not base.lower().endswith(ds.IMAGE_EXTENSIONS):
                raise HTTPException(status_code=400, detail=f"Not an image: {base}")

            target = os.path.join(staging, base)
            with open(target, "wb") as out:
                shutil.copyfileobj(upload.file, out, UPLOAD_CHUNK_BYTES)
            paths.append(target)

        try:
            result = predictor.run_prediction(
                model=key,
                image_paths=paths,
                limit=MAX_PREDICT_IMAGES,
                max_new_tokens=MAX_NEW_TOKENS,
                loader=_cached_loader,
                write_csv=False,
            )
        except predictor.PredictionError as e:
            raise HTTPException(status_code=400, detail=str(e))

    return {
        "ok": True,
        "model": _label_for(key),
        "modelKey": key,
        "count": result["count"],
        "average_confidence": result["average_confidence"],
        "low_confidence": result["low_confidence"],
        "threshold": result["threshold"],
        "rows": result["rows"],
    }


# ============================================================
# Datasets
# ============================================================
class DatasetsResponse(BaseModel):
    ok: bool
    datasets: list[dict]


@app.get("/datasets", response_model=DatasetsResponse)
def list_datasets() -> dict:
    """Named datasets with per-split image counts and total size."""
    return {"ok": True, "datasets": ds.list_datasets()}


@app.get("/datasets/{name}/validate")
def validate_dataset(name: str) -> dict:
    """Sanity report, run before a dataset is offered for training.

    A manifest that points at missing files wastes hours of GPU time and fails
    deep into an epoch, so this is not optional."""
    try:
        return {"ok": True, "report": ds.validate(name)}
    except ds.DatasetError as e:
        raise HTTPException(status_code=404, detail=str(e))


def _normalise_dataset_upload_paths(paths_json: str, uploads: list[UploadFile]) -> list[str]:
    """Validate and normalise browser-supplied relative paths in upload order."""
    try:
        paths = json.loads(paths_json)
    except (TypeError, json.JSONDecodeError):
        raise HTTPException(status_code=400, detail="paths_json must be a valid JSON array.")

    if not isinstance(paths, list):
        raise HTTPException(status_code=400, detail="paths_json must be a JSON array.")
    if not paths or not uploads:
        raise HTTPException(status_code=400, detail="No directory files were uploaded.")
    if len(paths) != len(uploads):
        raise HTTPException(
            status_code=400,
            detail="The number of uploaded files must match the number of paths.",
        )

    normalised = []
    file_paths = set()
    directory_paths = set()

    for index, raw_path in enumerate(paths):
        if not isinstance(raw_path, str):
            raise HTTPException(
                status_code=400,
                detail=f"Dataset path {index + 1} must be a string.",
            )

        path = raw_path.replace("\\", "/")
        drive, _tail = ntpath.splitdrive(path)
        if drive or path.startswith("/"):
            raise HTTPException(
                status_code=400,
                detail=f"Dataset path {index + 1} must be relative.",
            )

        segments = path.split("/")
        if any(segment in ("", ".", "..") for segment in segments):
            raise HTTPException(
                status_code=400,
                detail=f"Dataset path {index + 1} contains an unsafe path segment.",
            )
        if any(
            len(segment.encode("utf-8")) > 255
            or any(character in '<>:"|?*' for character in segment)
            or segment.rstrip(" .") != segment
            or any(unicodedata.category(character).startswith("C") for character in segment)
            for segment in segments
        ):
            raise HTTPException(
                status_code=400,
                detail=f"Dataset path {index + 1} contains an unsafe path segment.",
            )
        if any(
            os.path.splitext(segment)[0].casefold()
            in {"con", "prn", "aux", "nul", *(f"com{i}" for i in range(1, 10)), *(f"lpt{i}" for i in range(1, 10))}
            for segment in segments
        ):
            raise HTTPException(
                status_code=400,
                detail=f"Dataset path {index + 1} contains a reserved file name.",
            )

        path = "/".join(segments)
        filename = segments[-1]
        if filename != ds.MANIFEST_NAME and not filename.lower().endswith(ds.IMAGE_EXTENSIONS):
            raise HTTPException(
                status_code=400,
                detail=f"Dataset path '{path}' is not a manifest CSV or supported image.",
            )

        # Directory drops must remain portable to Windows, where case-only path
        # differences and alternate separators address the same destination.
        key = tuple(segment.casefold() for segment in segments)
        if key in file_paths:
            raise HTTPException(status_code=400, detail=f"Duplicate dataset path: {path}")
        if key in directory_paths or any(key[:length] in file_paths for length in range(1, len(key))):
            raise HTTPException(status_code=400, detail=f"Conflicting dataset path: {path}")

        file_paths.add(key)
        for length in range(1, len(key)):
            directory_paths.add(key[:length])
        normalised.append(path)

    return normalised


@app.post("/datasets")
def create_dataset(
    name: str = Form(""),
    file: Optional[UploadFile] = File(None),
    files: Optional[list[UploadFile]] = File(None),
    paths_json: Optional[str] = Form(None),
) -> dict:
    """Create a dataset from one zip or a directory drop, never both."""
    if not (name or "").strip():
        raise HTTPException(status_code=400, detail="Please provide a dataset name.")

    directory_files = files or []
    has_directory_payload = bool(directory_files) or paths_json is not None
    if file is not None and has_directory_payload:
        raise HTTPException(
            status_code=400,
            detail="Upload either one zip archive or directory files, not both.",
        )
    if file is None and not has_directory_payload:
        raise HTTPException(status_code=400, detail="No archive or directory files were uploaded.")

    try:
        safe = ds.sanitise_name(name)
    except ds.DatasetError as e:
        raise HTTPException(status_code=400, detail=str(e))

    os.makedirs(ds.DATASETS_DIR, exist_ok=True)

    if file is not None:
        # Copied in chunks: a dataset archive can be tens of gigabytes.
        # 8 MB copy buffer keeps memory flat regardless of archive size.
        handle, staged_zip = tempfile.mkstemp(suffix=".zip", prefix="crms-dataset-")
        os.close(handle)

        try:
            with open(staged_zip, "wb") as out:
                shutil.copyfileobj(file.file, out, UPLOAD_CHUNK_BYTES)

            result = ds.create_from_zip(safe, staged_zip)
        except ds.DatasetError as e:
            raise HTTPException(status_code=400, detail=str(e))
        except Exception as e:
            raise HTTPException(status_code=500, detail=f"Failed to unpack the dataset: {e}")
        finally:
            # Always remove the staged zip — it can be tens of gigabytes.
            try:
                os.remove(staged_zip)
            except OSError:
                pass
    else:
        if paths_json is None:
            raise HTTPException(status_code=400, detail="paths_json is required for directory files.")
        normalised_paths = _normalise_dataset_upload_paths(paths_json, directory_files)

        try:
            with tempfile.TemporaryDirectory(prefix="crms-dataset-directory-") as staged_directory:
                staging_root = os.path.realpath(staged_directory)
                for upload, relative_path in zip(directory_files, normalised_paths):
                    destination = os.path.realpath(
                        os.path.join(staged_directory, *relative_path.split("/"))
                    )
                    try:
                        inside_staging = os.path.commonpath((staging_root, destination)) == staging_root
                    except ValueError:
                        inside_staging = False
                    if not inside_staging:
                        raise ds.DatasetError("Refusing to stage a file outside the upload directory.")

                    os.makedirs(os.path.dirname(destination), exist_ok=True)
                    with open(destination, "wb") as out:
                        shutil.copyfileobj(upload.file, out, UPLOAD_CHUNK_BYTES)

                result = ds.create_from_directory(safe, staged_directory)
        except ds.DatasetError as e:
            raise HTTPException(status_code=400, detail=str(e))
        except Exception as e:
            raise HTTPException(status_code=500, detail=f"Failed to install the dataset: {e}")

    print(f"[ocr-api] Added dataset '{result['name']}'.")
    return {"ok": True, **result}


@app.delete("/datasets/{name}")
def delete_dataset(name: str) -> dict:
    """Destructive: removes the dataset folder and every image in it."""
    active = jobs.manager.active()
    if active is not None:
        # Both sides sanitised, so 'my dataset' and 'my-dataset' cannot slip past
        # the check and delete the images a running job is reading.
        try:
            requested = ds.sanitise_name(name)
        except ds.DatasetError:
            requested = name

        if (active.config or {}).get("dataset") == requested:
            raise HTTPException(
                status_code=409,
                detail=f"'{requested}' is in use by the running {active.type} job "
                       f"({active.id}). Cancel it first.",
            )

    try:
        deleted = ds.delete_dataset(name)
    except ds.DatasetError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Could not delete: {e}")

    print(f"[ocr-api] Deleted dataset '{deleted}'.")
    return {"ok": True, "deleted": deleted}


# ============================================================
# Jobs (training / evaluation)
# ============================================================
class JobRequest(BaseModel):
    type: str
    config: dict = Field(default_factory=dict)


@app.get("/jobs")
def list_jobs() -> dict:
    """Recent runs. Laravel mirrors these into ml_jobs for the durable history."""
    return {"ok": True, "jobs": jobs.manager.list(), "active": _active_summary()}


@app.get("/jobs/{job_id}")
def get_job(job_id: str) -> dict:
    job = jobs.manager.get(job_id)
    if job is None:
        raise HTTPException(status_code=404, detail=f"Job '{job_id}' was not found.")
    return {"ok": True, "job": job.snapshot()}


@app.post("/jobs")
def start_job(payload: JobRequest) -> dict:
    """Start a training or evaluation run.

    Returns 409 when a GPU job is already in flight: the message names what is
    running rather than queueing silently behind it."""
    job_type = (payload.type or "").strip().lower()

    # Checked before the config is validated: being told "already running" should
    # not depend on the second request happening to be well-formed.
    running = jobs.manager.active()
    if running is not None:
        raise HTTPException(status_code=409, detail=str(jobs.JobBusy(running.summary())))

    if job_type == jobs.TRAINING:
        try:
            config = runners.validate_training_config(
                payload.config, [m["key"] for m in _model_info()]
            )
        except (ValueError, ds.DatasetError) as e:
            raise HTTPException(status_code=400, detail=str(e))
        runner = runners.training_runner(config)

    elif job_type == jobs.EVALUATION:
        available = [m["key"] for m in _model_info()]
        try:
            config = runners.validate_evaluation_config(payload.config, available)
        except (ValueError, ds.DatasetError) as e:
            raise HTTPException(status_code=400, detail=str(e))
        runner = runners.evaluation_runner(config, loader=_cached_loader)

    else:
        raise HTTPException(
            status_code=400,
            detail=f"Unknown job type '{payload.type}'. Expected 'training' or 'evaluation'.",
        )

    try:
        job = jobs.manager.start(job_type, config, runner)
    except jobs.JobBusy as e:
        raise HTTPException(status_code=409, detail=str(e))

    print(f"[ocr-api] Started {job_type} job {job.id}: {config}")
    return {"ok": True, "job_id": job.id, "job": job.snapshot()}


@app.post("/jobs/{job_id}/cancel")
def cancel_job(job_id: str) -> dict:
    """Request cancellation. The runner stops between steps, leaving no
    half-written checkpoint."""
    job = jobs.manager.cancel(job_id)
    if job is None:
        raise HTTPException(status_code=404, detail=f"Job '{job_id}' was not found.")
    return {"ok": True, "job": job.snapshot()}


@app.get("/training_defaults")
def training_defaults() -> dict:
    """The scripts' own defaults, so the training form is pre-filled from one source."""
    return {"ok": True, "defaults": runners.training_defaults()}


def _active_summary():
    active = jobs.manager.active()
    return active.summary() if active else None


if __name__ == "__main__":
    import uvicorn

    # 127.0.0.1 only: the service has no auth of its own, Laravel proxies it.
    uvicorn.run(app, host="127.0.0.1", port=8001)
