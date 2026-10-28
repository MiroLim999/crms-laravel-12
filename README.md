# Civil Registry Management System (CRMS)

[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat&logo=laravel&logoColor=white)](https://laravel.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php&logoColor=white)](https://www.php.net/)
[![PyTorch](https://img.shields.io/badge/PyTorch-TrOCR-EE4C2C?style=flat&logo=pytorch&logoColor=white)](https://huggingface.co/microsoft/trocr-base-handwritten)
[![Docker](https://img.shields.io/badge/Docker-Windows%20%C2%B7%20Linux%20%C2%B7%20Mac-2496ED?style=flat&logo=docker&logoColor=white)](#installation-docker)
[![Tests](https://img.shields.io/badge/Tests-477%20passed-success)](#tests)

CRMS turns handwritten civil registry pages (birth, death and marriage certificates, and register ledgers) into searchable digital records. Staff scan a page, CRMS finds every handwritten line and reads it with a fine-tuned **TrOCR** model, a person checks every value, and the record is archived with a full audit trail.

**Everything runs on the computer itself.** Scans and their text are never sent to the internet or a cloud service, because the records are covered by the Data Privacy Act.

**Quick start:** [Installation (Docker)](#installation-docker) · [Docker guide](docs/DOCKER-SETUP.md) · [First login](#first-login)

**Contents:** [Installation](#installation-docker) · [First login](#first-login) · [What it does](#what-it-does) · [How it works](#how-it-works) · [Who can do what](#who-can-do-what) · [Manual setup](#manual-setup-windows-for-developers) · [Tests](#tests) · [Documentation](#documentation)

---

## Installation (Docker)

Works on **Windows 10/11, Linux, and Mac with Apple silicon**. Only this computer can open CRMS.

**You need:** [Git](https://git-scm.com/), [Docker Desktop](https://www.docker.com/products/docker-desktop/), about **20 GB free disk**, and internet for the first run. Open Docker Desktop and wait for **Engine running** before you start. Windows, Mac and Linux prep steps: [docs/DOCKER-SETUP.md](docs/DOCKER-SETUP.md#prepare-the-computer).

### Step 1: Get the code

**Mac / Linux** (Terminal):

```bash
cd ~
git clone -b docker-setup https://github.com/MiroLim999/crms-laravel-12.git
cd crms-laravel-12
cp .env.example .env
mkdir -p ml/models
```

**Windows** (PowerShell):

```powershell
cd C:\
git clone -b docker-setup https://github.com/MiroLim999/crms-laravel-12.git
cd C:\crms-laravel-12
Copy-Item .env.example .env
mkdir ml\models
```

### Step 2: Add the OCR model

The models are not in the repository (1.3 GB each). Copy the folder `TrOcr-50k-broken-samples` into `ml/models/`.

Check that this file exists: `ml/models/TrOcr-50k-broken-samples/config.json` (if there is an extra folder level, move the inner folder up one level).

> **No fine-tuned model?** Download the base model instead: `python ml/download_trocr.py`. It goes to `ml/models/base`.

### Step 3: Start and set up

**Mac / Linux:**

```bash
sh docker-start.sh
docker compose exec web php artisan key:generate
docker compose exec web php artisan migrate --seed
docker compose restart
```

**Windows:**

```powershell
powershell -ExecutionPolicy Bypass -File .\docker-start.ps1
docker compose exec web php artisan key:generate
docker compose exec web php artisan migrate --seed
docker compose restart
```

The first start takes **10 to 45 minutes** (Docker downloads several GB). Run `key:generate` only once. An NVIDIA GPU is used automatically if the computer has one that works; otherwise the processor is used.

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

| Account | Email | Password |
| :--- | :--- | :--- |
| Super Admin | `superadmin@admin.com` | `superadmin@admin.com` |

> **Change this password right after the first login** (it is public): initials badge at the top right → **Account Settings**. Create other accounts under **User Accounts**.

For testing only: `php artisan db:seed --class=DemoUsersSeeder` adds `staff@crms.test` and `admin@crms.test` (password `password123`).

---

## What It Does

| Feature | What it means |
| :--- | :--- |
| **Scan and align** | Upload a PDF or image and place the template's markers on it. |
| **Find the handwriting** | Kraken outlines every handwritten line and straightens a tilted page. |
| **Read it** | A fine-tuned TrOCR model reads each line and gives a confidence score. Low scores are flagged. |
| **Verify** | Staff check every value beside the page. Only checked values are saved. |
| **Archive** | Submitted records are locked. Corrections go through a change request that an Admin approves. |
| **Template Builder** | Super Admins design the layout of each document type. |
| **OCR Workspace** | Install models, choose the active one, and compare benchmarks (CER, WER, exact match). |
| **Audit log** | Every action is recorded: who, when, and what changed. |
| **Dashboard and reports** | Volume, document types, OCR quality and throughput, plus CSV export. |

---

## How It Works

```
Browser ──> Laravel website ──> queue worker ──> Kraken: finds and outlines the lines (processor)
                │                     └─────────> OCR service: TrOCR reads each line (NVIDIA GPU or processor)
                └──> MySQL: records, users, audit log
                     scheduler: deletes pages nobody submitted, every hour
```

Slow work (15–60 s a page) runs on the queue worker, so the website stays responsive.

## Who Can Do What

| | Staff | Admin | Super Admin |
| :--- | :---: | :---: | :---: |
| Scan, verify and submit records | ✔ | | ✔ |
| Search and view records | ✔ | ✔ | ✔ |
| Request a correction | ✔ | | ✔ |
| Approve or reject corrections | | ✔ | ✔ |
| Dashboard analytics and CSV reports | | ✔ | ✔ |
| Manage user accounts | | Staff only | ✔ |
| View the audit log | | ✔ | ✔ |
| Template Builder and OCR Workspace | | | ✔ |

Admins supervise but never enter or edit record values.

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

**477 tests, all passing** (2026-10-24): PHP 312 · JavaScript 86 · Python line detection 66 · Python OCR service 13.

Run them all with `.\tools\test-all.ps1`. MySQL must be running; PHP tests use their own `crms_test` database.

## Documentation

| File | Read it for |
| :--- | :--- |
| [docs/DOCKER-SETUP.md](docs/DOCKER-SETUP.md) | Docker prep per OS, updates, backups, NVIDIA GPU, troubleshooting |
| [docs/DEVELOPER-GUIDE.md](docs/DEVELOPER-GUIDE.md) | Architecture, features, commands, `.env` settings, ML pipeline, OCR API |

## Technology

Laravel 12 (PHP 8.2) · Bootstrap 5 (SNEAT) · Vite · MySQL · FastAPI · PyTorch · Hugging Face TrOCR · Kraken · PDF.js · ApexCharts · Docker

## License

This project is proprietary civil registry software. All rights reserved.
