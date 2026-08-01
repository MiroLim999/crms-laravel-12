# Tech stack & conventions

Backend layering, validation, auditing, naming, and testing rules are in
`architecture.md`. View rules are in `views.md`. Both are authoritative; this file covers
the stack, the toolchain, and the repo layout.

## Stack

| Layer | Choice |
|-------|--------|
| Framework | Laravel 12 (PHP 8.2+) |
| UI design | SNEAT admin template — design reference only, recreated by us |
| CSS | Bootstrap 5 + Tailwind for utility gaps |
| Assets | Laravel Vite |
| Database | MySQL |
| OCR service | FastAPI + Python, separate process (see `trocr-service.md`) |
| ML | PyTorch + Hugging Face `transformers` (TrOCR) |

## SNEAT is a design reference, not a scaffold

The template sits in `sneat/` as a full Laravel app. **Never route to it, extend it, or run
`composer install` inside it.** Its design has already been harvested into:

- `resources/scss/` — 64 SCSS files, treated as vendor code. Put overrides in `_crms.scss`.
- `resources/fonts/iconify/` — icon CSS, subsetted to the icons in use
- `resources/js/sneat/` — `helpers.js`, `menu.js`, `main.js`, `theme-config.js`

Our layouts and components are our own, under `resources/views/`. Copying a chunk of SNEAT
markup into one of our components is fine and expected; wiring our features into SNEAT's
controllers, layouts, or `verticalMenu.json` is not. Read `sneat/resources/views/` for
reference markup when building a new component.

Bootstrap first, Tailwind second. Don't restyle with Tailwind what Bootstrap already
handles, and don't add a third UI kit.

### Design tokens

Read from `sneat/resources/assets/vendor/scss/_bootstrap-extended/_variables.scss`. Don't
guess a value that isn't listed — read it from the SCSS.

| Token | Value |
|-------|-------|
| `$primary` | `#696cff` |
| `$body-bg` | `#f5f5f9` |
| `$body-color` | `$gray-700` |
| `$headings-color` | `$gray-900` |
| `$border-radius` | `.375rem` |
| Typeface | **Public Sans** (Google Fonts, 300–700, incl. italics) |
| Icons | **Iconify**, using Boxicons-style names |

Icon markup: `<i class="icon-base bx bx-menu icon-md"></i>`. Sizes are `icon-sm`,
`icon-md`, `icon-lg`. Don't install the `boxicons` package — `bx-*` comes from
`iconify.css`.

## Laravel conventions

- Migrations for all schema changes. Seeders for roles, the Super Admin, and starter
  document templates.
- Authorization is enforced by **Gates**, all in `AuthServiceProvider`. There are no Policy
  classes. Never rely on hiding a nav item as the only guard.
- Eloquent with eager loading. No raw string-interpolated SQL.

Not yet built, worth doing when batch work appears: queueing long OCR or reporting jobs.
The report export currently streams synchronously and there is no `app/Jobs/`.

## Environment

Windows, **PowerShell** (not cmd). Separator is `;`, never `&` or `&&`. It is
PowerShell 5.1, so `Invoke-WebRequest` needs `-UseBasicParsing` and has no
`-SkipHttpErrorCheck`. Avoid inline `php -r "..."` — PowerShell mangles `$` and quotes;
write a temp `.php` file and delete it after.

`gd` and `intl` are enabled in `C:\xampp\php\php.ini` (backup: `php.ini.crms-backup`).
Field cropping still happens client-side in canvas, deliberately — it keeps
full-resolution pixels and avoids a server round trip.

**Open decision — model uploads.** `php.ini` caps uploads at **40M** against ~1.3 GB model
folders. The OCR page posts a plain multipart form, so uploading a real model through the
browser fails at the PHP limit before Laravel sees it. The working path today is dropping
the folder into `ml/models/` and clicking Rescan. Resolve by raising the limits or
implementing chunked upload; chunking is preferred because it survives Laravel and the OCR
service being on separate hosts.

Two processes, both from the repo root:

```
php artisan serve                                       # Laravel app
uvicorn ml.api.main:app --host 127.0.0.1 --port 8001    # FastAPI OCR service
```

Use `python -m uvicorn ...` if `uvicorn` is not on PATH. ML dependencies:
`pip install -r ml\requirements.txt -r ml\api\requirements.txt`. Tests need a `crms_test`
MySQL database.

## Repo layout

Laravel occupies the repo root. **All Python lives under `ml/`.** Nothing ML-related
belongs at the root, and nothing Laravel belongs inside `ml/` — that boundary is the point.

```
ml/
├── api/main.py         FastAPI OCR service (+ requirements.txt)
├── train_trocr.py      fine-tuning
├── test_trocr.py       evaluate base model
├── test_finetuned.py   evaluate fine-tuned model
├── predict.py          batch predict a folder
├── metrics.py          CER / WER / exact-match + chart export
├── download_trocr.py   fetch the base model
├── requirements.txt    ML stack
├── models/             fine-tuned models (gitignored, ~1.3 GB each)
├── dataset/            training data (gitignored)
└── evaluation-metrics/ charts read by the OCR page (base/ and finetuned/)

sneat/                  design reference, never served or routed
tools/                  build-time scripts (subset-icons.mjs)
```

Every Python script anchors paths to `ML_ROOT` (its own directory), not the working
directory. Keep it that way — a bare `os.path.join("dataset", ...)` silently resolves
against the caller's CWD.

`ml/metrics.py`'s `DEFAULT_METRICS_DIR` and `EvaluationCharts::DIRECTORY` must agree.
Nothing enforces it; if they drift, charts silently stop appearing.

The legacy `web/` PHP prototype and Flask `api/app.py` are **deleted**, superseded by the
Laravel app and `ml/api/main.py`. Their logic survives in `resources/js/field-marker.js`
(canvas crop, drag boxes, PDF.js), `DocumentType::defaultFields()` (the field boxes), and
`ml/api/main.py` (endpoint contract, confidence maths, path guards). Recover from git
history to compare behaviour; do not reintroduce them.
