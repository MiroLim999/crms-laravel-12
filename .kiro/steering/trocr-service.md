# TrOCR service

## What already exists

This repo started as a working TrOCR prototype and that pipeline is the core of CRMS:

1. Fine-tune `microsoft/trocr-base-handwritten` on a custom handwriting dataset
   (`train_trocr.py`, best-by-val-loss checkpoint saved to `Models/<name>/`).
2. Evaluate base vs. fine-tuned with CER / WER / exact-match and export a timestamped
   PNG chart (`test_trocr.py`, `test_finetuned.py`, `metrics.py`).
3. Once a model's metrics look good, it is added to `Models/` and becomes selectable for
   scanning handwritten documents in the app.

Model folders are auto-discovered: any folder under `Models/` containing `config.json`
plus `model.safetensors` or `pytorch_model.bin` shows up without a restart. The base
`microsoft/trocr-base-handwritten` model is always offered as the `base` key and can
never be renamed or deleted.

Keep the training and evaluation scripts as standalone CLI tools. They are not part of
the request path.

## Flask to FastAPI migration

The prototype API is Flask (`api/app.py`, port 5000). **Rewrite it as FastAPI** — that is
the target for CRMS. Guidance:

- Entry point `api/main.py`, run with `uvicorn api.main:app --host 127.0.0.1 --port 8001`.
- Keep the endpoint paths and JSON shapes below so the migration is behavior-preserving.
- Port the logic as-is where it already works. Specifically preserve:
  - Lazy model loading with an in-process cache keyed by model name.
  - `compute_transition_scores` confidence: geometric mean of per-token probabilities up
    to the first EOS, returned as a 0-100 percentage.
  - Model-folder discovery, name sanitizing (`[^A-Za-z0-9._-]` to `-`), the allowed-file
    whitelist on upload, rollback on failed upload, and the path-safety checks that
    refuse to delete or rename anything outside `Models/`.
- Replace Flask idioms with FastAPI ones: Pydantic request/response models, `UploadFile`
  for weight uploads (stream to disk, never buffer multi-GB files in memory), `HTTPException`
  instead of `jsonify(...), status`, `async def` only where there's real I/O to await.
- Model inference is blocking and CPU/GPU-bound: run it in a threadpool (`def` endpoint or
  `run_in_threadpool`) so health checks and uploads aren't blocked.
- `api/requirements.txt` becomes `fastapi`, `uvicorn[standard]`, `python-multipart`
  (drop `flask`, `flask-cors`).

## Endpoint contract

| Method | Path             | Purpose                                                        |
|--------|------------------|----------------------------------------------------------------|
| GET    | `/health`        | status, device, whether any model is loaded, default model key  |
| GET    | `/models`        | selectable models: `key`, `label`, `available`, `loaded`         |
| POST   | `/ocr`           | `{ fields: [{ name, image }], model }` -> `{ results: [{ name, text, confidence }], model, modelKey }` |
| POST   | `/add_model`     | multipart `name` + `files` -> saved to `Models/<name>/`          |
| POST   | `/delete_model`  | `{ model }` -> removes the folder                               |
| POST   | `/rename_model`  | `{ model }`, `{ newName }` -> renames the folder                 |

`image` is a `data:image/png;base64,...` data URL of a single cropped field.

## How Laravel talks to it

- Laravel calls the FastAPI service **server-side** with the HTTP client, never from the
  browser. Base URL in config (`config/services.php` + `OCR_API_URL` in `.env`), defaulting
  to `http://127.0.0.1:8001`.
- The FastAPI service has **no authentication of its own** and must stay bound to
  `127.0.0.1`. All authorization happens in Laravel:
  - `/ocr` — Staff and Super Admin.
  - `/models` (read) — needed wherever a model must be picked.
  - `/add_model`, `/rename_model`, `/delete_model` — **Super Admin only**. These are OCR
    model management and must never be reachable by Staff or Admin.
- Model add / rename / delete and the active-model selection are audit-logged in Laravel.
- Handle the service being down gracefully: a clear "OCR service unavailable" state, no
  stack traces to the user, and no half-saved records.
- CORS no longer needs to be permissive. Since Laravel is the only client, drop the
  wide-open CORS from the prototype.

## OCR management page (Super Admin)

All TrOCR management lives on **one page**, the way the legacy prototype did it — a single
Super Admin screen, not features scattered across separate menus. Route it under something
like `/super-admin/ocr` and build it with SNEAT cards and modals.

Everything on that page:

- **Model list / picker** — every folder discovered under `Models/` plus the always-present
  `base` model, each with its label and whether it is currently loaded in the service. The
  default model is preselected.
- **Set active model** — choose which model Staff use for scanning. Persisted in the DB, not
  just in the browser, and audit-logged on change.
- **+ Add** — modal with a model name field and a **folder picker**
  (`<input type="file" webkitdirectory directory multiple>`), a file list showing what was
  selected, a validation badge confirming `config.json` and the weights are present, and an
  upload progress bar with status text. Uploads go Laravel → FastAPI, streamed to disk.
- **Rename** — modal prefilled with the current folder name.
- **Delete** — confirm modal with danger styling. Destructive: it removes the folder from disk.
- **↻ Rescan** — re-read `Models/` for folders added manually.
- **Engine status** — a dot plus label driven by `/health`: reachable or not, and the device
  (`cuda` / `cpu`). Show it clearly; Staff scanning fails without the service.
- **Evaluation metrics** — surface the saved charts from `Evaluation Metrics/base/` and
  `Evaluation Metrics/finetuned/` so a model's CER / WER / exact-match can be reviewed before
  it is promoted to active. This is the "is it good enough for Staff to use?" gate.

Rules for the page:

- The `base` model can never be renamed or deleted. Disable those controls when it is selected.
- Rename and Delete are disabled for whichever model is currently active. Switch active first.
- Every add / rename / delete / activate writes an audit log entry.
- Model weights are ~1.3 GB per folder. PHP will reject them at default limits, so raise
  `upload_max_filesize` and `post_max_size` (or upload in chunks) and set a generous HTTP
  client timeout. Don't let a 20-minute upload die on a 30-second default.

## Field templates

The prototype defines per-document-type field boxes as fractional coordinates
(`x`, `y`, `w`, `h` in 0-1 of page size) for birth, death, and marriage certificates —
see `web/js/config.js`. In CRMS these move into the database behind the Super Admin
document template builder, keeping the same fractional-coordinate model so the
field-marking UI stays resolution-independent.
