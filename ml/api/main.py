"""
main.py
FastAPI OCR service that serves the fine-tuned TrOCR models.

Laravel sends one or more cropped field images (as PNG data URLs) and gets back
the predicted text plus a confidence score (the model's certainty in its own
output, 0-100%) for each crop.

This is a port of the Flask prototype (api/app.py) with the same endpoints and
JSON shapes, so the migration is behavior-preserving for callers.

Run:
  uvicorn api.main:app --host 127.0.0.1 --port 8001
  (or: python api/main.py)

Endpoints:
  GET  /health     -> status + which models are available/loaded
  GET  /models     -> selectable models for the frontend dropdown
  POST /ocr        -> { "fields": [ { "name": "...", "image": "data:image/png;base64,..." } ],
                       "model": "<key>" }  returns { "results": [ { "name", "text", "confidence" } ] }
  POST /add_model  -> multipart upload (name + files) saved into Models/<name>/
  POST /delete_model -> { "model": "<key>" } removes that folder from Models/
  POST /rename_model -> { "model": "<key>", "newName": "<name>" } renames the folder
"""

import os
import io
import re
import math
import shutil
import base64
import warnings
import logging
from contextlib import asynccontextmanager
from typing import Any, Optional

# --- Quiet down HF / transformers noise ---
# Set before importing torch/transformers, otherwise they are read too late.
os.environ["HF_HUB_DISABLE_SYMLINKS_WARNING"] = "1"
os.environ["HF_HUB_DISABLE_IMPLICIT_TOKEN"] = "1"
os.environ["TRANSFORMERS_NO_ADVISORY_WARNINGS"] = "1"
os.environ["TRANSFORMERS_VERBOSITY"] = "error"
warnings.filterwarnings("ignore")
logging.disable(logging.WARNING)

import torch
from PIL import Image
from fastapi import FastAPI, File, Form, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field
from transformers import TrOCRProcessor, VisionEncoderDecoderModel

# ============================================================
# CONFIG
# ============================================================
# Anchored to this file's location (<repo>/ml/api/main.py) rather than the working
# directory, so the service behaves the same however it is launched.
ML_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

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


def _get_device():
    global _device
    if _device is None:
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

    if cache_key in _models:
        return _models[cache_key]

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
    status: str
    model_loaded: bool
    device: str
    default: str
    models: list[ModelInfo]


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
    print(f"[ocr-api] Models directory: {MODELS_DIR}")
    print(f"[ocr-api] Discovered models: {sorted(_discover_models()) or '(none)'}")
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


@app.get("/health", response_model=HealthResponse)
def health() -> dict:
    return {
        "status": "ok",
        "model_loaded": bool(_models),
        "device": str(_device) if _device else "not-loaded",
        "default": _default_key(),
        "models": _model_info(),
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

    # Sanitize into a safe folder name.
    safe_name = re.sub(r"[^A-Za-z0-9._-]+", "-", raw_name).strip("-._")
    if not safe_name or safe_name == BASE_MODEL_KEY:
        raise HTTPException(status_code=400, detail="That model name is not allowed.")

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

    # Free the model from memory if it was loaded.
    _models.pop(key, None)

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

    # Sanitize the new name the same way as add_model.
    new_name = re.sub(r"[^A-Za-z0-9._-]+", "-", raw_new).strip("-._")
    if not new_name or new_name == BASE_MODEL_KEY:
        raise HTTPException(status_code=400, detail="That model name is not allowed.")

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
    _models.pop(key, None)

    try:
        os.rename(src, dst)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Could not rename: {e}")

    print(f"[ocr-api] Renamed model '{key}' -> '{new_name}'.")
    return {"ok": True, "name": new_name}


if __name__ == "__main__":
    import uvicorn

    # 127.0.0.1 only: the service has no auth of its own, Laravel proxies it.
    uvicorn.run(app, host="127.0.0.1", port=8001)
