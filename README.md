# Civil Registry Management System (CRMS)

[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat&logo=laravel&logoColor=white)](https://laravel.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php&logoColor=white)](https://www.php.net/)
[![Python](https://img.shields.io/badge/Python-3.10%2B-3776AB?style=flat&logo=python&logoColor=white)](https://www.python.org/)
[![FastAPI](https://img.shields.io/badge/FastAPI-0.100%2B-009688?style=flat&logo=fastapi&logoColor=white)](https://fastapi.tiangolo.com/)
[![PyTorch](https://img.shields.io/badge/PyTorch-2.0%2B-EE4C2C?style=flat&logo=pytorch&logoColor=white)](https://pytorch.org/)
[![Transformers](https://img.shields.io/badge/Hugging%20Face-TrOCR-FFD21E?style=flat&logo=huggingface&logoColor=black)](https://huggingface.co/microsoft/trocr-base-handwritten)
[![Tests](https://img.shields.io/badge/Tests-176%20Passed%20(PHPUnit)-success)](tests/)

An enterprise-grade, AI-assisted civil registry digitisation and archival platform built with **Laravel 12**, **Bootstrap 5 (SNEAT design system)**, **FastAPI**, and a fine-tuned **Microsoft TrOCR** (Transformer-based Optical Character Recognition) handwriting engine.

CRMS enables registry staff to scan historical civil certificates (birth, death, marriage, and custom document types), run machine learning recognition on cropped bounding boxes, perform human-in-the-loop verification, and archive immutable records backed by an append-only audit trail and formal change-request governance.

---

## Table of Contents

- [System Architecture](#system-architecture)
- [Key Features](#key-features)
- [Role-Based Access Control & Capability Matrix](#role-based-access-control--capability-matrix)
- [Project Directory Structure](#project-directory-structure)
- [Prerequisites & Requirements](#prerequisites--requirements)
- [Installation & Setup](#installation--setup)
- [Running the Application](#running-the-application)
- [The TrOCR Machine Learning Pipeline](#the-trocr-machine-learning-pipeline)
- [OCR Microservice API Reference](#ocr-microservice-api-reference)
- [Production Deployment](#production-deployment)
- [Testing & Quality Assurance](#testing--quality-assurance)
- [Environment Configuration Reference](#environment-configuration-reference)
- [Technology Stack](#technology-stack)
- [License](#license)

---

## System Architecture

CRMS operates as a coordinated **two-process system** contained within a single repository:

```
                      +-------------------------------------------------------------+
                      |                        Web Browser                          |
                      +-------------------------------------------------------------+
                        /                       |                                 ^
                       / (1) Web Pages          | (3) Direct Upload               |
                      /      & AJAX Forms       |     (Signed Ticket)             |
                     v                          v                                 |
+-----------------------------------+    +----------------------------------+     |
|         Laravel 12 Web App        |    |       FastAPI OCR Service        |     |
|      (PHP 8.2+ / Port 8000)       |    |     (Python 3.10+ / Port 8001)   |     |
+-----------------------------------+    +----------------------------------+     |
| - Authentication & RBAC           |    | - PyTorch / Hugging Face TrOCR   |     |
| - Document & Template Management  |    | - Handwriting Recognition (/ocr) |     |
| - Bounding-Box Marker Control     |    | - Direct Model Storage & Unpack  |-----+
| - Audit Logging & Change Requests |    | - Evaluation Report Verification | (Model Registration)
| - Analytics & CSV Reports         |    +----------------------------------+
+-----------------------------------+                     ^
       |                    |                             |
       |                    +--- (2) Server-to-Server ----+
       v                             HTTP Requests
+---------------+             (Private Loopback / Health / OCR)
| MySQL 8.0+ DB |
+---------------+
```

| Component | Stack | Primary Responsibilities |
| :--- | :--- | :--- |
| **Web Application** | Laravel 12, Blade, Bootstrap 5 (SNEAT), Vite, MySQL | User interfaces, authentication, template builder, verification workspace, record archival, change request moderation, audit logging, reporting. |
| **OCR Microservice** | FastAPI, PyTorch, Hugging Face `transformers`, Pillow | High-throughput TrOCR handwriting inference, model inventory discovery, signed direct-upload ingestion, model lifecycle management. |

### Architectural Highlights

1. **Zero-PHP Large Model Uploads**: Uploading gigabyte-scale model checkpoints (`safetensors`/`bin` archives) never passes through PHP or consumes web worker memory. Laravel generates a short-lived, HMAC-SHA256 signed ticket (`OCR_UPLOAD_SECRET`); the browser uploads directly to FastAPI's `/add_model` endpoint. Once written to disk, the client posts the model key to Laravel, which verifies the inventory and registers the model in a database transaction.
2. **Server-Side AI Proxying**: Operational document recognition (`/documents/recognise`) is called server-to-server from Laravel to FastAPI. The FastAPI instance remains bound to loopback (`127.0.0.1`) without direct public access.
3. **Decoupled Process Lifecycles**: Laravel never spawns, restarts, or terminates the Python OCR daemon. The OCR workspace actively monitors reachability via asynchronous health polling.

---

## Key Features

### 1. Document Digitisation & Verification Workspace
- **Visual Bounding-Box Markup**: Interactive drag-and-drop marker tool with canvas zoom, pan, and marquee selection for setting field coordinates.
- **Split-Screen Verification Viewer**: Dual-pane workspace with configurable horizontal/vertical split views, smooth keyboard-accelerated split-bar adjustments, and Ctrl-wheel zoom.
- **Person Grouping**: Supports complex registry layouts grouping fields by role (e.g., Child, Mother, Father, Groom, Bride, Deceased, Informant) alongside general document details.
- **Confidence Scoring & Review Warnings**: Computes token-level geometric mean confidence scores (0–100%). Fields falling below the configured threshold are visually flagged for manual operator review.
- **Human-in-the-Loop Enforcement**: Only explicitly verified fields are committed to the permanent record upon submission.

### 2. Dynamic Document Template Builder & Type Management
- **Visual Template Designer**: Create and publish document layouts with real-time coordinate calibration.
- **Paper Specification Support**: Supports standard paper dimensions (A4, Letter, Legal, Folio) as well as arbitrary custom millimeter dimensions in Portrait or Landscape orientations.
- **Sample Document Upload**: Direct upload of PDF or image samples rendered with PDF.js for canvas alignment.
- **Custom Document Types**: Super Admins can define custom certificate classifications with distinct icons, validation rules, and active states.

### 3. Immutable Record Archival & Change Request Governance
- **Permanent Record Locking**: Once verified and submitted by Staff, records are sealed against direct in-place modification.
- **Formal Change-Request Workflow**: Staff initiate modification requests detailing justifications and proposed field values. Admins or Super Admins review, approve, or reject changes.
- **Data Integrity Constraints**: Mandatory captured fields cannot be blanked during correction; all historical iterations remain auditable.

### 4. OCR Model Management & Benchmark Provenance
- **Multi-Model Registry**: Live model scanning from `ml/models/`, support for custom checkpoints and the baseline `microsoft/trocr-base-handwritten`.
- **Benchmark Provenance Radar**: Visualizes Character Error Rate (CER), Word Error Rate (WER), and Exact Match metrics strictly from locked test-split reports (`evaluation-report.json`) verified against model weight SHA-256 hashes. CRMS never fabricates benchmark statistics from operational scans.
- **Operator Selection Flexibility**: Global model assignment with an optional Super Admin toggle allowing Staff to select approved alternative models during digitisation.

### 5. Tamper-Evident Audit Trail
- **Append-Only Logging**: Comprehensive activity logs recording actor ID, name, role, IP address, user agent, action verb, and before/after diffs.
- **Automatic Secret Redaction**: Sensitive attributes (passwords, tokens, credentials) are stripped before persistence.

### 6. Analytics & Administrative Oversight
- **Role-Tailored Dashboards**: Real-time KPI summaries, digitisation volume charts, correction rate tracking, and live OCR engine diagnostic badges.
- **Timezone-Aware Reporting**: Filterable civil registry reporting respecting configured local operational boundaries (e.g., `Asia/Manila`) with streaming CSV exports.
- **Secure Account Management**: Controlled staff/admin provisioning (no public sign-ups), auto-generated temporary passwords, mandatory first-login password rotation, soft deactivation, and protection against accidental Super Admin demotion.

---

## Role-Based Access Control & Capability Matrix

CRMS enforces a strict separation of duties verified end-to-end in the test suite (`tests/Feature/CapabilityMatrixTest.php`):

| Capability / Resource | Staff | Admin | Super Admin | Route / Gate |
| :--- | :---: | :---: | :---: | :--- |
| **Upload & Process Documents** | **Yes** | No | **Yes** | `documents.create`, `can:documents.process` |
| **Verify & Submit Records** | **Yes** | No | **Yes** | `documents.store`, `can:records.submit` |
| **Search & View Record Archive** | **Yes** | **Yes** | **Yes** | `records.index`, `can:records.view` |
| **Propose Change Requests** | **Yes** | No | **Yes** | `records.change-requests.create`, `can:change-requests.create` |
| **Approve / Reject Change Requests** | No | **Yes** | **Yes** | `change-requests.approve`, `can:change-requests.moderate` |
| **Access Consolidated Analytics** | No | **Yes** | **Yes** | `analytics.index`, `can:analytics.view` |
| **Generate & Export CSV Reports** | No | **Yes** | **Yes** | `reports.index`, `can:reports.generate` |
| **Manage User Accounts & Roles** | No | **Yes** | **Yes** | `users.index`, `can:users.manage` |
| **View Tamper-Evident Audit Log** | No | **Yes** | **Yes** | `audit.index`, `can:audit.view` |
| **Document Template Builder** | No | No | **Yes** | `templates.index`, `can:templates.manage` |
| **OCR Model & Engine Workspace** | No | No | **Yes** | `ocr.index`, `can:ocr.manage` |

> **Note on Separation of Duties**: Administrators perform supervisory and oversight functions and cannot perform primary data entry or directly edit civil records. Record corrections must strictly traverse the authenticated change request moderation pipeline.

---

## Project Directory Structure

```
crms-laravel-12/
├── app/                                # Core Laravel application logic
│   ├── Enums/                          # RoleSlug, DocumentType, RecordStatus, PaperSize, etc.
│   ├── Http/
│   │   ├── Controllers/                # Gated web controllers (Scan, Record, ChangeRequest, etc.)
│   │   ├── Middleware/                 # EnsureAccountIsActive, EnsurePasswordIsChanged
│   │   └── Requests/                   # Form validation request classes
│   ├── Models/                         # Eloquent models (CivilRecord, RecordField, AuditLog, etc.)
│   ├── Providers/                      # AuthServiceProvider (Capability Matrix Gate definitions)
│   ├── Services/                       # Business logic (AuditLogger, ChangeRequestService, etc.)
│   │   └── Ocr/                        # OcrClient, OcrModelManager, OcrUploadAuthorizer, EngineStatus
│   └── Support/                        # Navigation and UI support utilities
├── bootstrap/                          # Application bootstrap and middleware pipeline configuration
├── config/                             # Configuration files (crms.php, services.php, database.php)
├── database/
│   ├── factories/                      # Model factories for testing and seeding
│   ├── migrations/                     # Database migrations (records, templates, audit logs, OCR)
│   └── seeders/                        # RoleSeeder, SuperAdminSeeder, DocumentTemplateSeeder, DemoUsersSeeder
├── ml/                                 # Complete Python OCR & Machine Learning workspace
│   ├── api/
│   │   ├── main.py                     # FastAPI microservice (Inference, health, signed model uploads)
│   │   └── requirements.txt            # FastAPI microservice dependencies
│   ├── dataset/                        # Training/validation/test images & manifest CSV (gitignored)
│   ├── models/                         # Fine-tuned model checkpoints (gitignored)
│   ├── evaluation-metrics/             # Timestamped evaluation metric charts
│   ├── dataset_registry.py             # Dataset manifest validation and normalization
│   ├── download_trocr.py               # Downloads Hugging Face base TrOCR weights
│   ├── hf_quiet.py                     # Hugging Face environment logging silencer
│   ├── metrics.py                      # CER / WER / Exact-Match computation and plot generators
│   ├── predict.py                      # Standalone CLI batch prediction tool
│   ├── requirements.txt                # ML pipeline dependencies (PyTorch, Transformers, Pandas)
│   ├── test_finetuned.py               # CLI benchmark evaluator for fine-tuned models
│   ├── test_trocr.py                   # CLI benchmark evaluator for base model
│   └── train_trocr.py                  # PyTorch TrOCR fine-tuning script
├── public/                             # Publicly accessible web root
├── resources/
│   ├── css/ & scss/                    # SNEAT theme & custom CRMS stylesheet rules
│   ├── js/                             # Interactive JS (Template Builder, Field Marker, Split View)
│   └── views/                          # Blade templates organized by domain
├── routes/
│   ├── console.php                     # Artisan console commands
│   └── web.php                         # Application route definitions and permission middleware
├── tests/                              # Automated test suites
│   ├── Feature/                        # 14 PHPUnit feature test classes (RBAC, workflows, OCR)
│   ├── JavaScript/                     # Node.js test runner unit tests (controls, markers, SNEAT)
│   └── Python/                         # Python unit tests for ML evaluation report normalization
├── tools/                              # Development utility scripts (subset-icons.mjs)
├── serve.ps1                           # PowerShell orchestration runner for dual-process startup
├── trocr-finetuning-code.ipynb         # Kaggle / Colab fine-tuning and evaluation notebook
├── vite.config.js                      # Vite asset bundler configuration
├── composer.json                       # PHP dependencies
└── package.json                        # Node.js frontend dependencies
```

---

## Prerequisites & Requirements

Before setting up CRMS, ensure your environment meets the following requirements:

| Component | Minimum Version | Notes |
| :--- | :--- | :--- |
| **PHP** | 8.2+ | Extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `curl`, `zip`, `gd` |
| **Composer** | 2.5+ | PHP package manager |
| **Node.js** | 20.x+ & npm 10+ | JavaScript runtime and asset compiler |
| **MySQL / MariaDB** | MySQL 8.0+ / MariaDB 10.4+ | InnoDB engine, utf8mb4 charset |
| **Python** | 3.10+ | Required for running the FastAPI OCR service and training |
| **PyTorch & CUDA** | PyTorch 2.0+ (CUDA optional) | Optional GPU acceleration for rapid TrOCR inference |

---

## Installation & Setup

### 1. Clone the Repository
```bash
git clone https://github.com/MiroLim999/crms-laravel-12.git
cd crms-laravel-12
```

### 2. Install PHP & Frontend Dependencies
```bash
composer install
npm install
```

### 3. Setup Python Virtual Environment & ML Dependencies
```bash
# Windows
python -m venv .venv
.venv\Scripts\activate

# Linux / macOS
python3 -m venv .venv
source .venv/bin/activate

# Install requirements
pip install -r ml/requirements.txt -r ml/api/requirements.txt
```

> **PyTorch GPU Support**: To enable CUDA GPU acceleration, install the CUDA-enabled PyTorch wheel from [pytorch.org](https://pytorch.org/get-started/locally/) (e.g., `pip install torch --index-url https://download.pytorch.org/whl/cu121`).

### 4. Configure Environment Files
```bash
copy .env.example .env    # Windows PowerShell: copy .env.example .env
php artisan key:generate
```

Edit `.env` to configure your database connection and OCR parameters:
```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=crms
DB_USERNAME=root
DB_PASSWORD=

# OCR Service Configuration
OCR_API_URL=http://127.0.0.1:8001
OCR_BROWSER_API_URL=http://127.0.0.1:8001
CRMS_CONFIDENCE_THRESHOLD=80
CRMS_REPORTING_TIMEZONE=Asia/Manila
```

### 5. Migrate Database & Seed Initial Data
```bash
php artisan migrate --seed
```
`migrate --seed` seeds the core permission roles, default document templates (Birth, Death, Marriage), and the bootstrap Super Admin account.

### 6. Build Frontend Assets
```bash
npm run build
```

### Default Credentials

| Account | Email | Default Password | Role |
| :--- | :--- | :--- | :--- |
| **Bootstrap Super Admin** | `superadmin@admin.com` | `superadmin@admin.com` | Super Admin |

> **Security Warning**: You will be forced to change this password on your first login. To customize default bootstrap credentials before running seeders, specify `CRMS_SUPER_ADMIN_EMAIL` and `CRMS_SUPER_ADMIN_PASSWORD` in your `.env`.

#### Optional Demo Accounts
For development evaluation, you can seed demo Staff and Admin accounts:
```bash
php artisan db:seed --class=DemoUsersSeeder
```
* **Staff**: `staff@crms.test` / `password123`
* **Admin**: `admin@crms.test` / `password123`

---

## Running the Application

### Option A: Using the PowerShell Runner (Windows)
The included `serve.ps1` script performs an automated environment check (PHP, MySQL, Python, CUDA status) and launches both processes in individual windows:

```powershell
.\serve.ps1             # Starts both Laravel (8000) and FastAPI (8001)
.\serve.ps1 -Check      # Verifies environment requirements and exits
.\serve.ps1 -NoOcr      # Launches Laravel only
```

### Option B: Manual Process Execution

Open two separate terminals from the repository root:

**Terminal 1: Laravel Web Application**
```bash
php artisan serve --port=8000
```
Access the application at [http://127.0.0.1:8000](http://127.0.0.1:8000).

**Terminal 2: FastAPI OCR Microservice**
```bash
# Ensure your virtual environment is active
python -m uvicorn ml.api.main:app --host 127.0.0.1 --port 8001
```
The OCR service will listen on [http://127.0.0.1:8001](http://127.0.0.1:8001).

---

## The TrOCR Machine Learning Pipeline

All machine learning scripts, training routines, and evaluation utilities are isolated in the `ml/` directory.

```
ml/
├── train_trocr.py        # Fine-tunes VisionEncoderDecoderModel with Hugging Face Trainer
├── test_trocr.py         # Evaluates base model performance against test split
├── test_finetuned.py     # Evaluates fine-tuned model checkpoints & exports metrics
├── predict.py            # CLI batch inference on directory of image crops
├── metrics.py            # CER, WER, and exact-match computation logic
├── dataset_registry.py   # Dataset manifest validation and path resolution
└── download_trocr.py     # Fetches microsoft/trocr-base-handwritten weights
```

### 1. Dataset Layout Specification
Training datasets must follow this folder structure:
```
ml/dataset/
├── manifest.csv          # Columns: filename,label,split,source
├── train/                # Training image crops (.png, .jpg)
├── val/                  # Validation image crops
└── test/                 # Locked evaluation test split
```
*Entries labeled `UNREADABLE` or with blank labels are automatically skipped.*

### 2. Base Model Download
```bash
python ml/download_trocr.py
```

### 3. Fine-Tuning TrOCR
```bash
python ml/train_trocr.py
```
Fine-tuning configuration parameters (learning rate, batch size, epochs, warmup steps) are defined in the `CONFIG` dictionary of `ml/train_trocr.py`. Checkpoints with the lowest validation loss are saved into `ml/models/`.

### 4. Evaluating Models & Generating Provenance Reports
```bash
python ml/test_finetuned.py --model-dir ml/models/trocr-v1
```
This generates evaluation charts under `ml/evaluation-metrics/` and produces a signed `evaluation-report.json` containing:
- Sample count and dataset provenance
- Character Error Rate (CER), Word Error Rate (WER), Exact Match accuracy
- SHA-256 digests of the model weights file (`model.safetensors`) and the dataset manifest

### 5. Installing Models into the Web Application
1. Log in as **Super Admin** and navigate to the **OCR Workspace**.
2. Click **Add Model** and upload either a `.zip` archive or directory containing `config.json`, `model.safetensors` (or `pytorch_model.bin`), tokenizer files, and `evaluation-report.json`.
3. Select the model under **Model used for scanning** and click **Save settings**.

---

## OCR Microservice API Reference

The FastAPI service exposes the following endpoints (bound to `127.0.0.1:8001`):

| Method | Endpoint | Description | Access / Authorization |
| :--- | :--- | :--- | :--- |
| `GET` | `/health` | Returns service status, hardware device (`cuda`/`cpu`), active model, and model list. | Internal / Private |
| `GET` | `/models` | Returns available installed model metadata and provenance metrics. | Internal / Private |
| `POST` | `/ocr` | Ingests base64/dataURL cropped field images and returns predicted text + confidence scores. | Internal / Private |
| `POST` | `/add_model` | Direct-browser multipart upload endpoint for model archives (`.zip` or loose files). | Signed Ticket (`ticket`, `expires`, `sig`) |
| `POST` | `/rename_model` | Renames a model folder directory on disk. | Internal / Private |
| `POST` | `/delete_model` | Removes an inactive model folder from `ml/models/`. | Internal / Private |

### Sample OCR Request Payload (`POST /ocr`)
```json
{
  "model": "trocr-v1",
  "fields": [
    {
      "name": "child_first_name",
      "image": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."
    }
  ]
}
```

### Sample OCR Response Payload
```json
{
  "results": [
    {
      "name": "child_first_name",
      "text": "MARIA CLARA",
      "confidence": 96.84
    }
  ]
}
```

---

## Production Deployment

### Direct Upload Reverse-Proxy Configuration

In production environments, both Laravel and the browser-facing OCR upload endpoint must be served over HTTPS. Expose only the signed upload endpoint (`/ocr-api/add_model`) to the public network, while keeping internal endpoints strictly on private or loopback networks.

#### Production Environment Variables
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://crms.example.com

# Internal Laravel-to-FastAPI address
OCR_API_URL=http://127.0.0.1:8001
OCR_API_TIMEOUT=120

# Browser-facing direct upload proxy URL
OCR_BROWSER_API_URL=https://crms.example.com/ocr-api
OCR_BROWSER_ORIGIN_REGEX=^https://crms\.example\.com$

# Dedicated HMAC Secret shared by Laravel and FastAPI
OCR_UPLOAD_SECRET=generate-a-cryptographically-secure-production-secret
OCR_UPLOAD_TICKET_TTL=3600
```

#### Example Nginx Proxy Configuration
```nginx
# Public signed direct upload route for model installations
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

### Production Checklist
1. **Persistent Model Storage**: Ensure `ml/models/` is mounted on persistent, non-ephemeral storage.
2. **Process Management**: Run FastAPI under a supervisor daemon (systemd, Supervisor, or Docker restart policies) rather than development uvicorn reloaders.
3. **Configuration Caching**: Rebuild Laravel caches whenever `.env` parameters change:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

---

## Testing & Quality Assurance

CRMS maintains a comprehensive test suite across PHP, JavaScript, and Python layers.

```
tests/
├── Feature/                    # 14 PHPUnit feature test classes (176 tests, 1140 assertions)
│   ├── AnalyticsDashboardTest.php
│   ├── AuditLogTest.php
│   ├── AuditLogViewerTest.php
│   ├── AuthenticationTest.php
│   ├── CapabilityMatrixTest.php
│   ├── ChangeRequestPresentationTest.php
│   ├── ChangeRequestWorkflowTest.php
│   ├── DocumentTemplateBuilderTest.php
│   ├── DocumentUploadWorkflowTest.php
│   ├── OcrModelPerformanceTest.php
│   ├── OcrWorkspaceTest.php
│   ├── RecordDetailPresentationTest.php
│   ├── ReportExportTest.php
│   └── UserManagementTest.php
├── JavaScript/                 # 25 Node.js unit tests (SNEAT controls, shortcuts, markers)
│   ├── change-request.test.js
│   ├── icon-coverage.test.js
│   ├── person-grouping.test.js
│   ├── record-detail.test.js
│   ├── sneat-controls.test.js
│   ├── template-builder-shortcuts.test.js
│   └── verification-groups.test.js
└── Python/                     # Python unit tests for ML evaluation report verification
    └── test_evaluation_report.py
```

### Running Test Suites

#### 1. PHPUnit Automated Tests
Create an isolated test database (`crms_test`) and run the test suite:
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS crms_test;"
php artisan test
```

#### 2. JavaScript / UI Unit Tests
Run frontend logic and SNEAT design token validation tests using Node.js's built-in test runner:
```bash
npm run test:js
```

#### 3. Python Unit Tests
```bash
python -m unittest discover tests/Python
```

#### 4. Pre-Commit Validation Checklist
```bash
php artisan test                         # Verify Laravel business logic & capability matrix
npm run test:js                          # Verify JS workspace logic & button controls
npm run check:icons                      # Verify Boxicons icon subset coverage
npm run build                            # Verify production asset compilation
python -m py_compile ml/api/main.py      # Verify FastAPI microservice syntax
```

---

## Environment Configuration Reference

| Variable | Default | Description |
| :--- | :--- | :--- |
| `APP_NAME` | `"Civil Registry Management System"` | Application branding name. |
| `APP_ENV` | `local` | Application environment (`local`, `production`, `testing`). |
| `APP_KEY` | *(Generated)* | Laravel encryption key. |
| `APP_URL` | `http://localhost` | Canonical base web URL. |
| `DB_CONNECTION` | `mysql` | Database driver (`mysql`). |
| `DB_HOST` | `127.0.0.1` | Database server host. |
| `DB_PORT` | `3306` | Database server port. |
| `DB_DATABASE` | `crms` | Main application database name. |
| `DB_USERNAME` | `root` | Database user username. |
| `DB_PASSWORD` | `""` | Database user password. |
| `OCR_API_URL` | `http://127.0.0.1:8001` | Private address for Laravel-to-FastAPI server calls. |
| `OCR_BROWSER_API_URL` | `http://127.0.0.1:8001` | Browser-resolvable URL for direct multipart model uploads. |
| `OCR_API_TIMEOUT` | `120` | HTTP request timeout (seconds) for OCR inference operations. |
| `OCR_UPLOAD_SECRET` | *(Empty / Falls back to `APP_KEY`)* | HMAC secret for signing direct model upload tickets. |
| `OCR_UPLOAD_TICKET_TTL` | `900` | Validity lifetime in seconds for model upload tickets (max 3600). |
| `OCR_BROWSER_ORIGIN_REGEX` | *(Loopback regex)* | Allowed browser origins regex for CORS upload requests. |
| `CRMS_CONFIDENCE_THRESHOLD` | `80` | Default OCR confidence threshold below which fields flag for review. |
| `CRMS_REPORTING_TIMEZONE` | `Asia/Manila` | Local timezone used for civil registry day/month reporting boundaries. |
| `CRMS_SUPER_ADMIN_NAME` | `"Super Admin"` | Initial name for the bootstrap Super Admin seeder. |
| `CRMS_SUPER_ADMIN_EMAIL` | `superadmin@admin.com` | Initial email for the bootstrap Super Admin seeder. |
| `CRMS_SUPER_ADMIN_PASSWORD` | `superadmin@admin.com` | Initial password for the bootstrap Super Admin seeder. |

---

## Technology Stack

- **Backend**: [Laravel 12](https://laravel.com/), PHP 8.2+, Composer
- **OCR & ML Microservice**: [FastAPI](https://fastapi.tiangolo.com/), [PyTorch](https://pytorch.org/), [Hugging Face Transformers](https://huggingface.co/docs/transformers/index) (Microsoft TrOCR), Pillow, Pandas
- **Frontend & UI**: Blade Templates, [Bootstrap 5](https://getbootstrap.com/), SNEAT Design System, Sass, [Vite](https://vitejs.dev/)
- **Charts & Visuals**: [ApexCharts](https://apexcharts.com/)
- **Document Viewing**: [PDF.js](https://mozilla.github.io/pdf.js/)
- **Icons**: [Boxicons](https://boxicons.com/) (Optimized and subsetted via `@iconify/utils`)
- **Database**: MySQL 8.0+ / MariaDB

---

## License

This project is proprietary civil registry software. All rights reserved.
