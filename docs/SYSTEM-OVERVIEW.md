# CRMS: Complete System Overview

**Civil Registry Management System.** What it does, how it works, how it was built, how it was tested, and where it falls short.

| | |
| :--- | :--- |
| **Describes** | The code on branch `gono-latest-v1` at commit `40d36db`, 27 November 2026: the team's work up to `49d5ab9` plus the fixes made after this review (listed in [11.3](#113-risks-and-gaps-found-in-this-review)). Branch `main` (commit `6776cdb`, 25 November) does not have the work of 26–27 November yet. |
| **Based on** | The source code, the database structure, the installed AI models' own reports, the training datasets' own metadata, and a full test run on 27 November 2026 after the fixes |
| **Replaces** | The edition of 3 November 2026 (commit `e2167bc`) and its two later notes. [Changes since 3 November](#changes-since-3-november) lists what is new. |
| **Diagrams** | Plain text, so they show in VS Code's preview (Ctrl+Shift+V) and on GitHub without add-ons |
| **Related files** | [README](../README.md) (installation), [DEVELOPER-GUIDE](DEVELOPER-GUIDE.md) (developer reference), [DOCKER-SETUP](DOCKER-SETUP.md) (Docker), [System-Evaluation.pdf](System-Evaluation.pdf) (blank user-evaluation forms) |

> **About the numbers.** Every figure says where it comes from. "Measured" means a real run produced it. The AI model in
> use is now scored on **real ledger lines**, not only on computer-made ones. Those test lines come from the same pages
> as its training lines, so the headline score is optimistic. Section [7.7](#77-results) says which figure to quote and
> how.

## Contents

- [Changes since 3 November](#changes-since-3-november)
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
- [Appendix D. Service endpoints](#appendix-d-service-endpoints)
- [Appendix E. Numbers you can quote](#appendix-e-numbers-you-can-quote)
- [Appendix F. References to cite](#appendix-f-references-to-cite)

---

## Changes since 3 November

For anyone updating the manuscript: every change that a reader or user would notice, in date order (2026).

| Date | Change | Section |
| :--- | :--- | :--- |
| 5 Nov | A PDF of many pages (a register book) is read one page at a time, with a page bar; each page is its own record | [4.3](#43-new-document-step-1-document) |
| 9–10 Nov | **Two-page layouts**: a register spread (left page and right page) is aligned, verified side by side and saved as **one** record | [4.12](#412-template-builder) |
| 11 Nov | **One account only**, the Super Admin. Staff and Admin roles, User Accounts, approvals, deactivation and demo logins removed. **Test on sample** removed from the Template Builder | [2](#2-who-uses-it), [4.10](#410-removed-screens-and-features) |
| 13 Nov | Faster reading (half precision on the GPU) and line finding; a progress ring with the time left | [7.9](#79-speed) |
| 14 Nov | Template Builder: a layout in use is changed by saving a **duplicate**; a deleted layout that records used is **archived** and can be restored | [4.12](#412-template-builder) |
| 15 Nov | **Review & Export** (step 4) replaces Correct record and the Corrections page | [4.8](#48-review--export-step-4-and-editing-a-saved-record) |
| 18–21 Nov | **Batch File Upload** kept on the server: Scan all, Save & next, Save & finish batch, Resume, Re-align | [4.9](#49-batch-file-upload) |
| 22 Nov | Reports removed; Records Archive renamed **Saved Records**; optional **line finder service** keeps Kraken loaded (about 8 s less per page) | [3.1](#31-the-parts), [4.10](#410-removed-screens-and-features) |
| 23–25 Nov | **Register tables** `birth_records`, `death_records`, `marriage_records`: **Book No.** and **Page No.** entered in Align, step 4 shows the register's own columns, dates saved as YYYY-MM-DD, a register page is saved only once | [4.4](#44-new-document-step-2-align), [4.8](#48-review--export-step-4-and-editing-a-saved-record), [6](#6-the-database) |
| 24–25 Nov | New AI model in use, **`trocr-finetuned-final`**, trained partly on real ledger lines | [7.5](#75-training-the-model) |
| 25 Nov | **Forgot password?** with one-time recovery codes; name and email can be changed once; types renamed **Book of Birth / Death / Marriage**; **Smoke test scan** (Detect) hidden; each row line can be tilted on its own | [4.1](#41-sign-in-and-the-account), [4.5](#45-smoke-test-scan-detect-hidden) |
| 26 Nov | Saved Records: **Download one book as CSV**; Download all and Download ticked taken off that page. An unfinished batch blocks new uploads of its type, and **Resume** opens the page and step it was left on. Add column taken out of Review & Export | [4.7](#47-saved-records), [4.9](#49-batch-file-upload) |
| 27 Nov | Row lines can bend at each column (on screen); **column presets** and **Needed columns** in the Template Builder; duplicate file check removed; a register number may repeat on a page | [4.4](#44-new-document-step-2-align), [4.12](#412-template-builder) |
| 27 Nov (after this review) | Fixes: two tabs can no longer save the same book and page; the save message no longer names Correct record; an out-of-date test updated; all test files in the test runner; README and Developer Guide brought up to date; a training-data card and both training notebooks added to the repository | [11.3](#113-risks-and-gaps-found-in-this-review) |

---

## At a glance

| | |
| :--- | :--- |
| **What it does** | Turns handwritten civil registry books (births, deaths and marriages) into checked digital records. Each page is kept as a locked record with its scan, and each person on the page becomes one row of that book's register table. |
| **How** | The user scans a page, lines the layout up on it and types its book and page number. CRMS finds every handwritten line (Kraken) and an AI model reads each line (TrOCR). The user checks every value, reviews the page as a register table, and saves it. The record is locked and logged. |
| **Users** | One account, the Super Admin, who does everything. |
| **Where it runs** | Entirely on one computer. Nothing is sent to the internet while it runs, because the pages are personal data under the Data Privacy Act. |
| **Built with** | Laravel 12 (PHP 8.2), MySQL, Bootstrap 5 (SNEAT theme), Python FastAPI, PyTorch, Hugging Face TrOCR, Kraken 7.1, PDF.js, pdf-lib, ApexCharts, Docker. |
| **AI model in use** | `trocr-finetuned-final`: Microsoft's TrOCR base model (333.9 million parameters), fine-tuned first on 23,922 computer-made ledger-style images, then on 9,270 real ledger line images. |
| **Its test scores** | On 1,159 real ledger line images: character error rate 6.28%, word error rate 20.47%, exact match 79.2%. Microsoft's model before fine-tuning: 94.15%, 135.5% and 6.2% on the same images. On the 322 test lines whose text never appeared in training: 13.2%, 45.7% and 46.6%. |
| **Automated tests** | 783 tests: 781 pass, 2 skipped, none fail (run on 27 Nov 2026; see [9.1](#91-automated-tests)). |
| **Size** | 466 files in git. The largest are the scan screen (15,682 lines), the line detector (4,035), the Template Builder script (3,094) and the records table script (2,753). |

---

## 1. What CRMS is

### 1.1 The problem

Civil registry offices record births, deaths and marriages by hand, in register books (ledgers) and on certificates. To search those entries, count them, or correct one, someone has to read the handwriting and type it in. That is slow and easy to get wrong. The pages also hold personal data protected by the Data Privacy Act of 2012 (Republic Act No. 10173), so they cannot simply be sent to an online text-recognition service.

### 1.2 What CRMS does about it

CRMS is a web application that runs on one office computer. When the user scans a page, CRMS:

1. lines the page up with a **layout** (also called a template: where each value sits on that kind of book),
2. asks for the page's **Book No.** and **Page No.**, and says at once if that page of that book is already saved,
3. finds every handwritten line on the page and cuts it out,
4. reads each line with an AI handwriting model (**TrOCR**) and gives each reading a confidence score,
5. shows every reading beside the page, so the user can check and correct it,
6. shows the page as the **register's own table** (one row per person, the register's columns: name, date of birth, sex, and so on) to review, edit and download as CSV or Excel,
7. saves only the values the user ticked as checked, locks the record, writes each person as one row of the book's register table (`birth_records`, `death_records` or `marriage_records`), and writes the action to the audit log.

After that, records can be searched, viewed beside the original scan, downloaded one record or one whole book at a time, and edited. A locked record's values change only in **Review & Export**, which writes every old and new value to the audit log.

Many pages can also be done as a **batch**: queue a whole folder or book, line each page up, read them all at once, then check and save them one after another ([4.9](#49-batch-file-upload)).

### 1.3 What it is not

- **Not an online service.** All parts run on the same computer, and nothing is sent to the internet while it runs.
- **Not fully automatic.** The AI suggests; a person decides. Every saved value was checked by a person.
- **Not a births-and-deaths statistics system.** The dashboard counts digitization work (records saved, pages scanned), not civil events.
- **Not where the AI is trained.** Training happens outside CRMS, on Kaggle (notebooks in `finetuning-kaggle/`) or on a computer with a graphics card. The trained model is then installed into CRMS.

### 1.4 Design principles

These rules shape the whole system. Each one is enforced in code, not only in the screens.

| Principle | What it means | Where it is enforced |
| :--- | :--- | :--- |
| **Human in the loop** | Only values a person ticked are saved. Unticked values are dropped, and every register row is checked before it is saved. | `DocumentScanController::store` (every saved field must be marked verified), `RegistryFields::checkedRows` |
| **Local only** | Every part runs on one machine; the website, the OCR service and the line finder service listen on `127.0.0.1` only. | `docker-compose.yml`, `serve.ps1` |
| **Records do not change silently** | Saved records are locked. Their values change only in Review & Export, and each save logs every old and new value (`record.edited`). There is no delete route. | `routes/web.php`, `RecordReviewController` |
| **A register page is saved once** | A book and page that already has rows in its register table cannot be saved again, on a new scan or on an edit, not even from two tabs at once. | `DocumentScanController::refuseSavedPages`, `RecordReviewController::refuseOtherRecordsPages` |
| **One account, everything logged** | There is one account, so the protection is the audit log, not a second person: sign-ins, saves, edits, downloads, layout and model changes all go into it, and the code refuses to edit or delete an entry. | `AuthServiceProvider`, `AuditLogger`, `AuditLog` model |
| **Honest numbers** | AI scores are shown only from a test-set report tied to the exact model file. Confidence is labelled as the model's own certainty, not accuracy. | `ml/api/main.py`, `OcrModelPerformance` |
| **Slow work in the background** | Finding and reading the lines takes about 15 to 30 seconds a page, so it runs on a background worker and the website stays responsive. | `ProcessDocumentPage` job |

---

## 2. Who uses it

### 2.1 One account

CRMS runs on one offline PC with **one account, the Super Admin**. The person who signs in does everything: scans,
verifies, saves, edits saved records, builds layouts and manages the AI models. There are no other roles and no
screen for managing users. (Until 11 November 2026 there were three: Staff, Admin and Super Admin.)

| Account | Typical person | Main job |
| :--- | :--- | :--- |
| **Super Admin** | The registry's encoder or system administrator | Everything: New Document, Saved Records, Review & Export, Audit Log, Template Builder, OCR Workspace, Account Settings |

### 2.2 What the account can do

| Capability | Super Admin |
| :--- | :---: |
| Scan, verify and save records, one at a time or as a batch (New Document) | ✔ |
| Search and view the Saved Records | ✔ |
| Edit a saved record (Edit in Review & Export) and see its edit history | ✔ |
| Download one record as CSV, or its table as CSV or Excel | ✔ |
| Download one whole register book as CSV | ✔ |
| Dashboard with analytics | ✔ |
| View the audit log | ✔ |
| Template Builder | ✔ |
| OCR Workspace (AI models) | ✔ |
| Change own password; change name and email once; make recovery codes | ✔ |

Rules behind the table:

- Each page still checks a permission (`app/Providers/AuthServiceProvider.php`), and each permission asks only "is this
  the Super Admin?". An automated test checks that the account opens every page and a guest is sent to sign in
  (`tests/Feature/CapabilityMatrixTest.php`).
- There is **no sign-up page** and no User Accounts page. The account is created at installation.
- A saved record changes only in **Review & Export**, which logs the old and new values (see [4.8](#48-review--export-step-4-and-editing-a-saved-record)).

### 2.3 The account at installation

| Account | Created by | Sign-in |
| :--- | :--- | :--- |
| Super Admin | `php artisan migrate --seed` | `superadmin@admin.com`, first password `superadmin@admin.com`. At the first sign-in CRMS asks for a new password. Running the seeder again never resets an existing account. |

**Forgot the password?** Two ways back in:

1. On the sign-in page, **Forgot password?**: the email and one of the account's **recovery codes** (made in
   Account Settings), then a new password. Each code works once.
2. On the CRMS computer, `php artisan crms:reset-password`: it prints a new temporary password once, and at the next
   sign-in CRMS asks for a new one.

Both are written to the audit log.

---

## 3. How it is built

### 3.1 The parts

CRMS is five programs working together on one computer, plus an optional sixth that makes scanning faster.

```
 +-------------------------------------------------------------------------+
 |  WEB BROWSER  (the only part people touch)                              |
 +-----------------------------------+-------------------------------------+
                                     |  web pages and JSON
 +-----------------------------------v-------------------------------------+
 |  1. WEBSITE   Laravel 12 (PHP)                                          |
 |     screens, sign-in, checks, records, register tables, audit log       |
 +----------+-----------------------------------+--------------------------+
            |  adds a job to the queue          |  status checks and model
            |                                   |  actions (secret key)
 +----------v--------------+          +---------v--------------------------+
 |  2. QUEUE WORKER (PHP)  |  crops   |  4. OCR SERVICE  Python FastAPI    |
 |  Scan with OCR, one     +--------->|     TrOCR model on GPU or CPU,     |
 |  page at a time         |          |     returns text + confidence      |
 +----------+--------------+          +------------------------------------+
            |  finds and cuts the lines
 +----------v--------------------------+   +--------------------------------+
 |  LINE DETECTOR  Python + Kraken:    |   |  3. SCHEDULER (PHP)            |
 |  6. the LINE FINDER SERVICE when it |   |     every hour: deletes pages  |
 |  runs (keeps Kraken loaded, port    |   |     nobody saved and batches   |
 |  8002), else a program per page     |   |     discarded or left too long |
 +-------------------------------------+   +--------------------------------+

 Parts 1, 2 and 3 read and write  5. DATABASE (MySQL)  and the private file store
 (storage/app/private: scans, page images, line crops, queued batch files).
```

| # | Part | What it does | Started by (Windows) | Docker container |
| :--- | :--- | :--- | :--- | :--- |
| 1 | **Website** | Every screen and every permission check | `php artisan serve` (port 8000) | `web` (http://localhost:8080) |
| 2 | **Queue worker** | Runs the slow job: Scan with OCR (find the lines, read them) | `php artisan queue:listen --timeout=900 --tries=1` | `worker` (`queue:work`) |
| 3 | **Scheduler** | Every hour, deletes pages nobody saved (after 24 hours) and batches discarded or left open more than 7 days | `php artisan schedule:work` | `scheduler` |
| 4 | **OCR service** | Reads line images with TrOCR | `python -m uvicorn ml.api.main:app --port 8001` | `ocr` (port 8081) |
| 5 | **Database** | Stores the account, records, register rows, layouts, batches, audit log, job queue | MySQL (XAMPP or standalone) | `mysql` (no outside port) |
| 6 | **Line finder service** (optional) | Keeps Kraken loaded between pages, so each page's line finding is about 8 s faster, with the very same lines | `ml\.venv-kraken\Scripts\python.exe ml\line_markers_service.py --port 8002` | not used (the worker runs the program) |
| – | **Line detector** | Not a service: `ml/line_markers.py`, run by the worker for each page when the line finder service is not running, and always for Snap to table | `ml/.venv-kraken` (its own Python environment) | inside `worker` |

On Windows, `serve.ps1` checks the computer and starts parts 1 to 4, and part 6 when the Kraken environment exists. In
Kiro or VS Code, the project's tasks start each one in its own terminal tab.

### 3.2 Technology used

| Layer | Technology | Version installed | What it is used for |
| :--- | :--- | :--- | :--- |
| Web framework | Laravel | 12.64.0 | Routing, permissions, database access, queue, scheduler |
| Language | PHP | 8.2.12 (XAMPP) | The website, worker and scheduler |
| Database | MySQL | 9.3.0 (Windows), 9.7 (Docker) | All data |
| Screens | Blade templates, Bootstrap 5.3 with the SNEAT admin theme, SCSS | – | Page layout and styling |
| Front-end build | Vite 6, Tailwind 4, Sass | – | Bundles the CSS and JavaScript |
| Browser libraries | PDF.js 4.10, pdf-lib 1.17, write-excel-file 4.1, ApexCharts 4.2, Boxicons | – | Showing PDFs, cutting one page out of a PDF, Excel downloads, charts, icons |
| OCR service | Python, FastAPI 0.115.6, Uvicorn 0.34 | Python 3.13 | The reading service |
| AI framework | PyTorch 2.6.0 (CUDA 12.4), Hugging Face Transformers 5.14.1 | – | Running TrOCR |
| Line detection | Kraken 7.1.1, NumPy, Pillow, PyTorch 2.14 (CUDA 13.0) in its own environment | – | Finding handwritten lines |
| Training | Kaggle notebooks on 2 × NVIDIA Tesla T4; a laptop RTX 4050 (6 GB) | Transformers 5.24.0, PyTorch 2.11 and 2.14 | Fine-tuning TrOCR |
| Deployment | Docker Compose (5 containers), optional NVIDIA GPU add-on | – | Running on Windows, Linux or Mac |
| Testing | PHPUnit 11, Node.js test runner, Python unittest | – | Automated tests |

Kraken needs a newer PyTorch than the OCR service, which is why the line detector has its own Python environment.

### 3.3 How the parts talk to each other

| From | To | How | Protection |
| :--- | :--- | :--- | :--- |
| Browser | Website | Normal pages, forms, and JSON calls that poll for progress | Sign-in session, CSRF token, permission checks |
| Website and worker | OCR service | HTTP on the same computer | Header `X-CRMS-Service-Key`, a secret shared through `.env`. Every call but `/health` is refused without it (answer 401). |
| Worker | Line finder service | HTTP on `127.0.0.1:8002`: a page is handed over as a job, and the worker asks until it is done. Pages are done one at a time, in order. | The same service key. When the service does not answer, the worker runs the program itself (and stops asking for 2 minutes). |
| Worker | Line detector program | Starts `ml/line_markers.py`; they exchange files (`page.png`, `geometry.json` in; `lines.json` and crop images out) | Runs on the same computer; checked twice a second so a cancelled page stops it at once |
| Browser | OCR service | **Only** to upload a new AI model (about 1.3 GB), so the big file does not pass through PHP | A signed ticket from the website (HMAC-SHA256, expires after 15 minutes by default) |
| Website, worker, scheduler | Database | MySQL connection | Database password in `.env`; in Docker the database has no outside port |

**Why a queue worker?** On Windows, `php artisan serve` answers one request at a time, and one page takes tens of seconds. If the website did the work itself, every other screen would freeze meanwhile. So the website stores the page, puts a job in the queue and answers at once; the worker does the work; the browser asks for progress every few seconds.

**GPU when it works, CPU when it does not.** The OCR service uses an NVIDIA graphics card when there is one. If the card fails (old driver, out of memory), it switches to the processor and keeps reading, so no line is lost. Kraken does the same.

### 3.4 Two ways to run it

| | Docker | Windows, by hand |
| :--- | :--- | :--- |
| Who it is for | Any computer: Windows 10/11, Linux, Mac with Apple silicon | Developers |
| Needs | Git, Docker Desktop, about 20 GB free disk | PHP 8.2+, Composer, Node 20+, MySQL 8+, Python 3.10+ |
| Address | http://localhost:8080 | http://127.0.0.1:8000 |
| First start | 10 to 45 minutes (downloads several GB) | Manual install steps |
| Graphics card | Optional NVIDIA add-on (`docker-compose.gpu.yml`) | Used automatically if the CUDA build of PyTorch is installed |
| Line finder service | Not used | Started by `serve.ps1` or the editor task |
| Guide | [DOCKER-SETUP.md](DOCKER-SETUP.md) | [README](../README.md#manual-setup-windows-for-developers), [DEVELOPER-GUIDE](DEVELOPER-GUIDE.md) |

### 3.5 System structure

The same system, seen four ways. Use whichever view the chapter needs.

| View | Answers | Part |
| :--- | :--- | :--- |
| Module structure | What is the system made of? | [3.5.1](#351-module-structure) |
| Structure by user | What can the user open? | [3.5.2](#352-structure-by-user) |
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
 |    ACCESS    | |  DASHBOARD   | | DIGITIZATION | |   HISTORY    | | SYSTEM SETUP | |  BACKGROUND  |
 +--------------+ +--------------+ +--------------+ +--------------+ +--------------+ +--------------+
```

What each module contains:

```
 CRMS
 ├── ACCESS
 │   ├── Sign in and sign out (after 5 wrong attempts, a short wait)
 │   ├── Forgot password? (email + one-time recovery code, then a new password)
 │   ├── Forced password change (first sign-in, temporary passwords)
 │   └── Account Settings: password; name and email (once); recovery codes
 ├── DASHBOARD
 │   └── New document card, OCR Quality, OCR Performance, Volume and Trend,
 │       Archive by type, Processing, Edits after saving, Recent changes
 ├── DIGITIZATION
 │   ├── New Document
 │   │   ├── Step 1 Document: choose the type, Single Upload or Batch File Upload
 │   │   ├── Step 2 Align: place the markers (Snap to table, rows, columns,
 │   │   │   row lines, tilt), type the Book No. and Page No.
 │   │   ├── Scan with OCR: find the lines (Kraken), read them (TrOCR)
 │   │   ├── Step 3 Verify: check, correct, tick, redraw, add missed fields
 │   │   ├── Step 4 Review & Export: the register table, Columns → fields,
 │   │   │   CSV / Excel, Save record
 │   │   └── Batch mode: queue, Scan all, Save & next, Save & finish batch,
 │   │       Resume, Re-align, Download all
 │   └── Saved Records: search, filter (type, book, batch, dates),
 │       Download one book as CSV, view a record beside its scan,
 │       Edit in Review & Export, Download CSV
 ├── HISTORY
 │   └── Audit Log: view, filter, search
 ├── SYSTEM SETUP
 │   ├── Template Builder
 │   │   ├── Document types (books): add, rename, archive, restore, delete
 │   │   ├── Layouts: fields or ledger grid, one or two pages, sample page,
 │   │   │   field settings, people, row lines (tilt, length, bends),
 │   │   │   column presets, Needed columns
 │   │   └── Grid checks, publish checks, duplicates, archive and restore
 │   └── OCR Workspace
 │       ├── Service status (online or offline, GPU or CPU, fp16 or fp32)
 │       ├── Models: add, activate, rename, delete, colour, rescan
 │       └── Model performance chart (test-set scores), reading speed
 └── BACKGROUND SERVICES
     ├── Queue worker: the page job (Scan; Smoke test and Read when asked)
     ├── Line detector: Kraken; finds and cuts the lines
     │   (line finder service: the same, kept loaded; optional)
     ├── OCR service: TrOCR; reads the lines
     └── Scheduler: deletes unsaved pages and old batches every hour
```

| Module | Used by | Main code |
| :--- | :--- | :--- |
| Access | Everyone (the sign-in pages); the Super Admin | `app/Http/Controllers/Auth/` (sign-in, password change, password recovery), `AccountSettingsController.php`, `app/Http/Middleware/`, `app/Providers/AuthServiceProvider.php` |
| Dashboard | The Super Admin | `DashboardController.php`, `app/Services/DashboardAnalytics.php` |
| Digitization | The Super Admin | `DocumentScanController.php`, `DocumentPageController.php`, `ScanBatchController.php`, `RecordController.php`, `RecordReviewController.php`, `app/Support/RegistryFields.php` |
| History | The Super Admin | `AuditLogController.php`, `app/Services/AuditLogger.php` |
| System setup | The Super Admin | `DocumentTemplateController.php`, `DocumentTypeDefinitionController.php`, `OcrModelController.php`, `OcrUploadController.php`, `OcrEngineController.php` |
| Background services | Nobody directly; they start with the system | `app/Jobs/`, `ml/line_markers.py`, `ml/line_markers_service.py`, `ml/api/main.py`, `routes/console.php` |

#### 3.5.2 Structure by user

```
 CRMS
 └── SUPER ADMIN (the one account)
     ├── Dashboard
     ├── New Document: Document > Align > Verify > Review & Export > Save
     │   (one file at a time, or a batch)
     ├── Saved Records: search, filter, view, Edit in Review & Export,
     │   download one record or one book
     ├── Audit Log
     ├── OCR Workspace
     ├── Template Builder
     └── Account Settings: password, name and email (once), recovery codes
```

A saved record is edited only in **Review & Export**, which logs every old and new value
(see [4.8](#48-review--export-step-4-and-editing-a-saved-record)).

#### 3.5.3 Layer structure

CRMS follows Laravel's **Model-View-Controller (MVC)** pattern: **views** draw the screens, **controllers** handle each request, and **models** read and write the database. The business rules sit in a separate **service** layer, so no screen can go around them, and the AI work sits in its own Python layer.

```
 +-------------------------------------------------------------------------+
 |  PRESENTATION   what people see                                         |
 |  Blade views (resources/views), JavaScript (resources/js), styles       |
 |  (resources/scss); SNEAT on Bootstrap 5, PDF.js, pdf-lib, ApexCharts    |
 +-------------------------------------------------------------------------+
 |  APPLICATION    what happens on each request                            |
 |  routes (routes/web.php) > middleware > permission check > controller   |
 |  (app/Http) > services and rules (app/Services, app/Support) >          |
 |  background jobs (app/Jobs)                                             |
 +-------------------------------------------------------------------------+
 |  DATA           what is kept                                            |
 |  models (app/Models) > MySQL tables (built by database/migrations);     |
 |  private files (storage/app/private)                                    |
 +-------------------------------------------------------------------------+
 |  AI             what finds and reads the handwriting (Python, ml/)      |
 |  line detector (line_markers.py + Kraken, line_markers_service.py);     |
 |  OCR service (api/main.py + TrOCR); installed models (ml/models)        |
 +-------------------------------------------------------------------------+
   Outside CRMS: the notebooks (finetuning-kaggle/) train the models.
```

**How one request passes through the layers**, using a save in a saved birth record's Review & Export as the example:

```
 Super Admin clicks Save changes in Review & Export
   > routes/web.php           PATCH records/{id}/review
   > middleware               valid form token (CSRF)? signed in?
                              password changed?
   > permission check         records.edit (the Super Admin), checked by the route
   > RecordReviewController   checks each changed cell belongs to this record;
                              checks every register row as step 4 does (required
                              fields, digits, dates as YYYY-MM-DD, choices) and that
                              the book and page are not another record's;
                              skips values that did not really change
   > models > MySQL           writes the changed values and register rows,
                              in one database transaction
   > AuditLogger              writes record.edited with every old and new value
   < back to the record page: "3 values saved."
```

#### 3.5.4 Folder structure

The main folders and files of the project. Anything marked "not in git" exists only on the computer where CRMS runs.

```
crms-laravel-12/
├── app/                                 Laravel application code (PHP)
│   ├── Console/Commands/                5 commands: hourly clean-up, training export, password
│   │                                    reset, unlock name and email, register dates to YYYY-MM-DD
│   ├── Enums/                           fixed lists: the role, document types, record status
│   ├── Http/
│   │   ├── Controllers/                 16 controllers, one per screen area (3 of them for sign-in)
│   │   ├── Middleware/                  password changed?
│   │   └── Requests/                    the sign-in form check
│   ├── Jobs/                            background job: page processing
│   ├── Models/                          20 models, one per database table
│   ├── Providers/                       the permission rules (AuthServiceProvider)
│   ├── Services/                        business rules: audit log, record CSV, dashboard figures,
│   │   │                                record grouping, layout samples
│   │   ├── Lines/                       bridge to the line detector and the line finder service
│   │   └── Ocr/                         bridge to the OCR service, model registry
│   └── Support/                         rules and helpers: register fields and dates
│                                        (RegistryFields), Excel writer, CSV cell guard, sidebar
│                                        menu, Philippine time, limits
├── bootstrap/                           start-up: middleware order, routes
├── config/                              settings; crms.php is CRMS's own, services.php
│                                        holds the OCR service and line detector settings
├── database/
│   ├── migrations/                      69 files that build the tables
│   ├── seeders/                         the role, first Super Admin, starter layouts
│   └── factories/                       made-up data for the tests
├── docker/                              Docker images: app (PHP + Kraken), ocr (TrOCR),
│                                        MySQL first-start script
├── docs/                                SYSTEM-OVERVIEW.md (this file), DEVELOPER-GUIDE.md,
│                                        DOCKER-SETUP.md, TRAINING-DATA.md, System-Evaluation.pdf,
│                                        and the plans behind recent work (SUPER-ADMIN-ONLY-PLAN.md,
│                                        BATCH-IMPROVEMENT-PLAN.md, MULTI-PAGE-PDF-BATCH-NOTE.md,
│                                        LAYOUT-FLOW-FIX-PLAN.md, ALIGN-GRID-TASKS.md)
├── finetuning-kaggle/                   4 notebooks (Kaggle or a local GPU): fine-tune from the
│                                        base model, continue a fine-tuned model, continue with
│                                        replay, benchmark
├── ml/                                  the AI part (Python)
│   ├── api/main.py                      the OCR service (FastAPI + TrOCR)
│   ├── line_markers.py                  the line detector (Kraken)
│   ├── line_markers_service.py          the line finder service (keeps Kraken loaded)
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
│   ├── views/                           36 Blade screens, by area (scan, records, ...)
│   ├── js/                              29 JavaScript files
│   ├── scss/, css/                      SNEAT theme and CRMS styles, dark mode
│   └── fonts/                           icon font (Boxicons subset)
├── routes/
│   ├── web.php                          every web address and the permission it needs
│   └── console.php                      the hourly clean-up schedule
├── storage/app/private/                 private files (not in git): scans/, pages/,
│                                        documents/workspace/ (batch queue), records/,
│                                        template-samples/
├── tests/
│   ├── Feature/                         34 PHP test classes
│   ├── Unit/                            1 PHP test class (register dates)
│   ├── JavaScript/                      15 JavaScript test files
│   └── Python/                          7 Python test files
├── tools/                               test-all.ps1 (runs the suites), build helpers
├── OCR-REDESIGN-VERSIONS/               design mock-ups (not used by the app)
├── docker-compose.yml                   the five Docker containers
├── docker-compose.gpu.yml               optional: NVIDIA card for the OCR service
├── docker-start.ps1 / .sh               build and start in Docker
├── serve.ps1                            starts the processes on Windows
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
 │   └── Saved Records (each record: Edit in Review & Export)
 ├── History
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
- **Forgot password?** on the sign-in page: type the email and one of the account's **recovery codes** (10 letters and digits, like `ABCDE-12345`), then
  choose a new password. The code is used up. After **5 wrong codes** for an email the page waits an hour. A wrong
  email and a wrong code get the same answer, so the page does not reveal which email exists. When 2 codes or fewer are
  left, CRMS says to make a new set.
- New passwords need **at least 8 characters, with letters and numbers**, and must differ from the current one.
- Sessions are stored in the database and last 120 minutes by default. Passwords are stored as bcrypt hashes.
- Every sign-in, sign-out, recovery and failed recovery code is written to the audit log.

### 4.2 Dashboard

One dashboard. Each panel has its own reporting period (last 30 days, last 90 days, last 12 months, or custom dates; the default is 12 months).

| Panel | What it shows |
| :--- | :--- |
| **New document** | How many document types can be scanned now ("3 of 3 ready", naming any type without a published layout), records added this month, when the last one was added, and **Start scanning** |
| **OCR Quality** | Average confidence of the AI readings, the **correction rate** (share of values changed while verifying) and how many values fell below the confidence threshold |
| **OCR Performance** | A radar chart comparing the model in use with Microsoft's base model, from their test-set reports |
| **Digitization Volume & Trend** | Records saved per document type over time (by day for up to 90 days, by month beyond), and the period's total against the previous period of the same length |
| Archive by type | Records per document type |
| **Processing** | Scan runs that finished, failed or were cancelled; their average time; reading speed in milliseconds per line |
| **Edits after saving** | Review & Export saves on saved records in the period, the records they changed, and how many fields (from the `record.edited` audit entries; **All edits** opens them in the audit log) |
| **Recent changes** | The latest 5 audit entries, leaving out routine sign-ins, saves and downloads |

Times are stored in UTC and shown in Philippine time (`Asia/Manila`) on every page and in the CSV downloads. A bad date typed into the address bar shows a message on that panel instead of an error page.

### 4.3 New Document, step 1: Document

The scan screen has four steps, shown at the top: **1 Document**, **2 Align**, **3 Verify**, **4 Review & Export**.

- Choose the **document type**: **Book of Birth**, **Book of Death**, **Book of Marriage** (renamed from "… Certificate"
  on 25 November 2026), or a custom type. Only types with a published layout can be scanned; the others are listed but
  disabled.
- Choose the **upload mode**: **Single Upload** (one document at a time) or **Batch File Upload** (many files, or a
  folder; see [4.9](#49-batch-file-upload)).
- Drop or choose the **scan**: a PDF (up to 1 GB opens in the browser; only the page being read is sent), or a PNG,
  JPG, WEBP or BMP image up to 20 MB. TIFF is refused, because Chrome and Edge cannot display it.
- **A PDF of many pages (a register book) is read one page at a time**: a page bar (‹ Page 3 of 12 ›) picks the page.
  Each page is its own record, or each pair of pages when the layout has a page 2. After saving, the next page opens
  without uploading the file again. The record keeps only its own page, cut out of the PDF in the browser with the
  original scan quality, plus the file's name and page number ("Page 3 of 12 · BIRTHS-1995.pdf").
- **One unfinished batch at a time**: while an unfinished batch (or a single scan kept for later) of the chosen type is
  waiting, nothing new can be uploaded for that type until it is **Resumed** or **Discarded**. The waiting batch is shown
  with both buttons.
- CRMS remembers the last type used, so the next visit opens straight to it, ready for the next page of the same book.
- The screen shows whether the OCR service is online and which model is active.

Files are not checked for copies (the duplicate file check was removed on 27 November 2026): a register page is
protected from being saved twice by its book and page number instead (step 2).

### 4.4 New Document, step 2: Align

The layout's **markers** (boxes for fields, columns for a ledger) are drawn over the page. The user moves them onto the right places. Markers are stored as fractions of the page, so they fit any scan size.

| Tool | What it does |
| :--- | :--- |
| Move, resize, tilt | Drag a marker; resize from any corner or side; tilt with the rotate knob or the `[` and `]` keys |
| Magnetic edges | Markers move freely; with Alt held, an edge within 8 screen pixels of a printed line snaps onto it |
| **Snap to table** | Fits the layout's columns and rows onto the table printed on this page in about a second, without reading anything. It takes the rows from the page, measures how far the page is turned and turns the grid with it, follows printed rules that bend, and fills in up to three faint missing rules (drawn amber). |
| Rows and Columns card | Add or remove rows and columns for this page only (the layout is not changed) |
| Row lines | One line per printed row. Drag a line by its grip (it stays where it is let go; Alt snaps it to a printed line; Shift moves every line). Each line can also be **tilted on its own** (select it, then Tilt), made shorter or longer, and **bent at each column** with the small squares at a column's ends, for curved paper. The page starts from the row lines drawn in the Template Builder. |
| Tilt | Turns the whole table, or only the selected row line, to match a slightly turned scan |
| Grid lines switch | Shows or hides the grid over the page |
| Undo / Redo | Ctrl+Z and Ctrl+Y for every change |
| **Document Reference** | **Book No.** and **Page No.**, both required, digits only. As soon as both are typed, CRMS says whether that page of that book is already saved ("Already saved", with a link to the record) and, in a batch, whether another record of the batch uses it. Both pages of a spread share one page number. The book number list offers the books already saved. |
| **Scan with OCR** | Sends the page for line finding and reading. A progress window shows each stage, with a ring paced by the last few scans, the time left, and **Cancel**. |

Bends and lengths of row lines are for lining up by eye: each row line is still read at one height (across the middle
of the page).

The page image is stored on the server when the user presses Scan with OCR (Snap to table sends it too, but keeps nothing). The original file is uploaded when the record is saved (in a batch, when the page is queued).

### 4.5 Smoke test scan (Detect): hidden

Until 25 November 2026 the Align step had a second button, **Detect fields and ink** (renamed **Smoke test scan** on
12 November). It straightened the page, fitted the grid and outlined every handwritten line **without reading**, so the
outlines could be fixed first: stretch an outline, move its points, draw a new one, **Delete line**, **Add line**,
**Save crop**.

The button is now hidden. Its code is still in place (the job's `detect` mode, the line tools in Align, the routes),
so it can be brought back, but users can no longer reach it. Outlines are fixed **after** reading instead, in Verify
([4.6](#46-new-document-step-3-verify)).

### 4.6 New Document, step 3: Verify

The page (**Original document**) and the list of values (**Digital text output**) sit side by side. A two-page spread
shows both pages side by side, and clicking a value glides the page to its writing.

- When the page is read, CRMS checks whether its values match a record already saved. If so, Verify asks
  (**This document may already be saved**, with a link to that record) instead of refusing the scan; **Continue anyway**
  goes on as usual.
- Each value shows the **exact image the AI read**, the text it read, and its **confidence**.
- Values below the confidence threshold (default **80%**) are flagged for a closer look.
- Ledger rows are grouped as people (**Registry records**: Person 01, Person 02, and so on), each titled by the field
  that holds the name. Certificates group fields by person too (child, mother, father, and so on). **Verify all** ticks
  every value at once.
- Each value is checked against its layout settings: "This does not look like a date", "Expected one of: M, F". These hints never block a save, because registers hold odd values.
- Lines that sit between two rows (`no_row`) or share a cell with another line (`shared_cell`) are marked **Needs review**.
- **Grid notes** at the top say when the grid may be wrong (for example, writing below the last row).
- **Adjust outline** (Draw or Rectangle) redraws one line's outline; that line is re-cut and re-read.
- **Delete crop** removes a wrong crop: **Draw again**, then **Save and re-read**. A deleted crop that is not drawn again
  is left out of the record.
- **Add missed field**: draw (or drag a rectangle) over writing that was missed, then **Read boxes**; it is read on its
  own, and the user chooses which person and column it belongs to.
- **Group fields** → **Group as person**: pick fields on the page or in the list and make them a new person.
- A line can be moved to another person's row or another column.
- The user **ticks** each value they have checked, and corrects any that are wrong.
- **Re-align** (batch only) sends the whole document back to Align; it is read again once queued.

Verify ends with **Review & export**, which opens step 4. The record is saved from there
([4.8](#48-review--export-step-4-and-editing-a-saved-record)).

**When a record is saved.** If a required field or a required register column is left out, CRMS lists what is missing
and asks **Go back** or **Submit anyway**. The server repeats every check, so an edited form cannot skip it. Rows with
nothing ticked are not saved. Then CRMS:

1. for Birth, Death and Marriage, checks every row of the step 4 table ("Row 06: reg_no is required.") and refuses a
   book and page that is already saved; it asks again inside the save, one save of a book type at a time, so two
   tabs saving the same page at the same moment cannot both get through,
2. saves the original upload (each page of a spread keeps its own), creates the record as **Submitted** (locked), and
   saves every ticked value with both what the AI read and what the person approved,
3. copies the page image (straightened, if it was) and every line image into the record's own folder, so the archive
   keeps the very images the AI read,
4. for Birth, Death and Marriage, writes each row of the step 4 table into the book's register table, linked to the
   record,
5. keeps the step 4 **Columns → fields** choices on the layout, so the next page starts the same way,
6. writes a `record.submitted` audit entry: book and page, number of fields, how many were corrected, the model used,
   any missing required fields the person accepted, and rows or columns added or removed in Align,
7. deletes the page's working files (and a batch page's queued original).

All of steps 2 to 6 happen in one database transaction: if anything fails, nothing is half-saved and the copied files
are removed.

### 4.7 Saved Records

- **Search** by any verified value or by the name of the file a record came from (the top bar's search, or press `/`).
  **Filter** by document type, by **Book** (Birth, Death and Marriage: the book numbers saved), by the **batch** that
  saved it (or **Single files only**) and by submission date (Philippine days). 15 records per page. Filters, search and
  the pages of results load in place, without reloading the page.
- Each row is named by its file and pages (a register page also says how many people it holds and its first and last
  entry number), and a **Source** column says whether a batch or a single file saved it (records saved before batches
  were kept show "—").
- **Download one book as CSV**: pick the type (Birth, Death or Marriage) and a book number. One CSV file, one row per
  register entry, sorted by page and then register number, with the register's own columns. Dates are YYYY-MM-DD, text
  is in capitals, late registration is 1 or 0, and the file is UTF-8 with a byte-order mark, so Excel shows ñ and
  accents. Each download is one audit entry (`record.exported`).
- The **record page** shows the values grouped by person (**Verified data**), each named by entry number and name
  ("1978-0001 · Ramonte T. Abad"), dates shown as YYYY-MM-DD, and the page that was read with each value's box drawn on
  its handwriting. **Find a person or value** narrows the list, and **Open all / Close all** opens every person. Its
  top bar names the file and pages ("Pages 1–2 of 2 · register.pdf"). **Open full size** opens the original upload.
- It marks values that were corrected during verification, notes under a value when it was last edited ("Edited 2
  days ago"), and lists every later save (**Edit history**: who, when and which values, with a link to the audit log).
- **Edit in Review & Export** changes values (see [4.8](#48-review--export-step-4-and-editing-a-saved-record)).
- **Download CSV** gives this document's checked values, edits included, **one row per person**, for import into the
  client's old system. For Birth, Death and Marriage the columns are the register's own; for other types they follow
  the layout's field names, page 1 then page 2, and fields that belong to no person (for example Page No.) repeat on
  every row. The two pages of a document join by row: row 5 of page 1 and row 5 of page 2 are one CSV row. Each
  download is in the audit log (`record.exported`).

**Download all** and **Download ticked** (several records' tables as one CSV or Excel file) were taken off this page
on 26 November 2026. The same download is still offered at the end of a batch ([4.9](#49-batch-file-upload)).

### 4.8 Review & Export (step 4) and editing a saved record

**Step 4 of the scan screen.** After Verify, the document is shown as a **records table**: one row per person, with the
scan beside it. The bar between them drags (double-click: 50/50).

**The register's own columns.** For Birth, Death and Marriage the table's columns are the register table's fields, in
its fixed order. They are also the columns of every download.

| Book | Columns, in order (* required in every row) | Typed by hand, never read from the scan |
| :--- | :--- | :--- |
| Birth (`birth_records`) | name*, date_of_birth, sex, book_no*, page_no*, reg_no*, date_of_reg, place_of_birth, name_of_mother, nationality_mother, name_of_father, nationality_father, parents_marriage_date, parents_marriage_place, attendant, type_of_birth, mother_age, father_age, late_registration | attendant (PHYSICIAN, MIDWIFE, NURSE, HILOT, OTHERS, NOT APPLICABLE), late_registration (true or false; false to start) |
| Death (`death_records`) | name*, date_of_death, sex, book_no*, page_no*, reg_no*, date_of_reg, age_years, age_months, age_days, age_hours, age_mins, civil_status, nationality, place_of_death, cause_of_death, corpse_disposal, attendant, residence, date_of_birth, late_registration | corpse_disposal (BURIAL, CREMATION, OTHERS), attendant, late_registration |
| Marriage (`marriage_records`) | husband_name*, wife_name*, date_of_marriage, book_no*, page_no*, reg_no*, husband_age, wife_age, husb_nationality, wife_nationality, husb_civil_status, wife_civil_status, husb_mother, wife_mother, husb_father, wife_father, date_of_reg, place_of_marriage, ceremony_type, late_registration | ceremony_type (ROMAN CATHOLIC WEDDING, CIVIL WEDDING, OTHER RELIGIOUS WEDDING), late_registration |

- **book_no** and **page_no** come from the Document Reference in step 2; they are shown here, not typed.
- **Columns → fields** says which scanned column fills each register field. A column named like a field fills it by
  itself ("Name of Mother", "Mother's Name" → name_of_mother); any other choice is made from a list, with **Undo**. The
  choice is kept on the layout as soon as it is made (audit entry `template.field_map_changed`), so the next page starts
  the same way. A field with no column is typed by hand.
- In the marriage book the husband is written on the first line of an entry and the wife under him, so the second
  line of the husband's column can fill the wife's field.
- **Ages and numbers** must be digits. **Dates** are saved as YYYY-MM-DD. The ways registers write dates are understood
  ("5-14-79", "05/14/1979", "14-5-79", "May 14, 1979"), and a two-digit year is read as at most the entry's year of
  registration ("57" is 1957). A date misread by one stray digit ("22-22-57") is repaired from the page's usual month
  and shown in red, so the user checks it.

Other document types (custom types) have no register table: their columns are the layout's fields, page 1 then page 2.

**Working in the table:**

- Click or type in a cell to edit it; the scan glides in to that cell's writing. Enter goes down, Tab goes right.
- Drag down a column to pick several cells: type one value into them all, delete them, or paste a list or an Excel
  block to fill down. Ctrl+Z and Ctrl+Y undo and redo. The **Shortcuts** menu lists every key, Windows and Mac.
- Empty cells are yellow and invalid ones red; the issues button goes to the next one. Columns resize by dragging
  (double-click fits) and move by dragging their header (or Alt+Shift+arrow keys). Each header has ✎ (rename it in the
  download) and × (remove a column that is not required), each asking first.
- A row (person) with nothing ticked in Verify is left out; a value not ticked shows greyed and is left out of the
  downloads, because it is not saved. Editing the cell ticks it.
- A value typed into a cell where nothing was read becomes a new field of that person (`Column · row N`).
- **Download**: the table as **CSV** or **Excel (.xlsx)**, made in the browser.
- **Save record** saves the record (see "When a record is saved" in [4.6](#46-new-document-step-3-verify)). In a batch
  it reads **Save & next**. **Back to verify** returns to step 3.

**A saved record.** On the record page, **Edit in Review & Export** opens the same table for that record, holding the
register rows as they were saved.

1. Change cells, or fill cells where nothing was read. Download CSV or Excel at any time.
2. **Save changes** writes the changed values at once. The register rows go through the same checks as in step 4, and
   a book and page that another record has is refused. A save that changes nothing writes nothing.
3. Each save is one audit entry, `record.edited`, with every old and new value (fields and register rows). The record
   page shows it in its **Edit history**, and the dashboard counts it under **Edits after saving**.

Until 15 November 2026 a saved record was changed with **Correct record** (stored as change requests, listed on a
**Corrections** page). Those tables were dropped; the audit log keeps their old `change_request.*` and
`record.corrected` entries.

### 4.9 Batch File Upload

For a pile of files, a folder, or every page of a book. Each record still goes through Verify and Review & Export one
at a time, exactly as a single record does.

1. **Queue.** Choose **Batch File Upload** in step 1 and drop files or a folder. **Add files** adds more later. Each
   page is one record, or each pair of pages (1–2, 3–4) when the layout has a page 2.
2. **Align each page.** A page opens on the layout; **Snap to table** is pressed by hand. **Save & Queue Document**
   (on a two-page layout, **Save Both Pages & Next**) queues it and opens the next. Each record needs its own Book No.
   and Page No.; one already used in the batch is refused.
3. **Scan all** reads every queued page. It first checks that the OCR service is up, has no time limit, and stops after
   a second page fails because the OCR service went down (those pages wait to be scanned again).
4. **Check each record.** Verify → Review & Export → **Save & next**, which marks the record **Done** but does not save
   it yet. Done records can still be changed from the queue list. **Re-align** in Verify sends the whole document back
   to Align; **Cancel re-align** keeps the old reading. A record that should not be saved (a blank page, a cover) is
   taken out with its × in the queue list.
5. **Save & finish batch** (the last record's button) saves every Done record, one after another. A record that cannot
   be saved stops it there, with its message.
6. **Batch complete**: a summary of every record (file, pages, rows, Saved, Rejected or Skipped, View) with
   **Download all**: every saved record's table as one CSV, or one Excel file with an "All records" sheet and a sheet
   per record (up to 50).

The batch is kept on the server while it is worked on (`scan_batches`, `scan_batch_pages`, `scan_batch_drafts`). After a
reload or a crash, opening the workspace offers **Resume**, which opens the page and step it was left on ("left on
Review & Export"); the files of pages not queued yet are picked again and matched by their fingerprint (SHA-256).
**Cancel** asks **Keep for later** or **Discard batch**, and leaving a single scan asks the same (**Keep for later**
turns it into a batch). Each change carries the batch's version, so an older copy in another tab is refused. An open
batch is kept for 7 days (`CRMS_SCAN_BATCH_KEEP_DAYS`); discarded and expired batches are removed with their files by
the hourly clean-up.

### 4.10 Removed screens and features

What earlier documents, diagrams or the manuscript may still show, but CRMS no longer has:

| Removed | When (2026) | What replaced it |
| :--- | :--- | :--- |
| Staff and Admin roles, **User Accounts** page, account deactivation, demo logins | 11 Nov | One account ([2](#2-who-uses-it)). The old accounts' records moved to the Super Admin. |
| **Test on sample** in the Template Builder | 11 Nov | Publish checks and the sample page |
| **Correct record** and the **Corrections** page (change requests and approval) | 15 Nov | Review & Export on a saved record ([4.8](#48-review--export-step-4-and-editing-a-saved-record)) |
| **Reports** page and its CSV of record summaries | 22 Nov | The record's Download CSV and Download one book ([4.7](#47-saved-records)) |
| **Smoke test scan** (Detect fields and ink) | 25 Nov (hidden) | Outline fixes in Verify ([4.5](#45-smoke-test-scan-detect-hidden)) |
| Download all and Download ticked on Saved Records | 26 Nov | Download one book; Download all at the end of a batch |
| Add column in Review & Export | 26 Nov | Columns are added in Align or the Template Builder |
| Duplicate file check (an Account Settings switch) and the ✓ on pages of a file already saved | 27 Nov | The Book No. and Page No. check ([4.4](#44-new-document-step-2-align)) |

The audit log keeps the old entries of all of them (for example `report.generated`, `change_request.approved`).

### 4.11 Audit Log

- Every entry: when, who (the name at that time; entries from before 11 November 2026 keep their Staff and Admin names), what action, a description, the values before and after, IP address and browser.
- Filter by action and date range, and search the text. 25 entries per page.
- **Append-only**: there is no screen or route to edit or delete an entry, and the code throws an error if anything tries.
- Passwords and tokens are replaced by `[redacted]` before an entry is saved.
- [Appendix B](#appendix-b-audit-log-actions) lists every action.

### 4.12 Template Builder

A **layout** (also called a template) tells CRMS where each value sits on a document type.

**Document types.** Book of Birth, Book of Death and Book of Marriage are built in. The Super Admin can add custom
types (name and icon), rename them, and **archive** a type (it can no longer start a scan; its records and layouts are
kept) or restore it. Built-in types cannot be deleted, and neither can a custom type that has records. Only the three
built-in types have a register table.

**Two kinds of layout:**

| Kind | For | Made of |
| :--- | :--- | :--- |
| **Fields** | Certificates and forms | Rectangles, one per value (for example "Date of Birth") |
| **Ledger** | Register books | Column markers plus the printed **row lines**; each row is one person |

**Tools and rules:**

- **Sample document**: upload a scan of the real page to draw on. **Detect from sample** suggests the ledger grid from
  the printed lines, page by page: it follows the page's tilt and rules that bend, leaves out lines below the table's
  frame, and draws guessed rows (faint or missing printed lines) in amber; saving with amber rows asks first.
- **Two pages**: a register spread can have a **page 2** with its own layout. Page 2 belongs to its main layout alone:
  it is not listed, published or deleted by itself, and a duplicate gets its own copy. Row 1 of page 1 and row 1 of
  page 2 are one person.
- **Field settings**: what the field **holds** (the person's name or the entry number), its **value type** (text, date, number, or one of a list of choices), a **hint**, and whether it is **required**.
- **Person grouping**: which fields belong to which person.
- **Row lines** (ledger): each line has grips at the table's ends; it can be moved, **tilted on its own**, made shorter
  or longer, and **bent at each column** (small squares at a column's ends). These are saved with the layout
  (`ruled_tilts`, `ruled_spans`, `ruled_bends`) and are where the Align step starts. They are for lining up by eye: a
  page is still read by one height per row line.
- **Column presets** (27 Nov): **Preset → Columns from… → Apply** gives the page being edited another layout's columns
  (names, boxes and settings) and row lines in one step.
- **Needed columns** (27 Nov): for Birth, Death and Marriage, lists the register fields that no column fills yet, and
  ticks each one when a column fills it.
- Fields drawn over a missed row can be merged into columns; a column can be turned back into a field (**Make selected
  fields**). Selected fields can be tilted.
- **Grid checks**: row lines outside the columns or on top of each other stop the save; a field covering ledger cells is reported.
- **A layout in use is never changed in place.** A layout that is published, or that any record or page was read with,
  saves new markers or settings as a **duplicate** ("… (copy)"), either as a draft or published at once, so every
  record keeps pointing at the exact layout it was read with. Its name, notes and sample are saved in place. A save
  made from an outdated copy of the editor is refused.
- **One published layout per type.** Publishing a layout replaces the one used before.
- **Publish checks**: a sample page and real column names (no "Column 3"); for two pages, different column names on
  each page and the same number of rows. The Publish window lists what is missing; the user can publish anyway, and
  the audit entry lists the checks that were not met.
- **Delete**: the published layout cannot be deleted until another is published. A layout that records were read with
  is **archived** (hidden, kept, with its page 2) and can be **restored** as a draft; one nobody used is erased.
- **Duplicate** makes a draft copy, sample included.
- No paper size to set: layouts store the page's shape (width ÷ height), and the Align step warns when a scan looks sideways.
- Leaving with unsaved changes asks first.

### 4.13 OCR Workspace

This is where the AI models are managed. Training is not done here.

| Area | What it does |
| :--- | :--- |
| Service status | Shows whether the OCR service is online, whether it reads on the GPU or the CPU, and in which precision ("GPU, fp16"); checks again by itself |
| **Models** panel | Every installed model. **Activate** (with confirmation) makes it the one every scan uses. **Rename**, **Delete** (not the active one), **Colour** (each model keeps one colour on every chart), **Rescan models** (re-reads the models folder) |
| **Add model** | Drop a `.zip` or a model folder (`config.json`, `model.safetensors`, tokenizer files, and optionally the two report files). The browser sends it straight to the OCR service with a signed ticket; CRMS then registers it. |
| **OCR model performance** | Radar or bar chart of character accuracy (1 − CER), word accuracy (1 − WER) and exact match, from each model's test-set report. Models are compared only when their reports used the same test set. Also shows reading speed from real scans. |

Installed now: `trocr-finetuned-final` (active, scored on real ledger lines), and three older models scored on the
synthetic test set: `100k-finetuned-dataset-model`, `TrOcr-50k-broken` and `TrOcr-base-version-handwritten`
(Microsoft's base model).

A fresh installation has **no model**, and CRMS never downloads one by itself. Scanning then says "No OCR model is installed" until one is added.

The confidence threshold has **no screen**: it is set by `CRMS_CONFIDENCE_THRESHOLD` in `.env` (default 80). (The old
setting that let Staff choose a model was removed with the Staff role.)

### 4.14 Account Settings

Opened from the initials badge at the top right.

- **Your details**: the account's name and email. They can be changed **once**; the first save that changes them locks
  both (`php artisan crms:unlock-details` on the CRMS computer allows one more change). The email must be unused, and
  the change is in the audit log as `user.updated`.
- **Change password**: needs the current password.
- **Recovery codes**: with the current password, makes **10 new one-time codes** (10 letters and digits each) for Forgot password?. They are
  shown once, to write down; the old ones stop working. Only a keyed hash of each code is stored.

### 4.15 Background jobs and command-line tools

| Command | What it does | When |
| :--- | :--- | :--- |
| `documents:prune-pages` | Deletes pages nobody saved, once untouched for 24 hours, and batches discarded or left open more than 7 days, with their files | Every hour, by the scheduler |
| `crms:reset-password` | Gives the account a temporary password, printed once | By hand, when the password is forgotten |
| `crms:unlock-details` | Lets the account change its name and email once more | By hand |
| `crms:normalize-dates` | Rewrites the register tables' dates as YYYY-MM-DD where they can be worked out (`--dry-run` only shows what would change) | By hand, once after upgrading |
| `crms:export-training` | Exports verified line images and their corrected text as a TrOCR training CSV | By hand |
| `crms:export-training --test-set=<name>` | Exports the same lines as a local test set, to score a model on real lines on this computer | By hand |
| `python ml/test_finetuned.py --model <name>` | Scores a model: CER, WER, exact match, reading time | By hand |
| `python ml/train_trocr.py` | Fine-tunes TrOCR locally (the notebooks are the main way) | By hand |
| `python ml/download_trocr.py` | Downloads Microsoft's base model into `ml/models/base` | By hand |

---

## 5. How the work flows

### 5.1 Digitizing one page, start to finish

```
 USER                   WEBSITE                       QUEUE WORKER              OCR SERVICE
 ----                   -------                       ------------              -----------
 1 choose the type,
   drop the scan  --->  shows the page with the
                        layout's markers
 2 align the markers
   (Snap to table, Tilt,
   rows and columns),
   type Book No. and
   Page No.       --->  "already saved?" check
 3 Scan with OCR  --->  saves page.png and the
                        markers, status "queued",
                        puts a job in the queue  ---> 4 finds and outlines
                        and answers at once             every line (Kraken),
                                                        cuts each line image
                                                      5 sends the images,  ---> 6 reads 20 per pass,
                                                        40 per request          text + confidence
                                                      7 saves the readings <---
                                                        status "ready"
 8 the screen asks for
   progress and shows <---  lines and readings
   the readings
 9 checks, corrects,
   ticks every value,
   then reviews the
   register table (step 4;
   CSV / Excel download)
10 Save record    --->  checks again, creates the
                        record, copies the page and
                        line images, writes one
                        register row per person,
                        locks it, writes the audit
                        entry, deletes the working page
```

### 5.2 Page states

Between Align and saving, a page is a row in `document_pages` with a status:

```
                        Scan with OCR
 [queued] --> [detecting] -------------------------> [reading] --> [ready] --> Save:
                  |                                      ^            |        record created,
                  | Smoke test (hidden)                  |            |        page deleted
                  v                                      |            |
             [detected] ----- Scan with OCR -------------+            |
                  |           (reads its crops)                       |
                  |                                                   |
                  +---- markers moved: outline again ----> [queued] <-+

 Cancel before it finishes --> [cancelled], and the page is deleted at once.
 An error                  --> [failed], with a message; the page is scanned again.
 Never saved               --> deleted by the hourly clean-up after 24 hours
                               (a page of an open batch: after 7 days).
```

### 5.3 The three job modes

The worker runs one job, `ProcessDocumentPage`, in one of three modes:

| Mode | Started by | What it does |
| :--- | :--- | :--- |
| `full` | **Scan with OCR**, Scan all, a Re-aligned page | Keeps the markers where the user put them (straightening the page only by the tilt given to the table, and nudging row lines by at most half a row onto the printed lines), then outlines, cuts and reads every line. A Re-aligned page reuses what was found before, and a line cut out exactly as before keeps its reading. |
| `detect` | **Smoke test scan** (hidden since 25 Nov) | Straightens the page, fits the grid to the printed table, then outlines and cuts every line. Reads nothing. |
| `read` | **Scan with OCR** after a smoke test | Reads the line images the smoke test already cut. |

Every run, finished, failed or cancelled, adds a row to `page_runs` (who, mode, outcome, total time, reading time, number of lines, model). The dashboard counts from it, because the pages themselves are deleted. It holds no document text or images.

### 5.4 Editing a locked record

```
 record page --> Edit in Review & Export --> change or fill cells --> Save changes
                                                                          |
             record and register rows updated  <-- one database  <-------+
             at once                                transaction
             Edit history on the record page + audit entry record.edited (old and new values)
```

Before 15 November 2026 this was **Correct record**, stored as change requests; those tables were dropped, and
their audit entries (`change_request.*`, `record.corrected`) are kept.

### 5.5 Forgotten password

```
 WAY 1: a recovery code (sign-in page)

 Super Admin                       CRMS
 -----------                       ----
 Forgot password? email + code --> checks the code against the stored hashes
                                   (5 wrong codes per email: wait an hour;
                                   a wrong code: audit entry user.recovery_failed)
 chooses a new password (within
 10 minutes)                  --> saves it, crosses the code off, audit entry
                                   user.password_recovered; "N codes left"

 WAY 2: the command line (on the CRMS computer)

 php artisan crms:reset-password   gives the account a random 12-character
                                   temporary password, "must change password"
                                   on, audit entry user.password_reset
 sees the password once   <----    prints it (never stored in plain text)
 signs in with it          --->    blocks every page but "change password"
 chooses a new one         --->    saves it, audit entry user.password_changed
```

### 5.6 A layout's life

```
 create (draft) --> edit --> Publish (checks; or publish anyway) --> scans use it
                                                   |
                       change while in use: saved as a DUPLICATE ("… (copy)"), as a
                       draft or published at once; the original keeps its records
                                                   |
                       Delete: refused while published; ARCHIVED (kept, restorable as a
                       draft) when records used it; erased when nothing did
```

### 5.7 An AI model's life

```
 TRAINING (outside CRMS)            OCR WORKSPACE (Super Admin)          SCANNING
 -----------------------            ---------------------------          --------
 fine-tune TrOCR on Kaggle or  -->  Add model: the browser uploads  -->  every scan reads
 a local GPU; score it on a         the ZIP to the OCR service;          with the ACTIVE
 locked test split; write           CRMS registers it and reads its      model; each record
 evaluation-report.json;            scores; Activate                     stores which model
 package model + reports (ZIP)                                           read it
```

### 5.8 A batch's life

```
 Batch File Upload: drop files / a folder
   > each page: Align (Snap to table by hand), Book No. + Page No., Save & Queue Document
   > Scan all: every queued page is read
   > each record: Verify > Review & Export > Save & next (Done, not saved yet)
   > Save & finish batch: every Done record is saved, one after another
   > Batch complete: summary, Download all
 Kept on the server all along: Resume after a reload or crash (opens where it was left);
 Keep for later or Discard batch on leaving; removed 7 days after it was last used.
```

### 5.9 Where files are kept and when they are deleted

All files are under `storage/app/private/` (outside the public web folder) and are served only through pages that check permission.

| What | Where | Created | Deleted |
| :--- | :--- | :--- | :--- |
| Page being worked on | `pages/{id}/`: `page.png`, `page.input.png`, `geometry.json`, `lines.json`, `detector.json`, `crops/` | When Scan with OCR starts | On save (after copying), on cancel (at once), or after 24 hours untouched (7 days in an open batch) |
| Batch page's original file, queued | `documents/workspace/<batch>/` | When the page is queued | When its record is saved, or with its batch |
| Original upload | `scans/` (one per page of a spread) | On save | Never (records cannot be deleted) |
| Page as read, and line images | `records/{id}/page.png` (`page-2.png` for page 2), `records/{id}/crops/` | On save | Never |
| Layout sample | `template-samples/` | On upload | When replaced or deleted |
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
                                       | 1    (a layout may     +--------+---------+
                                       |       own a page 2:              | 0..1
                                       |       page_two_template_id)      |
                                       | *                       +--------+---------+ *  1 +--------------+
 +-------+ 1  * +-------+ 1  *    +----+-----+ 1     * +------+  | scan_batch_pages +------+ scan_batches |
 | roles +------+ users +---------+ records  +---------+record_|  +------------------+      +------+-------+
 +-------+      +---+---+         +----+-----+         |fields |                                   | 1
                    |                  |               +-------+                                   | *
                    |                  +-- * record_pages (pages after the first)       +----------+--------+
                    |                  +-- * birth_records / death_records /            | scan_batch_drafts |
                    |                        marriage_records (one row per person)      +-------------------+
                    | 1
                    +---- * audit_logs, page_runs, scan_batches (and "who did it" on most tables)

 Stand-alone: ocr_models (the model registry), ocr_settings (one row)
```

`users` link to almost everything as "who created / saved". A saved batch page points at the record it became
(`scan_batch_pages.record_id`). `document_templates.parent_id` points at the layout a duplicate was made from. The
`change_requests` and `change_request_items` tables were dropped on 15 November 2026.

### 6.2 The tables

| Table | Holds | Important columns |
| :--- | :--- | :--- |
| `roles` | The one role | `slug` (`super_admin`), `name`, `description` |
| `users` | The account | `name`, `email`, `password` (bcrypt hash), `role_id`, `must_change_password`, `password_changed_at`, `recovery_codes` (keyed hashes of the unused codes), `recovery_codes_generated_at`, `details_locked_at`, `last_login_at` |
| `document_types` | Kinds of document | `key`, `name`, `short_name`, `icon`, `is_system` (built-in), `archived_at` |
| `document_templates` | Layouts and their duplicates | `name`, `document_type_id`, `page_ratio`, `columns` (ledger column markers, JSON), `ruled_ys` (row line positions), `ruled_tilts`, `ruled_spans`, `ruled_bends`, `field_map` (Columns → fields), `grouping_mode`, `sample_*`, `is_active` (published), `published_at`, `archived_at`, `revision`, `parent_id`, `page_two_template_id`, `is_page_two` |
| `document_template_fields` | The rectangle fields of a layout | `name`, `x`, `y`, `width`, `height`, `angle`, `person_group`, `role` (name or entry number), `value_type`, `options`, `hint`, `is_required` |
| `records` | One saved page, or one two-page spread | `document_type_id`, `document_template_id` (the exact layout), `status` (`submitted`), `scan_path`, `page_image_path`, `scan_rotation`, `source_name`, `source_page`, `source_page_count`, `source_hash` (the file's SHA-256), `ocr_model_key`, `submitted_by`, `submitted_at` |
| `record_pages` | Pages after the first of a record | `record_id`, `page_no`, `scan_path`, `page_image_path`, `scan_rotation`, `source_page` |
| `record_fields` | One value of a record | `page_no`, `name`, **`ocr_text`** (what the AI read), `ocr_confidence`, **`verified_value`** (what the person approved), `is_required`, `role`, `person_group`, `crop_path`, position (`x`, `y`, `width`, `height`, `polygon`), ledger cell (`line_column`, `line_row`, `line_flags`) |
| `birth_records` | One person's birth entry | `civil_record_id` (the record), `book_no`, `page_no`, `reg_no`, `name`, `date_of_birth`, `sex`, `date_of_reg`, `place_of_birth`, parents' names and nationalities, `parents_marriage_date`, `parents_marriage_place`, `attendant`, `type_of_birth`, `mother_age`, `father_age`, `late_registration` |
| `death_records` | One person's death entry | `civil_record_id`, `book_no`, `page_no`, `reg_no`, `name`, `date_of_death`, `sex`, `date_of_reg`, `age_years` … `age_mins`, `civil_status`, `nationality`, `place_of_death`, `cause_of_death`, `corpse_disposal`, `attendant`, `residence`, `date_of_birth`, `late_registration` |
| `marriage_records` | One couple's marriage entry | `civil_record_id`, `book_no`, `page_no`, `reg_no`, `husband_name`, `wife_name`, `date_of_marriage`, both ages, nationalities, civil statuses, mothers and fathers, `date_of_reg`, `place_of_marriage`, `ceremony_type`, `late_registration` |
| `document_pages` | A page between Align and saving | `status`, `error`, `image_path`, `width`, `height`, `deskew_degrees`, `geometry` (markers, JSON), `notes` (grid notes), `ocr_model_key`, `created_by` |
| `page_lines` | One outlined line on a page | `source`, `column_name`, `row`, `polygon`, `baseline`, `bbox`, `crop_path`, `flags`, `ocr_text`, `ocr_confidence`, `adjusted_at`, `deleted_at` (kept for undo) |
| `page_runs` | One scan run, for the dashboard | `user_id`, `mode`, `outcome`, `duration_ms`, `read_ms`, `lines`, `ocr_model_key`, `error` |
| `scan_batches` | A batch being worked on | `user_id`, `doc_type`, `document_template_id`, `page_two_template_id`, `batch_key`, `status` (`open`, `done`, `abandoned`), `version`, `tab`, `seen_at` |
| `scan_batch_pages` | One page of a batch | `scan_batch_id`, `client_key`, `position`, `source_name`, `source_hash`, `page_no`, `page_total`, `record_key`, `status`, `document_page_id`, `workspace_scan_path`, `alignment`, `flags`, `error`, `record_id` |
| `scan_batch_drafts` | What was typed and ticked for a record of a batch, before it is saved | `scan_batch_id`, `record_key`, `draft` (JSON) |
| `audit_logs` | The audit trail | `user_id`, `actor_name`, `actor_role`, `action`, `auditable_type`, `auditable_id`, `description`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at` |
| `ocr_models` | AI models CRMS knows | `key` (folder name), `label`, `color_slot`, `is_active`, `cer`, `wer`, `exact_match`, `evaluation_*` (dataset, split, sample count, hashes), `disk_deleted_at` |
| `ocr_settings` | Scanning settings (one row) | `confidence_review_threshold` |

Laravel's own tables: `sessions`, `cache`, `cache_locks`, `jobs` (the queue), `job_batches`, `failed_jobs`, `migrations`.

### 6.3 Design notes

- **One record is one page, or one two-page spread.** A ledger page with 20 people is one record with up to 450 values per page, grouped by `person_group` (Person 01, 02, ...). Search finds the page, and the record page shows each person.
- **Each person is also one register row.** For Birth, Death and Marriage, every person on a saved page is one row of
  the book's register table, with typed columns, linked to the record by `civil_record_id`. The register rows are what
  the record's CSV and **Download one book** give. Review & Export keeps the two in step: a register cell that came
  from the scan changes its field too.
- **A register page is saved once.** A book and page that already has register rows is refused, by the save and by
  edits. A register number may repeat on a page, because the books themselves have repeats: the database's unique key
  on (book, page, register number), added on 25 November, was replaced by a plain index on 27 November. So the rule
  is kept by the code: the check is made again inside the save's transaction with the document type's row locked, so
  two saves of the same book type wait for each other.
- **Dates in the register tables are text in YYYY-MM-DD** where they can be worked out; one that cannot is kept as
  written. `crms:normalize-dates` rewrote the dates saved before this rule.
- **Both readings are kept.** `ocr_text` (the AI) and `verified_value` (the person) are stored side by side. That is how the correction rate is measured.
- **Positions are fractions of the page** (0 to 1), so a layout fits any scan resolution. Line outlines on a page in progress are in pixels.
- **Records point at the exact layout** they were read with, and keep their own copy of every image the AI read.
- **The audit log copies the person's name and role** at the time, so history stays readable even if the account changes later.
- **Times are stored in UTC** and shown in Philippine time.
- **The document type is stored twice** (`document_type_id` and an older `doc_type` column). See [11.3](#113-risks-and-gaps-found-in-this-review).
- The database structure is built by **69 migration files** in `database/migrations/`.

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
| 1. Straighten | Scan with OCR turns the page level by the tilt the user gave the table. (The hidden smoke test measured the tilt itself, within ±10°.) |
| 2. Find the printed table | Finds the printed horizontal and vertical lines and fits the layout's grid onto them (position and scale). A page turned a few degrees is straightened and looked at again when the first look finds no table. Rules that bend are followed, and up to three faint missing rules in a row are filled in. |
| 3. Find the writing | Kraken finds the baseline and outline of every line of writing. It uses the GPU if there is one and falls back to the CPU. Its ridge filter runs at five sizes side by side (5.8 s → 3.0 s, identical output), and the outlines are traced in 4 processes at once (6.6 s → 2.3 s). |
| 4. Split at the columns | Kraken often joins neighbouring cells into one line, so lines are split at the column rules, in the gap nearest the rule. |
| 5. Assign rows | Each line is put in a row according to where writing usually sits in a row on this page. A line too far from any row (more than 0.35 of a row) is flagged `no_row` instead of guessed. |
| 6. Give every stroke to one line | A pen stroke that enters a line's core band belongs to that line, even if its capital or tail reaches into the next row. Other lines' strokes, and marks running across the whole page, are cut out of the outline. |
| 7. Fill gaps | A cell with ink but no detected line (a single letter, an entry number) gets a line built from that ink. |
| 8. Free text | If a column's writing does not follow the rows at all (a list of diseases under a ledger layout), that column is read as free text, line by line. |
| 9. Cut each line | Each line is cut along its polygon, other ink is masked out, and the background is filled with the paper's own tone with an 8-pixel margin. On two real register pages (440 cells) this read at 2.0% character error rate, against 3.1% with a white fill. |
| 10. Report | Flags (`no_row`, `shared_cell`) and grid notes (for example "written lines below the last row") are saved for the Verify step. |

What Kraken found is saved beside the page under a fingerprint of the image, so outlining the page again after markers move reuses it (register page: 48.5 s → 4.8 s).

**The line finder service** (`ml/line_markers_service.py`, optional) runs the same code but keeps Kraken loaded between
pages, so a page skips loading it: line finding on a register page 21 s → 13.5 s, a 4-page batch 109 s → 71 s, with
byte-for-byte the same lines and crops (checked on 8 runs). When it is not running, each page runs the program as
before.

Older layouts made of plain rectangles still work: each rectangle becomes a four-point polygon, and a rectangle holding several written lines is split into one field per line ("Diseases · line 1", "· line 2").

### 7.3 Reading the lines (TrOCR)

**TrOCR** ("Transformer-based OCR", Microsoft Research, Li et al.) reads a picture of one line and writes out its text. It has two halves:

| Part | What it does | Size (measured from the model files) |
| :--- | :--- | :--- |
| Image encoder | A Vision Transformer. Resizes the line image to 384 × 384 pixels, cuts it into 16 × 16 patches, and turns them into numbers describing the writing. 12 layers. | 86.7 million parameters |
| Text decoder | A text Transformer. Writes the text one token (word piece) at a time from those numbers. 12 layers, 50,265-token vocabulary. | 247.3 million parameters |
| **Total** | | **333.9 million parameters, about 1.3 GB file** |

CRMS starts from `microsoft/trocr-base-handwritten` (Microsoft's model already trained on the IAM English handwriting dataset) and **fine-tunes** it: trains it further on its own data, so it adapts to the writing it will see (section [7.5](#75-training-the-model)).

**How CRMS reads:**

- The worker sends line images to the OCR service, **40 per request**. The service sorts them by shape and reads them
  **20 at a time** in one GPU pass.
- On the GPU the model is kept in **half precision (fp16)**, which uses the card's Tensor Cores: a 225-line page read in
  4.0 s instead of 8.1 s (2.8 s with the other speed-ups), with the same accuracy on 517 human-corrected lines. The CPU
  always reads in full precision. `OCR_PRECISION=fp32` goes back to full precision.
- **Greedy decoding** (it always takes the most likely next token), at most **32 tokens** per line.
- A line image that cannot be read gets an empty reading and an error message; the other lines are still read.
- Once the OCR service is found to be down, the next pages fail in seconds instead of after their lines are found.
- Every reading is saved with the model that made it.

### 7.4 The confidence score

For each reading, TrOCR gives a probability for every token it wrote. CRMS takes the **geometric mean** of those probabilities, up to the end of the line, as a percentage. One calculation (`ml/trocr_common.py`) is used everywhere.

**Confidence is the model's certainty in its own reading, not its accuracy.** A model can be confidently wrong. That is why the threshold (default 80%) only flags a value for a closer look; every value is still checked by a person.

### 7.5 Training the model

The model in use, **`trocr-finetuned-final`** (its report calls it `TrOcr-ledger-synth-30k-plus-real`), was trained in
**two stages**, from the figures in its own `training-summary.json` and `evaluation-report.json`.

**Stage 1: computer-made ledger lines, on Kaggle** (the result is called `TrOcr-ledger-synth-30k`).

| Item | Value |
| :--- | :--- |
| Starting model | `microsoft/trocr-base-handwritten`, pinned to revision `eaacaf45…` |
| Dataset | `30k-real-ledger-synthetic`: 30,000 computer-made images in the style of the client's ledgers (14 handwriting fonts, 120 simulated writers; names, dates, places, ledger entries), made from a profile of the real dataset's training split that holds real words, names and scanned paper. 3,835 of them ("mix" images) are pieces of **real handwriting** from that split, recombined. |
| Split | 23,922 training, 3,054 validation, 3,024 test images |
| Settings | 2 epochs; 16 images × 6 accumulation steps (96 per update); learning rate 2.5e-5 (decoder) and 1.25e-5 (encoder); AdamW; 4% warmup; weight decay 0.01; augmentation on; seed 20261102 |
| Hardware and time | 2 × NVIDIA Tesla T4 on Kaggle, 39.6 minutes |
| Its own test (synthetic, 3,024 images) | CER 3.98%, WER 12.06%, exact match 85.8% |

**Stage 2: real ledger lines, on the development laptop** (the result is the model in use).

| Item | Value |
| :--- | :--- |
| Starting model | Stage 1's model |
| Dataset | `raw-cropped-dataset`: **11,589 real ledger line images** ("source": "real" in its own metadata), cut with Kraken, made grayscale, trimmed to the ink and scaled to 64 pixels tall. Built on 22 November 2026 from 11,638 crops; a label review flagged 435, corrected 48 labels and removed 49 crops. |
| Split | 80/10/10 by a seeded shuffle of the file names: 9,270 training, 1,160 validation, **1,159 test** (locked: never used for training or choosing). No exact copies found between them. |
| Settings | 12 epochs; 16 images × 4 accumulation steps (64 per update); learning rate 3e-5 (decoder) and 1.5e-5 (encoder); Adafactor optimizer; 5% warmup; weight decay 0.01; gradient checkpointing; augmentation on; early stop after 3 epochs without improvement; labels up to 40 tokens; seed 20261102 |
| Choosing the best epoch | Lowest validation CER: epoch 10 |
| Hardware and time | 1 × NVIDIA GeForce RTX 4050 Laptop GPU (6 GB), 105.2 minutes |
| Output | The model, `evaluation-report.json` (its test scores), `base-evaluation-report.json` (Microsoft's model on the same test) and `training-summary.json` |

Stage 2 on its validation split (1,160 real line images):

| Epoch | Training loss | Validation CER | Validation exact match |
| :---: | ---: | ---: | ---: |
| 0 (stage 1's model) | – | 12.31% | 62.67% |
| 1 | 0.589 | 10.43% | 68.71% |
| 2 | 0.418 | 8.83% | 71.55% |
| 4 | 0.229 | 7.92% | 74.05% |
| 6 | 0.144 | 7.11% | 76.12% |
| 8 | 0.102 | 7.27% | 75.69% |
| **10 (kept)** | 0.073 | **6.57%** | **78.19%** |
| 12 | 0.057 | 6.66% | 78.02% |

Both datasets are kept on the training computer only, **not in the repository**. What is known about them, from
their own metadata, is in the dataset card [TRAINING-DATA.md](TRAINING-DATA.md). The repository's notebooks
(`finetuning-kaggle/`, outputs removed because they show real handwriting) are:

- `trocr-finetuning-code.ipynb` fine-tunes from Microsoft's base model, on Kaggle or on a local GPU. It trained stage 1,
  and in an earlier version the previous `100k-finetuned-dataset-model` (80,000 synthetic images, 2 epochs, 121 minutes
  on 2 × T4).
- `trocr-finetuning-from-model.ipynb` continues a fine-tuned model on real samples. It trained stage 2.
- `trocr-sequential-finetuning.ipynb` continues training an already fine-tuned model on new samples, mixing in old samples ("replay") so it does not forget, and compares the base, old and new models with a McNemar test before recommending UPLOAD or DO NOT UPLOAD.
- `trocr-benchmark-models.ipynb` trains nothing: it scores existing models on one test set so they can be compared fairly.

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

**The model in use, on real ledger lines.** All three were scored on the same 1,159 real test images (the reports
carry the same dataset name, sample count and manifest hash), reading as CRMS reads (fp16, greedy, 32 tokens, 8-pixel
border):

| Model | CER | WER | Exact match | Character accuracy | Reading time per image (RTX 4050) |
| :--- | ---: | ---: | ---: | ---: | ---: |
| Microsoft base, not fine-tuned | 94.15% | 135.49% | 6.21% | 5.85% | 11.9 ms |
| After stage 1 (synthetic ledger lines only) | 12.94% | 39.61% | 60.83% | 87.06% | 11.8 ms |
| **After stage 2: `trocr-finetuned-final`, in use** | **6.28%** | **20.47%** | **79.21%** | **93.72%** | 12.0 ms |

The model in use on two parts of the test set:

| Test images | Count | CER | WER | Exact match |
| :--- | ---: | ---: | ---: | ---: |
| Text also appears in training (a different image of it) | 837 | 2.71% | 8.30% | 91.76% |
| Text **never seen** in training | 322 | 13.22% | 45.69% | 46.58% |

Against Microsoft's model on the same images, the final model got **850** images right that the base model got wrong,
and **4** wrong that the base model got right (McNemar test, p < 0.001). Against stage 1's model: **242** fixed, **29**
broken (p < 0.001). Neither improvement is chance.

**Earlier models, on the synthetic test set.** Still installed and shown in the OCR Workspace. These scores are on
10,000 computer-made images, so they **cannot be compared** with the table above:

| Model | CER | WER | Exact match | Reading time per image (T4 GPU) |
| :--- | ---: | ---: | ---: | ---: |
| Microsoft base, not fine-tuned (`TrOcr-base-version-handwritten`) | 49.72% | 93.33% | 6.11% | 43.2 ms |
| Fine-tuned on 50k other synthetic samples (`TrOcr-50k-broken`) | 14.26% | 36.39% | 56.05% | 46.7 ms |
| Fine-tuned on 80,000 of these synthetic images (`100k-finetuned-dataset-model`, in use until about 25 Nov) | 4.33% | 15.45% | 79.20% | 49.8 ms |

**What these results show, and what they do not:**

- These are the **first scores on real ledger lines**, and fine-tuning made a large, real improvement on them.
- **The test lines come from the same pages as the training lines.** The real dataset was split by shuffling file
  names, not by page or by book, so the same writers, ink and paper are in both, and 837 of the 1,159 test lines (72%)
  have a text that also appears in training. The overall 79.2% is therefore **optimistic** for a new book.
- **The fairest single figure is the "never seen" part: 46.6% exact match, 13.2% CER.** Quote it beside the overall one.
- The dataset records a label review (435 crops flagged, 48 labels corrected, 49 crops removed), but not which books,
  pages and years the lines came from: its manifest has no page or book column. Write that down before the defense
  ([TRAINING-DATA.md](TRAINING-DATA.md)).
- Confidence scores have not been checked against real accuracy (calibration).
- The stage 1 set, which ran on Kaggle, was made from real data; see [8.1](#81-privacy).

### 7.8 Keeping benchmark numbers honest

The OCR Workspace and the Dashboard show a model's scores **only** from an `evaluation-report.json` inside the model folder. The OCR service accepts the report only if:

- it is schema version 1, measured on the `test` split, with a positive sample count,
- it names the dataset and the SHA-256 hash of the dataset's manifest file,
- it names the weights file and its **SHA-256 hash**, and the service hashes the real weights file and refuses the report if they differ. So scores from one training run cannot be attached to another model.

Two models are compared on the chart only when their reports name the same dataset, manifest hash and sample count:
the model in use is compared with the base model on the real lines, and the three older models with each other on the
synthetic set. CRMS never computes "accuracy" from everyday scans, because corrections made while verifying are not a
controlled test.

### 7.9 Speed

Measured during development (not a formal benchmark):

| What | Result | Where measured |
| :--- | :--- | :--- |
| Scan with OCR, all pages, 13–25 Nov 2026 | 461 runs: 21.2 s on average for 129 lines on average; reading 19.5 ms per line | Development laptop with GPU (`page_runs` table) |
| Scan with OCR of a 220-line register page | 28.2 s in all, reading 23.7 ms per line | One run, 13–25 Nov 2026 (`page_runs`) |
| The same, before the November speed-ups | 58 to 69 s (average 62 s), reading about 81 ms per line | 4 runs, 31 Oct to 3 Nov 2026 (`page_runs`) |
| Reading in fp16 instead of fp32 | 225 lines: 8.1 s → 4.0 s (2.8 s with the other reading speed-ups); same accuracy on 517 corrected lines | RTX 4050 laptop GPU |
| Line finder service kept loaded | Line finding 21 s → 13.5 s per register page; 4-page batch 109 s → 71 s | Development laptop |
| Kraken's ridge filter at five sizes side by side | 5.8 s → 3.0 s, identical output | Development laptop |
| Line detector opted out of Windows' efficiency mode | Register page 42–49 s → 19–20 s, same lines (56 of 84 real scans had been slow) | Intel i5-14500HX |
| Reading 40 line images in batches instead of one by one | 6.14 s → 2.99 s | RTX 4050 laptop GPU |
| Tracing Kraken's outlines in 4 processes | 6.6 s → 2.3 s | Intel i5-14500HX, one register page |
| Kraken on the GPU instead of the CPU | about 24 s → 17 s per ledger page | RTX 4050 laptop GPU |
| Re-outlining after markers moved (reusing detection) | 48.5 s → 4.8 s (register page); 15.5 s → 0.4 s (small crop) | Development laptop |
| Reading one test image | 12.0 ms (fp16, in batches) | RTX 4050, the model's evaluation report |
| Without a GPU | about 2 minutes per page | Comment in `docker-compose.yml` |
| Snap to table | about a second | Code comment, server side |

---

## 8. Security, privacy and data integrity

### 8.1 Privacy

- **No internet, no cloud while it runs.** Every part runs on one computer. The website, the OCR service and the line finder service listen on `127.0.0.1` only, so other computers on the network cannot open CRMS. The AI model is never downloaded by itself.
- **Files are private.** Scans and line images are stored outside the public web folder and are only served to the signed-in account, with "no-sniff" headers.
- **Unfinished work is deleted.** A cancelled page is deleted at once. A page nobody saves is deleted after 24 hours by the hourly clean-up, and a batch left open after 7 days.
- **Training data.** The training export writes only to this computer, and the code warns that it must not be
  uploaded. The model in use was trained in two stages ([7.5](#75-training-the-model)): stage 2, on the real ledger
  lines, ran on the development laptop. **Stage 1 ran on Kaggle**, on a set made from a profile of the real data
  (whose README says it holds real words, names and scanned paper, and must not be uploaded), and whose own notes say
  that 3,835 of its images recombine real handwriting. That means real registry data was very likely uploaded to
  Kaggle. Confirm this with the person who trained the model, and treat it in the manuscript's Data Privacy
  discussion ([TRAINING-DATA.md](TRAINING-DATA.md), [11.3](#113-risks-and-gaps-found-in-this-review)).

### 8.2 Sign-in and permissions

- No public sign-up; the one account is created at installation.
- Passwords stored as bcrypt hashes; temporary passwords shown once, never stored in plain text, never logged.
- Sign-in limited to 5 attempts per email and computer; the session is renewed at sign-in.
- Forced password change at the first sign-in and after a temporary password, on every page (a new page cannot forget it).
- **Recovery codes**: 10 one-time codes of 10 characters (about 50 bits each; old 6-digit sets work until replaced); only a keyed hash of each (HMAC-SHA256 with the app's secret key) is
  stored; at most 5 wrong codes per email an hour; a wrong email and a wrong code get the same answer; a wrong code
  for the account's own email is logged (`user.recovery_failed`).
- The account's name and email can be changed once from the screen; a second change needs the command line.
- Every route checks a named permission (Laravel "gates"), and the menu is built from the same permissions.

### 8.3 Protecting the records

- **No delete route** exists for records. Values change only in Review & Export (`PATCH records/{id}/review`), which
  refuses a value of another record or a page the record does not have, checks every register row, and saves in one
  database transaction.
- Every such save writes **one `record.edited` audit entry** with each old and new value, so no value is lost.
- **A register page is saved once**: a book and page with register rows is refused on a new save and on an edit,
  checked again inside the save with the book type locked, so two tabs cannot both save the same page.
- The **audit log is append-only**: the code refuses to update or delete an entry, and passwords and tokens are redacted.
- A save is done in **one database transaction**: if anything fails, nothing is half-saved and the copied files are removed.
- Every record keeps the exact layout and the exact images the AI read.

### 8.4 Between the website and the services

- Every call to the OCR service and the line finder service carries the shared secret `X-CRMS-Service-Key`, compared in constant time. Without it they answer 401. Only `/health` (is it up?) is open.
- Model uploads from the browser need a **signed ticket** (HMAC-SHA256 over the model name, user, expiry time and a random value), valid 15 minutes by default and at most 1 hour.
- Uploaded ZIPs are checked: at most 500 files and 12 GB unpacked (against "zip bombs"), only expected file types, and no file may land outside the models folder.

### 8.5 Checking what comes in

- Scans: a PDF of up to 1 GB opens in the browser, but only the page being read is sent; a record's scan (an image, or
  that page) is at most 200 MB, and images at most 20 MB. The page sent for line finding must be a PNG of at most 100 MB.
- Book and page numbers: digits only, at most 9.
- Register rows: required fields, digits where digits belong, whole dates (YYYY-MM-DD), values from the fixed lists,
  and length limits; anything wrong stops the save with "Row 06: …" messages.
- Markers must stay inside the page; at most 450 fields, 60 columns and 400 row lines per page.
- A bad value typed into an address bar (a list where one value belongs, an impossible date) shows a message instead of an error page.
- CSV cells that could run as Excel formulas are neutralized.

### 8.6 Known security gaps

| Gap | Why it matters | Suggested fix |
| :--- | :--- | :--- |
| Real data in a training set used on Kaggle | Stage 1's set was made from real words, names and paper, and holds 3,835 recombined pieces of real handwriting ([8.1](#81-privacy)) | Confirm; if so, delete the Kaggle dataset and say so in the manuscript; later, retrain stage 1 on public names and plain paper only |
| PHP 8.2.12 (XAMPP) | A security review on 25 Oct 2026 found it affected by a published critical PHP vulnerability fixed in PHP 8.2.35; PHP 8.2 security support ends 31 Dec 2026 | Update PHP (Docker: rebuild the image) |
| MySQL 9.3 on Windows | Out of support; the Windows MySQL also accepts network connections, though only `root@localhost` exists | Bind MySQL to 127.0.0.1; move to 9.7 (Docker already uses it) |
| No encryption at rest | The database and scan files are plain on disk | Turn on disk encryption (BitLocker on Windows, FileVault on Mac) |
| Audit log protected by the application only | Someone with direct database access could still change rows; there is no hash chain | Restrict database access; optionally chain entry hashes |
| A saved record's values are edited directly, with no second person approving | With one account there is no separation of duties; a wrong edit is caught only by reading the audit log | Review `record.edited` entries regularly (the dashboard's **Edits after saving** links to them) |
| Recovery codes depend on the app's secret key | Changing `APP_KEY` makes every code useless | Make a new set of codes after any key change |
| Backups are manual | A disk failure could lose records | Schedule the backup in [DOCKER-SETUP.md](DOCKER-SETUP.md#backup-and-restore) (database dump plus the `docker-data` folder) |
| `php artisan serve` is a development web server | Fine for one local computer, not for a shared office server | Use Nginx or Apache with PHP-FPM if CRMS is ever shared on a network |

(The 3 November edition listed "the seeded Super Admin password is not forced to change". That is fixed: the first
sign-in now asks for a new password.)

---

## 9. Testing and evaluation

### 9.1 Automated tests

All four suites were run on **27 November 2026** for this document, on branch `gono-latest-v1` with the fixes of this
review (the code committed as `40d36db`):

| Suite | What it tests | Tests | Passed | Failed | Skipped | Time |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: |
| PHP (PHPUnit), 34 feature + 1 unit test classes | Website: permissions, sign-in and recovery, scanning and saving, register rows, batches, records, layouts, OCR client | 436 | 436 | 0 | 0 | 79 s |
| JavaScript (Node test runner), 15 files | Screen logic: markers, ledger grid, row lines, line geometry, value and date checks, records table, batches, undo | 194 | 194 | 0 | 0 | 1 s |
| Python, line detection (Kraken environment), 3 files | Line finding on synthetic pages and many ledger layouts; the line finder service | 123 | 122 | 0 | 1 (Linux only) | 60 s |
| Python, OCR service, 4 files | Service key, batched reading, GPU fallback, report checks, no model installed | 30 | 29 | 0 | 1 | 8 s |
| **Total** | | **783** | **781** | **0** | **2** | |

**Fixed in this review.** One line-detection test (*rows guessed down to a footer line stop at the table*) failed
before the fixes: its first check expected the row finder to guess rows on its drawing, which it no longer does since
the faint-rows fix of 13 November (commit `152cbac`). The behaviour it is about, the rows stopping at the table, still
held, so the test now checks that directly. Two new PHP tests check that a book and page saved by another tab during
a save, or during an edit, is still refused; both fail without the fix. `tools/test-all.ps1` now runs all 7 Python
test files.

**The skipped tests**: one needs Linux (stopping helper processes), and one checks a fast image-resizing path that this
version of Transformers does not take (it uses the standard processor instead, as it should).

All 5 failures of 3 November are gone too (their tests were updated or removed).

### 9.2 What the tests cover

| Area | Example tests |
| :--- | :--- |
| Permissions and the one account | `CapabilityMatrixTest` (every page, and removed pages give Not Found), `SingleAccountTest`, `AuthenticationTest`, `DetailsLockTest` (name and email once) |
| Forgot password | `PasswordRecoveryTest`: codes work once, wrong codes limited, same answer for a wrong email |
| Scanning and saving | `DocumentUploadWorkflowTest`, `LineOutlinePipelineTest`, `TwoPageRecordTest`, `MultiPageSourceTest`: page job, outlines, line fixes, missing required fields, spreads, pages of one PDF |
| Register tables | `RegistryRowsTest` (rows checked and saved, a book and page saved once, even from two tabs, Columns → fields kept), `SavedRegisterEditTest`, `DocumentReferenceTest` (the book and page check); `RegisterDatesTest` (dates to YYYY-MM-DD, repaired dates) |
| Batches | `ScanBatchTest`: kept on the server, versions, resume where it was left, release; `scan-source.test.js`: fingerprints, pages, page pairs, batch states |
| Records and downloads | `RecordArchiveFilterTest`, `RecordsArchiveListTest`, `RecordDetailPresentationTest`, `RecordPersonNameTest`, `RecordCsvExportTest`, `RecordsExportTest` |
| Review & Export | `RecordReviewTest`: only changed values saved, old and new logged, added cells, other records' fields refused; `review-table.test.js`: rows per person, register columns, CSV and Excel, keys, a batch as one table |
| Layouts | `DocumentTemplateBuilderTest`, `TemplateVersioningTest`, `TemplateBuilderGridChecksTest`, `TemplateFieldSettingsTest`, `TemplatePublishFlowTest`, `LedgerTemplateAndExportTest`; `ledger-grid.test.js`, `layout-publish-checks.test.js` |
| Dashboard | `AnalyticsDashboardTest`: each panel's own period, bad periods in the address |
| OCR | `OcrClientTest` (the service key on every call), `OcrWorkspaceTest`, `OcrModelPerformanceTest`; Python: `test_service_key`, `test_ocr_batch`, `test_evaluation_report`, `test_no_model_installed` |
| Line detection | `test_line_markers.py`, `test_grid_layouts.py` (ledgers with different columns, rows, headers and missing lines), `test_line_markers_service.py` |

### 9.3 User evaluation instrument

[`docs/System-Evaluation.pdf`](System-Evaluation.pdf) is a ready-made evaluation kit based on **ISO/IEC 25010** (functional suitability, performance efficiency, usability) and the **System Usability Scale (SUS)**:

| Part | Contents |
| :--- | :--- |
| 1–2 | What is measured, and the plan (respondents, test documents, procedure) |
| 3 | 15 functional tasks marked Pass or Fail, a functional suitability score, and an OCR accuracy table per document type |
| 4 | Timing sheet: manual encoding time against system time for 10 documents, corrections made, time saved |
| 5 | Questionnaire: 15 statements (functionality, efficiency, usability) on a 5-point scale, plus the 10 SUS statements |
| 6 | Scoring: mean ranges and their verbal interpretation, the SUS formula |

The copy in the repository is **blank**. No respondent results are stored in the repository. Its functional tasks
were written before the register tables, batches and recovery codes; check them against [section 4](#4-the-screens-and-features) before use.

---

## 10. Installing and running

Full steps are in the [README](../README.md) and [DOCKER-SETUP.md](DOCKER-SETUP.md). In short:

**Docker (any computer):**

1. Get the code and copy `.env.example` to `.env`.
2. Put a model folder in `ml/models/` (or add it later in the OCR Workspace).
3. Run `docker-start.ps1` (Windows) or `docker-start.sh` (Mac/Linux), then `docker compose exec web php artisan key:generate` and `docker compose run --rm migrate --seed` (migrations run as the database owner, which the running app never uses).
4. Open http://localhost:8080, sign in, choose a new password, and activate the model in the OCR Workspace.
5. In Account Settings, make the **recovery codes** and keep them somewhere safe.

Daily use: `docker compose up -d` to start, `docker compose down` to stop. **Never run `docker compose down -v` on a computer with real records**: it deletes the database.

**Windows by hand:** install PHP, Composer, Node, MySQL and Python; build the two Python environments (`.venv` for the
OCR service, `ml/.venv-kraken` for line detection); run `.\serve.ps1`, which checks everything and starts the website,
worker, scheduler, OCR service and line finder service. Open http://127.0.0.1:8000.

**After pulling new code:** `php artisan migrate` (the register tables and batches came with new migrations), and
once, `php artisan crms:normalize-dates` for register dates saved before 25 November.

---

## 11. Review and analysis

### 11.1 Strengths

1. **A person checks every value.** The AI never writes to the archive on its own, and the server enforces it.
2. **Register-ready output.** Each person on a saved page is one typed row of the book's register table, checked
   field by field, with dates in one format, and a whole book downloads as one CSV for the client's old system.
3. **Privacy by design while it runs.** Fully offline, private file storage, automatic deletion of unfinished pages and batches.
4. **Line outlines instead of rectangles** handle the real problems of register books: drifting handwriting, tails crossing rows, merged cells, tilted scans, curved and faint rules, and more or fewer rows than the layout.
5. **Strong traceability.** Every record keeps the AI reading next to the approved value, the exact images read, the model used and the exact layout; every action is in an append-only audit log.
6. **Honest AI reporting.** Scores come only from locked test sets bound to the model file by hash; confidence is described as certainty, not accuracy; models are compared only on the same test set.
7. **Measured on real ledger lines**: CER 94.2% → 6.3% and exact match 6.2% → 79.2% on 1,159 real lines, with a significance test (and an honest "never seen" figure beside it).
8. **Batch work that survives a reload or a crash**: a whole book can be queued, read at once, checked record by record and resumed where it was left.
9. **Resilience and speed**: slow work in the background, cancel at any point, GPU-to-CPU fallback, one bad line image never fails the page; a register page now scans in about 20–30 seconds instead of about a minute.
10. **Runs anywhere** through Docker (Windows, Linux, Mac), with an optional NVIDIA GPU.
11. **Large automated test suite** (783 tests across PHP, JavaScript and Python, none failing).

### 11.2 Limitations

| Limitation | Effect |
| :--- | :--- |
| **Real-line test set split by file name, not by page** | Test lines share pages, writers and many texts with training, so the 79.2% overstates accuracy on a new book. The "never seen" figure (46.6% exact) is closer. |
| Training data is mostly from a few books | The real lines and the synthetic style come from the pages collected so far; other books, eras and writers may read worse. |
| Confidence not calibrated | A high confidence does not guarantee a correct reading. |
| Register tables for three types only | Birth, Death and Marriage have register tables and book downloads; a custom type is saved only as page records. |
| One record at a time to check | A batch reads many pages at once, but each record is still checked in Verify and Review & Export by hand. |
| No way to fix outlines before reading | With the smoke test hidden, a badly cut line is found and redrawn only after reading, in Verify. |
| One reading model for everything | Typed or printed text on a page is read by the same handwriting model; there is no separate engine for printed text. |
| One record per page | A ledger page with many people is one record; Saved Records search finds the page, not a single person's own record (the register tables hold the people). |
| Only this computer can open it | Ports are bound to `127.0.0.1`, so several staff on different computers cannot use one installation as set up today. |
| Line-finding rules tuned on a few sample pages | Very different ledger layouts may need layout adjustments or new rules. |
| Search uses simple text matching (`LIKE`) | Fine for hundreds of records; slower with many thousands. |
| No screen for the confidence threshold | It can only be changed in `.env`. |
| Processing history is short | Run times are recorded only since `page_runs` was added (1 Nov 2026), mostly from development scans. |

### 11.3 Risks and gaps found in this review

**Fixed after this review (27 November 2026, commit `40d36db`):**

| Problem found | What was done |
| :--- | :--- |
| Two saves of the same book and page at the very same moment (two tabs) could both pass the "already saved" check, because the database has no unique key since 27 November | The check is made again inside the save's transaction with the document type's row locked, for new saves (`DocumentScanController`) and edits (`RecordReviewController`); two new tests prove a page saved meanwhile is refused |
| After saving, the message said "Change it later with **Correct record**", a feature removed on 15 November | It now says "Edit in Review & Export" |
| 1 out-of-date line-detection test | Its first check now tests what the row finder does today; all tests pass ([9.1](#91-automated-tests)) |
| `tools/test-all.ps1` left out 2 Python test files | It runs all 7 |
| The README and DEVELOPER-GUIDE were out of date (old model and test counts, "password change not forced", removed features, nothing on register tables or recovery codes) | Both updated |
| The training data and the stage 2 notebook were not documented in the repository | The dataset card [TRAINING-DATA.md](TRAINING-DATA.md) and both training notebooks (outputs removed) are in the repository |

**Still open (each needs a decision or an answer from the team):**

1. **Real data in the Kaggle training set** ([8.1](#81-privacy)). The stage 1 set (fingerprint matches the one in the
   model's report) was made from real words, names and paper, holds 3,835 recombined real-handwriting images, and was
   trained on Kaggle. Confirm, then disclose it and delete the Kaggle copy, or retrain stage 1 without real data. This
   affects the manuscript's privacy claims.
2. **Which books and pages the real lines came from** is not recorded anywhere (the dataset has no page column). Write
   it down in [TRAINING-DATA.md](TRAINING-DATA.md), with who checked the labels.
3. **Security items in [8.6](#86-known-security-gaps)**: outdated PHP and MySQL, no encryption at rest, record edits
   with no second approver, manual backups. These change the computer, not the code.
4. **Branches.** `main` lacks the 26–27 November work and these fixes; they are on `gono-latest-v1` (pushed). Merge
   into `main` before the defense, so the code shown matches this document.
5. **The hidden smoke test**: show it again or remove its code.
6. **Technical debt** (does not affect users today):
   - The document type is stored twice (`document_type_id` and an older `doc_type` column with a fixed list).
   - Very large files: `resources/views/scan/workspace.blade.php` (15,682 lines, mostly JavaScript), `ml/line_markers.py` (4,035), `resources/js/template-builder.js` (3,094), `resources/js/review-table.js` (2,753), `app/Http/Controllers/DocumentTemplateController.php` (1,853).
   - Old migrations create and later drop two unused tables (`ml_jobs`, `ml_datasets`).
   - The development database `crms` also holds `record_downloads` and `skipped_source_pages`, made by migrations from
     other branches; a fresh install does not have them. Drop them only once no branch needs them.

### 11.4 Recommendations

In order of value for the defense and for real use:

1. **Settle the training-data privacy question** (open item 1) and complete the dataset card (open item 2).
2. **Measure accuracy on a book the model never saw.** Keep one real book (or a set of pages) out of all training,
   score the base and active models on it, and report it per document type, as Part 3 of the evaluation kit asks.
   This is the strongest evidence missing.
3. **Run the user evaluation** in `docs/System-Evaluation.pdf` (functional checklist, timing against manual encoding, questionnaire and SUS) with real registry staff.
4. **Merge `gono-latest-v1` into `main`**, so the code shown at the defense is the code tested here.
5. **Close the quick security gaps**: update PHP, bind MySQL to 127.0.0.1, turn on disk encryption, schedule backups.
6. Later: network access for several staff computers (with HTTPS), full-text search, splitting the largest files.

---

## 12. Where things are in the code

| Feature | Main files |
| :--- | :--- |
| All web addresses and their permissions | `routes/web.php` |
| Permission rules | `app/Providers/AuthServiceProvider.php`, `app/Enums/RoleSlug.php` |
| Sidebar menu | `app/Support/Navigation.php` |
| Sign-in, forced password change | `app/Http/Controllers/Auth/LoginController.php`, `PasswordChangeController.php`, `app/Http/Middleware/`, `app/Http/Requests/Auth/LoginRequest.php` |
| Forgot password, recovery codes | `app/Http/Controllers/Auth/PasswordRecoveryController.php`, `app/Models/User.php`, `resources/views/auth/forgot-password.blade.php` |
| Account Settings | `app/Http/Controllers/AccountSettingsController.php`, `resources/views/settings/edit.blade.php`, `app/Console/Commands/ResetPassword.php`, `UnlockDetails.php` |
| Dashboard | `app/Http/Controllers/DashboardController.php`, `app/Services/DashboardAnalytics.php`, `resources/views/dashboard.blade.php`, `resources/js/dashboard-analytics.js` |
| Scan screen (Document, Align, Verify, Review & Export) | `app/Http/Controllers/DocumentScanController.php`, `resources/views/scan/workspace.blade.php`, `resources/js/field-marker.js`, `line-overlay.js`, `ledger-grid.js`, `row-lines.js`, `line-geometry.js`, `review-table.js`, `scan-source.js`, `pdf-source.js`, `scan-progress.js` |
| Batch File Upload | `app/Http/Controllers/ScanBatchController.php`, `app/Models/ScanBatch*.php`, `resources/js/scan-source.js`, `resources/views/scan/workspace.blade.php` |
| Register tables, Columns → fields, dates | `app/Support/RegistryFields.php`, `app/Models/BirthRecord.php`, `DeathRecord.php`, `MarriageRecord.php`, `resources/js/value-types.js`, `app/Console/Commands/NormalizeRegisterDates.php` |
| Pages in progress, line fixes | `app/Http/Controllers/DocumentPageController.php`, `app/Jobs/ProcessDocumentPage.php`, `app/Services/Lines/` |
| Line detection | `ml/line_markers.py`, `ml/line_markers_service.py` |
| Reading service | `ml/api/main.py`, `ml/trocr_common.py`, `app/Services/Ocr/OcrClient.php` |
| Saved Records, book download | `app/Http/Controllers/RecordController.php`, `app/Services/RecordFieldGrouper.php`, `app/Services/RecordCsv.php`, `app/Support/SimpleXlsx.php`, `resources/views/records/` |
| Review & Export of a saved record | `app/Http/Controllers/RecordReviewController.php`, `resources/views/records/review.blade.php`, `resources/js/record-review.js`, `review-table.js` |
| Audit log | `app/Services/AuditLogger.php`, `app/Models/AuditLog.php`, `app/Http/Controllers/AuditLogController.php` |
| Template Builder | `app/Http/Controllers/DocumentTemplateController.php`, `DocumentTypeDefinitionController.php`, `resources/js/template-builder.js`, `layout-publish-checks.js`, `resources/views/templates/` |
| OCR Workspace | `app/Http/Controllers/OcrModelController.php`, `OcrUploadController.php`, `app/Services/Ocr/`, `resources/js/ocr-workspace.js` |
| Clean-up and training export | `app/Console/Commands/`, `routes/console.php` |
| Database structure | `database/migrations/`, `app/Models/` |
| Training and scoring | `finetuning-kaggle/*.ipynb` (the model in use: `trocr-finetuning-code.ipynb`, then `trocr-finetuning-from-model.ipynb`), `docs/TRAINING-DATA.md`, `ml/train_trocr.py`, `ml/test_finetuned.py`, `ml/metrics.py` |
| Docker | `docker-compose.yml`, `docker-compose.gpu.yml`, `docker/` |
| Tests | `tests/Feature/`, `tests/Unit/` (PHP), `tests/JavaScript/`, `tests/Python/`, `tools/test-all.ps1` |

---

## 13. Glossary

| Term | Meaning |
| :--- | :--- |
| **Align** | Step 2 of scanning: placing the layout's markers on the scanned page and typing its book and page number |
| **Audit log** | The permanent list of who did what and when |
| **Baseline** | The imaginary line handwriting sits on; Kraken finds it for every line |
| **Batch** | Many files or pages queued, read together with Scan all, and checked and saved one record at a time |
| **Book No. / Page No.** | The register book and page a scan is; a book and page is saved once |
| **CER / WER** | Character / word error rate: how much of the text the model got wrong (lower is better) |
| **Columns → fields** | The step 4 choice of which scanned column fills each register field; kept on the layout |
| **Confidence** | The model's certainty in its own reading (0–100%), not its accuracy |
| **Crop** | The small image of one line, cut from the page, that TrOCR reads |
| **Deskew** | Straightening a page that was scanned slightly turned |
| **Docker** | A tool that runs each part of CRMS in its own ready-made "container", the same on every computer |
| **Epoch** | One full pass of training over the training images |
| **Exact match** | Share of lines read exactly right |
| **fp16** | Half precision: the model's numbers kept in 16 bits on the GPU, which reads about twice as fast |
| **Fine-tuning** | Training an existing AI model further on new data so it fits a task better |
| **GPU** | Graphics card; it runs the AI much faster than the processor (CPU) |
| **Grid notes** | Warnings that the table grid may not fit the page |
| **Kraken** | Open-source software that finds lines of writing on a page |
| **Layout / template** | The description of where each value sits on a document type |
| **Ledger / register** | A book of entries in ruled rows and columns; in CRMS each row is one person |
| **Line finder service** | An optional local program that keeps Kraken loaded between pages, so scans are faster |
| **Marker** | A box or column on the page that says where a value is |
| **OCR** | Optical character recognition: turning a picture of text into text |
| **Outline / polygon** | The shape traced around one line of writing |
| **Person group** | The values that belong to one person (one ledger row, or the mother's fields on a certificate) |
| **Queue worker** | The background program that does slow work so the website stays fast |
| **Re-align** | Sending a read document of a batch back to Align, to be read again |
| **Recovery code** | One of 10 one-time 10-character codes that let the account set a new password from the sign-in page |
| **Register table / register row** | `birth_records`, `death_records` or `marriage_records`: one typed row per person, linked to the saved page record |
| **Review & Export** | Step 4 of scanning, and the edit page of a saved record: the values as a table, one row per person, to edit and download as CSV or Excel |
| **Scheduler** | The background program that runs the hourly clean-up |
| **Service key** | The shared secret the website sends with every call to the OCR service and the line finder service |
| **SHA-256** | A fingerprint of a file; any change to the file changes it |
| **Smoke test scan** | Formerly "Detect fields and ink": outlining every line without reading. Hidden since 25 November 2026 |
| **Snap to table** | Fitting the markers to the printed table in about a second |
| **Spread / two-page layout** | A register's left and right pages, aligned and verified side by side and saved as one record |
| **Synthetic data** | Computer-generated images made to look like handwriting |
| **Test split** | Images kept apart and never used for training, used only to score the model |
| **TrOCR** | Microsoft's Transformer-based model that reads one line of text from an image |
| **Validation split** | Images used during training to choose the best epoch |
| **Verify** | Step 3 of scanning: checking and ticking every value |

---

## Appendix A. Status codes, flags and notes

**Page status** (`document_pages.status`)

| Code | Meaning |
| :--- | :--- |
| `queued` | Waiting for the queue worker |
| `detecting` | Lines are being found and outlined |
| `detected` | Smoke test finished: outlines ready, nothing read yet |
| `reading` | TrOCR is reading the lines |
| `ready` | Read; ready for Verify |
| `failed` | Stopped with an error; scan again |
| `cancelled` | Cancelled by the user; deleted by the worker |

**Batch page status** (`scan_batch_pages.status`)

| Code | Meaning |
| :--- | :--- |
| `pending` | Not aligned and queued yet (also a page sent back by Re-align) |
| `saved` | Aligned and queued, waiting for Scan all |
| `scanning` | Being read |
| `ready` | Read; ready to check |
| `imported` | Saved as a record |
| `rejected` | Taken out of the batch (× in the queue list) |
| `failed` | Could not be read |

**Batch status** (`scan_batches.status`): `open` (being worked on, or kept for later), `done`, `abandoned` (discarded).

**Line source** (`page_lines.source`)

| Code | Meaning |
| :--- | :--- |
| `kraken` | Found by Kraken |
| `ink` | Built from ink in a cell where Kraken found no line |
| `field` | A written line found inside a rectangle field |
| `template` | An older rectangle field passed through unchanged |
| `manual` | Drawn by hand (Add missed field, Adjust outline, Add line) |

**Line flags**

| Code | Meaning |
| :--- | :--- |
| `no_row` | The line sits between two rows and could not be placed with confidence |
| `shared_cell` | Two or more lines ended up in the same cell |
| `redraw` | Its crop was deleted in Verify and must be drawn again (or it is left out) |

**Grid notes** (shown at the top of Verify)

| Code | Meaning |
| :--- | :--- |
| `rows_uneven` | Some rows differ a lot in height from the layout's |
| `lines_below_grid` | Written lines below the last row (they are not read) |
| `lines_above_grid` | Written lines in a row-sized band above the first row |
| `grid_moved` | The smoke test moved the grid by some rows |
| `rows_do_not_fit` | The writing does not follow the rows, so the columns were read as free text |
| `rows_empty` | Rows of the table with no writing |

**Record status:** `submitted` (the only one; every record is locked when it is saved).

## Appendix B. Audit log actions

| Area | Actions |
| :--- | :--- |
| Sign-in | `auth.login`, `auth.logout` |
| The account | `user.updated` (name or email), `user.password_changed`, `user.password_reset` (command line), `user.password_recovered` (recovery code), `user.recovery_failed`, `user.recovery_codes_generated`, `user.details_unlocked` |
| Records | `record.submitted`, `record.edited` (a save in Review & Export, with every old and new value), `record.exported` (one record, several records, or one book) |
| Register tables | `registry.dates_normalized` (`crms:normalize-dates`) |
| Exports | `training.exported` |
| Document types | `document-type.created`, `document-type.renamed`, `document-type.archived`, `document-type.restored`, `document-type.deleted` |
| Layouts | `template.created`, `template.updated`, `template.versioned` (saved as a duplicate), `template.duplicated`, `template.activated` (published), `template.archived`, `template.restored`, `template.deleted`, `template.sample-uploaded`, `template.sample-replaced`, `template.sample-deleted`, `template.field_map_changed` (Columns → fields) |
| AI models | `ocr_model.added`, `ocr_model.registered`, `ocr_model.restored`, `ocr_model.replaced`, `ocr_model.activated`, `ocr_model.renamed`, `ocr_model.color_changed`, `ocr_model.deleted`, `ocr_model.marked_missing`, `ocr_model.evaluation_imported`, `ocr_model.evaluation_cleared` |
| Removed features (older entries only) | `user.created`, `user.activated`, `user.deactivated`, `users.removed` (11 Nov migration), `change_request.opened`, `change_request.approved`, `change_request.rejected`, `change_request.withdrawn`, `record.corrected`, `report.generated`, `ocr_settings.updated` |

## Appendix C. Main settings

Set in the `.env` file. Defaults from `.env.example`.

| Setting | Default | Meaning |
| :--- | :--- | :--- |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `crms`, `root`, empty | Database connection |
| `QUEUE_CONNECTION` | `database` | The job queue lives in the database |
| `DB_QUEUE_RETRY_AFTER` | `960` | Seconds before a stuck page job is tried again; keep it above the worker's 900 |
| `SESSION_DRIVER`, `SESSION_LIFETIME` | `database`, `120` | Sign-in sessions and their length in minutes |
| `OCR_API_URL` | `http://127.0.0.1:8001` | Where the website reaches the OCR service |
| `OCR_BROWSER_API_URL` | `http://127.0.0.1:8001` | Where the browser uploads models |
| `OCR_API_TIMEOUT` | `120` | Seconds to wait for a reading |
| `OCR_DEVICE` | `auto` | `auto` (GPU if present), `cuda` or `cpu` |
| `OCR_PRECISION` | `fp16` | `fp16` (faster, half the GPU memory) or `fp32`; GPU only |
| `OCR_UPLOAD_SECRET` | empty (uses `APP_KEY`) | The shared secret: signs upload tickets and is the service key |
| `OCR_UPLOAD_TICKET_TTL` | `900` | Seconds an upload ticket is valid (at most 3,600) |
| `OCR_BROWSER_ORIGIN_REGEX` | `localhost` and `127.0.0.1` | Pages allowed to upload models to the OCR service |
| `LINE_MARKERS_PYTHON` | empty (uses `ml/.venv-kraken`) | Python that runs the line detector |
| `LINE_MARKERS_TIMEOUT` | `600` | Seconds one page's line detection may take |
| `LINE_MARKERS_DEVICE` | `auto` | Where Kraken runs |
| `LINE_MARKERS_WORKERS` | `4` | Processes that trace Kraken's outlines |
| `LINE_MARKERS_SERVICE_URL` | `http://127.0.0.1:8002` | The line finder service; empty means always run the program |
| `LINE_MARKERS_KEEP_HOURS` | `24` | Unsaved pages older than this are deleted |
| `CRMS_SCAN_BATCH_KEEP_DAYS` | `7` | An open batch untouched this long is deleted with its files |
| `CRMS_CONFIDENCE_THRESHOLD` | `80` | Readings below this confidence are flagged |
| `CRMS_REPORTING_TIMEZONE` | `Asia/Manila` | Time zone for screens, filters and the CSV |
| `CRMS_SETUP_LOCAL_ONLY` | `true` | The one-time Set up CRMS page (makes the account) opens only on the CRMS computer itself; when `false` (Docker) it asks for the token from `php artisan crms:setup-token` |

## Appendix D. Service endpoints

**OCR service** (`ml/api/main.py`, port 8001):

| Method | Address | What it does | Needs |
| :--- | :--- | :--- | :--- |
| GET | `/health` | Is the service up, GPU or CPU, precision, which models | nothing (open) |
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
  "model": "trocr finetuned final",
  "modelKey": "trocr-finetuned-final"
}
```

**Line finder service** (`ml/line_markers_service.py`, port 8002, optional):

| Method | Address | What it does | Needs |
| :--- | :--- | :--- | :--- |
| GET | `/health` | Is it up, and is Kraken loaded yet? | nothing (open) |
| POST | `/jobs` | Takes one page's line-finding job; answers with its id | service key |
| GET | `/jobs/{id}` | Queued, running or done, with the result | service key |
| POST | `/jobs/{id}/cancel` | The page was cancelled: its result is not wanted | service key |

## Appendix E. Numbers you can quote

| Figure | Value | Source | Say it carefully |
| :--- | :--- | :--- | :--- |
| Model in use: character error rate | 6.28% | `ml/models/trocr-finetuned-final/evaluation-report.json` | On 1,159 **real** ledger lines from the same pages as its training lines |
| Word error rate | 20.47% | same | same |
| Exact match | 79.21% (918 of 1,159) | same | same; optimistic for a new book |
| Exact match on text never seen in training | 46.58% (150 of 322; CER 13.22%) | same, `test_subsets` | The fairest figure for new text |
| Microsoft base model on the same lines | CER 94.15%, WER 135.49%, exact match 6.21% | `base-evaluation-report.json` | Before fine-tuning |
| Improvement significance | 850 fixed, 4 broken, McNemar p < 0.001 | `training-summary.json` | Against the base model, same images |
| Gain from the real lines (stage 2) | exact match 60.8% → 79.2%; 242 fixed, 29 broken, p < 0.001 | `training-summary.json` | Against stage 1's model, same images |
| Training | Stage 1: 23,922 synthetic ledger-style images, 2 epochs, 39.6 min on 2 × T4 (Kaggle). Stage 2: 9,270 real line images, 12 epochs, 105.2 min on an RTX 4050 laptop | `training-summary.json`, [TRAINING-DATA.md](TRAINING-DATA.md) | Stage 1's set was made from real data and ran on Kaggle |
| Model size | 333.9 million parameters | counted from `model.safetensors` | |
| Reading time per line | 12.0 ms (RTX 4050, fp16, test set); 19.5 ms (development laptop, real scans) | report; `page_runs` | Depends on hardware |
| Scan with OCR of a page | 21.2 s on average (461 scans, 129 lines on average); 28.2 s for a 220-line register page | `page_runs`, 13–25 Nov 2026 | Development laptop with GPU; about 2 minutes on CPU only |
| Paper-tone crop fill | 3.1% → 2.0% CER, 440 cells of 2 real pages | `ml/line_markers.py` comment | Design check with an earlier model, not a formal test |
| Automated tests | 783 (781 pass, 2 skipped, none fail) | run of 27 Nov 2026 | After this review's fixes |
| Earlier model on synthetic data | `100k-finetuned-dataset-model`: CER 4.33%, exact match 79.20% on 10,000 synthetic images | its `evaluation-report.json` | Not comparable with the real-line figures |

**Do not quote** averages from the local development database (about 110 test records made with several experimental models). They are not an evaluation sample.

## Appendix F. References to cite

Starting points for the manuscript. Check each against your school's citation style.

- Li, M., Lv, T., Chen, J., Cui, L., Lu, Y., Florencio, D., Zhang, C., Li, Z., & Wei, F. (2023). *TrOCR: Transformer-based Optical Character Recognition with Pre-trained Models.* Proceedings of the AAAI Conference on Artificial Intelligence, 37(11). (Preprint arXiv:2109.10282, 2021.)
- Kiessling, B. (2020). *A Modular Region and Text Line Layout Analysis System.* 17th International Conference on Frontiers in Handwriting Recognition (ICFHR). (Kraken's baseline segmenter.)
- Marti, U.-V., & Bunke, H. (2002). *The IAM-database: an English sentence database for offline handwriting recognition.* International Journal on Document Analysis and Recognition, 5, 39–46. (What `trocr-base-handwritten` was fine-tuned on.)
- Dosovitskiy, A., et al. (2021). *An Image is Worth 16x16 Words: Transformers for Image Recognition at Scale.* ICLR. (Vision Transformer, the encoder type.)
- Wolf, T., et al. (2020). *Transformers: State-of-the-Art Natural Language Processing.* EMNLP: System Demonstrations. (Hugging Face Transformers.)
- Paszke, A., et al. (2019). *PyTorch: An Imperative Style, High-Performance Deep Learning Library.* NeurIPS.
- Shazeer, N., & Stern, M. (2018). *Adafactor: Adaptive Learning Rates with Sublinear Memory Cost.* ICML. (The optimizer of training stage 2.)
- Micikevicius, P., et al. (2018). *Mixed Precision Training.* ICLR. (Half precision, fp16.)
- Levenshtein, V. I. (1966). *Binary codes capable of correcting deletions, insertions, and reversals.* Soviet Physics Doklady, 10(8), 707–710. (Edit distance behind CER and WER.)
- McNemar, Q. (1947). *Note on the sampling error of the difference between correlated proportions or percentages.* Psychometrika, 12(2), 153–157.
- Brooke, J. (1996). *SUS: A "quick and dirty" usability scale.* In Usability Evaluation in Industry. Taylor & Francis.
- ISO/IEC 25010:2011 (revised 2023). *Systems and software Quality Requirements and Evaluation (SQuaRE): System and software quality models.*
- Republic Act No. 10173, *Data Privacy Act of 2012* (Philippines).
