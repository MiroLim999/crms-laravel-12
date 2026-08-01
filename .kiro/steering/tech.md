# Tech stack & conventions

## Stack

| Layer          | Choice                                                        |
|----------------|---------------------------------------------------------------|
| Framework      | Laravel 12 (PHP 8.2+)                                         |
| UI design      | SNEAT admin template — design reference only, recreated by us  |
| CSS            | Bootstrap 5 + Tailwind for custom utility styling              |
| Assets         | Laravel Vite                                                   |
| Database       | MySQL                                                         |
| OCR service    | FastAPI + Python (separate process, see `trocr-service.md`)   |
| ML             | PyTorch + Hugging Face `transformers` (TrOCR)                 |

## UI: SNEAT is a design source, not a scaffold

The SNEAT template is kept in the repo as a **reference for the visual layer only**. The
application is built from scratch and its structure comes from this steering, not from the
template.

**Take from SNEAT:**
- Design tokens — colors, typography, spacing, border radius, shadows
- Component appearance — sidebar, navbar, cards, tables, forms, buttons, badges, modals,
  alerts, toasts, pagination
- Layout anatomy — collapsible vertical sidebar, sticky top navbar with avatar dropdown,
  light page background with card-based content, soft shadows and rounded corners rather
  than heavy borders
- Its SCSS source, fonts, and icon set

**Do not take from SNEAT:**
- Its premade MVC structure — routes, controllers, models, or middleware
- Its Blade layout hierarchy, view folder conventions, or menu config JSON
- Its asset pipeline (gulp/webpack). Use Laravel Vite.
- Its bundled demo pages, auth pages, or example CRUD

Rules:
- Recreate components as **our own Blade components** under
  `resources/views/components/`, styled to match SNEAT. Build the library incrementally as
  pages need it, not all upfront.
### Verified design tokens

Read from `sneat/resources/assets/vendor/scss/_bootstrap-extended/_variables.scss` in the
downloaded template. Do not guess these; if a value is needed that isn't listed, read it
from the SCSS.

| Token           | Value                          |
|-----------------|--------------------------------|
| `$primary`      | `#696cff` (`$purple`)          |
| `$body-bg`      | `#f5f5f9`                      |
| `$body-color`   | `$gray-700`                    |
| `$headings-color` | `$gray-900`                  |
| `$border-radius`| `.375rem`                      |
| Typeface        | **Public Sans** (Google Fonts, weights 300-700, incl. italics) |
| Icons           | **Iconify** (`vendor/fonts/iconify/`)          |

Icon markup uses Boxicons-style names delivered through Iconify's CSS:
`<i class="icon-base bx bx-menu icon-md"></i>`. Size modifiers are `icon-sm`, `icon-md`,
`icon-lg`. Don't install the `boxicons` package — the `bx-*` classes come from
`iconify.css`.

### The downloaded copy is the Laravel edition

`sneat/` contains a full Laravel app (`app/`, `routes/`, `artisan`, `composer.json`).
Ignore all of it except:

- `sneat/resources/assets/vendor/scss/` — 64 SCSS files, the real source of the design
- `sneat/resources/assets/vendor/fonts/` — iconify + fontawesome
- `sneat/resources/assets/vendor/libs/` — apex-charts, perfect-scrollbar, popper, jquery
- `sneat/resources/assets/js/` — `main.js`, `config.js`, and per-page scripts
- `sneat/resources/views/layouts/` and `_partials/` — markup to read and adapt

Never route to, extend, or `composer install` inside `sneat/`. Its `resources/menu/verticalMenu.json`
is its own menu config — our sidebar is role-driven from our code instead.
- Bootstrap 5 first, Tailwind second. Tailwind covers utility gaps only. Do not restyle a
  component with Tailwind that Bootstrap already handles, and do not add a third UI kit.
- Blade + Blade components throughout. Keep JS minimal and vanilla unless a page genuinely
  needs more (the scan/field-marking screen does).
- Copying a chunk of SNEAT markup into one of our components is fine and expected. Wiring
  our features into SNEAT's own controllers or layouts is not.

## Laravel conventions

- Authorization through Policies and Gates, enforced at the route/controller level with
  middleware. Never rely on hiding a nav item as the only guard.
- Role checks belong in one place (policies / a `HasRole` concern), not scattered `if`s.
- Requests validated with Form Request classes.
- Migrations for all schema changes. Seeders for roles and the Super Admin account.
- Long OCR/reporting work goes through queued jobs, not synchronous requests, once a
  batch is involved.
- Use Eloquent with eager loading. No raw string-interpolated SQL.

## Environment

Windows, **PowerShell** (not cmd). Use `;` as the command separator, never `&` or `&&`.
Avoid inline `php -r "..."` — PowerShell mangles `$` and quotes. Write a temp `.php` file
and run it instead.

Verified local toolchain:

| Tool     | Version                        |
|----------|--------------------------------|
| PHP      | 8.2.12 (ZTS, x64)              |
| Composer | 2.8.9                          |
| Node     | 22.17.0 / npm 10.9.2           |
| MySQL    | 9.3.0 at `C:\mysql`            |
| Python   | 3.13.14 (global, no venv yet)  |
| Laravel  | 12.64.0                        |

PHP extension gaps to be aware of: **`gd` and `intl` are not enabled.** If server-side
image work is needed (thumbnails, re-encoding scans), enable `gd` first or keep the
cropping client-side in canvas the way the legacy prototype does.

`php.ini` currently caps uploads at **40M** (`upload_max_filesize`, `post_max_size`),
`memory_limit` 512M. Model folders are ~1.3 GB, so the OCR management page **cannot** use a
plain form upload as-is. Either raise the limits or upload in chunks.

Two processes run side by side in development:

```
php artisan serve                                       # Laravel app
uvicorn ml.api.main:app --host 127.0.0.1 --port 8001    # FastAPI OCR service
```

Both run from the repo root. ML dependencies install with
`pip install -r ml\requirements.txt -r ml\api\requirements.txt`.

## Repo layout

The Laravel app lives at the repo root alongside the existing ML tooling:

Everything Python lives under `ml/`. Nothing ML-related belongs at the repo root, and
nothing Laravel belongs inside `ml/` — that boundary is the point.

```
ml/                     ALL Python. One boundary between the two ecosystems.
├── api/
│   ├── main.py         FastAPI OCR service
│   └── requirements.txt
├── train_trocr.py      fine-tuning
├── test_trocr.py       evaluate base model
├── test_finetuned.py   evaluate fine-tuned model
├── predict.py          batch predict a folder
├── metrics.py          CER / WER / exact-match helpers + chart export
├── download_trocr.py   fetch the base model
├── requirements.txt    ML stack (torch, transformers, Pillow, ...)
├── models/             fine-tuned model folders (gitignored, ~1.3 GB each)
├── dataset/            training images + manifest CSV (gitignored)
└── evaluation-metrics/ saved charts, read by the OCR page (base/ and finetuned/)

sneat/                  downloaded SNEAT template - design reference, never served or routed
```

Every script anchors its paths to `ML_ROOT` (its own directory), not the working
directory, so they behave identically however they are launched. Keep it that way — a
bare `os.path.join("dataset", ...)` silently resolves against the caller's CWD.

Two path constants must agree, and there is no test tying them together:
`metrics.py`'s `DEFAULT_METRICS_DIR` and `EvaluationCharts::DIRECTORY`. Change one,
change the other.

### Laravel side of the OCR feature

Organised by domain, never by role. Access is decided by gates in `AuthServiceProvider`,
so there is no `SuperAdmin/` folder anywhere and there should not be one.

```
app/Services/Ocr/       OcrClient, OcrModelManager, OcrServiceException, EvaluationCharts
app/Models/OcrModel.php active-model registry + recorded evaluation figures
app/Http/Controllers/OcrModelController.php
resources/views/ocr/    the single Super Admin management page
```

The legacy `web/` PHP prototype and the Flask `api/app.py` have been **deleted** — both are
now fully superseded by the Laravel app and `api/main.py`. Their logic lives on in:

- `resources/js/field-marker.js` — the canvas crop, drag/resize boxes, and PDF.js rendering
- `app/Enums/DocumentType.php` — `defaultFields()`, the field boxes from `web/js/config.js`
- `ml/api/main.py` — the endpoint contract, confidence maths, and path-safety guards

Recover either from git history if you need to compare behaviour; do not reintroduce them.
