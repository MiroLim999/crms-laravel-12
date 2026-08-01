# TrOCR service

The OCR pipeline is the core of CRMS. It runs as a **separate FastAPI process**, not inside
Laravel, because it owns the GPU and the Python runtime.

## The full lifecycle is driven from the UI

A Super Admin does the entire ML workflow from the OCR workspace — **no CLI required**:

1. **Upload a dataset** — drag and drop images plus a manifest CSV.
2. **Fine-tune** — pick a dataset and hyperparameters, start a training run, watch progress.
3. **Evaluate** — run a trained model against a dataset split, get CER / WER / exact-match
   and a chart.
4. **Predict** — spot-check a model on a handful of loose images.
5. **Promote** — review the figures and set a model active. Only then does Staff scanning
   use it.
6. **Manage** — rename, delete, and inspect both models and datasets.

The Python scripts under `ml/` remain runnable as CLI tools for debugging, but the UI is
the primary interface and must not require dropping to a terminal.

## The scripts must be parameterised, not just CLI-shaped

`train_trocr.py`, `test_finetuned.py`, `test_trocr.py`, and `predict.py` currently hold
their configuration in module-level constants and run from `main()`. To be driven by the
API they need a callable entry point that takes parameters and reports progress:

- Accept config as arguments, not module constants. The constants may stay as defaults.
- Accept a progress callback so the API can report epoch, step, and loss as training runs.
- Return metrics as data, not just print them. `predict.py` must return rows rather than
  only writing `predictions.csv`.
- Never call `sys.exit()` or `input()` — they must be importable and cancellable.

Keep the CLI wrappers so `python ml\train_trocr.py` still works.

## Long-running work is a job, never a request

Training takes hours. Evaluation over a large split takes minutes. **Neither may be a
synchronous HTTP request.** FastAPI owns the job because it owns the GPU.

Job contract:

- `POST /jobs` starts one. Body carries `type` (`training` | `evaluation`), the dataset,
  the model, and the config. Returns a `job_id`.
- `GET /jobs/{id}` returns `status` (`queued` | `running` | `completed` | `failed` |
  `cancelled`), progress (current epoch / total, current step / total), the latest metrics,
  and a log tail.
- `POST /jobs/{id}/cancel` requests cancellation. The job must check a cancel flag between
  steps and stop cleanly, leaving no half-written checkpoint.
- `GET /jobs` lists recent jobs.

Rules:

- **One GPU job at a time.** A second start returns `409` with a clear message. Do not
  queue silently — tell the Super Admin what is already running.
- Job state must survive a page refresh. It lives in the service, and Laravel mirrors it.
- A failed job records the error and the log tail. Never fail silently.
- A completed training job registers its output model so it appears in the model list.

Laravel polls `GET /jobs/{id}` from the workspace page. Polling is fine; do not add
websockets for this.

## Datasets are a managed entity

Expected layout under `ml/datasets/<name>/`:

```
manifest.csv        columns: filename,label,split,source
train/  val/  test/
```

Rows with an empty label or the label `UNREADABLE` are skipped by training.

The service must expose:

- `POST /datasets` — create from an upload. Accept a zip archive **or** a directory drop.
- `GET /datasets` — list with per-split image counts and total size.
- `GET /datasets/{name}/validate` — sanity report **before** anyone trains on it: manifest
  rows whose image is missing, images with no manifest row, empty and `UNREADABLE` label
  counts, and the split distribution.
- `DELETE /datasets/{name}` — destructive, same path-safety guards as model deletion.

**Always validate before offering a dataset for training.** A manifest that points at
missing files wastes hours of GPU time and fails deep into an epoch.

Note the move from a single `ml/dataset/` to named datasets under `ml/datasets/`. The
scripts' existing default paths must follow.

## Uploads: drag and drop, and chunked

Every upload surface is **drag and drop** with a click-to-browse fallback: datasets, model
folders, and predict images.

Both datasets and model folders are far larger than PHP's 40M limit (models ~1.3 GB;
datasets can be thousands of images). **Plain multipart form posts will fail.** Implement
chunked upload: slice in the browser, post the pieces, reassemble server-side, then hand
the assembled file to the service. Show per-file progress and a total.

Until chunking exists, the documented fallback is placing the folder under `ml/models/` or
`ml/datasets/` by hand and clicking Rescan.

## GPU contention is a real constraint

Training, evaluation, and Staff scanning all want the same GPU. A training run will slow
or OOM concurrent `/ocr` requests.

- Never start a GPU job automatically. It is always an explicit Super Admin action.
- Warn on the confirm step that scanning will be degraded while the job runs.
- Show a banner on the scan page while a GPU job is active, so Staff understand the slowness.
- `/health` and `/models` must stay responsive during a job — they touch no GPU.
- Check free disk before starting training. A checkpoint is ~1.3 GB.

## Endpoint contract

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | status, device, loaded models, active job summary |
| GET | `/models` | selectable models: `key`, `label`, `available`, `loaded` |
| POST | `/ocr` | `{ fields: [{ name, image }], model }` → `{ results: [{ name, text, confidence }], model, modelKey }` |
| POST | `/add_model` | upload → saved to `ml/models/<name>/` |
| POST | `/delete_model` | `{ model }` → removes the folder |
| POST | `/rename_model` | `{ model }`, `{ newName }` → renames the folder |
| POST | `/predict` | loose images + model → rows of `{ filename, text, confidence }`. Synchronous, capped at 50 images. |
| GET | `/datasets` | list with split counts and size |
| POST | `/datasets` | create from upload |
| GET | `/datasets/{name}/validate` | pre-training sanity report |
| DELETE | `/datasets/{name}` | remove a dataset |
| POST | `/jobs` | start a training or evaluation run |
| GET | `/jobs` | recent jobs |
| GET | `/jobs/{id}` | status, progress, metrics, log tail |
| POST | `/jobs/{id}/cancel` | request cancellation |

`image` in `/ocr` is a `data:image/png;base64,...` data URL of one cropped field.

Errors return `{ "ok": false, "error": "..." }` rather than FastAPI's default
`{ "detail": ... }`, because Laravel's `OcrClient` reads `error`.

## Behaviour that must not be broken

`ml/api/main.py` is a behaviour-preserving port of the original Flask prototype. Preserve:

- Lazy model loading with an in-process cache keyed by model name.
- The confidence calculation: `compute_transition_scores`, geometric mean of per-token
  probabilities up to the first EOS, as a 0-100 percentage rounded to 1 dp.
- Model discovery re-scanned per call, so a manually added folder appears immediately.
- Name sanitising `[^A-Za-z0-9._-]+` to `-`, the allowed-file whitelist on upload, rollback
  via `rmtree` on a failed save, and path-safety checks refusing to touch anything whose
  parent is not `ml/models/` (now also `ml/datasets/`).
- `MAX_NEW_TOKENS = 32` and the HF/transformers warning suppression env vars, set before
  `torch`/`transformers` are imported.
- Inference endpoints are plain `def`, not `async def`, so FastAPI runs the blocking GPU
  work in a threadpool and `/health` stays responsive.
- Paths anchor to `ML_ROOT` (the file's own directory), never the working directory.
- **No CORS middleware.** Laravel is the only client and calls it server-side.
- The `base` model is always offered and can never be renamed or deleted.

## How Laravel talks to it

- Server-side only, via `App\Services\Ocr\OcrClient`. The browser never calls the service
  directly. Base URL is `config('services.ocr.url')` from `OCR_API_URL`, defaulting to
  `http://127.0.0.1:8001`.
- The service has **no authentication of its own** and must stay bound to `127.0.0.1`.
  Every authorization decision happens in Laravel:
  - `/ocr` — Staff and Super Admin (`documents.process`).
  - Everything else — **Super Admin only** (`ocr.manage`). Training, dataset deletion, and
    model deletion must never be reachable by Staff or Admin.
- Transport failures surface as `OcrServiceException`, rendered as a clear "OCR service
  unavailable" state. No stack traces, no half-saved records.
- Long job timeouts: starting a job returns immediately, so the HTTP client timeout applies
  only to the start call, not the run.

Laravel-side tables:

- `ml_datasets` — name, split counts, size, validation summary, uploaded_by, timestamps.
- `ml_jobs` — type, status, the config used, the dataset and model involved, progress,
  final metrics, error, started/finished, triggered_by.

`ml_jobs` is the audit-friendly history of every training and evaluation run. Mirror the
service's state into it; the service stays the source of truth while a job is live.

Audit-log every one of: dataset uploaded, dataset deleted, job started, job cancelled,
model added, renamed, deleted, evaluated, and activated.

## The OCR workspace (Super Admin)

One route, `/ocr` (`ocr.index`), organised into **tabbed sections** — not one long scroll,
and not scattered across separate menu items:

**Models** — every folder under `ml/models/` plus `base`, reconciled with the `ocr_models`
registry. Shows which is active, which is loaded in memory, and recorded CER / WER /
exact-match. Actions: set active, rename, delete, record evaluation. Models the service can
no longer see are shown flagged rather than hidden, so a mismatch is visible.

**Datasets** — list with per-split counts and size. Drag-and-drop upload. A validation
report per dataset, run before it can be used for training. Delete with a confirm.

**Training** — pick a dataset and a base model, set hyperparameters
(`EPOCHS`, `BATCH_SIZE`, `LEARNING_RATE`, `MAX_LABEL_LENGTH`, `TRAIN_SUBSET`, `VAL_SUBSET`),
name the output model, start. Live progress: epoch, step, loss, elapsed, log tail, cancel.

**Evaluation** — pick a model and a dataset split, run it, get CER / WER / exact-match plus
the generated chart. Charts from `ml/evaluation-metrics/{base,finetuned}/` are streamed
through a gated route since they sit outside the web root.

**Predict** — drag in a few loose images, pick a model, see text and confidence per image.
Synchronous and capped; for anything bigger use an evaluation run.

**Engine status** — always visible regardless of tab: reachable or not, device
(`cuda` / `cpu`), and any running job with its progress.

Rules for the page:

- Defaults must be sensible enough that a Super Admin can train without understanding every
  hyperparameter. Pre-fill from the script defaults and explain each field in one line.
- Rename and Delete are blocked while a model is active, and while it is the output target
  of a running job. Activate or finish first.
- Never present confidence or correction rate as accuracy. See `product.md`.
- Destructive actions (delete model, delete dataset, cancel a running job) need an explicit
  confirm naming what is being lost.

## Field templates

Field boxes are stored as fractional coordinates (`x`, `y`, `width`, `height` in 0-1 of page
size) so a layout holds at any scan resolution or zoom level.

The original per-document-type defaults came from the deleted `web/js/config.js` and now
live in `App\Enums\DocumentType::defaultFields()`, which `DocumentTemplateSeeder` uses to
create one active starter template per certificate type. Super Admins adjust them in the
template builder.

## Two path constants that must agree

`ml/metrics.py`'s `DEFAULT_METRICS_DIR` and `App\Services\Ocr\EvaluationCharts::DIRECTORY`
both point at `ml/evaluation-metrics`. Nothing enforces this. If they drift, charts silently
stop appearing.
