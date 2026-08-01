# Civil Registry Management System (CRMS)

A Laravel 12 system for digitising and managing civil registry documents (birth, death, and
marriage certificates). Staff scan handwritten certificates, a fine-tuned
[TrOCR](https://huggingface.co/microsoft/trocr-base-handwritten) model extracts the field
values, Staff verify and submit them, and the result becomes a searchable, locked archive
with a legally meaningful audit trail.

## Architecture

Two processes, one repository:

| Part | Stack | Role |
|---|---|---|
| Web application | Laravel 12, Blade, Bootstrap 5 (SNEAT design), MySQL | Everything users touch |
| OCR service | FastAPI, PyTorch, Hugging Face `transformers` | Reads handwriting from cropped field images |

Laravel calls the OCR service **server-side only**. The service has no authentication of
its own and stays bound to `127.0.0.1`; all authorization happens in Laravel.

## Roles

Three seeded roles. **There is no public sign-up** — every account is created by an admin.

| Capability | Staff | Admin | Super Admin |
|---|---|---|---|
| Upload & process documents | Yes | No | Yes |
| Verify & submit records | Yes | No | Yes |
| Search / view archive | Yes | Yes | Yes |
| Request changes to locked records | Yes | No | Yes |
| Approve / reject change requests | No | Yes | Yes |
| Analytics dashboard | No | Yes | Yes |
| Manage user accounts & roles | No | Yes | Yes |
| View audit log | No | Yes | Yes |
| Generate reports | No | Yes | Yes |
| Document template builder | No | No | Yes |
| OCR model management | No | No | Yes |

**Admin cannot edit record values.** Data entry belongs to Staff; corrections go through the
change-request flow. This is intentional — it is what keeps the audit trail meaningful.

## Project structure

```
app/                    Laravel application code
├── Enums/              RoleSlug, DocumentType, RecordStatus, ChangeRequestStatus
├── Models/             User, Role, CivilRecord, RecordField, ChangeRequest, OcrModel, ...
├── Services/Ocr/       OcrClient, OcrModelManager, EvaluationCharts
├── Services/           AuditLogger, UserProvisioner, ChangeRequestService
└── Providers/          AuthServiceProvider - the capability matrix, in code

ml/                     ALL Python lives here
├── api/main.py         FastAPI OCR service
├── train_trocr.py      fine-tuning
├── test_trocr.py       evaluate the base model
├── test_finetuned.py   evaluate a fine-tuned model
├── predict.py          batch predict a folder of images
├── metrics.py          CER / WER / exact-match + chart export
├── download_trocr.py   fetch the base model
├── models/             fine-tuned model folders (gitignored, ~1.3 GB each)
├── dataset/            training images + manifest CSV (gitignored)
└── evaluation-metrics/ charts, surfaced on the OCR management page

sneat/                  SNEAT template - visual design reference only, never routed
```

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
uvicorn ml.api.main:app --host 127.0.0.1 --port 8001    # OCR service
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

## The OCR workflow

1. **Fine-tune** — `python ml\train_trocr.py`. Hyperparameters are in the `CONFIG` block.
   The best checkpoint by validation loss is saved to `ml/models/`.
2. **Evaluate** — `python ml\test_trocr.py` and `python ml\test_finetuned.py`. Each writes a
   timestamped chart to `ml/evaluation-metrics/{base,finetuned}/`.
3. **Promote** — sign in as Super Admin, open **OCR Models**, review the charts, record the
   metrics, and set the model active. Only then does Staff scanning use it.
4. **Scan** — Staff upload a certificate, adjust the field boxes, run the model, correct
   anything flagged, and submit. Submission locks the record.

Any folder dropped into `ml/models/` is auto-discovered — no restart needed. It needs
`config.json` plus `model.safetensors` or `pytorch_model.bin`.

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
This is **the model's certainty in its own output, not accuracy**. Fields below the
threshold (`CRMS_CONFIDENCE_THRESHOLD`, default 80%) are flagged for review. Treat it as a
prompt to look closer, never as a quality guarantee.

The analytics page also shows a correction rate — how often a person changed what the model
read. Also a signal, not a validated metric: a corrected field may have been right, and an
uncorrected one may have been wrong and missed.

## Tests

```bash
php artisan test
```

Requires a `crms_test` MySQL database (`CREATE DATABASE crms_test;`).

`tests/Feature/CapabilityMatrixTest.php` is the load-bearing one: it asserts the permission
table above, route by route and ability by ability. If a change makes it fail, the change is
wrong, not the test.

## Model & data

`ml/models/` and `ml/dataset/` are gitignored — they exceed GitHub's file-size limits. To
share them, push to the [Hugging Face Hub](https://huggingface.co/docs/hub/models-uploading),
use [Git LFS](https://git-lfs.com/), or host the dataset externally.

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
