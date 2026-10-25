# Civil Registry Management System (CRMS)

[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat&logo=laravel&logoColor=white)](https://laravel.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php&logoColor=white)](https://www.php.net/)
[![PyTorch](https://img.shields.io/badge/PyTorch-TrOCR-EE4C2C?style=flat&logo=pytorch&logoColor=white)](https://huggingface.co/microsoft/trocr-base-handwritten)
[![Docker](https://img.shields.io/badge/Docker-Windows%20%C2%B7%20Linux%20%C2%B7%20Mac-2496ED?style=flat&logo=docker&logoColor=white)](#run-it-with-docker)
[![Tests](https://img.shields.io/badge/Tests-477%20passed-success)](#tests)

CRMS turns handwritten civil registry pages (birth, death and marriage certificates, and register ledgers) into searchable digital records. Staff scan a page, CRMS finds every handwritten line and reads it with a fine-tuned **TrOCR** model, a person checks every value, and the record is archived with a full audit trail.

**Everything runs on the computer itself.** Scans and their text are never sent to the internet or a cloud service, because the records are covered by the Data Privacy Act.

**Contents:** [What it does](#what-it-does) · [How it works](#how-it-works) · [Who can do what](#who-can-do-what) · [Run it with Docker](#run-it-with-docker) · [Manual setup](#manual-setup-windows) · [First login](#first-login) · [Tests](#tests) · [Documentation](#documentation)

---

## What It Does

| Feature | What it means |
| :--- | :--- |
| **Scan and align** | Upload a PDF or image and place the template's markers on it: drag, resize, tilt, or snap them to the printed table. |
| **Find the handwriting** | Kraken outlines every handwritten line, straightens a tilted page, and fits ledger columns and rows to the page. |
| **Read it** | A fine-tuned TrOCR model reads each line and gives a confidence score. Low scores are flagged for review. |
| **Verify** | Staff check every value beside the page. Only checked values are saved. |
| **Archive** | Submitted records are locked. A correction goes through a change request that an Admin approves or rejects. |
| **Template Builder** | Super Admins design the layout of each document type, with versions and a test on a sample page. |
| **OCR Workspace** | Install models, choose the one Staff scan with, and compare benchmarks (CER, WER, exact match) from locked test reports. |
| **Audit log** | Every action is recorded: who did it, when, and what changed. |
| **Dashboard and reports** | Volume, document types, OCR quality and throughput for any period, plus CSV export. |
| **Light and dark mode** | Light, Dark, or follow the computer's setting. |

## How It Works

```
Browser ──> Laravel website ──> queue worker ──> Kraken: finds and outlines the lines (processor)
                │                     └─────────> OCR service: TrOCR reads each line (NVIDIA GPU or processor)
                └──> MySQL: records, users, audit log
                     scheduler: deletes pages nobody submitted, every hour
```

Slow work (finding and reading the lines, 15–60 s a page) runs on the queue worker, so the website stays responsive. The OCR service uses an NVIDIA graphics card when there is one that works, and the processor otherwise.

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

Admins supervise but never enter or edit record values. Every correction goes through a change request.

---

## Run It with Docker

The easiest way, on **Windows 10/11, Linux or a Mac with Apple silicon**. You need only [Docker](https://www.docker.com/products/docker-desktop/) and [Git](https://git-scm.com/).

```bash
git clone -b docker-setup https://github.com/MiroLim999/crms-laravel-12.git
cd crms-laravel-12
cp .env.example .env              # Windows: Copy-Item .env.example .env
mkdir ml/models                   # then copy the model folder into it
sh docker-start.sh                # Windows: powershell -ExecutionPolicy Bypass -File .\docker-start.ps1
docker compose exec web php artisan key:generate
docker compose exec web php artisan migrate --seed
docker compose restart
```

Then open **http://localhost:8080**.

- **Step by step, plus backups, updates and problems:** [docs/DOCKER-SETUP.md](docs/DOCKER-SETUP.md).
- **The models are not in the repository** (1.3 GB each). Copy a model folder, such as `TrOcr-50k-broken-samples/`, into `ml/models/` by hand.
- **Only this computer can open CRMS**, and the records stay on it.
- The start script uses an NVIDIA card when the computer has one that works. AMD cards and Macs use the processor.
- Tested on Windows 11. Windows 10, Linux and a real Mac are not tested yet.

## Manual Setup (Windows)

For development. You need PHP 8.2+, Composer, Node.js 20+, MySQL 8+ (for example XAMPP) and Python 3.10+.

```powershell
composer install
npm install
npm run build
python -m venv .venv
.venv\Scripts\pip install torch torchvision --index-url https://download.pytorch.org/whl/cu124   # NVIDIA GPU only
.venv\Scripts\pip install -r ml/requirements.txt -r ml/api/requirements.txt
pip install uv
.\ml\setup_kraken.ps1             # line detection (Kraken), in its own environment
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
.\serve.ps1                       # website, queue worker, scheduler and OCR service
```

Then open **http://127.0.0.1:8000**. The [Developer Guide](docs/DEVELOPER-GUIDE.md) explains each step, every command and every setting.

## First Login

| Account | Email | Password |
| :--- | :--- | :--- |
| Super Admin | `superadmin@admin.com` | `superadmin@admin.com` |

> **Change this password right after the first login**, because it is public: click the initials badge at the top right → **Account Settings**. CRMS does not force the change for this account. Other accounts are created under **User Accounts**.

For testing only, `php artisan db:seed --class=DemoUsersSeeder` adds `staff@crms.test` and `admin@crms.test` (password `password123`).

## Tests

**477 tests, all passing** (2026-10-24).

| Suite | Tests |
| :--- | ---: |
| PHP | 312 |
| JavaScript | 86 |
| Python: line detection | 66 |
| Python: OCR service | 13 |

Run them all with `.\tools\test-all.ps1`. MySQL must be running; the PHP tests use their own `crms_test` database and never touch the real records.

---

## Documentation

| File | Read it for |
| :--- | :--- |
| [docs/DOCKER-SETUP.md](docs/DOCKER-SETUP.md) | Setting CRMS up on another computer with Docker, step by step (Windows, Mac or Linux), then daily use, updates, backups, the NVIDIA GPU and troubleshooting |
| [docs/DEVELOPER-GUIDE.md](docs/DEVELOPER-GUIDE.md) | Developers: architecture, every feature in detail, roles, commands, `.env` settings, the ML pipeline, the OCR API, production, tests, known limitations |

## Technology

Laravel 12 (PHP 8.2) · Bootstrap 5 (SNEAT) · Vite · MySQL · FastAPI · PyTorch · Hugging Face TrOCR · Kraken · PDF.js · ApexCharts · Docker

## License

This project is proprietary civil registry software. All rights reserved.
