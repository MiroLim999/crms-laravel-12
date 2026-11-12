# CRMS: Complete System Overview

**Civil Registry Management System.** What it does, how it works, how it was built, how it was tested, and where it falls short.

| | |
| :--- | :--- |
| **Describes** | The code on branch `main` at commit `e2167bc`, 3 November 2026 |
| **Based on** | The source code, the database structure, the installed AI model reports, and a full test run on 3 November 2026 |
| **Diagrams** | Plain text, so they show in VS Code's preview (Ctrl+Shift+V) and on GitHub without add-ons |
| **Related files** | [README](../README.md) (installation), [DEVELOPER-GUIDE](DEVELOPER-GUIDE.md) (developer reference), [DOCKER-SETUP](DOCKER-SETUP.md) (Docker), [System-Evaluation.pdf](System-Evaluation.pdf) (blank user-evaluation forms) |

> **Changed on 11 November 2026: one account only.** The client runs CRMS on one offline PC with one account, the
> Super Admin. The Staff and Admin roles, User Accounts, change-request approval, account deactivation and the demo
> logins were removed (branch `miro-dev5`, plan in [SUPER-ADMIN-ONLY-PLAN](SUPER-ADMIN-ONLY-PLAN.md)). Sections 2, 3.5.2,
> 4.1, 4.2, 4.7, 4.8, 4.10, 4.14, 5.4 and 5.5 describe the new system. Other sections may still say "Staff" for the
> person scanning; read it as "the user". **Any manuscript diagram or table with three user types now needs one.**

> **About the numbers.** Every figure says where it comes from. "Measured" means a real run produced it. The AI accuracy figures come from a **synthetic** (computer-generated) test set, not from real registry pages. Section [7.7](#77-results) explains what that means and what not to claim.

## Contents

- [At a glance](#at-a-glance)
- [1. What CRMS is](#1-what-crms-is)
- [2. Who uses it](#2-who-uses-it)
- [3. How it is built](#3-how-it-is-built)
  - [3.5 System structure](#35-system-structure)
- [4. The screens and features](#4-the-screens-and-features)
- [5. How the work flows](#5-how-the-work-flows)
- [6. The database](#6-the-database)
- [7. The OCR and AI pipeline](#7-the-ocr-and-ai-pipeline)
- [8. Security, privacy and data integrity](#8-security-privacy-and-data-integrity)
- [9. Testing and evaluation](#9-testing-and-evaluation)
- [10. Installing and running](#10-installing-and-running)
- [11. Review and analysis](#11-review-and-analysis)
- [12. Where things are in the code](#12-where-things-are-in-the-code)
- [13. Glossary](#13-glossary)
- [Appendix A. Status codes, flags and notes](#appendix-a-status-codes-flags-and-notes)
- [Appendix B. Audit log actions](#appendix-b-audit-log-actions)
- [Appendix C. Main settings](#appendix-c-main-settings)
- [Appendix D. OCR service endpoints](#appendix-d-ocr-service-endpoints)
- [Appendix E. Numbers you can quote](#appendix-e-numbers-you-can-quote)
- [Appendix F. References to cite](#appendix-f-references-to-cite)

---

## At a glance

| | |
| :--- | :--- |
| **What it does** | Turns handwritten civil registry pages (birth, death and marriage registers and certificates) into verified, searchable digital records. |
| **How** | Staff scan a page. CRMS finds every handwritten line (Kraken), an AI model reads each line (TrOCR), a person checks every value, and the record is saved, locked and logged. |
| **Users** | Staff (encode), Admin (supervise and approve), Super Admin (everything, plus system setup). |
| **Where it runs** | Entirely on one computer. Nothing is sent to the internet, because the pages are personal data under the Data Privacy Act. |
| **Built with** | Laravel 12 (PHP 8.2), MySQL, Bootstrap 5 (SNEAT theme), Python FastAPI, PyTorch, Hugging Face TrOCR, Kraken 7.1, PDF.js, ApexCharts, Docker. |
| **AI model in use** | `100k-finetuned-dataset-model`: Microsoft's TrOCR base model, fine-tuned on 80,000 synthetic handwriting images (333.9 million parameters). |
| **Its test scores** | Character error rate 4.33%, word error rate 15.45%, exact match 79.2%, on 10,000 synthetic test images. Microsoft's model before fine-tuning scored 49.72%, 93.33% and 6.11% on the same images. |
| **Automated tests** | 559 tests: 553 pass, 5 fail, 1 skipped (run on 3 Nov 2026; see [9.1](#91-automated-tests)). |
| **Size** | About 412 files in git. The largest are the scan screen (5,621 lines) and the line detector (3,193 lines). |

---

## 1. What CRMS is

### 1.1 The problem

Civil registry offices record births, deaths and marriages by hand, in register books (ledgers) and on certificates. To search those entries, count them, or correct one, someone has to read the handwriting and type it in. That is slow and easy to get wrong. The pages also hold personal data protected by the Data Privacy Act of 2012 (Republic Act No. 10173), so they cannot simply be sent to an online text-recognition service.

### 1.2 What CRMS does about it

CRMS is a web application that runs on one office computer. When Staff scan a page, CRMS:

1. lines the page up with a **template** (the layout of that document type),
2. finds every handwritten line on the page and cuts it out,
3. reads each line with an AI handwriting model (**TrOCR**) and gives each reading a confidence score,
4. shows every reading beside the page, so a person can check and correct it,
5. saves only the values the person ticked as checked, locks the record, and writes the action to the audit log.

After that, records can be searched, viewed beside the original scan, reported and exported. A locked record changes only through a **change request** that an Admin approves.

### 1.3 What it is not

- **Not an online service.** All parts run on the same computer, and nothing is sent to the internet while it runs.
- **Not fully automatic.** The AI suggests; a person decides. Every saved value was checked by a person.
- **Not a births-and-deaths statistics system.** The dashboard counts digitization work (records archived, pages scanned), not civil events.
- **Not where the AI is trained.** Training happens separately on Kaggle (notebooks in `finetuning-kaggle/`). The trained model is then installed into CRMS.

### 1.4 Design principles

These rules shape the whole system. Each one is enforced in code, not only in the screens.

| Principle | What it means | Where it is enforced |
| :--- | :--- | :--- |
| **Human in the loop** | Only values a person ticked are saved. Unticked values are dropped. | `DocumentScanController::store` requires every saved field to be marked verified |
| **Local only** | Every part runs on one machine; the website and the OCR service listen on `127.0.0.1` only. | `docker-compose.yml`, `serve.ps1` |
| **Records do not change silently** | Submitted records are locked. There is no edit route for anyone. Corrections need an approved change request. | `routes/web.php`, `ChangeRequestService` |
| **Separation of duties** | Staff encode. Admins approve and supervise but cannot encode or edit values. Only the Super Admin configures templates and AI models. | `AuthServiceProvider` (permission rules) |
| **Everything is logged** | Sign-ins, submissions, approvals, account changes, template and model changes all go into the audit log, which the code refuses to edit or delete. | `AuditLogger`, `AuditLog` model |
| **Honest numbers** | AI scores are shown only from a test-set report tied to the exact model file. Confidence is labelled as the model's own certainty, not accuracy. | `ml/api/main.py`, `OcrModelPerformance` |
| **Slow work in the background** | Finding and reading lines takes 15 to 70 seconds a page, so it runs on a background worker and the website stays responsive. | `ProcessDocumentPage` job |

---

## 2. Who uses it

### 2.1 One account

CRMS runs on one offline PC with **one account, the Super Admin**. The person who signs in does everything: scans,
verifies, corrects, reports, builds templates and manages the AI models. There are no other roles and no screen for
managing users.

| Account | Typical person | Main job |
| :--- | :--- | :--- |
| **Super Admin** | The registry's encoder or system administrator | Everything: New Document, Records Archive, Corrections, Reports, Audit Log, Template Builder, OCR Workspace, Account Settings |

### 2.2 What the account can do

| Capability | Super Admin |
| :--- | :---: |
| Scan, verify and submit records (New Document) | ✔ |
| Search and view the Records Archive | ✔ |
| Correct a saved record (Correct record) and see every correction | ✔ |
| Download one record as CSV | ✔ |
| Dashboard with analytics | ✔ |
| Reports and CSV export | ✔ |
| View the audit log | ✔ |
| Template Builder | ✔ |
| OCR Workspace (AI models) | ✔ |
| Change own name, email and password | ✔ |

Rules behind the table:

- Each page still checks a permission (`app/Providers/AuthServiceProvider.php`), and each permission asks only "is this
  the Super Admin?". An automated test checks that the account opens every page and a guest is sent to sign in
  (`tests/Feature/CapabilityMatrixTest.php`).
- There is **no sign-up page** and no User Accounts page. The account is created at installation.
- A saved record changes only through **Correct record**, which keeps the old values (see [4.8](#48-corrections)).

### 2.3 The account at installation

| Account | Created by | Sign-in |
| :--- | :--- | :--- |
| Super Admin | `php artisan migrate --seed` | `superadmin@admin.com`, first password `superadmin@admin.com`. At the first sign-in CRMS asks for a new password. Running the seeder again never resets an existing account. |

**Forgot the password?** On the CRMS computer run `php artisan crms:reset-password`. It prints a new temporary
password once; at the next sign-in CRMS asks for a new one. The reset is written to the audit log.

---

## 3. How it is built

### 3.1 The five parts

CRMS is five programs working together on one computer.

```
 +-------------------------------------------------------------------------+
 |  WEB BROWSER  (the only part people touch)                              |
 +-----------------------------------+-------------------------------------+
                                     |  web pages and JSON
 +-----------------------------------v-------------------------------------+
 |  1. WEBSITE   Laravel 12 (PHP)                                          |
 |     screens, sign-in, permissions, checks, records, audit log           |
 +----------+-----------------------------------+--------------------------+
            |  adds a job to the queue          |  status checks and model
            |                                   |  actions (secret key)
 +----------v--------------+          +---------v--------------------------+
 |  2. QUEUE WORKER (PHP)  |  crops   |  4. OCR SERVICE  Python FastAPI    |
 |  Detect, Scan, Test on  +--------->|     TrOCR model on GPU or CPU,     |
 |  sample: one at a time  |          |     returns text + confidence      |
 +----------+--------------+          +------------------------------------+
            |  runs a local program
 +----------v--------------+          +------------------------------------+
 |  line detector          |          |  3. SCHEDULER (PHP)                |
 |  Python + Kraken:       |          |     every hour: deletes pages      |
 |  straightens the page,  |          |     nobody submitted               |
 |  finds and cuts lines   |          +------------------------------------+
 +-------------------------+

 Parts 1, 2 and 3 read and write  5. DATABASE (MySQL)  and the private file store
 (storage/app/private: scans, page images, line crops).
```

| # | Part | What it does | Started by (Windows) | Docker container |
| :--- | :--- | :--- | :--- | :--- |
| 1 | **Website** | Every screen and every permission check | `php artisan serve` (port 8000) | `web` (http://localhost:8080) |
| 2 | **Queue worker** | Runs the slow jobs: Detect, Scan with OCR | `php artisan queue:work --timeout=900 --tries=1` | `worker` |
| 3 | **Scheduler** | Every hour, deletes pages nobody submitted (after 24 hours) | `php artisan schedule:work` | `scheduler` |
| 4 | **OCR service** | Reads line images with TrOCR | `python -m uvicorn ml.api.main:app --port 8001` | `ocr` (port 8081) |
| 5 | **Database** | Stores users, records, templates, audit log, job queue | MySQL (XAMPP or standalone) | `mysql` (no outside port) |
| – | **Line detector** | Not a separate service: a Python program the worker runs for each page | `ml/line_markers.py` in its own Python environment `ml/.venv-kraken` | inside `worker` |

On Windows, `serve.ps1` checks the computer and starts parts 1 to 4 together.

### 3.2 Technology used

| Layer | Technology | Version installed | What it is used for |
| :--- | :--- | :--- | :--- |
| Web framework | Laravel | 12.64.0 | Routing, permissions, database access, queue, scheduler |
| Language | PHP | 8.2.12 (XAMPP) | The website, worker and scheduler |
| Database | MySQL | 9.3.0 (Windows), 9.7 (Docker) | All data |
| Screens | Blade templates, Bootstrap 5.3 with the SNEAT admin theme, SCSS | – | Page layout and styling |
| Front-end build | Vite 6, Tailwind 4, Sass | – | Bundles the CSS and JavaScript |
| Browser libraries | PDF.js 4.10, ApexCharts 4.2, Boxicons | – | Showing PDFs, charts, icons |
| OCR service | Python, FastAPI 0.115.6, Uvicorn 0.34 | Python 3.13 | The reading service |
| AI framework | PyTorch 2.6.0 (CUDA 12.4), Hugging Face Transformers 5.14.1 | – | Running TrOCR |
| Line detection | Kraken 7.1.1, NumPy, Pillow (PyTorch 2.14 in its own environment) | – | Finding handwritten lines |
| Training | Kaggle notebooks on 2 × NVIDIA Tesla T4 | Transformers 5.16.1, PyTorch 2.11 | Fine-tuning TrOCR |
| Deployment | Docker Compose (5 containers), optional NVIDIA GPU add-on | – | Running on Windows, Linux or Mac |
| Testing | PHPUnit 11, Node.js test runner, Python unittest | – | Automated tests |

Kraken needs a newer PyTorch than the OCR service, which is why the line detector has its own Python environment.

### 3.3 How the parts talk to each other

| From | To | How | Protection |
| :--- | :--- | :--- | :--- |
| Browser | Website | Normal pages, forms, and JSON calls that poll for progress | Sign-in session, CSRF token, permission checks |
| Website and worker | OCR service | HTTP on the same computer | Header `X-CRMS-Service-Key`, a secret shared through `.env`. Every call but `/health` is refused without it (answer 401). |
| Browser | OCR service | **Only** to upload a new AI model (about 1.3 GB), so the big file does not pass through PHP | A signed ticket from the website (HMAC-SHA256, expires after 15 minutes by default) |
| Worker | Line detector | Starts `ml/line_markers.py` as a local program; they exchange files (`page.png`, `geometry.json` in; `lines.json` and crop images out) | Runs on the same computer; checked twice a second so a cancelled page stops it at once |
| Website, worker, scheduler | Database | MySQL connection | Database password in `.env`; in Docker the database has no outside port |

**Why a queue worker?** On Windows, `php artisan serve` answers one request at a time, and one page takes 15 to 70 seconds. If the website did the work itself, every other screen would freeze meanwhile. So the website stores the page, puts a job in the queue and answers at once; the worker does the work; the browser asks for progress every few seconds.

**GPU when it works, CPU when it does not.** The OCR service uses an NVIDIA graphics card when there is one. If the card fails (old driver, out of memory), it switches to the processor and keeps reading, so no line is lost. Kraken does the same.

### 3.4 Two ways to run it

| | Docker | Windows, by hand |
| :--- | :--- | :--- |
| Who it is for | Any computer: Windows 10/11, Linux, Mac with Apple silicon | Developers |
| Needs | Git, Docker Desktop, about 20 GB free disk | PHP 8.2+, Composer, Node 20+, MySQL 8+, Python 3.10+ |
| Address | http://localhost:8080 | http://127.0.0.1:8000 |
| First start | 10 to 45 minutes (downloads several GB) | Manual install steps |
| Graphics card | Optional NVIDIA add-on (`docker-compose.gpu.yml`) | Used automatically if the CUDA build of PyTorch is installed |
| Guide | [DOCKER-SETUP.md](DOCKER-SETUP.md) | [README](../README.md#manual-setup-windows-for-developers), [DEVELOPER-GUIDE](DEVELOPER-GUIDE.md) |

### 3.5 System structure

The same system, seen four ways. Use whichever view the chapter needs.

| View | Answers | Part |
| :--- | :--- | :--- |
| Module structure | What is the system made of? | [3.5.1](#351-module-structure) |
| Structure by user | What can each kind of user open? | [3.5.2](#352-structure-by-user) |
| Layer structure | How is the code organized? | [3.5.3](#353-layer-structure) |
| Folder structure | Where is each part in the project? | [3.5.4](#354-folder-structure) |

#### 3.5.1 Module structure

CRMS has six modules. Five are screens that people use; the sixth runs in the background.

```
                                +-------------------------------------+
                                |   CIVIL REGISTRY MANAGEMENT SYSTEM  |
                                |                (CRMS)               |
                                +------------------+------------------+
                                                   |
         +----------------+----------------+-------+--------+----------------+----------------+
         |                |                |                |                |                |
 +-------+------+ +-------+------+ +-------+------+ +-------+------+ +-------+------+ +-------+------+
 |    ACCESS    | |  DASHBOARD   | | DIGITIZATION | |  OVERSIGHT   | | SYSTEM SETUP | |  BACKGROUND  |
 +--------------+ +--------------+ +--------------+ +--------------+ +--------------+ +--------------+
```

What each module contains:

```
 CRMS
 ├── ACCESS
 │   ├── Sign in and sign out (after 5 wrong attempts, a short wait)
 │   ├── Forced password change (temporary passwords)
 │   ├── Account Settings (change own password)
 │   └── Roles and permissions (Staff, Admin, Super Admin)
 ├── DASHBOARD
 │   ├── Staff summary: own submissions, own requests, own activity
 │   └── Oversight analytics: OCR Quality, OCR Performance, Volume and Trend,
 │       Archive by type, Processing and staff activity, Change requests,
 │       Recent changes
 ├── DIGITIZATION
 │   ├── New Document
 │   │   ├── Step 1 Document: choose the type, upload the scan
 │   │   ├── Step 2 Align: place the markers (Snap to table, rows, columns, tilt)
 │   │   ├── Detect (optional): straighten, fit the grid, outline and fix lines
 │   │   ├── Scan with OCR: find the lines (Kraken), read them (TrOCR)
 │   │   └── Step 3 Verify: check, correct, tick, add missed fields, submit
 │   ├── Records Archive: search, filter, view a record beside its scan
 │   └── Change Requests: request a correction, approve or reject, withdraw
 ├── OVERSIGHT
 │   ├── Reports: filter, summary, CSV export
 │   ├── User Accounts: create, edit, reset password, deactivate, reactivate
 │   └── Audit Log: view, filter, search
 ├── SYSTEM SETUP
 │   ├── Template Builder
 │   │   ├── Document types: add, rename, archive, restore, delete
 │   │   ├── Layouts: fields or ledger grid, sample page, field settings, people
 │   │   └── grid checks, versions, publish checks
 │   └── OCR Workspace
 │       ├── Service status (online or offline, GPU or CPU)
 │       ├── Models: add, activate, rename, delete, colour, rescan
 │       └── Model performance chart (test-set scores)
 └── BACKGROUND SERVICES
     ├── Queue worker: the page job (Detect, Scan, Read)
     ├── Line detector: Kraken; finds and cuts the lines
     ├── OCR service: TrOCR; reads the lines
     └── Scheduler: deletes unsubmitted pages every hour
```

| Module | Used by | Main code |
| :--- | :--- | :--- |
| Access | Everyone | `app/Http/Controllers/Auth/`, `app/Http/Middleware/`, `app/Providers/AuthServiceProvider.php` |
| Dashboard | Everyone: Staff see their own work, Admin and Super Admin see analytics | `DashboardController.php`, `app/Services/DashboardAnalytics.php` |
| Digitization | New Document: Staff and Super Admin. Records Archive: everyone. Change Requests: Staff and Super Admin ask, Admin and Super Admin decide. | `DocumentScanController.php`, `DocumentPageController.php`, `RecordController.php`, `ChangeRequestController.php` |
| Oversight | Admin and Super Admin (an Admin manages Staff accounts only) | `ReportController.php`, `UserController.php`, `AuditLogController.php` |
| System setup | Super Admin | `DocumentTemplateController.php`, `DocumentTypeDefinitionController.php`, `OcrModelController.php`, `OcrUploadController.php` |
| Background services | Nobody directly; they start with the system | `app/Jobs/`, `ml/line_markers.py`, `ml/api/main.py`, `routes/console.php` |

#### 3.5.2 Structure by user

```
 CRMS
 └── SUPER ADMIN (the one account)
     ├── Dashboard
     ├── New Document: Document > Align > Verify > Submit
     ├── Records Archive: search, view, Correct record, Download CSV
     ├── Corrections: every correction, read-only
     ├── Reports and CSV export
     ├── Audit Log
     ├── OCR Workspace
     ├── Template Builder
     └── Account Settings: name, email, password
```

Nobody can edit a submitted record directly: **Correct record** saves a correction that keeps the old values
(see [4.8](#48-corrections)).

#### 3.5.3 Layer structure

CRMS follows Laravel's **Model-View-Controller (MVC)** pattern: **views** draw the screens, **controllers** handle each request, and **models** read and write the database. The business rules sit in a separate **service** layer, so no screen can go around them, and the AI work sits in its own Python layer.

```
 +-------------------------------------------------------------------------+
 |  PRESENTATION   what people see                                         |
 |  Blade views (resources/views), JavaScript (resources/js), styles       |
 |  (resources/scss); SNEAT on Bootstrap 5, PDF.js, ApexCharts             |
 +-------------------------------------------------------------------------+
 |  APPLICATION    what happens on each request                            |
 |  routes (routes/web.php) > middleware > permission check > controller   |
 |  (app/Http) > services: the business rules (app/Services) > background  |
 |  jobs (app/Jobs)                                                        |
 +-------------------------------------------------------------------------+
 |  DATA           what is kept                                            |
 |  models (app/Models) > MySQL tables (built by database/migrations);     |
 |  private files (storage/app/private)                                    |
 +-------------------------------------------------------------------------+
 |  AI             what finds and reads the handwriting (Python, ml/)      |
 |  line detector (line_markers.py + Kraken); OCR service (api/main.py +   |
 |  TrOCR); installed models (ml/models)                                   |
 +-------------------------------------------------------------------------+
   Outside CRMS: the Kaggle notebooks (finetuning-kaggle/) train the models.
```

**How one request passes through the layers**, using an Admin approving a correction as the example:

```
 Admin clicks Approve on a change request
   > routes/web.php           POST change-requests/{id}/approve
   > middleware               valid form token (CSRF)? signed in? account active?
                              password changed?
   > permission check         change-requests.moderate (Admin and Super Admin only),
                              checked by the route and again by the controller
   > ChangeRequestController  reads the optional note (up to 2,000 characters)
   > ChangeRequestService     locks the request, checks it is still pending,
                              writes the new values into the record,
                              marks the request approved
   > AuditLogger              writes change_request.approved with old and new values
   > models > MySQL           all of it in one database transaction
   < back to the request page: "Approved. The record has been updated."
```

#### 3.5.4 Folder structure

The main folders and files of the project. Anything marked "not in git" exists only on the computer where CRMS runs.

```
crms-laravel-12/
├── app/                                 Laravel application code (PHP)
│   ├── Console/Commands/                2 commands: hourly clean-up, training export
│   ├── Enums/                           fixed lists: roles, document types, statuses
│   ├── Exceptions/                      a refused change request, shown to the user
│   ├── Http/
│   │   ├── Controllers/                 17 controllers, one per screen area
│   │   ├── Middleware/                  account active? password changed?
│   │   └── Requests/                    form checks: sign-in, new and edited users
│   ├── Jobs/                            background job: page processing
│   ├── Models/                          15 models, one per database table
│   ├── Providers/                       the permission rules (AuthServiceProvider)
│   ├── Services/                        business rules: audit log, change requests,
│   │   │                                dashboard figures, user accounts, record grouping
│   │   ├── Lines/                       bridge to the line detector
│   │   └── Ocr/                         bridge to the OCR service, model registry
│   └── Support/                         helpers: sidebar menu, Philippine time, limits
├── bootstrap/                           start-up: middleware order, routes
├── config/                              settings; crms.php is CRMS's own, services.php
│                                        holds the OCR service and line detector settings
├── database/
│   ├── migrations/                      45 files that build the tables
│   ├── seeders/                         roles, first Super Admin, starter templates,
│   │                                    demo users
│   └── factories/                       made-up data for the tests
├── docker/                              Docker images: app (PHP + Kraken), ocr (TrOCR),
│                                        MySQL first-start script
├── docs/                                SYSTEM-OVERVIEW.md (this file), DEVELOPER-GUIDE.md,
│                                        DOCKER-SETUP.md, ALIGN-GRID-TASKS.md,
│                                        System-Evaluation.pdf
├── finetuning-kaggle/                   3 Kaggle notebooks: fine-tune, continue
│                                        fine-tuning, benchmark
├── ml/                                  the AI part (Python)
│   ├── api/main.py                      the OCR service (FastAPI + TrOCR)
│   ├── line_markers.py                  the line detector (Kraken)
│   ├── trocr_common.py                  loads a model; the confidence score
│   ├── metrics.py                       CER, WER, exact match
│   ├── train_trocr.py                   fine-tunes TrOCR on this computer
│   ├── test_finetuned.py                scores a model: CER, WER, exact match, time
│   ├── download_trocr.py                downloads Microsoft's base model
│   ├── setup_kraken.ps1                 builds the line detector's Python environment
│   ├── models/                          installed AI models (not in git, ~1.3 GB each)
│   └── datasets/                        local test sets (not in git)
├── public/                              the web root: index.php, built CSS and JS, logo
├── resources/
│   ├── views/                           39 Blade screens, by area (scan, records, ...)
│   ├── js/                              23 JavaScript modules + 5 SNEAT theme scripts
│   ├── scss/, css/                      SNEAT theme and CRMS styles, dark mode
│   └── fonts/                           icon font (Boxicons subset)
├── routes/
│   ├── web.php                          every web address and the permission it needs
│   └── console.php                      the hourly clean-up schedule
├── storage/app/private/                 private files (not in git): scans/, pages/,
│                                        records/, template-samples/, template-tests/
├── tests/
│   ├── Feature/                         23 PHP test classes
│   ├── JavaScript/                      13 JavaScript test files
│   └── Python/                          6 Python test files
├── tools/                               test-all.ps1 (runs every suite), build helpers
├── OCR-REDESIGN-VERSIONS/               design mock-ups (not used by the app)
├── docker-compose.yml                   the five Docker containers
├── docker-compose.gpu.yml               optional: NVIDIA card for the OCR service
├── docker-start.ps1 / .sh               build and start in Docker
├── serve.ps1                            starts the four processes on Windows
├── server.php                           lets the browser cache the built files
├── composer.json, package.json          PHP and JavaScript packages
├── vite.config.js                       how the CSS and JavaScript are built
├── phpunit.xml                          PHP test settings
└── .env.example                         template for the settings file (.env itself is not in git)
```

Where each feature's code is, file by file, is listed in [section 12](#12-where-things-are-in-the-code).

---

## 4. The screens and features

```
 CRMS sidebar (all for the one account, the Super Admin)
 ├── Dashboard
 ├── Digitization
 │   ├── New Document
 │   ├── Records Archive
 │   └── Corrections
 ├── Reports & history
 │   ├── Reports
 │   └── Audit Log
 └── System
     ├── OCR Workspace
     └── Template Builder

 Top bar: record search ("/" key), light / dark / system colours, account menu
          (Account Settings, Log out)
```

### 4.1 Sign-in and the account

- Sign in with email and password. There is no sign-up page.
- After **5 wrong attempts** for the same email from the same computer, sign-in is blocked for a short time, and the screen says how long.
- At the **first sign-in** (and after `php artisan crms:reset-password`) the account must choose its own password before it can open anything else.
- New passwords need **at least 8 characters, with letters and numbers**, and must differ from the current one.
- Sessions are stored in the database and last 120 minutes by default. Passwords are stored as bcrypt hashes.
- Every sign-in and sign-out is written to the audit log.

### 4.2 Dashboard

One dashboard. Each panel has its own reporting period (last 30 days, last 90 days, last 12 months, or custom dates; the default is 12 months).

| Panel | What it shows |
| :--- | :--- |
| Headline figures | Records archived (all time, this month, and in the period), the change against the previous period of the same length, and when the last record was submitted |
| New document | Which document types can be scanned now |
| **OCR Quality** | Average confidence of the AI readings, the **correction rate** (share of values changed while verifying) and how many values fell below the confidence threshold |
| **OCR Performance** | A radar chart comparing the active model with Microsoft's base model, from their test-set reports |
| **Digitization Volume & Trend** | Records archived per document type over time (by day for up to 90 days, by month beyond) |
| Archive by type | Records per document type |
| **Processing** | Detect and Scan runs that finished, failed or were cancelled; their average time; reading speed in milliseconds per line |
| **Corrections** | Corrections saved in the period, the records they changed, and how many fields |
| **Recent changes** | The latest 5 audit entries, leaving out routine sign-ins, submissions and CSV downloads |

Times are stored in UTC and shown in Philippine time (`Asia/Manila`) on every page and in the CSV export. A bad date typed into the address bar shows a message on that panel instead of an error page.

### 4.3 New Document, step 1: Document

The scan screen has three steps, shown at the top: **1 Document**, **2 Align**, **3 Verify**.

- Choose the **document type**. Only types with a published template can be scanned; the others are listed but disabled.
- Drop or choose the **scan**: a PDF up to 200 MB, or a PNG, JPG, WEBP or BMP image up to 20 MB. TIFF is refused, because Chrome and Edge cannot display it.
- A PDF is shown in the browser by PDF.js. **A PDF of many pages (a register book) is read one page at a time**: a page bar (‹ Page 3 of 12 ›) picks the page, each page is saved as its own record, and after Submit a window offers the next page without uploading the file again. The record keeps only its own page, cut out of the PDF in the browser with the original scan quality, plus the file's name and page number ("Page 3 of 12 · BIRTHS-1995.pdf" on the record).
- CRMS remembers which pages of that exact file are saved (by the file's SHA-256 fingerprint): saved pages get a ✓, the file opens at its first page not saved yet, and saving a page (or a one-page file) a second time asks first (**Go back** or **Save again**; the audit log notes a page saved again).
- CRMS remembers the last type used, so the next visit opens straight to it, ready for the next page of the same book.
- The screen shows whether the OCR service is online and which model is active.

### 4.4 New Document, step 2: Align

The template's **markers** (boxes for fields, columns for a ledger) are drawn over the page. Staff move them onto the right places. Markers are stored as fractions of the page, so they fit any scan size.

| Tool | What it does |
| :--- | :--- |
| Move, resize, tilt | Drag a marker; resize from any corner or side; tilt with the rotate knob or the `[` and `]` keys |
| Magnetic edges | An edge within 8 screen pixels of a printed line snaps onto it (hold Alt to place freely) |
| **Snap to table** | Fits the template's columns and rows onto the table printed on this page in under a second, without reading anything. It also measures how far the page is turned. |
| Rows and Columns card | Add or remove rows and columns for this page only (the template is not changed) |
| Row lines | For a ledger, one line per printed row; each can be dragged or tilted on its own |
| Tilt | Turns the whole table to match a slightly turned scan |
| Undo / Redo | Ctrl+Z and Ctrl+Y for every change |
| **Detect fields and ink** | Optional. Straightens the page, fits the grid, and outlines every handwritten line **without reading yet**, so Staff can check the outlines first (section 4.5) |
| **Scan with OCR** | Sends the page for line finding and reading. A progress window shows each stage, with **Cancel**. |

The page image is stored on the server when Staff press Detect or Scan with OCR (Snap to table sends it too, but keeps nothing). The original file is uploaded only at submission.

### 4.5 Detect: checking the outlines before reading

After **Detect**, every outlined line shows on the page, and Staff can fix the cuts before anything is read:

- **Stretch** an outline by its handles, move its **points**, or **draw** a new outline round the writing.
- **Delete line** removes a stray mark or a wrongly split word; Ctrl+Z puts it back.
- **Add line** draws a line the detector missed.
- **Save crop** re-cuts that one line on the server.
- Moving a marker after Detect sets Detect's result aside; Scan with OCR then outlines the page again from the new markers, reusing what Detect found (a register page: 48.5 s → 4.8 s).

### 4.6 New Document, step 3: Verify and submit

The page and the list of values sit side by side.

- Each value shows the **exact image the AI read**, the text it read, and its **confidence**.
- Values below the confidence threshold (default **80%**) are flagged for a closer look.
- Ledger rows are grouped as people: **Person 01**, **Person 02**, and so on. Certificates group fields by person too (child, mother, father, and so on).
- Each value is checked against its template settings: "This does not look like a date", "Expected one of: M, F". These hints never block a submission, because registers hold odd values.
- Lines that sit between two rows (`no_row`) or share a cell with another line (`shared_cell`) are marked **Needs review**.
- **Grid notes** at the top say when the grid may be wrong (for example, writing below the last row).
- Redrawing an outline re-cuts and re-reads that one line.
- **Add missed field**: draw a box over writing that was missed; it is read on its own, and Staff choose which person and column it belongs to.
- A line can be moved to another person's row or another column.
- Staff **tick** each value they have checked, and correct any that are wrong.

**Submit.** If a required field or a required register column is left out, CRMS lists what is missing and asks **Go back** or **Submit anyway**. The server repeats this check, so an edited form cannot skip it. Rows with nothing ticked are not saved.

On submission CRMS:

1. saves the original upload, creates the record as **Submitted** (locked), and saves every ticked value with both what the AI read and what the person approved,
2. copies the page image (straightened, if it was) and every line image into the record's own folder, so the archive keeps the very images the AI read,
3. writes a `record.submitted` audit entry: number of fields, how many were corrected, the model used, and any missing required fields the person accepted,
4. deletes the page's working files.

### 4.7 Records Archive

- **Search** by any verified value (the top bar's search, or press `/`), and filter by document type and submission date (Philippine days). 15 records per page.
- The **record page** shows the values grouped by person, and the page that was read with each value's box drawn on its handwriting. **Open full size** opens the original upload.
- It marks values that were corrected during verification and lists every later correction (**Change history**), field by field.
- **Correct record** changes values (see [4.8](#48-corrections)).
- **Download CSV** gives this document's checked values, corrections included, **one row per person**, for import
  into the client's old system. The columns follow the layout's field names, page 1 then page 2; fields that belong
  to no person (for example Page No.) repeat on every row. The two pages of a document join by row: row 5 of page 1
  and row 5 of page 2 are one CSV row. The file is UTF-8 with a byte-order mark, so Excel shows ñ and accents, and
  each download is in the audit log (`record.exported`).

### 4.8 Corrections

1. On a record, choose **Correct record**. Every value is shown beside a box for the right value. A **reason** is
   optional (up to 2,000 characters). Only values that really differ are kept.
2. **Save correction** changes the record at once. Required fields cannot be blanked; a save with no real change is refused.
3. The old and new values are kept: on the record's **Change history**, on the **Corrections** page (sidebar,
   read-only), and in the audit log (`record.corrected`).
4. Saving locks the record's database row first, so two saves of one record take turns.

In the database a correction is a change request saved as already approved, so older requests (from before
11 November 2026) and new corrections are listed together.

### 4.9 Reports

- Filter records by document type and date range; see a summary and a list (25 per page).
- **Export CSV** writes the matching records: ID, type, status, main value, created and submitted (time and person), OCR model, number of fields, average confidence.
- The file is written 200 records at a time straight to the download, so even the whole archive does not overload the computer.
- A cell that starts with `=`, `+`, `-` or `@` gets a leading `'`, so Excel shows it as text instead of running it as a formula ("CSV injection").
- Every export is written to the audit log with its filters (`report.generated`).

### 4.10 User Accounts (removed)

Removed on 11 November 2026: there is one account. Its name and email are changed in Account Settings
([4.14](#414-account-settings)); a forgotten password is reset with `php artisan crms:reset-password`.

### 4.11 Audit Log

- Every entry: when, who (the name at that time; entries from before 11 November 2026 keep their Staff and Admin names), what action, a description, the values before and after, IP address and browser.
- Filter by action and date range, and search the text. 25 entries per page.
- **Append-only**: there is no screen or route to edit or delete an entry, and the code throws an error if anything tries.
- Passwords and tokens are replaced by `[redacted]` before an entry is saved.
- [Appendix B](#appendix-b-audit-log-actions) lists every action.

### 4.12 Template Builder (Super Admin)

A **template** (also called a layout) tells CRMS where each value sits on a document type.

**Document types.** Birth, Death and Marriage are built in. The Super Admin can add custom types (name and icon), rename them, and **archive** a type (Staff can no longer start a scan of it; its records and layouts are kept) or restore it. Built-in types cannot be deleted, and neither can a custom type that has records.

**Two kinds of layout:**

| Kind | For | Made of |
| :--- | :--- | :--- |
| **Fields** | Certificates and forms | Rectangles, one per value (for example "Date of Birth") |
| **Ledger** | Register books | Column markers plus the printed **row lines**; each row is one person |

**Tools and rules:**

- **Sample document**: upload a scan of the real page to draw on. CRMS can suggest the ledger grid from the printed lines on the sample.
- **Field settings**: what the field **holds** (the person's name or the entry number), its **value type** (text, date, number, or one of a list of choices), a **hint** for Staff, and whether it is **required**.
- **Person grouping**: which fields belong to which person.
- **Grid checks**: row lines outside the columns or on top of each other stop the save; a field covering ledger cells is reported.
- **Versions**: a layout that is published, or that any record or page was read with, is never changed in place. Saving new markers or settings makes a **new version**, so every record keeps pointing at the exact layout it was read with. A save made from an outdated copy of the editor is refused.
- **One published layout per type.** Publishing a layout replaces the one Staff used before.
- **Publish checks**: a layout is published only with a sample page, real column names (no "Column 3") and a column or field that holds the person's name. The Publish window lists what is missing; the server checks the same.
- **Two pages**: page 2 of a spread is its own hidden layout that belongs to its main layout alone. Duplicates and new versions get their own copy, so changing one never changes another. It is not listed, published or deleted by itself.
- **Detect from sample** follows the page's tilt, leaves out lines below the table's frame, and shows guessed rows (faint or missing printed lines) in amber; saving with amber rows asks first.
- Duplicate makes a draft copy. A layout used by records cannot be deleted, and the published one cannot be deleted until another layout is published.
- No paper size to set: layouts store the page's shape (width ÷ height), and the Align step warns when a scan looks sideways.
- Leaving with unsaved changes asks first.

### 4.13 OCR Workspace (Super Admin)

This is where the AI models are managed. Training is not done here.

| Area | What it does |
| :--- | :--- |
| Service status | Shows whether the OCR service is online and whether it reads on the GPU or the CPU; checks again by itself |
| **Models** panel | Every installed model. **Activate** (with confirmation) makes it the one Staff scan with. **Rename**, **Delete** (not the active one), **Colour** (each model keeps one colour on every chart), **Rescan models** (re-reads the models folder) |
| **Add model** | Drop a `.zip` or a model folder (`config.json`, `model.safetensors`, tokenizer files, and optionally the two report files). The browser sends it straight to the OCR service with a signed ticket; CRMS then registers it. |
| **OCR model performance** | Radar or bar chart of character accuracy (1 − CER), word accuracy (1 − WER) and exact match, from each model's test-set report. Models are compared only when their reports used the same test set. Also shows reading speed from real scans. |

A fresh installation has **no model**, and CRMS never downloads one by itself. Scanning then says "No OCR model is installed" until a Super Admin adds one.

Two settings exist in the database but have **no screen** at present: letting Staff choose another model while scanning (`allow_staff_model_choice`, off), and the confidence threshold (set by `CRMS_CONFIDENCE_THRESHOLD` in `.env`, default 80).

### 4.14 Account Settings

Opened from the initials badge at the top right. **Your details** changes the account's name and email (the email must
be unused; the change is in the audit log as `user.updated`), and **Change password** changes the password.

### 4.15 Background jobs and command-line tools

| Command | What it does | When |
| :--- | :--- | :--- |
| `documents:prune-pages` | Deletes aligned pages nobody submitted, once untouched for 24 hours | Every hour, by the scheduler |
| `crms:export-training` | Exports verified line images and their corrected text as a TrOCR training CSV | By hand |
| `crms:export-training --test-set=<name>` | Exports the same lines as a local test set, to measure a model on **real** lines on this computer | By hand (not run yet; see [11.2](#112-limitations)) |
| `python ml/test_finetuned.py --model <name>` | Scores a model: CER, WER, exact match, reading time | By hand |
| `python ml/train_trocr.py` | Fine-tunes TrOCR locally (the Kaggle notebooks are the main way) | By hand |
| `python ml/download_trocr.py` | Downloads Microsoft's base model into `ml/models/base` | By hand |

---

## 5. How the work flows

### 5.1 Digitizing one page, start to finish

```
 STAFF                  WEBSITE                       QUEUE WORKER              OCR SERVICE
 -----                  -------                       ------------              -----------
 1 choose the type,
   drop the scan  --->  shows the page with the
                        template's markers
 2 align the markers
   (Snap to table, Tilt,
   rows and columns)
 3 Scan with OCR  --->  saves page.png and the
                        markers, status "queued",
                        puts a job in the queue  ---> 4 finds and outlines
                        and answers at once             every line (Kraken),
                                                        cuts each line image
                                                      5 sends the images,  ---> 6 reads 16 per pass,
                                                        40 per request          text + confidence
                                                      7 saves the readings <---
                                                        status "ready"
 8 the screen asks for
   progress and shows <---  lines and readings
   the readings
 9 checks, corrects,
   ticks every value
10 Submit         --->  checks again, creates the
                        record, copies the page and
                        line images, locks it,
                        writes the audit entry,
                        deletes the working page
```

### 5.2 Page states

Between Align and Submit, a page is a row in `document_pages` with a status:

```
                        Scan with OCR
 [queued] --> [detecting] -------------------------> [reading] --> [ready] --> Submit:
                  |                                      ^            |        record created,
                  | Detect                               |            |        page deleted
                  v                                      |            |
             [detected] ----- Scan with OCR -------------+            |
                  |           (reads Detect's crops)                  |
                  |                                                   |
                  +---- markers moved: outline again ----> [queued] <-+

 Cancel before it finishes --> [cancelled], and the page is deleted at once.
     (Cancelling the reading of a Detect result goes back to [detected] instead.)
 An error                  --> [failed], with a message; Staff scan the page again.
 Never submitted           --> deleted by the hourly clean-up after 24 hours.
```

### 5.3 The three job modes

The worker runs one job, `ProcessDocumentPage`, in one of three modes:

| Mode | Started by | What it does |
| :--- | :--- | :--- |
| `detect` | **Detect fields and ink** | Measures the page's tilt and straightens it, fits the template's grid to the printed table (position, scale and the page's own rows), then outlines and cuts every line. Reads nothing. |
| `full` | **Scan with OCR** without Detect, or after markers were moved | Keeps the markers where Staff put them (straightening the page only by the tilt Staff gave the table, and nudging row lines by at most half a row onto the printed lines), then outlines, cuts and reads every line. |
| `read` | **Scan with OCR** after Detect | Reads the line images Detect already cut. |

Every run, finished, failed or cancelled, adds a row to `page_runs` (who, mode, outcome, total time, reading time, number of lines, model). The dashboard counts from it, because the pages themselves are deleted. It holds no document text or images.

### 5.4 Correcting a locked record

```
 record page --> Correct record --> change values (reason optional) --> Save correction
                                                                            |
             record updated at once  <-- one database transaction  <--------+
             Change history + Corrections page + audit entry record.corrected (old and new values)
```

A correction is stored as a change request with the status `approved`. Requests from before 11 November 2026 may
also show `rejected` or `withdrawn`; those never changed the record.

### 5.5 Forgotten password

```
 Super Admin (on the CRMS PC)     CRMS                                  Sign-in page
 ----------------------------     ----                                  ------------
 php artisan crms:reset-password  gives the account a random 12-character
                                  temporary password, "must change
                                  password" on, audit entry
                                  user.password_reset
 sees the password once   <----   prints it (never stored in plain text)
                                                                        signs in with it
                                  blocks every page but "change   <---
                                  password"
                                  saves the new password, audit   <---  chooses a new one
                                  entry user.password_changed
```

### 5.6 A template's life

```
 create (draft) --> edit --> Publish --> Staff scan with it
                                                   |
                       change while in use: saved as a NEW VERSION (the old one keeps
                       its records); "Save as new draft" or "Save & publish new version"
```

### 5.7 An AI model's life

```
 KAGGLE (outside CRMS)              OCR WORKSPACE (Super Admin)          SCANNING
 ---------------------              ---------------------------          --------
 fine-tune TrOCR on a dataset  -->  Add model: the browser uploads  -->  Staff scan with the
 score it on the locked test        the ZIP to the OCR service;          ACTIVE model; each
 write evaluation-report.json       CRMS registers it and reads its      record stores which
 package model + reports (ZIP)      scores; Super Admin clicks Activate  model read it
```

### 5.8 Where files are kept and when they are deleted

All files are under `storage/app/private/` (outside the public web folder) and are served only through pages that check permission.

| What | Where | Created | Deleted |
| :--- | :--- | :--- | :--- |
| Page being worked on | `pages/{id}/`: `page.png`, `geometry.json`, `lines.json`, `detector.json`, `crops/` | When Detect or Scan starts | On submit (after copying), on cancel (at once), or after 24 hours untouched |
| Original upload | `scans/` | On submit | Never (records cannot be deleted) |
| Page as read, and line images | `records/{id}/page.png`, `records/{id}/crops/` | On submit | Never |
| Template sample | `template-samples/` | On upload | When replaced or deleted |
| Training export | `training-exports/<time>/`, or `ml/datasets/<name>/` | By the command | By hand |
| AI models | `ml/models/<name>/` (about 1.3 GB each, not in git) | Add model, or copied by hand | Delete in the OCR Workspace |

In Docker the same files live in `docker-data/storage/` in the project folder, and the database in a Docker volume.

---

## 6. The database

### 6.1 How the tables connect

```
                               +----------------+
                               | document_types |
                               +-------+--------+
                                       | 1
                                       | *
 +--------------------------+ * 1 +----+---------------+ 1   * +------------------+ 1  * +------------+
 | document_template_fields +-----+ document_templates +-------+ document_pages   +------+ page_lines |
 +--------------------------+     +----+---------------+       | (in progress)    |      +------------+
                                       | 1                     +------------------+
                                       | *
 +-------+ 1  * +-------+ 1  *    +----+-----+ 1     * +---------------+
 | roles +------+ users +---------+ records  +---------+ record_fields |
 +-------+      +---+---+         +----+-----+         +-------+-------+
                    |                  | 1                     | 1
                    |                  | *                     | *
                    |         +--------+--------+ 1   * +------+---------------+
                    |         | change_requests +-------+ change_request_items |
                    |         +-----------------+       +----------------------+
                    | 1
                    +---- * audit_logs, page_runs (and "who did it" on most tables)

 Stand-alone: ocr_models (the model registry), ocr_settings (one row)
```

`users` link to almost everything as "who created / submitted / asked / reviewed". `document_templates.parent_id` points at the version a layout was made from.

### 6.2 The tables

| Table | Holds | Important columns |
| :--- | :--- | :--- |
| `roles` | The three roles | `slug` (`staff`, `admin`, `super_admin`), `name`, `description` |
| `users` | Accounts | `name`, `email`, `password` (bcrypt hash), `role_id`, `must_change_password`, `is_active`, `last_login_at`, `created_by` |
| `document_types` | Kinds of document | `key`, `name`, `short_name`, `icon`, `is_system` (built-in), `archived_at` |
| `document_templates` | Layouts and their versions | `name`, `document_type_id`, `page_ratio`, `columns` (ledger column markers, JSON), `ruled_ys` (row line positions, JSON), `grouping_mode`, `sample_*`, `is_active` (published), `revision`, `parent_id` |
| `document_template_fields` | The rectangle fields of a layout | `name`, `x`, `y`, `width`, `height`, `angle`, `person_group`, `role` (name or entry number), `value_type`, `options`, `hint`, `is_required` |
| `records` | One submitted page | `document_type_id`, `document_template_id` (the exact version), `status` (`submitted`), `scan_path`, `page_image_path`, `scan_rotation`, `ocr_model_key`, `submitted_by`, `submitted_at` |
| `record_fields` | One value of a record | `name`, **`ocr_text`** (what the AI read), `ocr_confidence`, **`verified_value`** (what the person approved), `is_required`, `person_group`, `crop_path`, position (`x`, `y`, `width`, `height`, `polygon`), ledger cell (`line_column`, `line_row`, `line_flags`) |
| `change_requests` | Correction requests | `record_id`, `status`, `reason`, `requested_by`, `reviewed_by`, `reviewed_at`, `decision_note` |
| `change_request_items` | One proposed change | `record_field_id`, `current_value`, `proposed_value` |
| `document_pages` | A page between Align and Submit | `status`, `error`, `image_path`, `width`, `height`, `deskew_degrees`, `geometry` (markers, JSON), `notes` (grid notes), `ocr_model_key`, `created_by` |
| `page_lines` | One outlined line on a page | `source`, `column_name`, `row`, `polygon`, `baseline`, `bbox`, `crop_path`, `flags`, `ocr_text`, `ocr_confidence`, `adjusted_at`, `deleted_at` (kept for undo) |
| `page_runs` | One Detect or Scan run, for the dashboard | `user_id`, `mode`, `outcome`, `duration_ms`, `read_ms`, `lines`, `ocr_model_key`, `error` |
| `audit_logs` | The audit trail | `user_id`, `actor_name`, `actor_role`, `action`, `auditable_type`, `auditable_id`, `description`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at` |
| `ocr_models` | AI models CRMS knows | `key` (folder name), `label`, `color_slot`, `is_active`, `cer`, `wer`, `exact_match`, `evaluation_*` (dataset, split, sample count, hashes), `disk_deleted_at` |
| `ocr_settings` | Scanning settings (one row) | `allow_staff_model_choice`, `confidence_review_threshold` |

Laravel's own tables: `sessions`, `cache`, `cache_locks`, `jobs` (the queue), `job_batches`, `failed_jobs`, `migrations`.

### 6.3 Design notes

- **One record is one page.** A ledger page with 20 people is one record with up to 450 values, grouped by `person_group` (Person 01, 02, ...). Search finds the page, and the record page shows each person.
- **Both readings are kept.** `ocr_text` (the AI) and `verified_value` (the person) are stored side by side. That is how the correction rate is measured.
- **Positions are fractions of the page** (0 to 1), so a layout fits any scan resolution. Line outlines on a page in progress are in pixels.
- **Records point at the exact layout version** they were read with, and keep their own copy of every image the AI read.
- **The audit log copies the person's name and role** at the time, so history stays readable even if the account changes later.
- **Times are stored in UTC** and shown in Philippine time.
- **The document type is stored twice** (`document_type_id` and an older `doc_type` column). See [11.3](#113-risks-and-gaps-found-in-this-review).
- The database structure is built by **45 migration files** in `database/migrations/`.

---

## 7. The OCR and AI pipeline

OCR (optical character recognition) means turning a picture of text into text. CRMS does it in two stages: first **find** every handwritten line (Kraken), then **read** each line (TrOCR).

### 7.1 Why lines instead of boxes

The first version cut a fixed rectangle per field. On real register books that failed: handwriting drifts across the printed lines, and tall capitals and tails reach into the rows above and below. A rectangle cut letters off and picked up a neighbour's ink. TrOCR also reads one line at a time, so a box holding several lines came back empty or wrong.

CRMS therefore traces a **polygon around the ink of each line** and cuts the line out along it.

### 7.2 Finding the lines (Kraken)

The line detector is `ml/line_markers.py`. [Kraken](https://kraken.re) is an open-source OCR system built for historical documents; CRMS uses only its **baseline segmentation** neural network (`blla`, which ships inside the Kraken package, so nothing is downloaded).

For each page:

| Step | What happens |
| :--- | :--- |
| 1. Straighten (Detect) | Estimates how far the page is turned (search within ±10°, refined from the slope of the printed lines) and rotates it level, filling the corners with the paper's colour. Only when it is turned 0.1° or more. |
| 2. Find the printed table | Finds the printed horizontal and vertical lines, then fits the template's grid onto them (position and scale). Unless Staff set the rows by hand, the rows are taken from the page, so a page with more or fewer rows than the template still gets every row. |
| 3. Find the writing | Kraken finds the baseline and outline of every line of writing. It uses the GPU if there is one and falls back to the CPU. The outlines are traced in 4 processes at once (6.6 s → 2.3 s on a test page). |
| 4. Split at the columns | Kraken often joins neighbouring cells into one line, so lines are split at the column rules, in the gap nearest the rule. |
| 5. Assign rows | Each line is put in a row according to where writing usually sits in a row on this page. A line too far from any row (more than 0.35 of a row) is flagged `no_row` instead of guessed. |
| 6. Give every stroke to one line | A pen stroke that enters a line's core band belongs to that line, even if its capital or tail reaches into the next row. Other lines' strokes are cut out of the outline. |
| 7. Fill gaps | A cell with ink but no detected line (a single letter, an entry number) gets a line built from that ink. |
| 8. Free text | If a column's writing does not follow the rows at all (a list of diseases under a ledger template), that column is read as free text, line by line. |
| 9. Cut each line | Each line is cut along its polygon, other ink is masked out, and the background is filled with the paper's own tone with an 8-pixel margin. On two real register pages (440 cells) this read at 2.0% character error rate, against 3.1% with a white fill. |
| 10. Report | Flags (`no_row`, `shared_cell`) and grid notes (for example "written lines below the last row") are saved for the Verify step. |

What Kraken found is saved beside the page under a fingerprint of the image, so outlining the page again after markers move reuses it (register page: 48.5 s → 4.8 s).

Older layouts made of plain rectangles still work: each rectangle becomes a four-point polygon, and a rectangle holding several written lines is split into one field per line ("Diseases · line 1", "· line 2").

### 7.3 Reading the lines (TrOCR)

**TrOCR** ("Transformer-based OCR", Microsoft Research, Li et al.) reads a picture of one line and writes out its text. It has two halves:

| Part | What it does | Size (measured from the model files) |
| :--- | :--- | :--- |
| Image encoder | A Vision Transformer. Resizes the line image to 384 × 384 pixels, cuts it into 16 × 16 patches, and turns them into numbers describing the writing. 12 layers. | 86.7 million parameters |
| Text decoder | A text Transformer. Writes the text one token (word piece) at a time from those numbers. 12 layers, 50,265-token vocabulary. | 247.3 million parameters |
| **Total** | | **333.9 million parameters, 1.27 GB file** |

CRMS starts from `microsoft/trocr-base-handwritten` (Microsoft's model already trained on the IAM English handwriting dataset) and **fine-tunes** it: trains it further on its own data, so it adapts to the writing it will see (section [7.5](#75-training-the-model)).

**How CRMS reads:**

- The worker sends line images to the OCR service, **40 per request**. The service reads them **16 at a time** in one GPU pass (40 lines: 6.14 s → 2.99 s on an RTX 4050 laptop, with identical results).
- **Greedy decoding** (it always takes the most likely next token), at most **32 tokens** per line, full precision.
- A line image that cannot be read gets an empty reading and an error message; the other lines are still read.
- Every reading is saved with the model that made it.

### 7.4 The confidence score

For each reading, TrOCR gives a probability for every token it wrote. CRMS takes the **geometric mean** of those probabilities, up to the end of the line, as a percentage. One calculation (`ml/trocr_common.py`) is used everywhere.

**Confidence is the model's certainty in its own reading, not its accuracy.** A model can be confidently wrong. That is why the threshold (default 80%) only flags a value for a closer look; every value is still checked by a person.

### 7.5 Training the model

The active model was trained on **Kaggle** (a free online notebook service with GPUs) with `finetuning-kaggle/trocr-finetuning-code.ipynb`. Real registry pages were **not** used for training, because they are personal data and must not leave the office.

| Item | Value (from the model's `training-summary.json`) |
| :--- | :--- |
| Starting model | `microsoft/trocr-base-handwritten`, pinned to revision `eaacaf45…` |
| Dataset | `synthetic-handwritten-samples100k`: 100,000 synthetic (computer-generated) handwriting images with their text |
| Split | 80,000 training images, 10,000 **test** images (locked: never used for training or choosing), and a validation split (5,000 of its images scored after each epoch) |
| Image size | Median 113 pixels tall, about 2.7 times as wide as tall |
| Data checks | Missing images refused; exact copies of test images removed from training (none found); test texts that also appear in training counted (4,590 of 10,000, scored separately) |
| Picture variation | Training images only: narrower or wider writing, tilt up to 2.5°, extra margin, thicker or thinner ink, contrast, blur, JPEG compression |
| Settings | 2 epochs; batch 16 × gradient accumulation 8 (128 images per update); learning rate 2e-5 (decoder) and 1e-5 (encoder); 3% warmup then linear decay; weight decay 0.01; mixed precision; early stop after 1 epoch without improvement; labels up to 40 tokens; random seed 20261102 |
| Choosing the best epoch | Lowest validation CER: epoch 2 |
| Hardware and time | 2 × NVIDIA Tesla T4, 121 minutes |
| Output | A ZIP with the model, `evaluation-report.json` (its test scores), `base-evaluation-report.json` (Microsoft's model on the same test), `training-summary.json` and `test-errors.csv` |

| Epoch | Training loss | Validation CER | Validation exact match |
| :---: | ---: | ---: | ---: |
| 0 (before training) | – | 21.64% | 23.68% |
| 1 | 0.249 | 0.28% | 98.00% |
| 2 (kept) | 0.014 | 0.12% | 99.06% |

The validation split turned out much easier than the test split, for Microsoft's model too (21.6% CER on validation, 49.7% on test). **Quote the test scores, not the validation scores.**

Two more notebooks exist:

- `trocr-sequential-finetuning.ipynb` continues training an already fine-tuned model on new samples, mixing in old samples ("replay") so it does not forget, and compares the base, old and new models with a McNemar test before recommending UPLOAD or DO NOT UPLOAD.
- `trocr-benchmark-models.ipynb` trains nothing: it scores existing models on one test set so they can be compared fairly. The 50k model's and base model's reports below came from it.

### 7.6 How accuracy is measured

| Measure | Meaning | Better |
| :--- | :--- | :--- |
| **CER**, character error rate | Characters that must be added, removed or changed to turn the reading into the correct text, divided by the number of characters in the correct text, over the whole test set | lower |
| **WER**, word error rate | The same, counted in words. It can pass 100% when the model adds extra words. | lower |
| **Exact match** | Share of lines read exactly right, every character | higher |
| Character accuracy | 1 − CER (used on the OCR Workspace chart) | higher |

Example: correct text `MARIA CLARA`, reading `MARIA CLARE`. One character of 11 is wrong (CER 9.1%), one word of 2 is wrong (WER 50%), and it is not an exact match.

The counting uses the Levenshtein edit distance (`ml/metrics.py` and the notebooks).

### 7.7 Results

All three installed models were scored on **the same 10,000 synthetic test images** (the reports carry the same dataset name, sample count and manifest hash, so the comparison is fair):

| Model | CER | WER | Exact match | Character accuracy | Reading time per image (T4 GPU) |
| :--- | ---: | ---: | ---: | ---: | ---: |
| Microsoft base, not fine-tuned (`TrOcr-base-version-handwritten`) | 49.72% | 93.33% | 6.11% | 50.28% | 43.2 ms |
| Fine-tuned on 50k other synthetic samples (`TrOcr-50k-broken`) | 14.26% | 36.39% | 56.05% | 85.74% | 46.7 ms |
| **Fine-tuned on this dataset, active (`100k-finetuned-dataset-model`)** | **4.33%** | **15.45%** | **79.20%** | **95.67%** | 49.8 ms |

The active model on two halves of the test set:

| Test images | Count | CER | WER | Exact match |
| :--- | ---: | ---: | ---: | ---: |
| Text also appears in training (different image) | 4,590 | 1.85% | 3.23% | 96.27% |
| Text **never seen** in training | 5,410 | 5.35% | 20.32% | 64.71% |

Against Microsoft's model on the same images, the fine-tuned model got **7,320** images right that the base model got wrong, and **11** wrong that the base model got right (McNemar test, p < 0.001: the improvement is not chance).

**What these results show, and what they do not:**

- Fine-tuning made a large, real improvement **on this kind of synthetic image**.
- The 100k model was trained on the same dataset's training split; the other two were not. So the table says how well each model reads *this dataset's style*, not real ledgers.
- The fairest single figure for new text is the "never seen" half: 64.7% exact match, 5.35% CER.
- **No model has yet been scored on real registry lines.** The tool exists (`crms:export-training --test-set`, then `ml/test_finetuned.py`), but no real test set exists yet. The only real-page figure is the development measurement in step 9 of 7.2 (2.0% CER on 440 cells of two pages, with an earlier fine-tuned model), which was a design check, not a formal test.
- Confidence scores have not been checked against real accuracy (calibration).

### 7.8 Keeping benchmark numbers honest

The OCR Workspace and the Dashboard show a model's scores **only** from an `evaluation-report.json` inside the model folder. The OCR service accepts the report only if:

- it is schema version 1, measured on the `test` split, with a positive sample count,
- it names the dataset and the SHA-256 hash of the dataset's manifest file,
- it names the weights file and its **SHA-256 hash**, and the service hashes the real weights file and refuses the report if they differ. So scores from one training run cannot be attached to another model.

Two models are compared on the chart only when their reports name the same dataset, manifest hash and sample count. CRMS never computes "accuracy" from everyday scans, because Staff corrections are not a controlled test.

### 7.9 Speed

Measured during development (not a formal benchmark):

| What | Result | Where measured |
| :--- | :--- | :--- |
| Full Scan with OCR of a 220-line register page | 58 to 69 s in total (average 62 s), of which reading 12 to 28 s (about 81 ms per line) | 4 runs on the development laptop, 31 Oct to 3 Nov 2026 (`page_runs` table) |
| Reading 40 line images in batches instead of one by one | 6.14 s → 2.99 s | RTX 4050 laptop GPU |
| Keeping the decoder's cache while reading 215 lines | 17 s → 8 s, same readings | Development laptop |
| Tracing Kraken's outlines in 4 processes | 6.6 s → 2.3 s | Intel i5-14500HX, one register page |
| Kraken on the GPU instead of the CPU | about 24 s → 17 s per ledger page | RTX 4050 laptop GPU |
| Re-outlining after markers moved (reusing detection) | 48.5 s → 4.8 s (register page); 15.5 s → 0.4 s (small crop) | Development laptop |
| Without a GPU | about 2 minutes per page | Comment in `docker-compose.yml` |
| Snap to table | under a second | Code comment, server side |

---

## 8. Security, privacy and data integrity

### 8.1 Privacy

- **No internet, no cloud.** Every part runs on one computer. The website and the OCR service listen on `127.0.0.1` only, so other computers on the network cannot open CRMS. The AI model is never downloaded by itself.
- **Files are private.** Scans and line images are stored outside the public web folder and are only served to signed-in users with permission, with "no-sniff" headers.
- **Unfinished work is deleted.** A cancelled page is deleted at once. A page nobody submits is deleted after 24 hours by the hourly clean-up. A user can only see their own pages in progress; another user's page simply "does not exist" (404).
- **Training data stays local.** The training export writes only to this computer, and the code warns that it must not be uploaded to Kaggle or any other service.

### 8.2 Sign-in and permissions

- No public sign-up; accounts are created by Admins and Super Admins only.
- Passwords stored as bcrypt hashes; temporary passwords shown once, never stored in plain text, never logged.
- Sign-in limited to 5 attempts per email and computer; the session is renewed at sign-in.
- Forced password change for temporary passwords, on every page (a new page cannot forget it).
- Deactivated accounts are signed out and refused everything.
- Every route checks a named permission (Laravel "gates"), and the menu is built from the same permissions.

### 8.3 Protecting the records

- **No edit or delete route** exists for records, for anyone. Values change only through an approved change request.
- Change request decisions **lock the database row** first, so they cannot be decided twice.
- Required values cannot be blanked by a correction.
- The **audit log is append-only**: the code refuses to update or delete an entry, and passwords and tokens are redacted.
- A submission is saved in **one database transaction**: if anything fails, nothing is half-saved and the copied files are removed.
- Every record keeps the exact layout version and the exact images the AI read.

### 8.4 Between the website and the OCR service

- Every call carries the shared secret `X-CRMS-Service-Key`, compared in constant time. Without it the service answers 401. Only `/health` (is it up?) is open.
- Model uploads from the browser need a **signed ticket** (HMAC-SHA256 over the model name, user, expiry time and a random value), valid 15 minutes by default and at most 1 hour.
- Uploaded ZIPs are checked: at most 500 files and 12 GB unpacked (against "zip bombs"), only expected file types, and no file may land outside the models folder.

### 8.5 Checking what comes in

- Scans: a PDF of at most 200 MB opens in the browser, but only one page of it is sent; a record's scan (an image, or that one page) is at most 20 MB. The page sent for line finding must be a PNG of at most 40 MB.
- Markers must stay inside the page; at most 450 fields, 60 columns and 400 row lines per page.
- A bad value typed into an address bar (a list where one value belongs, an impossible date) shows a message instead of an error page.
- CSV cells that could run as Excel formulas are neutralized.

### 8.6 Known security gaps

| Gap | Why it matters | Suggested fix |
| :--- | :--- | :--- |
| The seeded Super Admin password is public and not forced to change | Anyone who reads the README can sign in on a fresh install | Force a change at first sign-in, or set it during installation |
| PHP 8.2.12 (XAMPP) | A security review on 25 Oct 2026 found it affected by a published critical PHP vulnerability fixed in PHP 8.2.35; PHP 8.2 security support ends 31 Dec 2026 | Update PHP (Docker: rebuild the image) |
| MySQL 9.3 on Windows | Out of support; the Windows MySQL also accepts network connections, though only `root@localhost` exists | Bind MySQL to 127.0.0.1; move to 9.7 (Docker already uses it) |
| No encryption at rest | The database and scan files are plain on disk | Turn on disk encryption (BitLocker on Windows, FileVault on Mac) |
| Audit log protected by the application only | Someone with direct database access could still change rows; there is no hash chain | Restrict database access; optionally chain entry hashes |
| A Super Admin can approve their own change request | Separation of duties is weaker for that one role | Refuse approval when the reviewer is the requester |
| Backups are manual | A disk failure could lose records | Schedule the backup in [DOCKER-SETUP.md](DOCKER-SETUP.md#backup-and-restore) (database dump plus the `docker-data` folder) |
| `php artisan serve` is a development web server | Fine for one local computer, not for a shared office server | Use Nginx or Apache with PHP-FPM if CRMS is ever shared on a network |

---

## 9. Testing and evaluation

### 9.1 Automated tests

All four suites were run on **3 November 2026** for this document:

| Suite | What it tests | Tests | Passed | Failed | Skipped | Time |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: |
| PHP (PHPUnit), 23 test classes | Website: permissions, workflows, records, reports, templates, OCR client | 346 | 343 | **3** | 0 | 61 s |
| JavaScript (Node test runner), 13 files | Screen logic: markers, ledger grid, line geometry, value checks, undo | 107 | 105 | **2** | 0 | – |
| Python, line detection (Kraken environment) | Line finding on synthetic pages and many ledger layouts | 87 | 86 | 0 | 1 (Linux only) | 106 s |
| Python, OCR service | Service key, batched reading, GPU fallback, report checks, no model installed | 19 | 19 | 0 | 0 | 6 s |
| **Total** | | **559** | **553** | **5** | **1** | |

**The 5 failing tests:**

| Test | Why it fails | Likely cause |
| :--- | :--- | :--- |
| `DocumentUploadWorkflowTest`: register rows left incomplete need confirmation; confirmed rows are listed in the audit log (2 tests) | The server did not ask for confirmation. The test's page has **no row lines**, and since commit `4dc9234` the check counts only rows inside the page's table. | The test data is out of date (a real ledger page always has row lines). Not yet checked in the browser. |
| `LedgerTemplateAndExportTest`: an existing rectangle template keeps working | The Template Builder page no longer has the element `ledgerGridHeading` | The builder screen was redesigned; the test still looks for the old element |
| `sneat-controls.test.js` (2 tests) | Some buttons do not use the theme's standard button style | Style rule checks, not behaviour |

None points to wrong data being saved, but they should be fixed or updated before the defense so the suite is green. The README badge ("477 passed", 24 Oct) is out of date. `tools/test-all.ps1` does not run `test_no_model_installed.py` (2 tests; included above).

### 9.2 What the tests cover

| Area | Example tests |
| :--- | :--- |
| Permissions | `CapabilityMatrixTest`: every row of the role table in 2.2, both the permission and the pages it guards |
| Sign-in and accounts | `AuthenticationTest`, `UserManagementTest`: temporary passwords, forced change, deactivation, Admin limited to Staff |
| Scanning and submitting | `DocumentUploadWorkflowTest`, `LineOutlinePipelineTest`: page job, Detect, outlines, manual fixes, missing required fields, TIFF refused |
| Records | `RecordArchiveFilterTest`, `RecordDetailPresentationTest`: search, filters by Philippine day, straightened page kept |
| Change requests | `ChangeRequestWorkflowTest`: approve, reject, withdraw, row locks, long notes |
| Reports | `ReportExportTest`: CSV injection, Philippine time in the CSV |
| Templates | `DocumentTemplateBuilderTest`, `TemplateVersioningTest`, `TemplateBuilderGridChecksTest`, `TemplateFieldSettingsTest` |
| Dashboard | `AnalyticsDashboardTest`: each panel's own period, bad periods in the address |
| OCR | `OcrClientTest` (the service key on every call), `OcrWorkspaceTest`, `OcrModelPerformanceTest`; Python: `test_service_key`, `test_ocr_batch`, `test_evaluation_report` |
| Line detection | `test_line_markers.py`, `test_grid_layouts.py`: ledgers with different columns, rows, headers and missing lines |

### 9.3 User evaluation instrument

[`docs/System-Evaluation.pdf`](System-Evaluation.pdf) is a ready-made evaluation kit based on **ISO/IEC 25010** (functional suitability, performance efficiency, usability) and the **System Usability Scale (SUS)**:

| Part | Contents |
| :--- | :--- |
| 1–2 | What is measured, and the plan (respondents, test documents, procedure) |
| 3 | 15 functional tasks marked Pass or Fail, a functional suitability score, and an OCR accuracy table per document type |
| 4 | Timing sheet: manual encoding time against system time for 10 documents, corrections made, time saved |
| 5 | Questionnaire: 15 statements (functionality, efficiency, usability) on a 5-point scale, plus the 10 SUS statements |
| 6 | Scoring: mean ranges and their verbal interpretation, the SUS formula |

The copy in the repository is **blank**. No respondent results are stored in the repository.

---

## 10. Installing and running

Full steps are in the [README](../README.md) and [DOCKER-SETUP.md](DOCKER-SETUP.md). In short:

**Docker (any computer):**

1. Get the code and copy `.env.example` to `.env`.
2. Put a model folder in `ml/models/` (or add it later in the OCR Workspace).
3. Run `docker-start.ps1` (Windows) or `docker-start.sh` (Mac/Linux), then `php artisan key:generate` and `php artisan migrate --seed` inside the `web` container.
4. Open http://localhost:8080, sign in, and activate the model in the OCR Workspace.

Daily use: `docker compose up -d` to start, `docker compose down` to stop. **Never run `docker compose down -v` on a computer with real records**: it deletes the database.

**Windows by hand:** install PHP, Composer, Node, MySQL and Python; build the two Python environments (`.venv` for the OCR service, `ml/.venv-kraken` for line detection); run `.\serve.ps1`, which checks everything and starts the website, worker, scheduler and OCR service. Open http://127.0.0.1:8000.

---

## 11. Review and analysis

### 11.1 Strengths

1. **A person checks every value.** The AI never writes to the archive on its own, and the server enforces it.
2. **Privacy by design.** Fully offline, private file storage, automatic deletion of unfinished pages, no cloud training on real data.
3. **Line outlines instead of rectangles** handle the real problems of register books: drifting handwriting, tails crossing rows, merged cells, tilted scans, and more or fewer rows than the template.
4. **Clear separation of duties** in one tested permission table: Staff encode, Admins approve, only the Super Admin configures.
5. **Strong traceability.** Every record keeps the AI reading next to the approved value, the exact images read, the model used and the exact layout version; every action is in an append-only audit log.
6. **Honest AI reporting.** Scores come only from locked test sets bound to the model file by hash; confidence is described as certainty, not accuracy; models are compared only on the same test set.
7. **Measured fine-tuning gain**: CER 49.7% → 4.3% and exact match 6.1% → 79.2% on the synthetic test set, with a significance test.
8. **Resilience**: slow work in the background, cancel at any point, GPU-to-CPU fallback, one bad line image never fails the page.
9. **Runs anywhere** through Docker (Windows, Linux, Mac), with an optional NVIDIA GPU.
10. **Large automated test suite** (559 tests across PHP, JavaScript and Python).

### 11.2 Limitations

| Limitation | Effect |
| :--- | :--- |
| **AI accuracy measured on synthetic data only** | The 79.2% exact match is not a measure of real ledger reading. A real-line test set has not been made yet. |
| Training data is synthetic | Real Filipino registry handwriting, ink, paper and scan quality were not in training. |
| Confidence not calibrated | A high confidence does not guarantee a correct reading. |
| One page at a time | A PDF of many pages is read page by page in the workspace (Next page after each save). Batch scanning of a whole book is being built on the `gono` branch. |
| One reading model for everything | Typed or printed text on a page is read by the same handwriting model; there is no separate engine for printed text. |
| One record per page | A ledger page with many people is stored as one record; search finds the page, not a single person's own record. |
| Only this computer can open it | Ports are bound to `127.0.0.1`, so several staff on different computers cannot use one installation as set up today. |
| Line-finding rules tuned on a few sample pages | Very different ledger layouts may need template adjustments or new rules. |
| Search uses simple text matching (`LIKE`) | Fine for hundreds of records; slower with many thousands. |
| No screen for two settings | The confidence threshold and Staff model choice can only be changed in `.env` or the database. |
| Processing history is short | Run times are recorded only since `page_runs` was added (1 Nov 2026), and only a few runs exist so far. |

### 11.3 Risks and gaps found in this review

1. **5 failing tests** ([9.1](#91-automated-tests)). Probably out-of-date tests, but the register-row check should be confirmed in the browser.
2. **Security items in [8.6](#86-known-security-gaps)**: public default Super Admin password, outdated PHP and MySQL, no encryption at rest, Super Admin self-approval, manual backups.
3. **Documentation drift.** The README and DEVELOPER-GUIDE still name `TrOcr-50k-broken-samples` as the model to install and quote its older scores, and still say "477 tests". The active model is now `100k-finetuned-dataset-model`.
4. **Technical debt** (does not affect users today):
   - The document type is stored twice (`document_type_id` and an older `doc_type` column with a fixed list).
   - Very large files: `resources/views/scan/workspace.blade.php` (5,621 lines, mostly JavaScript), `ml/line_markers.py` (3,193), `resources/js/template-builder.js` (2,032), `app/Http/Controllers/DocumentTemplateController.php` (1,184).
   - Old migrations create and later drop two unused tables (`ml_jobs`, `ml_datasets`).

### 11.4 Recommendations

In order of value for the defense and for real use:

1. **Measure accuracy on real lines.** Scan and verify a sample of real pages, run `php artisan crms:export-training --test-set=real-lines`, then score the base and active models with `ml/test_finetuned.py`. This is the most important missing evidence. Report it per document type, as Part 3 of the evaluation kit asks.
2. **Run the user evaluation** in `docs/System-Evaluation.pdf` (functional checklist, timing against manual encoding, questionnaire and SUS) with real registry staff.
3. **Fix or update the 5 failing tests** so the full suite passes.
4. **Close the quick security gaps**: force the Super Admin password change, update PHP, bind MySQL to 127.0.0.1, refuse self-approval, turn on disk encryption, schedule backups.
5. **Update the README and DEVELOPER-GUIDE** to the current model and test numbers.
6. Later: batch scanning (on the `gono` branch; multi-page PDFs are read page by page already), network access for several staff computers (with HTTPS), full-text search, splitting the largest files.

---

## 12. Where things are in the code

| Feature | Main files |
| :--- | :--- |
| All web addresses and their permissions | `routes/web.php` |
| Permission rules (who may do what) | `app/Providers/AuthServiceProvider.php`, `app/Enums/RoleSlug.php` |
| Sidebar menu | `app/Support/Navigation.php` |
| Sign-in, forced password change | `app/Http/Controllers/Auth/`, `app/Http/Middleware/`, `app/Http/Requests/Auth/LoginRequest.php` |
| Dashboard | `app/Http/Controllers/DashboardController.php`, `app/Services/DashboardAnalytics.php`, `resources/views/dashboard.blade.php`, `resources/js/dashboard-analytics.js` |
| Scan screen (Document, Align, Verify) | `app/Http/Controllers/DocumentScanController.php`, `resources/views/scan/workspace.blade.php`, `resources/js/field-marker.js`, `line-overlay.js`, `ledger-grid.js`, `row-lines.js`, `line-geometry.js` |
| Pages in progress, Detect, line fixes | `app/Http/Controllers/DocumentPageController.php`, `app/Jobs/ProcessDocumentPage.php`, `app/Services/Lines/` |
| Line detection | `ml/line_markers.py` |
| Reading service | `ml/api/main.py`, `ml/trocr_common.py`, `app/Services/Ocr/OcrClient.php` |
| Records archive | `app/Http/Controllers/RecordController.php`, `app/Services/RecordFieldGrouper.php`, `resources/views/records/` |
| Change requests | `app/Http/Controllers/ChangeRequestController.php`, `app/Services/ChangeRequestService.php` |
| Reports and CSV | `app/Http/Controllers/ReportController.php` |
| User accounts | `app/Http/Controllers/UserController.php`, `app/Services/UserProvisioner.php` |
| Audit log | `app/Services/AuditLogger.php`, `app/Models/AuditLog.php`, `app/Http/Controllers/AuditLogController.php` |
| Template Builder | `app/Http/Controllers/DocumentTemplateController.php`, `DocumentTypeDefinitionController.php`, `resources/js/template-builder.js` |
| OCR Workspace | `app/Http/Controllers/OcrModelController.php`, `OcrUploadController.php`, `app/Services/Ocr/`, `resources/js/ocr-workspace.js` |
| Clean-up and training export | `app/Console/Commands/`, `routes/console.php` |
| Database structure | `database/migrations/`, `app/Models/` |
| Training and scoring | `finetuning-kaggle/*.ipynb`, `ml/train_trocr.py`, `ml/test_finetuned.py`, `ml/metrics.py` |
| Docker | `docker-compose.yml`, `docker-compose.gpu.yml`, `docker/` |
| Tests | `tests/Feature/` (PHP), `tests/JavaScript/`, `tests/Python/`, `tools/test-all.ps1` |

---

## 13. Glossary

| Term | Meaning |
| :--- | :--- |
| **Align** | Step 2 of scanning: placing the template's markers on the scanned page |
| **Audit log** | The permanent list of who did what and when |
| **Baseline** | The imaginary line handwriting sits on; Kraken finds it for every line |
| **CER / WER** | Character / word error rate: how much of the text the model got wrong (lower is better) |
| **Change request** | A request to correct a locked record, decided by an Admin |
| **Confidence** | The model's certainty in its own reading (0–100%), not its accuracy |
| **Crop** | The small image of one line, cut from the page, that TrOCR reads |
| **Deskew** | Straightening a page that was scanned slightly turned |
| **Detect** | "Detect fields and ink": straighten, fit the grid and outline every line, without reading |
| **Docker** | A tool that runs each part of CRMS in its own ready-made "container", the same on every computer |
| **Epoch** | One full pass of training over the training images |
| **Exact match** | Share of lines read exactly right |
| **Fine-tuning** | Training an existing AI model further on new data so it fits a task better |
| **GPU** | Graphics card; it runs the AI much faster than the processor (CPU) |
| **Grid notes** | Warnings that the table grid may not fit the page |
| **Kraken** | Open-source software that finds lines of writing on a page |
| **Ledger / register** | A book of entries in ruled rows and columns; in CRMS each row is one person |
| **Marker** | A box or column on the page that says where a value is |
| **OCR** | Optical character recognition: turning a picture of text into text |
| **Outline / polygon** | The shape traced around one line of writing |
| **Person group** | The values that belong to one person (one ledger row, or the mother's fields on a certificate) |
| **Queue worker** | The background program that does slow work so the website stays fast |
| **Scheduler** | The background program that runs the hourly clean-up |
| **Service key** | The shared secret the website sends with every call to the OCR service |
| **SHA-256** | A fingerprint of a file; any change to the file changes it |
| **Snap to table** | Fitting the markers to the printed table in under a second |
| **Synthetic data** | Computer-generated images made to look like handwriting |
| **Template / layout** | The description of where each value sits on a document type |
| **Test split** | Images kept apart and never used for training, used only to score the model |
| **TrOCR** | Microsoft's Transformer-based model that reads one line of text from an image |
| **Validation split** | Images used during training to choose the best epoch |
| **Verify** | Step 3 of scanning: checking and ticking every value before submitting |

---

## Appendix A. Status codes, flags and notes

**Page status** (`document_pages.status`)

| Code | Meaning |
| :--- | :--- |
| `queued` | Waiting for the queue worker |
| `detecting` | Lines are being found and outlined |
| `detected` | Detect finished: outlines ready, nothing read yet |
| `reading` | TrOCR is reading the lines |
| `ready` | Read; ready for Verify |
| `failed` | Stopped with an error; scan again |
| `cancelled` | Cancelled by Staff; deleted by the worker |

**Line source** (`page_lines.source`)

| Code | Meaning |
| :--- | :--- |
| `kraken` | Found by Kraken |
| `ink` | Built from ink in a cell where Kraken found no line |
| `field` | A written line found inside a rectangle field |
| `template` | An older rectangle field passed through unchanged |
| `manual` | Drawn by hand (Add line, Add missed field) |

**Line flags**

| Code | Meaning |
| :--- | :--- |
| `no_row` | The line sits between two rows and could not be placed with confidence |
| `shared_cell` | Two or more lines ended up in the same cell |

**Grid notes** (shown in the Detect summary and at the top of Verify)

| Code | Meaning |
| :--- | :--- |
| `rows_uneven` | Some rows differ a lot in height from the template's |
| `lines_below_grid` | Written lines below the last row (they are not read) |
| `lines_above_grid` | Written lines in a row-sized band above the first row |
| `grid_moved` | Detect moved the grid by some rows |
| `rows_do_not_fit` | The writing does not follow the rows, so the columns were read as free text |
| `rows_empty` | Rows of the table with no writing |

**Change request status:** `pending`, `approved`, `rejected`, `withdrawn`. **Record status:** `submitted` (the only one; every record is locked when submitted).

## Appendix B. Audit log actions

| Area | Actions |
| :--- | :--- |
| Sign-in | `auth.login`, `auth.logout` |
| Accounts | `user.created`, `user.updated`, `user.password_changed`, `user.password_reset`, `user.activated`, `user.deactivated` |
| Records | `record.submitted` |
| Change requests | `change_request.opened`, `change_request.approved`, `change_request.rejected`, `change_request.withdrawn` |
| Reports and exports | `report.generated`, `training.exported` |
| Document types | `document-type.created`, `document-type.renamed`, `document-type.archived`, `document-type.restored`, `document-type.deleted` |
| Templates | `template.created`, `template.updated`, `template.versioned`, `template.duplicated`, `template.activated`, `template.deleted`, `template.sample-uploaded`, `template.sample-replaced`, `template.sample-deleted` |
| AI models | `ocr_model.added`, `ocr_model.registered`, `ocr_model.activated`, `ocr_model.renamed`, `ocr_model.color_changed`, `ocr_model.deleted`, `ocr_model.marked_missing`, `ocr_model.evaluation_imported`, `ocr_model.evaluation_cleared`, `ocr_settings.updated` |

## Appendix C. Main settings

Set in the `.env` file. Defaults from `.env.example`.

| Setting | Default | Meaning |
| :--- | :--- | :--- |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `crms`, `root`, empty | Database connection |
| `QUEUE_CONNECTION` | `database` | The job queue lives in the database |
| `SESSION_DRIVER`, `SESSION_LIFETIME` | `database`, `120` | Sign-in sessions and their length in minutes |
| `OCR_API_URL` | `http://127.0.0.1:8001` | Where the website reaches the OCR service |
| `OCR_BROWSER_API_URL` | `http://127.0.0.1:8001` | Where the browser uploads models |
| `OCR_API_TIMEOUT` | `120` | Seconds to wait for a reading |
| `OCR_DEVICE` | `auto` | `auto` (GPU if present), `cuda` or `cpu` |
| `OCR_PRECISION` | `fp16` | `fp16` (faster, half the GPU memory) or `fp32`; GPU only |
| `OCR_UPLOAD_SECRET` | empty (uses `APP_KEY`) | The shared secret: signs upload tickets and is the service key |
| `OCR_UPLOAD_TICKET_TTL` | `900` | Seconds an upload ticket is valid (at most 3,600) |
| `LINE_MARKERS_PYTHON` | empty (uses `ml/.venv-kraken`) | Python that runs the line detector |
| `LINE_MARKERS_TIMEOUT` | `600` | Seconds one page's line detection may take |
| `LINE_MARKERS_DEVICE` | `auto` | Where Kraken runs |
| `LINE_MARKERS_WORKERS` | `4` | Processes that trace Kraken's outlines |
| `LINE_MARKERS_KEEP_HOURS` | `24` | Unsubmitted pages older than this are deleted |
| `CRMS_CONFIDENCE_THRESHOLD` | `80` | Readings below this confidence are flagged |
| `CRMS_REPORTING_TIMEZONE` | `Asia/Manila` | Time zone for screens, filters and the CSV |
| `CRMS_SUPER_ADMIN_EMAIL`, `CRMS_SUPER_ADMIN_PASSWORD` | `superadmin@admin.com` | The first Super Admin, used only at installation |

## Appendix D. OCR service endpoints

| Method | Address | What it does | Needs |
| :--- | :--- | :--- | :--- |
| GET | `/health` | Is the service up, GPU or CPU, which models | nothing (open) |
| GET | `/models` | Installed models and their verified test scores | service key |
| POST | `/ocr` | Reads a list of line images (PNG, base64); returns text and confidence for each, in order | service key |
| POST | `/add_model` | Receives a model upload from the browser | signed upload ticket |
| POST | `/rename_model` | Renames a model folder | service key |
| POST | `/delete_model` | Deletes a model folder | service key |

Example reply from `/ocr`:

```json
{
  "results": [
    { "name": "line-1532", "text": "MARIA CLARA", "confidence": 96.8 }
  ],
  "model": "100k finetuned dataset model",
  "modelKey": "100k-finetuned-dataset-model"
}
```

## Appendix E. Numbers you can quote

| Figure | Value | Source | Say it carefully |
| :--- | :--- | :--- | :--- |
| Active model's character error rate | 4.33% | `ml/models/100k-finetuned-dataset-model/evaluation-report.json` | On 10,000 **synthetic** test images |
| Word error rate | 15.45% | same | same |
| Exact match | 79.20% (7,920 of 10,000) | same | same |
| Exact match on text never seen in training | 64.71% (CER 5.35%) | same, `test_subsets` | The fairest figure for new text |
| Microsoft base model on the same test | CER 49.72%, WER 93.33%, exact match 6.11% | the base model's report | Before fine-tuning |
| Improvement significance | 7,320 fixed, 11 broken, McNemar p < 0.001 | `training-summary.json` | Against the base model, same images |
| Training | 80,000 images, 2 epochs, 121 minutes on 2 × T4 | `training-summary.json` | Kaggle |
| Model size | 333.9 million parameters | counted from `model.safetensors` | |
| Reading time per line | 49.8 ms (T4 GPU, test set); about 81 ms (development laptop, real scans) | report; `page_runs` | Depends on hardware |
| Full scan of a 220-line register page | about 1 minute (58–69 s) | `page_runs`, 4 runs | Development laptop with GPU; about 2 minutes on CPU only |
| Paper-tone crop fill | 3.1% → 2.0% CER, 440 cells of 2 real pages | `ml/line_markers.py` comment | Design check with an earlier model, not a formal test |
| Automated tests | 559 (553 pass, 5 fail, 1 skipped) | run of 3 Nov 2026 | |

**Do not quote** averages from the local development database (about 55 test records made with several experimental models). They are not an evaluation sample.

## Appendix F. References to cite

Starting points for the manuscript. Check each against your school's citation style.

- Li, M., Lv, T., Chen, J., Cui, L., Lu, Y., Florencio, D., Zhang, C., Li, Z., & Wei, F. (2023). *TrOCR: Transformer-based Optical Character Recognition with Pre-trained Models.* Proceedings of the AAAI Conference on Artificial Intelligence, 37(11). (Preprint arXiv:2109.10282, 2021.)
- Kiessling, B. (2020). *A Modular Region and Text Line Layout Analysis System.* 17th International Conference on Frontiers in Handwriting Recognition (ICFHR). (Kraken's baseline segmenter.)
- Marti, U.-V., & Bunke, H. (2002). *The IAM-database: an English sentence database for offline handwriting recognition.* International Journal on Document Analysis and Recognition, 5, 39–46. (What `trocr-base-handwritten` was fine-tuned on.)
- Dosovitskiy, A., et al. (2021). *An Image is Worth 16x16 Words: Transformers for Image Recognition at Scale.* ICLR. (Vision Transformer, the encoder type.)
- Wolf, T., et al. (2020). *Transformers: State-of-the-Art Natural Language Processing.* EMNLP: System Demonstrations. (Hugging Face Transformers.)
- Paszke, A., et al. (2019). *PyTorch: An Imperative Style, High-Performance Deep Learning Library.* NeurIPS.
- Levenshtein, V. I. (1966). *Binary codes capable of correcting deletions, insertions, and reversals.* Soviet Physics Doklady, 10(8), 707–710. (Edit distance behind CER and WER.)
- McNemar, Q. (1947). *Note on the sampling error of the difference between correlated proportions or percentages.* Psychometrika, 12(2), 153–157.
- Brooke, J. (1996). *SUS: A "quick and dirty" usability scale.* In Usability Evaluation in Industry. Taylor & Francis.
- ISO/IEC 25010:2011 (revised 2023). *Systems and software Quality Requirements and Evaluation (SQuaRE): System and software quality models.*
- Republic Act No. 10173, *Data Privacy Act of 2012* (Philippines).
