# Civil Registry Management System (CRMS)

A Laravel 12 system for digitising and managing civil registry documents (birth, death, and
marriage certificates). Staff scan handwritten certificates, a fine-tuned
[TrOCR](https://huggingface.co/microsoft/trocr-base-handwritten) model extracts the field
values, Staff verify and submit them, and the result becomes a searchable, locked archive
with a legally meaningful audit trail.

## Architecture

Two processes, one repository:

| Part            | Stack                                                | Role                                        |
| --------------- | ---------------------------------------------------- | ------------------------------------------- |
| Web application | Laravel 12, Blade, Bootstrap 5 (SNEAT design), MySQL | Everything users touch                      |
| OCR service     | FastAPI, PyTorch, Hugging Face `transformers`        | Reads handwriting from cropped field images |

Laravel normally calls the OCR service server-side. Model installation is the deliberate
exception: Laravel issues a short-lived signed ticket, then the browser sends the large
multipart body directly to FastAPI. After FastAPI saves the files, the browser posts only
the installed model name to Laravel; Laravel verifies it against FastAPI's own inventory,
then writes the registry and audit records transactionally. The model bytes never pass
through PHP. If that final lightweight request fails, the page can retry registration
without uploading the model again, and _Rescan models_ remains the recovery path.

The service stays bound to `127.0.0.1` in local development. In production, expose only
the signed upload endpoint through an HTTPS reverse proxy; keep Laravel's other calls to
FastAPI on a private or loopback address.

Laravel does not start or stop that process. It is a separate program with its own
lifetime — run it from a terminal in development, or under a supervisor in a
deployment. The OCR workspace reports whether it answers and shows the command.

## Roles

Three seeded roles. **There is no public sign-up** — every account is created by an admin.

| Capability                        | Staff | Admin | Super Admin |
| --------------------------------- | ----- | ----- | ----------- |
| Upload & process documents        | Yes   | No    | Yes         |
| Verify & submit records           | Yes   | No    | Yes         |
| Search / view archive             | Yes   | Yes   | Yes         |
| Request changes to locked records | Yes   | No    | Yes         |
| Approve / reject change requests  | No    | Yes   | Yes         |
| Analytics dashboard               | No    | Yes   | Yes         |
| Manage user accounts & roles      | No    | Yes   | Yes         |
| View audit log                    | No    | Yes   | Yes         |
| Generate reports                  | No    | Yes   | Yes         |
| Document template builder         | No    | No    | Yes         |
| OCR model management              | No    | No    | Yes         |

**Admin cannot edit record values.** Data entry belongs to Staff; corrections go through the
change-request flow. This is intentional — it is what keeps the audit trail meaningful.

## Project structure

```
app/                    Laravel application code
├── Enums/              RoleSlug, DocumentType, RecordStatus, ChangeRequestStatus
├── Models/             User, Role, CivilRecord, RecordField, ChangeRequest,
│                       OcrModel, OcrSetting, ...
├── Services/Ocr/       OcrClient, OcrModelManager, EngineStatus, OcrUploadAuthorizer
├── Services/           AuditLogger, UserProvisioner, ChangeRequestService
└── Providers/          AuthServiceProvider - the capability matrix, in code

ml/                     ALL Python lives here
├── api/main.py         FastAPI OCR service - serve, install, rename, delete models
├── train_trocr.py      fine-tuning
├── test_trocr.py       manual CLI evaluation of the base model (not an automated test)
├── test_finetuned.py   manual CLI evaluation of any model (not an automated test)
├── predict.py          batch predict a folder of images
├── metrics.py          CER / WER / exact-match + chart export
├── dataset_registry.py dataset layout, validation, and name sanitising
├── download_trocr.py   fetch the base model
├── models/             fine-tuned model folders (gitignored, ~1.3 GB each)
├── dataset/            training images + manifest CSV (gitignored)
└── evaluation-metrics/ charts written by the evaluation scripts
```

Training, evaluation, dataset preparation, and batch prediction are **command-line
work only**. They are deliberately not driven from the web UI: a request handler is
the wrong place to pin a GPU for hours, and the scripts' output is far more useful in
a terminal than paraphrased into a progress bar.

## Setup

Requires PHP 8.2+, Composer, Node 20+, MySQL 8+, and Python 3.10+.

```bash
composer install
npm install
pip install -r ml\requirements.txt -r ml\api\requirements.txt

copy .env.example .env
php artisan key:generate
# set DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env, then:
php artisan migrate --seed
npm run build
```

`php artisan migrate --seed` creates the three roles, the bootstrap Super Admin, and a
starter template per certificate type.

PHP needs `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `curl`, and `zip`. Enable `gd` if
you want server-side image work; field cropping happens in the browser, so it is optional.

For GPU inference, install the CUDA build of PyTorch from the
[official install guide](https://pytorch.org/get-started/locally/) rather than the default
CPU wheel.

## Running

Two processes, both from the repo root:

```bash
php artisan serve                                       # http://127.0.0.1:8000
python -m uvicorn ml.api.main:app --host 127.0.0.1 --port 8001    # OCR service
```

Sign in with the seeded account:

```
superadmin@admin.com / superadmin@admin.com
```

Change that password before any real deployment. Override the defaults with
`CRMS_SUPER_ADMIN_EMAIL` and `CRMS_SUPER_ADMIN_PASSWORD`.

While user management is still being learned, `DemoUsersSeeder` creates one Staff and one
Admin account for clicking around. It is deliberately not part of `db:seed`:

```bash
php artisan db:seed --class=DemoUsersSeeder    # staff@crms.test / admin@crms.test, password123
```

Delete that seeder before deploying.

## Production deployment

The direct-upload design is retained in production. Only the browser-facing address
changes:

```text
Local:       browser -> http://127.0.0.1:8001/add_model -> FastAPI
Production:  browser -> https://crms.example.com/ocr-api/add_model
                     -> reverse proxy -> 127.0.0.1:8001/add_model
```

The reverse proxy is part of the web-server path, but Laravel/PHP never receives or
writes the multi-gigabyte request body.

For a same-server deployment, use separate private and browser-facing URLs:

```dotenv
OCR_API_URL=http://127.0.0.1:8001
OCR_API_TIMEOUT=120

OCR_BROWSER_API_URL=https://crms.example.com/ocr-api
OCR_BROWSER_ORIGIN_REGEX=^https://crms\.example\.com$

# Use the same dedicated value in the Laravel and FastAPI environments.
OCR_UPLOAD_SECRET=replace-with-a-long-random-production-secret

# The ticket is checked after multipart parsing, so allow for internet upload speed.
# The current application clamps this value to a maximum of 3600 seconds.
OCR_UPLOAD_TICKET_TTL=3600
```

An Nginx location for the public upload path can look like this:

```nginx
location = /ocr-api/add_model {
    client_max_body_size 3g;
    client_body_timeout 3600s;

    proxy_http_version 1.1;
    proxy_request_buffering off;
    proxy_send_timeout 3600s;
    proxy_read_timeout 3600s;

    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

    proxy_pass http://127.0.0.1:8001/add_model;
}
```

Important production requirements:

- Serve both Laravel and the browser-facing OCR upload URL over HTTPS. Browsers block an
  HTTPS page from sending `fetch`/XHR uploads to an HTTP endpoint.
- Publicly expose only `/add_model`. The rename, delete, model-list, health, and OCR
  endpoints are designed for Laravel-to-FastAPI communication and rely on private-network
  or loopback isolation.
- Run FastAPI under a process supervisor or container restart policy; do not use development
  auto-reload in production.
- Keep `ml/models/` on persistent, writable storage. Container-local ephemeral storage will
  lose installed models during replacement or redeployment.
- Allow enough request size, proxy time, temporary disk, and model disk for a model upload.
  Multipart spooling and archive extraction can temporarily require more than the final
  model size.
- After changing OCR environment values, rebuild Laravel's configuration cache and restart
  FastAPI so both processes read the same secret and URLs.

```bash
php artisan config:cache
```

A VPS, dedicated server, or persistent container host can support this layout. Typical
PHP-only shared hosting cannot, because the application also requires a continuously running
Python service and control over the large-upload reverse proxy. If FastAPI runs on a separate
host, set `OCR_BROWSER_API_URL` to its public HTTPS URL, set the CORS regex to the Laravel
origin, and keep `OCR_API_URL` on a private server-to-server address where possible.

## The OCR workflow

1. **Fine-tune** — `python ml\train_trocr.py`. Hyperparameters are in the `CONFIG` block.
   The best checkpoint by validation loss is saved to `ml/models/`.
2. **Evaluate** — `python ml\test_trocr.py` and `python ml\test_finetuned.py`. Each writes a
   timestamped chart to `ml/evaluation-metrics/{base,finetuned}/`.
3. **Install** — sign in as Super Admin, open **OCR Workspace**, and add the model with
   _Add_. Either a `.zip` or the model folder is sent in one browser-to-FastAPI request,
   so PHP's upload limit and the former Laravel-to-FastAPI second copy do not apply.
4. **Select** — pick it in _Model used for scanning_ and press **Save settings**. Only
   then does Staff scanning use it. Installing a model changes nothing on its own.
5. **Scan** — Staff upload a certificate, adjust the field boxes, run the model, correct
   anything flagged, and submit. Submission locks the record.

Any folder dropped into `ml/models/` is auto-discovered — no restart needed, just press
_Rescan_. It needs `config.json` plus `model.safetensors` or `pytorch_model.bin`, and the
tokenizer files.

### The OCR workspace

One page, Super Admin only. It does exactly two things:

- **Manage models** — install (folder or `.zip`), rename, delete. A `.zip` may wrap the
  model in a folder; the service finds it. The base model and the model currently in use
  cannot be renamed or deleted.
- **Save settings** — which model Staff scan with, whether Staff may choose a different
  one per document, and the review threshold. _Save settings_ stays disabled until
  something actually differs from what is stored.

There is deliberately no fine-tuning, dataset upload, evaluation, batch prediction, or
Start/Stop button on that page. The first four are long-running command-line work; the
last is an OS process, and spawning or killing one from a browser tab is a lot of blast
radius for a convenience.

**Staff model choice is off by default.** Left off, every reading in the archive came from
the one model a Super Admin approved, which is the easier position to defend. Switched on,
Staff get a picker on the marking step and the record stores whichever model produced its
readings. A submitted key is honoured only if the service can actually serve it — a stale
tab cannot swap the model behind a record.

### Dataset format

```
ml/dataset/
├── manifest.csv        columns: filename,label,split,source
├── train/
├── val/
└── test/
```

Rows with empty labels or the label `UNREADABLE` are skipped.

## A note on confidence

Every reading carries a confidence score: the geometric mean of per-token probabilities.
This is **the model's certainty in its own output, not accuracy**. Fields below the review
threshold are flagged for a closer look. Treat it as a prompt to look closer, never as a
quality guarantee.

The threshold is set in the OCR workspace. `CRMS_CONFIDENCE_THRESHOLD` (default 80%) is the
fallback used until a Super Admin overrides it, and clearing the field in the UI returns to
that fallback.

The analytics page also shows a correction rate — how often a person changed what the model
read. Also a signal, not a validated metric: a corrected field may have been right, and an
uncorrected one may have been wrong and missed.

## Tests

The automated suite is PHPUnit under `tests/`. It currently contains nine feature-test
classes: 95 test methods expand to 113 executed cases through data providers. The suite
covers authentication, the role capability matrix, user management, audit integrity and
viewing, change requests, analytics, reports, and OCR model management.

Create the isolated database once, then run the suite:

```bash
mysql -e "CREATE DATABASE crms_test"
php artisan test
```

`phpunit.xml` forces the test connection to `crms_test`. `tests/TestCase.php` refuses to run
when the selected database name does not end in `_test`, and `tests/bootstrap.php` preserves
that protection when local environment variables would otherwise override PHPUnit. These
two support files are required even though they do not contain test methods.

`tests/Feature/CapabilityMatrixTest.php` is the load-bearing one: it asserts the permission
table above, route by route and ability by ability. If a change makes it fail, the change is
wrong, not the test.

The files `ml/test_trocr.py` and `ml/test_finetuned.py` are manual GPU/CPU evaluation
commands. Despite their historical names, they are not part of PHPUnit and there is no
automated Python or browser test runner configured yet.

Before handing a change to another collaborator, run checks appropriate to the files changed:

```bash
php artisan test                         # Laravel behavior and authorization
npm run build                            # frontend compilation
python -m py_compile ml/api/main.py      # FastAPI syntax
git diff --check                         # whitespace and conflict-marker mistakes
```

Do not delete tests merely to reduce the repository size. They are small, execute in seconds,
and document security and workflow rules that are otherwise easy for collaborators to break.
Current areas that would benefit from additional coverage are document scanning/submission,
template lifecycle operations, record browsing, FastAPI archive security, and browser-level
direct-upload recovery.

## Model & data

`ml/models/` and `ml/dataset/` are gitignored — they exceed GitHub's file-size limits. To
share them, push to the [Hugging Face Hub](https://huggingface.co/docs/hub/models-uploading),
use [Git LFS](https://git-lfs.com/), or host the dataset externally.

Evaluation charts under `ml/evaluation-metrics/` are generated artifacts. Avoid adding every
timestamped run to a collaboration branch unless the chart is an intentional comparison or
documented baseline.

## Continued fine-tuning

To keep training from an existing checkpoint, point the loaders in `ml/train_trocr.py` at a
saved directory:

```python
processor = TrOCRProcessor.from_pretrained("models/your-model", local_files_only=True)
model = VisionEncoderDecoderModel.from_pretrained("models/your-model", local_files_only=True)
```

A lower learning rate and mixing in earlier data help reduce catastrophic forgetting.

## License

No license specified. Add one if you intend others to reuse this code.
