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
  POST /add_model  -> multipart upload saved into ml/models/<name>/. Either loose
                      `files` (a model folder) or one `archive` (.zip).
  POST /delete_model -> { "model": "<key>" } removes that folder from ml/models/
  POST /rename_model -> { "model": "<key>", "newName": "<name>" } renames the folder

Training, evaluation, dataset preparation, and batch prediction are deliberately
NOT here. They are long-running command-line work - see ml/train_trocr.py,
ml/test_finetuned.py, ml/predict.py - and a request handler is the wrong place to
pin a GPU for hours.
"""

import os
import io
import sys
import math
import shutil
import base64
import tempfile
import threading
import zipfile
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
# and would silence uvicorn's own log along with everything else - leaving the
# terminal with nothing to explain a failed start.
import hf_quiet  # noqa: E402,F401

import torch
from PIL import Image
from fastapi import FastAPI, File, Form, HTTPException, Request, UploadFile
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ConfigDict, Field
from transformers import TrOCRProcessor, VisionEncoderDecoderModel

# Import-cheap sibling: no torch, so /health stays responsive while the GPU is busy.
# Only `sanitise_name` is used, so model and dataset names fold to a safe path
# segment by exactly one rule rather than two copies of it.
import dataset_registry as ds

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
    return name.replace("-", " ").replace("_", " ").strip()


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

_WEIGHT_FILES = {"model.safetensors", "pytorch_model.bin"}

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
    except HTTPException:
        shutil.rmtree(target, ignore_errors=True)  # never leave a half-written model
        raise
    except Exception as e:
        shutil.rmtree(target, ignore_errors=True)
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


if __name__ == "__main__":
    import uvicorn

    # 127.0.0.1 only: the service has no auth of its own, Laravel proxies it.
    uvicorn.run(app, host="127.0.0.1", port=8001)
