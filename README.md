# Civil Registry Management System (CRMS)

[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat&logo=laravel&logoColor=white)](https://laravel.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php&logoColor=white)](https://www.php.net/)
[![PyTorch](https://img.shields.io/badge/PyTorch-TrOCR-EE4C2C?style=flat&logo=pytorch&logoColor=white)](https://huggingface.co/microsoft/trocr-base-handwritten)
[![Docker](https://img.shields.io/badge/Docker-Windows%20%C2%B7%20Linux%20%C2%B7%20Mac-2496ED?style=flat&logo=docker&logoColor=white)](#installation-docker)
[![Tests](https://img.shields.io/badge/Tests-781%20passed-success)](#tests)

CRMS turns handwritten civil registry pages (birth, death and marriage register books and certificates) into searchable digital records. You scan a page and type its book and page number, CRMS finds every handwritten line and reads it with a fine-tuned **TrOCR** model, a person checks every value, reviews the result as the register's own table (one row per person) that downloads as CSV or Excel, and the record is archived with a full audit trail. Each person is also saved as one row of the book's register table, and a whole book downloads as one CSV.

**Everything runs on the computer itself.** Scans and their text are never sent to the internet or a cloud service, because the records are covered by the Data Privacy Act.

**Quick start:** [Installation (Docker)](#installation-docker) · [Docker guide](docs/DOCKER-SETUP.md) · [First login](#first-login)

**Contents:** [Installation](#installation-docker) · [First login](#first-login) · [What it does](#what-it-does) · [How it works](#how-it-works) · [Manual setup](#manual-setup-windows-for-developers) · [Tests](#tests) · [Documentation](#documentation)

---

## Installation (Docker)

Works on **Windows 10/11, Linux, and Mac with Apple silicon**. Only this computer can open CRMS.

**You need:** [Git](https://git-scm.com/), [Docker Desktop](https://www.docker.com/products/docker-desktop/), about **20 GB free disk**, and internet for the first run. Open Docker Desktop and wait for **Engine running** before you start. Windows, Mac and Linux prep steps: [docs/DOCKER-SETUP.md](docs/DOCKER-SETUP.md#prepare-the-computer).

### Step 1: Get the code

**Mac / Linux** (Terminal):

```bash
cd ~
git clone https://github.com/MiroLim999/crms-laravel-12.git
cd crms-laravel-12
cp .env.example .env
mkdir -p ml/models
```

**Windows** (PowerShell):

```powershell
cd C:\
git clone https://github.com/MiroLim999/crms-laravel-12.git
cd C:\crms-laravel-12
Copy-Item .env.example .env
mkdir ml\models
```

### Step 2: Add the OCR model

The models are not in the repository (1.3 GB each). Copy the model folder into `ml/models/`. The model in use is `trocr-finetuned-final` (trained on real ledger lines; see [docs/TRAINING-DATA.md](docs/TRAINING-DATA.md)).

Check that this file exists: `ml/models/trocr-finetuned-final/config.json` (if there is an extra folder level, move the inner folder up one level).

> **No fine-tuned model?** Download the base model instead: `python ml/download_trocr.py`. It goes to `ml/models/base`.
>
> **You can also skip this step.** CRMS never downloads a model by itself, so the OCR Workspace then starts with **no models**, and scanning says "No OCR model is installed" until you add one. Add it later from your USB: **OCR Workspace → Add model** (the model's ZIP), or copy the folder into `ml/models/` and click **Rescan models**. Then click **Activate**.

### Step 3: Start and set up

**Mac / Linux:**

```bash
sh docker-start.sh
docker compose run --rm migrate --seed
docker compose restart
```

**Windows:**

```powershell
powershell -ExecutionPolicy Bypass -File .\docker-start.ps1
docker compose run --rm migrate --seed
docker compose restart
```

The first start takes **10 to 45 minutes** (Docker downloads several GB). The start script writes Laravel's `APP_KEY` into `.env` the first time (the containers can only read `.env`), and makes the database passwords (in `docker-data/secrets`) and encrypts the database; see [Database security](docs/DOCKER-SETUP.md#database-security). An NVIDIA GPU is used automatically if the computer has one that works; otherwise the processor is used.

### Step 4: Open CRMS

Go to **http://localhost:8080** and log in (see [First login](#first-login)).

Then go to **System → OCR Workspace**, click **Activate** next to your model in the **Models** panel, and confirm. If it says *OCR service offline*, wait a minute and reload.

### Daily use

```bash
docker compose up -d      # start
docker compose down       # stop (records are kept)
docker compose ps         # check status
```

> **Never run `docker compose down -v` on a computer with real records.** It deletes the database.

Updates, backups, the NVIDIA GPU and troubleshooting: **[docs/DOCKER-SETUP.md](docs/DOCKER-SETUP.md)**.

---

## First Login

CRMS has **one account**, the Super Admin. There is **no default password**: the first time CRMS is opened, it shows
**Set up CRMS**, where you type your name, the email you will sign in with, and your own password. This page works
**once**, and only on the CRMS computer itself, so nobody else on the network can make the account first.
In Docker the page also asks for a **setup token**; see it with `docker compose exec web php artisan crms:setup-token`.

Then, in **Account Settings** (CRMS opens it right after setup; later: initials badge at the top right →
**Account Settings**):

- **Recovery codes**: make your 10 one-time codes and keep them somewhere safe. They are shown only once.
- **Your details**: your name and email. They can be changed **once**; after that they are locked
  (`php artisan crms:unlock-details` on the CRMS computer allows one more change).

Running `php artisan db:seed` never makes or changes an account.

### Forgot the password?

There is no email when offline. Two ways back in:

1. On the sign-in page, click **Forgot password?**, type your email and one of your **recovery codes**, then choose a
   new password. Each code works once.
2. On the CRMS computer, in the project folder, run:

   ```powershell
   php artisan crms:reset-password
   ```

   It prints a new temporary password once. Sign in with it, and CRMS asks you to choose a new one.
   (With Docker: `docker compose exec web php artisan crms:reset-password`.)

---

## What It Does

| Feature | What it means |
| :--- | :--- |
| **Scan and align** | Upload a PDF or image, place the layout's markers on it, and type the page's **Book No.** and **Page No.** (a page of a book is saved once). A PDF of many pages is read one page at a time. |
| **Batch mode** | Queue several files, or a whole register book, read them all with **Scan all**, then check and save them one record at a time. A batch survives a reload and can be resumed. It ends with **Download all** as one CSV or Excel file. |
| **Find the handwriting** | Kraken outlines every handwritten line, following the page's tilt. |
| **Read it** | A fine-tuned TrOCR model reads each line and gives a confidence score. Low scores are flagged. |
| **Verify** | Check every value beside the page. Only checked values are saved. |
| **Review & Export** | Step 4: the document as the register's own table (for birth, death and marriage books: name, dates, sex, book, page, register number, and so on), one row per person, beside the scan. Edit cells (fill several at once, paste from Excel, undo), then **Download** as CSV or Excel, and **Save record**. |
| **Saved Records** | Saved records are locked. **Edit in Review & Export** changes a saved record's values on the same table, and every old and new value goes to the Audit Log. **Download CSV** gives one document's values; **Download one book as CSV** gives a whole register book, one row per entry, for import into another system. |
| **Template Builder** | Design the layout of each document type (one or two pages, columns, row lines). |
| **OCR Workspace** | Install models, choose the active one, and compare benchmarks (CER, WER, exact match). |
| **Audit log** | Every action is recorded: who, when, and what changed. |
| **Dashboard** | Volume, document types, OCR quality and processing times. |

---

## How It Works

```
Browser ──> Laravel website ──> queue worker ──> Kraken: finds and outlines the lines
                │                     │            (line finder service keeps it loaded, optional)
                │                     └─────────> OCR service: TrOCR reads each line (NVIDIA GPU or processor)
                └──> MySQL: records, register tables, batches, audit log
                     scheduler: deletes pages nobody saved, and old batches, every hour
```

Slow work (about 15–30 s a page with a GPU) runs on the queue worker, so the website stays responsive.

---

## Manual Setup (Windows, for developers)

You need PHP 8.2+, Composer, Node.js 20+, MySQL 8+ (for example XAMPP) and Python 3.10+.

```powershell
composer install
npm install
npm run build
python -m venv .venv
.venv\Scripts\pip install torch torchvision --index-url https://download.pytorch.org/whl/cu124   # NVIDIA GPU only
.venv\Scripts\pip install -r ml/requirements.txt -r ml/api/requirements.txt
pip install uv
.\ml\setup_kraken.ps1
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
.\serve.ps1
```

Open **http://127.0.0.1:8000**. See the [Developer Guide](docs/DEVELOPER-GUIDE.md) for details.

## Tests

**783 tests: 781 pass, 2 skipped, none fail** (2026-11-27): PHP 436 · JavaScript 194 · Python line detection 123 · Python OCR service 30.

Run them all with `.\tools\test-all.ps1`. MySQL must be running; PHP tests use their own `crms_test` database.

## Documentation

| File | Read it for |
| :--- | :--- |
| [docs/DOCKER-SETUP.md](docs/DOCKER-SETUP.md) | Docker prep per OS, updates, backups, NVIDIA GPU, troubleshooting |
| [docs/DEVELOPER-GUIDE.md](docs/DEVELOPER-GUIDE.md) | Architecture, features, commands, `.env` settings, ML pipeline, OCR API |
| [docs/SYSTEM-OVERVIEW.md](docs/SYSTEM-OVERVIEW.md) | The whole system in one document: screens, work flows, database, security, testing, review |
| [docs/TRAINING-DATA.md](docs/TRAINING-DATA.md) | What the AI model in use was trained and tested on, and the open privacy question |
| [docs/MULTI-PAGE-PDF-BATCH-NOTE.md](docs/MULTI-PAGE-PDF-BATCH-NOTE.md) | How batch mode saves multi-page PDFs and page pairs |

## Technology

Laravel 12 (PHP 8.2) · Bootstrap 5 (SNEAT) · Vite · MySQL · FastAPI · PyTorch · Hugging Face TrOCR · Kraken · PDF.js · pdf-lib · write-excel-file · ApexCharts · Docker

## License

This project is proprietary civil registry software. All rights reserved.
