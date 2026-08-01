# TrOCR service

The OCR pipeline is the core of CRMS. It runs as a **separate FastAPI process**, not inside
Laravel.

## The pipeline

1. **Fine-tune** — `ml/train_trocr.py` trains `microsoft/trocr-base-handwritten` on a
   custom handwriting dataset. The best-by-validation-loss checkpoint is saved to
   `ml/models/<name>/`.
2. **Evaluate** — `ml/test_trocr.py` (base) and `ml/test_finetuned.py` (fine-tuned) compute
   CER / WER / exact-match via `ml/metrics.py` and export a timestamped PNG chart to
   `ml/evaluation-metrics/{base,finetuned}/`.
3. **Promote** — a Super Admin reviews those charts on the OCR page, records the figures,
   and sets the model active. Only then does Staff scanning use it.
4. **Scan** — Staff mark fields on a certificate; the crops go to the service; the readings
   come back with confidence scores.

Model folders are auto-discovered: any folder under `ml/models/` containing `config.json`
plus `model.safetensors` or `pytorch_model.bin` appears without a restart. The base
`microsoft/trocr-base-handwritten` model is always offered as the `base` key and can never
be renamed or deleted.

The training and evaluation scripts are standalone CLI tools. They are **not** part of the
request path and must not be invoked from Laravel.

## Running it

```
uvicorn ml.api.main:app --host 127.0.0.1 --port 8001
```

From the repo root. `python -m uvicorn ...` if `uvicorn` is not on PATH.

## Behaviour that must not be broken

`ml/api/main.py` is a behaviour-preserving port of the original Flask prototype. Preserve:

- Lazy model loading with an in-process cache keyed by model name.
- The confidence calculation: `compute_transition_scores`, geometric mean of per-token
  probabilities up to the first EOS, returned as a 0-100 percentage rounded to 1 dp.
- Model discovery re-scanned on every call, so a manually added folder appears immediately.
- Name sanitising `[^A-Za-z0-9._-]+` to `-`, the allowed-file whitelist on upload, rollback
  via `rmtree` on a failed save, and the path-safety checks that refuse to touch anything
  whose parent is not `ml/models/`.
- `MAX_NEW_TOKENS = 32` and the HF/transformers warning suppression env vars, which must be
  set before `torch`/`transformers` are imported.
- Endpoints are plain `def`, not `async def`, so FastAPI runs the blocking GPU work in a
  threadpool and `/health` stays responsive during inference.
- Paths anchor to `ML_ROOT` (the file's own directory), never the working directory.
- **No CORS middleware.** Laravel is the only client and calls it server-side.

## Endpoint contract

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | status, device, whether any model is loaded, default model key |
| GET | `/models` | selectable models: `key`, `label`, `available`, `loaded` |
| POST | `/ocr` | `{ fields: [{ name, image }], model }` → `{ results: [{ name, text, confidence }], model, modelKey }` |
| POST | `/add_model` | multipart `name` + `files` → saved to `ml/models/<name>/` |
| POST | `/delete_model` | `{ model }` → removes the folder |
| POST | `/rename_model` | `{ model }`, `{ newName }` → renames the folder |

`image` is a `data:image/png;base64,...` data URL of one cropped field.

Errors return `{ "ok": false, "error": "..." }` rather than FastAPI's default
`{ "detail": ... }`, because Laravel's `OcrClient` reads `error`.

## How Laravel talks to it

- Server-side only, via `App\Services\Ocr\OcrClient`. The browser never calls the service
  directly. Base URL is `config('services.ocr.url')`, from `OCR_API_URL`, defaulting to
  `http://127.0.0.1:8001`.
- The service has **no authentication of its own** and must stay bound to `127.0.0.1`.
  Every authorization decision happens in Laravel:
  - `/ocr` — Staff and Super Admin (`documents.process`).
  - `/add_model`, `/rename_model`, `/delete_model` — **Super Admin only** (`ocr.manage`).
- Transport failures surface as `OcrServiceException`, which the UI renders as a clear
  "OCR service unavailable" state. No stack traces, and no half-saved records.
- Model add / rename / delete and the active-model change are all audit-logged in Laravel.

## OCR management page (Super Admin)

All TrOCR management lives on **one page** at `/ocr` (`ocr.index`), the way the legacy
prototype did it — not scattered across separate menus. Built with SNEAT cards and modals.

Everything on that page:

- **Model list** — every folder discovered under `ml/models/` plus the always-present
  `base` model, reconciled with the `ocr_models` registry table. Rows the service can no
  longer see are shown flagged rather than hidden, so a mismatch is visible.
- **Set active model** — which model Staff scanning uses. Persisted in `ocr_models`, not in
  the browser, and audit-logged on change. If no model is active, the scan page says so and
  Staff cannot produce readings.
- **+ Add** — modal with a name field and a folder picker
  (`<input type="file" webkitdirectory directory multiple>`), a file list, and a validation
  badge confirming `config.json` and the weights are present. See the upload caveat below.
- **Rename** — modal prefilled with the current folder name.
- **Delete** — confirm modal, danger styling. Destructive: removes the folder from disk.
- **↻ Rescan** — re-read `ml/models/` for folders added by hand.
- **Engine status** — dot plus label driven by `/health`: reachable or not, and the device
  (`cuda` / `cpu`).
- **Evaluation metrics** — the saved charts from `ml/evaluation-metrics/{base,finetuned}/`,
  streamed through a gated route since they sit outside the web root. Plus a modal to record
  CER / WER / exact-match against a model, so the promotion decision is traceable.

Rules for the page:

- The `base` model can never be renamed or deleted.
- Rename and Delete are blocked while a model is active. Activate another model first.
- Every add / rename / delete / activate writes an audit entry.
- **Upload caveat:** the Add modal posts a plain multipart form, and `php.ini` caps uploads
  at 40M against ~1.3 GB model folders. Uploading a real model through the browser will
  fail at the PHP limit. The working path today is dropping the folder into `ml/models/`
  and clicking Rescan. See `tech.md` for the open decision on raising limits vs chunking.

## Field templates

Field boxes are stored as fractional coordinates (`x`, `y`, `width`, `height` in 0-1 of
page size) so a layout holds at any scan resolution or zoom level.

The original per-document-type defaults came from the deleted `web/js/config.js` and now
live in `App\Enums\DocumentType::defaultFields()`, which `DocumentTemplateSeeder` uses to
create one active starter template per certificate type. Super Admins adjust them in the
template builder from there.

## Two path constants that must agree

`ml/metrics.py`'s `DEFAULT_METRICS_DIR` and
`App\Services\Ocr\EvaluationCharts::DIRECTORY` both point at `ml/evaluation-metrics`.
Nothing enforces this. If they drift, charts silently stop appearing on the OCR page.
