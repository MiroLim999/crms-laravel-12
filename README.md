# Civil Registry Management System (CRMS)

[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat&logo=laravel&logoColor=white)](https://laravel.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php&logoColor=white)](https://www.php.net/)
[![Python](https://img.shields.io/badge/Python-3.10%2B-3776AB?style=flat&logo=python&logoColor=white)](https://www.python.org/)
[![FastAPI](https://img.shields.io/badge/FastAPI-0.115-009688?style=flat&logo=fastapi&logoColor=white)](https://fastapi.tiangolo.com/)
[![PyTorch](https://img.shields.io/badge/PyTorch-2.6%2B-EE4C2C?style=flat&logo=pytorch&logoColor=white)](https://pytorch.org/)
[![Transformers](https://img.shields.io/badge/Hugging%20Face-TrOCR-FFD21E?style=flat&logo=huggingface&logoColor=black)](https://huggingface.co/microsoft/trocr-base-handwritten)
[![Tests](https://img.shields.io/badge/Tests-455%20passed%20(PHP%20300%20%C2%B7%20JS%2086%20%C2%B7%20Python%2069)-success)](#testing--quality-assurance)

An enterprise-grade, AI-assisted civil registry digitisation and archival platform built with **Laravel 12**, **Bootstrap 5 (SNEAT design system)**, **FastAPI**, and a fine-tuned **Microsoft TrOCR** (Transformer-based Optical Character Recognition) handwriting engine.

CRMS enables registry staff to scan historical civil certificates and register ledgers (birth, death, marriage, and custom document types), detect and outline each handwritten line on the page, run machine learning recognition on the outlined crops, perform human-in-the-loop verification, and archive immutable records backed by an append-only audit trail and formal change-request governance.

Everything runs locally. Page images and their text are never sent to an external API or cloud service: the records are covered by the Data Privacy Act. Nothing needs the internet at run time either: the PDF reader's worker is served by the app itself, so PDFs open offline.

---

## Table of Contents

- [System Architecture](#system-architecture)
- [Key Features](#key-features)
- [Role-Based Access Control & Capability Matrix](#role-based-access-control--capability-matrix)
- [Project Directory Structure](#project-directory-structure)
- [Prerequisites & Requirements](#prerequisites--requirements)
- [Installation & Setup](#installation--setup)
- [Running the Application](#running-the-application)
- [Command Reference](#command-reference)
- [The TrOCR Machine Learning Pipeline](#the-trocr-machine-learning-pipeline)
- [OCR Microservice API Reference](#ocr-microservice-api-reference)
- [Production Deployment](#production-deployment)
- [Testing & Quality Assurance](#testing--quality-assurance)
- [Environment Configuration Reference](#environment-configuration-reference)
- [Known Limitations & Planned Work](#known-limitations--planned-work)
- [Technology Stack](#technology-stack)
- [License](#license)

---

## System Architecture

CRMS runs as **four local processes** from a single repository: the Laravel web app, a Laravel **queue worker** that detects and outlines handwritten lines in the background, the Laravel **scheduler** that deletes unsubmitted pages every hour, and the FastAPI OCR service. The diagram shows the web app and the OCR service; the queue worker and scheduler are described below it.

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
+---------------+          (Loopback only, X-CRMS-Service-Key)
| MySQL 8.0+ DB |
+---------------+
```

| Component | Stack | Primary Responsibilities |
| :--- | :--- | :--- |
| **Web Application** | Laravel 12, Blade, Bootstrap 5 (SNEAT), Vite, MySQL | User interfaces, authentication, template builder, verification workspace, record archival, change request moderation, audit logging, reporting. |
| **Queue Worker** | `php artisan queue:work` (database queue), `ml/line_markers.py`, Kraken in `ml/.venv-kraken` | Runs the page job queued by **Detect** and **Scan with OCR**: straightens the page, fits the template to it, outlines every handwritten line, crops along the outlines and sends the crops to the OCR service. Also runs the Template Builder's **Test on sample**. Without a running worker, pages wait at "Waiting for the line detector". |
| **Scheduler** | `php artisan schedule:work` | Runs `documents:prune-pages` every hour, which deletes aligned pages nobody submitted (and abandoned Test on sample folders) once they are older than `LINE_MARKERS_KEEP_HOURS` (default 24). |
| **OCR Microservice** | FastAPI, PyTorch, Hugging Face `transformers`, Pillow | TrOCR handwriting inference in batches of 16 crops, model inventory discovery, signed direct-upload ingestion, model lifecycle management. |

### Architectural Highlights

1. **Zero-PHP Large Model Uploads**: Uploading gigabyte-scale model checkpoints (`safetensors`/`bin` archives) never passes through PHP or consumes web worker memory. Laravel generates a short-lived, HMAC-SHA256 signed ticket (`OCR_UPLOAD_SECRET`); the browser uploads directly to FastAPI's `/add_model` endpoint. Once written to disk, the client posts the model key to Laravel, which verifies the inventory and registers the model in a database transaction.
2. **Server-Side AI Proxying**: Operational document recognition is called server-to-server: the queue worker sends each page's line crops from Laravel to FastAPI, and the browser never calls the OCR service. The FastAPI instance remains bound to loopback (`127.0.0.1`) without direct public access.
3. **Shared Service Key**: Binding to loopback keeps other machines out, but not other programs on the same computer (for example another site on XAMPP's `localhost`). So every call from Laravel carries the header `X-CRMS-Service-Key`, a secret both sides read from `.env` (`OCR_UPLOAD_SECRET`, or `APP_KEY` when that is empty). FastAPI compares it in constant time and answers **401** without it. Only `/health` stays open, and `/add_model` keeps its own signed ticket.
4. **Batched Reading**: The OCR service reads a page's crops 16 at a time in one GPU pass instead of one by one: 40 crops took **6.14 s → 2.99 s** on an RTX 4050 laptop, with identical text and confidence. A crop that cannot be decoded becomes an error row on its own, and if a whole batch fails (for example out of GPU memory) that batch is read again one crop at a time.
5. **Decoupled Process Lifecycles**: Laravel never spawns, restarts, or terminates the Python OCR daemon. The OCR workspace actively monitors reachability via asynchronous health polling.
6. **Slow Work Never Blocks the Website**: On Windows, `php artisan serve` handles one request at a time. Everything slow (Detect, Scan with OCR and the Template Builder's Test on sample, 15–60 s each) therefore runs on the queue worker while the browser polls for the result, so other pages keep loading.
7. **Separate Python Environment for Line Detection**: Kraken needs a newer PyTorch than the TrOCR service, so `ml/line_markers.py` runs in its own environment (`ml/.venv-kraken`, CPU). The queue worker calls it as a local subprocess; Kraken's model ships inside its package, so nothing is downloaded at run time.

---

## Key Features

### 1. Document Digitisation & Verification Workspace
- **Visual Bounding-Box Markup**: Interactive drag-and-drop marker tool with canvas zoom, pan, and marquee selection for setting field coordinates.
- **Tiltable Markers**: In both the Staff workspace and the Template Builder, every marker, including each ledger column and any newly added field, can be tilted on its own: drag the rotate knob below it (<kbd>Shift</kbd> snaps to 5°), or use <kbd>[</kbd> / <kbd>]</kbd> in 0.5° steps (<kbd>Shift</kbd>: 5°); double-click the knob to straighten. A marker turns about its own centre, and a tilted field is cropped level for TrOCR. For a ledger, the columns' typical tilt is taken as the page's: the page is straightened by it before line detection, as Detect does, and each column is placed where its centre lands.
- **Resize from Any Side**: Every marker has handles on all four corners and all four sides, so one side can be fixed without moving the others. They appear, with the rotate knob, only on the marker you click (never on hover), so neighbouring columns do not pile handles on each other. The dragged side snaps to the nearest printed line (hold <kbd>Alt</kbd> to place it freely), and a tilted marker resizes along its own sides.
- **Snap to Table**: In the Align step, **Snap to table** moves the template's ledger columns and ruled rows onto the lines this page actually printed, in under a second on the server, without outlining any handwriting. It fits position and scale, then pulls each column edge and row onto its nearest printed rule. It does not straighten a tilted page; use Detect for that. While it works, the button shows a spinner. <kbd>Ctrl</kbd>+<kbd>Z</kbd> undoes a snap.
- **Magnetic Edges**: While you drag or resize a marker, an edge that comes within 8 screen pixels of a printed line snaps onto it, and a red guide shows the line it caught. Hold <kbd>Alt</kbd> while dragging to place the marker freely.
- **Split-Screen Verification Viewer**: Dual-pane workspace with configurable horizontal/vertical split views, smooth keyboard-accelerated split-bar adjustments, and Ctrl-wheel zoom.
- **Person Grouping**: Supports complex registry layouts grouping fields by role (e.g., Child, Mother, Father, Groom, Bride, Deceased, Informant) alongside general document details.
- **Confidence Scoring & Review Warnings**: Computes token-level geometric mean confidence scores (0–100%). Fields falling below the configured threshold are visually flagged for manual operator review.
- **Checked Values**: Verify uses each template field's settings (see the Template Builder). A person is titled by the field that holds their name. Under a value it shows the template's hint, and it points out a value that does not fit its field ("This does not look like a date", "Expected one of: M, F"). A person whose required columns had nothing read is marked "N missing", with the columns named. None of these hints blocks a submission: registers hold odd values.
- **Missing Required Fields Ask First**: Submitting with a required field, or a required register column, left unticked opens a confirmation that lists what will be left out ("Person 01: Column 2"), with **Go back** and **Submit anyway**. Register rows with nothing ticked are named in one line, because they are not saved and the page is removed after submitting. It asks rather than blocks, because some real certificates do leave a field blank. The server checks the same thing, so an edited form cannot skip the question, and an accepted omission is listed in the `record.submitted` audit entry (`missing_required_fields`).
- **Accepted Scan Formats**: PDF, PNG, JPG, WEBP or BMP, up to 20 MB. TIFF is refused with a clear message, because Chrome and Edge cannot display it; scan to PDF or PNG instead.
- **Human-in-the-Loop Enforcement**: Only explicitly verified fields are committed to the permanent record upon submission.

### 2. Handwritten Line Detection (Detect)
- **Per-Document Detect**: In the Align step, **Detect fields and ink** straightens a tilted page, fits the template's ledger columns and ruled rows to where this page actually printed them, and outlines every handwritten line before anything is read. Every document can sit at a different position, scale and tilt.
- **Outlines, Not Rectangles**: Each line gets a polygon traced around its own strokes, so capitals and tails that cross a printed rule stay with their line and a neighbour's ink stays out. TrOCR reads a masked crop along that outline.
- **Drawn Boxes Split into Lines**: A rectangle field drawn over a handwritten list is split into one outlined field per written line ("Diseases · line 1", "· line 2", ...), and Verify heads each one with its number. **Both buttons cut a page the same way**: Scan with OCR does this whether or not Detect ran first, because TrOCR reads one line at a time and a whole box of eight diseases came back empty. A box the detector finds no writing in stays one crop of the box, as it always was. A layout of plain boxes therefore now runs line detection on its first scan (about 15 s on a small crop, 25 s on a register page); scanning the same page again reuses that work.
- **Adjust Line Crops Before Reading**: After Detect, every outline shows on the page, and the **Adjust line crops** switch makes them clickable (the field markers lock meanwhile). Clicking a line brings its card into view in the side panel, showing a live preview of the crop TrOCR will read. Three ways to change it: **Stretch** by its eight handles (and grow or shrink with <kbd>+</kbd>/<kbd>−</kbd>), **Points** to drag the outline's own corners (drag the dot between two corners to add one, <kbd>Delete</kbd> or double-click to remove one), or **Draw** a new outline with as many clicked points as needed, or by tracing round the writing (close on the first point, by double-clicking, with <kbd>Enter</kbd>, or by tracing back to the start; <kbd>Esc</kbd> cancels). <kbd>Ctrl</kbd>+<kbd>Z</kbd> / <kbd>Ctrl</kbd>+<kbd>Y</kbd> undo and redo every outline change (while drawing, <kbd>Ctrl</kbd>+<kbd>Z</kbd> takes back the last point). Step through lines with <kbd>Tab</kbd>, and **Reset line** goes back to Kraken's outline. Changes stay in the browser until **Save crop** (<kbd>Ctrl</kbd>+<kbd>S</kbd>) re-crops that line on the server (not read); **Discard** goes back to the last saved outline. Unsaved lines are outlined in orange dashes, saved adjustments are marked ✎, and Scan with OCR reads the saved crops, offering first to save any line still unsaved. **Delete line** (or the <kbd>Delete</kbd> key, where no outline corner is picked) removes a line outright - a stray mark the detector took for writing, or a wrongly split word - so it is never scanned or submitted, and moves on to the line that took its place, so a run of bad lines is cleared one after another. It is undone by <kbd>Ctrl</kbd>+<kbd>Z</kbd> like any other change: the line and its crop are kept on the server until the page is outlined again, and put back exactly where they were. **Add line** draws one the detector missed: click round the writing (or trace it) and close the outline, and it is cropped and placed in its ledger cell, or in the drawn box it sits inside, as the last of that box's lines; <kbd>Ctrl</kbd>+<kbd>Z</kbd> takes it away again. Both only while the page still holds Detect's result, before it is read.
- **Moving a Marker After Detect**: Detect's outlines belong to the markers as Detect placed them, so moving, resizing or snapping a marker sets the result aside: the outlines hide, and Scan with OCR outlines the page afresh from the new markers. <kbd>Ctrl</kbd>+<kbd>Z</kbd> back to Detect's markers brings the result back, line adjustments (saved or not) included. To keep the new markers instead, run Detect again.
- **Outlining Again Reuses What Detect Found**: Finding the lines is nearly all of a page's work and depends only on the pixels, not on the markers, so it is kept beside the page under a fingerprint of the image. Scanning after a marker moved sends only the markers - the page is already on the server, already straightened - and outlines it again from the lines Detect found: measured 15.5 s → **0.4 s** on a small crop and 48.5 s → **4.8 s** on a register page, with no second upload. A page that was straightened or replaced has a different fingerprint and is detected afresh. Every line is worked out again from the new markers, so adjusted outlines cannot come with it; Staff are asked first.
- **Cancel**: The progress window's **Cancel** stops a Detect or Scan at once: a running detector is stopped within half a second, and a read stops before its next batch of 40 lines, so the next page does not wait behind it. Cancelling the read of a Detect result keeps that result; any other cancelled page is discarded.
- **Review Flags**: Lines between two ruled rows (`no_row`) or sharing a cell (`shared_cell`) are outlined in red and marked **Needs review** in Verify. A reviewer can redraw an outline; only that line is re-cropped and re-read.
- **Your Markers Decide the Rows**: Evenly ruled rows repeat, so a grid slid one row up or down matches the printed rules exactly as well as the right one. Scan with OCR therefore keeps the table's first row line within half a row of where you put it (and lets the row spacing stretch by up to 10% about that line, for a page scanned at another scale). It never jumps a whole row on its own. Detect and Snap to table can carry far-off markers onto the table, and Detect says how many rows it moved the grid.
- **A Ledger Marker on Free Text**: If a ledger template's column is placed over writing that does not sit in its rows (a list of diseases under the register's 22-row template), Detect and Scan with OCR read that column as free text, line by line ("Remarks · line 1", "· line 2", ...), exactly as an added field is, instead of forcing every line into one of the template's rows. The test is scale-free: on every real ledger page the baselines sit at the same height within their rows and one row apart; on free text they do not. Staff are told in the Detect summary.
- **Grid Notes**: When the grid may be wrong, the Detect summary and the top of Verify say so, in plain words, and change nothing: **rows that differ a lot in height** from the template's (a header taken for a row, a rule the page lacks), **written lines below the last row** (the page has more rows than the template, and those are not read), **written lines in a row-sized band above the first row** (a taller band is a header and stays quiet), and **Detect moved the grid N rows**. The page's row lines are also cut at the page's edge, so a template with more rows than the page never leaves a marker running past it.
- **Training Export**: `php artisan crms:export-training` writes verified crops and their corrected text as a TrOCR training CSV (`file_name, text, doc_id, column, row`).

### 3. Dynamic Document Template Builder & Type Management
- **Visual Template Designer**: Create and publish document layouts with real-time coordinate calibration.
- **Paper Specification Support**: Supports standard paper dimensions (A4, Letter, Legal, Folio) as well as arbitrary custom millimeter dimensions in Portrait or Landscape orientations.
- **Sample Document Upload**: Direct upload of a PDF, PNG, JPG, WEBP or BMP sample, rendered with PDF.js for canvas alignment.
- **Custom Document Types**: Super Admins can define custom certificate classifications with distinct icons, validation rules, and active states.
- **Field Settings**: Select a field or ledger column to set what it **holds** (the person's name or the entry number, one of each per person), its **value** (text, a date, a number, or one of a list of choices), a **hint** for Staff, and whether it is **required**. Verify and the records archive use these. They replace the one layout the code used to know by heart (an 11-column birth register whose third column was the child's name). The existing birth ledger's entry-number and child's-name columns were marked when the settings came in.
- **Layouts in Use Keep Their Versions**: A layout that is published, or that records or unsubmitted pages were read with, is never changed in place. Saving changed markers, row lines or field settings makes a **new version**, so every record keeps the layout it was read with. **Save as new draft** leaves the published one live; **Save & publish new version** swaps it in. Its name, notes and sample only describe it and are saved in place. The editor says when a layout is in use. A save made from an older copy of the editor (another admin saved in between) is refused rather than overwriting the newer version. **Duplicate** in the template library makes a draft copy, sample included.
- **Layouts Records Use Cannot Be Deleted**: Deleting a layout that any record was read with is refused ("This layout was used by N records…"), and the template library explains why instead of offering the delete, so every record keeps pointing at its layout. Pages still in progress do not block a delete; they are only unlinked, then pruned.
- **Ledger Grid Tools**: Moving or stretching the columns as a table carries their row lines along, exactly as the Align step does, and columns sitting on the first and last row line follow those lines. Click a row line to select it, then drag it or nudge it with the arrow keys (<kbd>Shift</kbd>: 10 px) or press <kbd>Delete</kbd>. <kbd>Shift</kbd>+drag moves every line. **Add** puts a line under the selected one, and **N rows, evenly** spaces the rows between the first and last line. Once a sample is open, row lines and marker edges snap to its printed rules (<kbd>Alt</kbd>: place freely). Lines Detect estimated below the last printed rule are drawn amber until checked. **Make selected fields** turns a column back into a field. Undo (<kbd>Ctrl</kbd>+<kbd>Z</kbd>) covers row lines as well as markers. Arrow keys also nudge selected markers.
- **Grid Checks**: The ledger card lists what is wrong while you work. Row lines outside the columns, or almost on top of each other, stop the save, and the server refuses them too. A column that spans other rows than the rest is a warning. A field that covers the middle of ledger cells is reported (**Select them** / **Remove them**), because line detection would read that handwriting as the field and the rows would lose those cells; saving asks first. A tilted marker whose corner leaves the page is refused, and publishing a ledger without a sample asks first.
- **Test on Sample**: Runs Detect on the sample with the layout as it stands, saved or not (about half a minute). It shows every outlined line on the page and the fitted row lines, with the lines that fall between rows, lines sharing a cell, rows missing required cells, written lines outside every marker, and the grid notes. Nothing is read. The test runs on the queue worker, like Detect, so other pages stay usable meanwhile; until the worker picks it up the builder says "Waiting for the background worker". The copy of the sample made for the test is deleted as soon as the result is shown.
- **Unsaved Changes**: Leaving the builder with unsaved changes (closing the tab, going back, following a link) asks first.

### 4. Immutable Record Archival & Change Request Governance
- **Permanent Record Locking**: Once verified and submitted by Staff, records are sealed against direct in-place modification.
- **Formal Change-Request Workflow**: Staff initiate modification requests detailing justifications and proposed field values. Admins or Super Admins review, approve, or reject changes.
- **Data Integrity Constraints**: Mandatory captured fields cannot be blanked during correction; all historical iterations remain auditable.
- **Safe Against Double Clicks**: Approve, reject and withdraw re-read the request under a database row lock before deciding, and opening a request locks the record first, so two reviewers (or one double-click) cannot both approve and reject a request, and a record cannot get two pending requests. The buttons also disable after the first click. A rejection note of any allowed length (up to 2,000 characters) is kept in the audit entry.
- **The Record Shows the Page That Was Read**: A submitted record keeps the page image its boxes were measured on (straightened, when Detect straightened a tilted page) and draws its value boxes on it, so each box sits on its handwriting. (Drawn over the original upload instead, a 3° tilt put the boxes about one line off.) **Open full size** still opens the original upload, and records submitted before this change keep showing their upload.
- **Archive Search & Filters**: Search by registry number or any verified value, and filter by document type and submission date. A bad filter from an edited URL (an impossible date, a list where one value belongs) is left out with a message under it, instead of an error page.
- **One Record Status**: Every record is saved as **Submitted**, and therefore locked, the moment Staff submit it. The unused "Draft" status was removed.

### 5. OCR Model Management & Benchmark Provenance
- **Multi-Model Registry**: Live model scanning from `ml/models/`, support for custom checkpoints and the baseline `microsoft/trocr-base-handwritten`.
- **Benchmark Provenance Radar**: Visualizes Character Error Rate (CER), Word Error Rate (WER), and Exact Match metrics strictly from locked test-split reports (`evaluation-report.json`) verified against model weight SHA-256 hashes. CRMS never fabricates benchmark statistics from operational scans.
- **Operator Selection Flexibility**: Global model assignment with an optional Super Admin toggle allowing Staff to select approved alternative models during digitisation.

### 6. Tamper-Evident Audit Trail
- **Append-Only Logging**: Comprehensive activity logs recording actor ID, name, role, IP address, user agent, action verb, and before/after diffs.
- **Automatic Secret Redaction**: Sensitive attributes (passwords, tokens, credentials) are stripped before persistence.

### 7. Analytics & Administrative Oversight
- **Role-Tailored Dashboards**: Real-time KPI summaries, digitisation volume charts, correction rate tracking, and live OCR engine diagnostic badges.
- **Philippine Time Everywhere**: Times are stored in UTC and shown in `CRMS_REPORTING_TIMEZONE` (default `Asia/Manila`) on every page and in the CSV export, through one helper (`App\Support\LocalTime`). The date filters on Records, Audit Log and Reports all use Philippine days, so a record submitted at 7:30 AM on 2 October is found under 2 October.
- **Filterable Reports with Streaming CSV Export**: Reports filter by document type, date range and submitting user, and export the matching records as a CSV that is written in chunks of 200 straight to the download, never built in memory.
- **Light List Pages**: The Records list, the Reports page and the CSV export load only what they show (each record's title field and its average confidence), not every field of every record. With 15 register records of 242 fields each, the Records list went from 3,651 loaded rows to 36, and Reports from 3,653 to 38.
- **Safe CSV Export**: A text cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading `'`, so Excel shows it as text instead of running it as a formula ("CSV injection"). Number columns are unchanged.
- **Secure Account Management**: Controlled staff/admin provisioning (no public sign-ups), auto-generated temporary passwords, mandatory first-login password rotation, soft deactivation, and protection against accidental Super Admin demotion.

---

## Role-Based Access Control & Capability Matrix

CRMS enforces a strict separation of duties verified end-to-end in the test suite (`tests/Feature/CapabilityMatrixTest.php`):

| Capability / Resource | Staff | Admin | Super Admin | Route / Gate |
| :--- | :---: | :---: | :---: | :--- |
| **Upload & Process Documents** | **Yes** | No | **Yes** | `documents.create`, `can:documents.process` |
| **Verify & Submit Records** | **Yes** | No | **Yes** | `documents.store`, `can:documents.process` |
| **Search & View Record Archive** | **Yes** | **Yes** | **Yes** | `records.index`, `can:records.view` |
| **Propose Change Requests** | **Yes** | No | **Yes** | `records.change-requests.create`, `can:change-requests.create` |
| **Approve / Reject Change Requests** | No | **Yes** | **Yes** | `change-requests.approve`, `can:change-requests.moderate` |
| **See Analytics (on the Dashboard)** | No | **Yes** | **Yes** | `analytics.index`, `can:analytics.view` |
| **Generate & Export CSV Reports** | No | **Yes** | **Yes** | `reports.index`, `can:reports.generate` |
| **Manage User Accounts & Roles** | No | **Yes** | **Yes** | `users.index`, `can:users.manage` |
| **View Tamper-Evident Audit Log** | No | **Yes** | **Yes** | `audit.index`, `can:audit.view` |
| **Document Template Builder** | No | No | **Yes** | `templates.index`, `can:templates.manage` |
| **OCR Model & Engine Workspace** | No | No | **Yes** | `ocr.index`, `can:ocr.manage` |

> **Note on Separation of Duties**: Administrators perform supervisory and oversight functions and cannot perform primary data entry or directly edit civil records. Record corrections must strictly traverse the authenticated change request moderation pipeline.

The full rules, including who may create, edit and deactivate which accounts, are in [docs/roles.md](docs/roles.md).

---

## Project Directory Structure

```
crms-laravel-12/
├── .vscode/tasks.json                  # (local, gitignored) Kiro / VS Code tasks that start the four processes
├── app/                                # Core Laravel application logic
│   ├── Console/Commands/               # crms:export-training, documents:prune-pages
│   ├── Enums/                          # RoleSlug, DocumentType, RecordStatus, PaperSize, etc.
│   ├── Exceptions/                     # ChangeRequestException (a refused change request, shown to the user)
│   ├── Http/
│   │   ├── Controllers/                # Gated web controllers (Scan, Record, ChangeRequest, etc.)
│   │   ├── Middleware/                 # EnsureAccountIsActive, EnsurePasswordIsChanged
│   │   └── Requests/                   # Form validation request classes
│   ├── Jobs/                           # ProcessDocumentPage (Detect / outline / read), TestTemplateLayout (Test on sample)
│   ├── Models/                         # Eloquent models (CivilRecord, RecordField, DocumentPage, PageLine, AuditLog, etc.)
│   ├── Providers/                      # AuthServiceProvider (Capability Matrix Gate definitions)
│   ├── Services/                       # Business logic (AuditLogger, ChangeRequestService, etc.)
│   │   ├── Lines/                      # LineMarkers (runs ml/line_markers.py), PageLineReader, GeometryInput
│   │   └── Ocr/                        # OcrClient (sends the service key), OcrModelManager, OcrUploadAuthorizer, EngineStatus
│   └── Support/                        # LocalTime (Philippine time), Limits (450 fields), MarkerBounds, Navigation
├── bootstrap/                          # Application bootstrap and middleware pipeline configuration
├── config/                             # Configuration files (crms.php, services.php, database.php)
├── database/
│   ├── factories/                      # Model factories for testing and seeding
│   ├── migrations/                     # Database migrations (records, templates, audit logs, OCR)
│   └── seeders/                        # RoleSeeder, SuperAdminSeeder, DocumentTemplateSeeder, DemoUsersSeeder
├── docs/
│   ├── roles.md                        # Who may do what: roles, abilities and account rules
│   ├── CODE_REVIEW_TODO.md             # Findings of the 2026-10-02 code review, with checklists
│   ├── IMPLEMENTATION_PLAN.md          # The order those fixes were made in, with decisions and measurements
│   ├── HOW_TO_RUN_THE_PLAN.md          # How to run the plan's tasks with Claude Code
│   └── CIVIC_PALETTE_EXECUTION_PLAN.md # The SNEAT UI and brand system plan
├── ml/                                 # Complete Python OCR & Machine Learning workspace
│   ├── api/
│   │   ├── main.py                     # FastAPI microservice (batched inference, health, service key, signed model uploads)
│   │   └── requirements.txt            # FastAPI microservice dependencies
│   ├── datasets/<name>/                # Training/validation/test images & manifest CSV (gitignored)
│   ├── models/                         # Fine-tuned model checkpoints (gitignored)
│   ├── evaluation-metrics/             # Evaluation charts, in base/ and finetuned/
│   ├── notebooks/
│   │   └── trocr-finetuning-code.ipynb # Kaggle / Colab fine-tuning notebook; writes evaluation-report.json
│   ├── dataset_registry.py             # Dataset names, folders and label rules
│   ├── download_trocr.py               # Downloads Hugging Face base TrOCR weights
│   ├── hf_quiet.py                     # Hugging Face environment logging silencer
│   ├── line_markers.py                 # Deskew, template fit, line outlines and masked crops (Kraken)
│   ├── metrics.py                      # CER / WER / Exact-Match computation and plot generators
│   ├── predict.py                      # Standalone CLI batch prediction tool
│   ├── requirements.txt                # ML pipeline dependencies (PyTorch, Transformers, Pandas)
│   ├── requirements-kraken.txt         # Line-detection environment (ml/.venv-kraken)
│   ├── setup_kraken.ps1                # Builds ml/.venv-kraken
│   ├── test_finetuned.py               # CLI benchmark evaluator for fine-tuned models
│   ├── test_trocr.py                   # CLI benchmark evaluator for base model
│   ├── train_trocr.py                  # PyTorch TrOCR fine-tuning script
│   └── trocr_common.py                 # Shared model loading and confidence scoring (predict.py, test_finetuned.py, api/main.py)
├── public/                             # Publicly accessible web root
│   └── vendor/pdfjs/                   # PDF.js worker, copied from node_modules on every build (gitignored)
├── resources/
│   ├── css/ & scss/                    # SNEAT theme & custom CRMS stylesheet rules
│   ├── js/                             # Interactive JS (Template Builder, Field Marker, Split View)
│   └── views/                          # Blade templates organized by domain
├── routes/
│   ├── console.php                     # Artisan console commands
│   └── web.php                         # Application route definitions and permission middleware
├── tests/                              # Automated test suites
│   ├── Feature/                        # PHPUnit feature test classes (RBAC, workflows, OCR)
│   ├── JavaScript/                     # Node.js test runner unit tests (controls, markers, SNEAT)
│   └── Python/                         # Line detection, OCR service key, batched reading, evaluation reports
├── tools/
│   ├── test-all.ps1                    # Runs every test suite; stops at the first failure
│   ├── copy-pdf-worker.mjs             # Copies the PDF.js worker into public/vendor/pdfjs (runs before build and dev)
│   └── subset-icons.mjs                # Builds and checks the Boxicons subset
├── serve.ps1                           # Starts the web app, queue worker, scheduler and OCR service (see Running)
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
| **PyTorch & CUDA** | PyTorch 2.6+ (CUDA 12.4 wheel optional) | Optional GPU acceleration for rapid TrOCR inference. On Windows, install the CUDA wheel first (see step 3) |
| **Kraken environment** | `ml/.venv-kraken` (kraken 7.1, CPU PyTorch 2.9+) | Line detection for Detect and ledger templates. Built by `ml\setup_kraken.ps1`; older rectangle-only templates work without it |

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

# With an NVIDIA GPU, install the CUDA build of PyTorch FIRST (skip this line for CPU only)
pip install torch torchvision --index-url https://download.pytorch.org/whl/cu124

# Then everything else
pip install -r ml/requirements.txt -r ml/api/requirements.txt
```

> **PyTorch GPU Support**: On Windows, the default `torch` from PyPI is CPU-only. Installed without the line above, the OCR service still works but is very slow, and nothing warns you. Check with `python -c "import torch; print(torch.cuda.is_available())"`, which prints `True` when the GPU can be used; once the service runs, `/health` reports `"device": "cuda"`. Other CUDA versions are listed on [pytorch.org](https://pytorch.org/get-started/locally/).

#### Line-Detection Environment (Kraken)
Kraken needs a newer PyTorch than the TrOCR service, so it gets its own environment. The script uses [`uv`](https://docs.astral.sh/uv/). From the repository root:
```powershell
pip install uv            # once, if uv is not on PATH yet
.\ml\setup_kraken.ps1
```
This creates `ml\.venv-kraken` with CPU PyTorch. With an NVIDIA GPU, use `.\ml\setup_kraken.ps1 -Cuda` instead (about 2.5 GB more): Kraken's neural network then runs on the GPU, falling back to the CPU if the GPU fails. On an RTX 4050 laptop this cut Kraken from about 24 s to about 17 s per ledger page; most of Kraken's time is outline tracing on the CPU, which the GPU does not speed up. The app finds the environment automatically; set `LINE_MARKERS_PYTHON` only to use a different interpreter.

### 4. Configure Environment Files
```bash
copy .env.example .env    # Windows (PowerShell or cmd)
cp .env.example .env      # Linux / macOS
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
# Optional shared secret (empty = APP_KEY). Don't put a comment on the same line:
# the OCR service would read the comment as part of the secret.
OCR_UPLOAD_SECRET=
CRMS_CONFIDENCE_THRESHOLD=80
CRMS_REPORTING_TIMEZONE=Asia/Manila
```

The OCR service reads this same `.env` file, so the shared service key needs no extra setup. If you change `OCR_UPLOAD_SECRET` or `APP_KEY` later, run `php artisan queue:restart`, because the queue worker keeps the old value until it restarts.

### 5. Migrate Database & Seed Initial Data
```bash
php artisan migrate --seed
```
`migrate --seed` seeds the core permission roles, default document templates (Birth, Death, Marriage), and the bootstrap Super Admin account.

After pulling new code later, run `php artisan migrate` again to apply any new migrations.

### 6. Build Frontend Assets
```bash
npm run build
```
Before building, this copies the PDF.js worker from `node_modules` into `public/vendor/pdfjs/`, so PDFs open without internet and the worker always matches the installed PDF.js version.

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

CRMS needs **four processes** running, plus MySQL (start it in the XAMPP Control Panel; Apache is not used):

| Process | Command | Port |
| :--- | :--- | :--- |
| Web app | `php artisan serve` | [8000](http://127.0.0.1:8000) |
| Queue worker | `php artisan queue:work --timeout=900 --tries=1` | – |
| Scheduler | `php artisan schedule:work` | – |
| OCR service | `python -m uvicorn ml.api.main:app --host 127.0.0.1 --port 8001` | [8001](http://127.0.0.1:8001) |

> **Do not skip the queue worker.** `php artisan serve` starts the website only. Detect, Scan with OCR and the Template Builder's Test on sample queue a background job, and without a worker the page stays on **"Waiting for the line detector"** (or **"Waiting for the background worker"** in the builder).
>
> **The scheduler** deletes, every hour, the aligned pages nobody submitted once they are older than `LINE_MARKERS_KEEP_HOURS` (default 24). They are unsubmitted civil registry scans, so without it they stay on disk.

### Option A: Automatically When the Project Opens (Kiro / VS Code)
`.vscode/tasks.json` starts all four as tabs in the editor's terminal panel whenever the project folder opens: **CRMS: website**, **CRMS: queue worker**, **CRMS: scheduler** and **CRMS: OCR service**. Closing the editor stops them. Each tab skips its process if it is already running, so nothing starts twice.

- **First time only**: the editor asks whether to allow automatic tasks. Choose **Allow**. (Kiro ships with automatic tasks off; if you missed the prompt, run **Tasks: Manage Automatic Tasks → Allow Automatic Tasks** from the Command Palette.)
- **Start them by hand**: press **Ctrl+Shift+B** (it is the default build task), or Command Palette (**Ctrl+Shift+P**) → **Tasks: Run Task** → **CRMS: start services**. One tab per service: **CRMS: website**, **CRMS: queue worker**, **CRMS: scheduler**, **CRMS: OCR service**.
- `.vscode/` is gitignored, so this file is local to each machine. To set it up on a new machine, create `.vscode/tasks.json` with one task per process that runs `powershell.exe -NoProfile -ExecutionPolicy Bypass -File serve.ps1 -Only web` (and `-Only worker`, `-Only scheduler`, `-Only ocr`), each with `"isBackground": true` and `"runOptions": { "runOn": "folderOpen" }`.

### Option B: The PowerShell Runner (Windows)
From the repository root, `serve.ps1` checks the environment (PHP, MySQL, Python, CUDA, Kraken) and starts the four processes:

```powershell
.\serve.ps1               # All four. In a Kiro / VS Code terminal: inside that one terminal
                          # (Ctrl+C there stops them all). Elsewhere: one window each.
.\serve.ps1 -Windows      # One window each, even from the editor
.\serve.ps1 -Check        # Check the environment and exit
.\serve.ps1 -NoOcr        # Everything but the OCR service
.\serve.ps1 -Only worker  # One process in the current terminal (web | worker | scheduler | ocr)
```

A script cannot open editor tabs: for one tab per service, use Option A (**Ctrl+Shift+B**). If PowerShell refuses to run scripts, use `powershell -ExecutionPolicy Bypass -File .\serve.ps1`.

### Option C: Manual Process Execution
Open four terminals in the repository root and run one command from the table above in each. For the OCR service, activate `.venv` first.

### If Something Stops
Run **Ctrl+Shift+B** (or `.\serve.ps1`) again: it only starts what is not running and skips the rest, so nothing starts twice.

| Symptom | What stopped |
| :--- | :--- |
| "This site can't be reached" at 127.0.0.1:8000 | web app |
| Detect or Scan stays on "Waiting for the line detector", or Test on sample on "Waiting for the background worker" | queue worker |
| Unsubmitted pages pile up in `storage\app\private\pages` | scheduler |
| Scan fails with an OCR / TrOCR connection error, or the OCR workspace shows the engine offline | OCR service |

- **"Port 8000/8001 is already in use"**: that service is still running (perhaps in an old window); close that window or keep using it.
- **"This call needs the CRMS service key" (401)**: Laravel and the OCR service read different secrets. Both read `OCR_UPLOAD_SECRET` (or `APP_KEY` when it is empty) from `.env`. After changing either, run `php artisan queue:restart`, and `php artisan config:clear` if the configuration was cached. Also check that `.env` has no comment on the `OCR_UPLOAD_SECRET` line.
- **"A queue worker is already running"** but nothing gets processed: a worker from a closed window may be left running without its window. Find it with `Get-CimInstance Win32_Process | Where-Object CommandLine -like '*queue:work*'`, stop it with `Stop-Process -Id <id>`, and start again.
- **Database connection errors**: MySQL is not running. Start it in the XAMPP Control Panel; the queue worker reconnects by itself, the web app needs a restart.

### Notes on the Queue Worker
- The worker started by Option A or B restarts itself: after a crash, after MySQL comes up late, and after `php artisan queue:restart`. Run `queue:restart` after changing job code (for example `app/Jobs/ProcessDocumentPage.php`), because a running worker keeps the old code loaded. A worker started by hand (Option C) does not come back; start it again.
- While idle, the worker uses about 50 MB of memory and no noticeable CPU. Line detection uses the CPU for roughly 20–60 seconds per page when Detect or Scan runs; reading uses the GPU when CUDA is available.
- The yellow `PHP_CLI_SERVER_WORKERS` warning from `php artisan serve` is harmless: PHP cannot run several server workers on Windows. It does mean the website answers one request at a time, which is why every slow job runs on the queue worker instead.

---

## Command Reference

Every command here runs from the repository root, in PowerShell (the editor's terminal is PowerShell). `ml\.venv-kraken\Scripts\python.exe` is the line-detection (Kraken) environment; plain `python` means the OCR environment, so activate it first with `.venv\Scripts\activate`.

### Start the Services
```powershell
.\serve.ps1                        # Check the environment, then start all four services
.\serve.ps1 -Only web              # Only the web app, in this terminal -> http://127.0.0.1:8000
.\serve.ps1 -Only worker           # Only the queue worker, in this terminal (restarts itself)
.\serve.ps1 -Only scheduler        # Only the scheduler, in this terminal (restarts itself)
.\serve.ps1 -Only ocr              # Only the OCR service, in this terminal -> http://127.0.0.1:8001
.\serve.ps1 -NoOcr                 # Everything but the OCR service
.\serve.ps1 -Windows               # All four, one window each, even from the editor
.\serve.ps1 -Check                 # Only check PHP, MySQL, Python, CUDA and Kraken; start nothing
.\serve.ps1 -AppPort 8080          # Web app on another port
.\serve.ps1 -OcrPort 8002          # OCR service on another port (also change OCR_API_URL and OCR_BROWSER_API_URL in .env)
```

In the editor:
- **Ctrl+Shift+B**: start all four, one terminal tab each.
- **Ctrl+Shift+P** → **Tasks: Run Task** → **CRMS: website**, **CRMS: queue worker**, **CRMS: scheduler** or **CRMS: OCR service**: start one of them.
- To stop one, press **Ctrl+C** in its tab, or close the tab (trash-can icon).

Without `serve.ps1`, one command per terminal:
```powershell
php artisan serve --port=8000
php artisan queue:work --timeout=900 --tries=1
php artisan schedule:work
python -m uvicorn ml.api.main:app --host 127.0.0.1 --port 8001
```

### Queue Worker
```powershell
php artisan queue:restart          # Reload the worker after a PHP change; it starts again by itself within 5 s
php artisan queue:failed           # List jobs that failed
php artisan queue:flush            # Forget the failed jobs listed there

# A worker left running without its window ("A queue worker is already running"):
Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -like '*queue:work*' | Select-Object ProcessId, CommandLine
Stop-Process -Id <ProcessId>       # Stop it, then start the worker again
```

### Check What Is Running
```powershell
Get-NetTCPConnection -LocalPort 8000, 8001 -State Listen   # Web app (8000) and OCR service (8001) listening?
Invoke-RestMethod http://127.0.0.1:8001/health             # OCR service status, device (cuda / cpu) and active model
nvidia-smi                                                 # GPU memory in use
ml\.venv-kraken\Scripts\python.exe -c "import torch; print(torch.cuda.is_available())"   # Can line detection use the GPU?
```

### After Editing `.env`
```powershell
php artisan config:clear           # Only needed if the configuration was cached (php artisan config:cache)
php artisan queue:restart          # The worker reads .env when it starts (for example LINE_MARKERS_DEVICE)
```
Restart the web app too: **Ctrl+C** in its tab, then `.\serve.ps1 -Only web`.

### Frontend
```powershell
npm install                        # Install JavaScript dependencies
npm run build                      # Build the assets into public/build; needed after any change in resources/
npm run dev                        # Rebuild on every save while editing (Ctrl+C to stop; run npm run build before using the app normally)
npm run subset-icons               # Rebuild the Boxicons subset after using a new icon
npm run check:icons                # Check that every icon used is in the subset
```
After `npm run build`, press **Ctrl+F5** in the browser.

### Database
```powershell
php artisan migrate                              # Apply new migrations
php artisan migrate:status                       # Which migrations have run
php artisan migrate --seed                       # First setup: tables, roles, default templates, Super Admin
php artisan db:seed --class=DemoUsersSeeder      # Demo Staff and Admin accounts
```

### Line Detection (Kraken)
```powershell
.\ml\setup_kraken.ps1              # Create ml\.venv-kraken with CPU PyTorch
.\ml\setup_kraken.ps1 -Cuda        # The same with CUDA PyTorch, for an NVIDIA GPU
```
The detector can also be run on one page by hand, for example to look into a page that came out wrong. Each page Staff aligned is kept in `storage\app\private\pages\<id>\` (`page.png`, `geometry.json`) until it is submitted or pruned. `detect` overwrites `--page` with the straightened page, so work on a copy:
```powershell
ml\.venv-kraken\Scripts\python.exe ml\line_markers.py detect  --page page.png --geometry geometry.json --out out   # What Detect does
ml\.venv-kraken\Scripts\python.exe ml\line_markers.py process --page page.png --geometry geometry.json --out out   # What Scan with OCR does, markers as placed
ml\.venv-kraken\Scripts\python.exe ml\line_markers.py snap    --page page.png --geometry geometry.json             # What Snap to table does
ml\.venv-kraken\Scripts\python.exe ml\line_markers.py grid    --page sample.png                                   # Printed rules on a template sample
```
`--out` gets `lines.json`, the crops, and `overlay.png` (every outline drawn on the page).

### Housekeeping and Training Data
```powershell
php artisan documents:prune-pages                   # Delete unsubmitted pages older than LINE_MARKERS_KEEP_HOURS (default 24)
php artisan documents:prune-pages --hours=2         # ... older than 2 hours
php artisan schedule:list                           # What the scheduler runs, and when (prune-pages: every hour)
php artisan schedule:work                           # Run prune-pages every hour; serve.ps1 starts it (use Task Scheduler in production)
php artisan crms:export-training                    # Verified line crops and their corrected text, as a TrOCR training CSV
php artisan crms:export-training --since=2026-09-01 --out=D:\exports\september   # Only records submitted since then, to that folder
```

### TrOCR Models
With `.venv` activated. `<model>` is a folder under `ml\models\`, or `base` for the unmodified Microsoft model.
```powershell
python ml\download_trocr.py                                  # Download microsoft/trocr-base-handwritten into ml\models\base
python ml\train_trocr.py --dataset default --epochs 5        # Fine-tune; saves the best epoch to ml\models\trocr-finetuned
python ml\train_trocr.py --help                              # Every option (--output-name, --batch-size, --learning-rate, ...)
python ml\test_trocr.py                                      # Evaluate the base model on the test split
python ml\test_finetuned.py --model <model>                  # Evaluate a model: CER, WER, exact match, and a chart
python ml\test_finetuned.py --model <model> --limit 200      # ... on the first 200 samples only
python ml\predict.py --model <model> --folder ml\new_images  # Read a folder of loose images
```

### Tests and Code Style

**Before you commit, run `.\tools\test-all.ps1`.** It runs the PHP, JavaScript and both Python suites in order and stops with a clear message at the first failure. MySQL (XAMPP) must be running, because the PHP tests use the `crms_test` database.

```powershell
.\tools\test-all.ps1                                         # All four test suites
php artisan test                                             # PHP tests
php artisan test --filter=LineOutlinePipelineTest            # One test class
npm run test:js                                              # JavaScript tests
ml\.venv-kraken\Scripts\python.exe -m unittest tests.Python.test_line_markers   # Line detection tests
ml\.venv-kraken\Scripts\python.exe -m unittest tests.Python.test_grid_layouts   # Row grid on many ledger layouts (about a minute)
python -m unittest discover tests/Python                     # All Python tests (line detection ones skip without Kraken's packages)
vendor\bin\pint --test                                       # Check PHP code style
vendor\bin\pint                                              # Fix PHP code style
```

---

## The TrOCR Machine Learning Pipeline

All machine learning scripts, training routines, and evaluation utilities are isolated in the `ml/` directory.

```
ml/
├── train_trocr.py        # Fine-tunes VisionEncoderDecoderModel with its own PyTorch loop (AdamW, mixed precision)
├── test_trocr.py         # Evaluates base model performance against test split
├── test_finetuned.py     # Evaluates a model on a split: CER / WER / exact match, plus a chart
├── predict.py            # CLI batch inference on directory of image crops
├── metrics.py            # CER, WER, and exact-match computation logic
├── trocr_common.py       # Model loading and confidence scoring shared with the OCR service
├── dataset_registry.py   # Dataset names and path resolution
├── download_trocr.py     # Fetches microsoft/trocr-base-handwritten weights
└── notebooks/            # Kaggle / Colab fine-tuning notebook (writes evaluation-report.json)
```

### 1. Dataset Layout Specification
Training datasets live in named folders under `ml/datasets/` (gitignored). The scripts use the one called `default` unless you pass `--dataset <name>`:
```
ml/datasets/default/
├── manifest.csv          # Columns: filename,label,split,source
├── train/                # Training image crops (.png, .jpg)
├── val/                  # Validation image crops
└── test/                 # Locked evaluation test split
```
*Entries labeled `UNREADABLE` or with blank labels are automatically skipped. An older checkout's single `ml/dataset/` folder is still used as `default` when `ml/datasets/default/` does not exist.*

### 2. Base Model Download
```bash
python ml/download_trocr.py
```
This saves the base model to `ml/models/base/`. Restart the OCR service, then click **Rescan models** in the OCR Workspace.

### 3. Fine-Tuning TrOCR
```bash
python ml/train_trocr.py --dataset default --epochs 5
```
Every setting is a command-line option: `--dataset`, `--output-name` (default `trocr-finetuned`), `--base-model`, `--epochs` (5), `--batch-size` (8, for a 6 GB GPU), `--learning-rate` (5e-5), `--max-label-length` (32), and more (`--help` lists them). After each epoch the model is kept only if its validation loss is the lowest so far, so `ml/models/<output-name>/` ends up holding the best epoch.

### 4. Evaluating Models
```bash
python ml/test_finetuned.py --model trocr-v1   # a folder under ml/models/
```
This prints the Character Error Rate (CER), Word Error Rate (WER) and exact-match accuracy on the test split, and saves a chart under `ml/evaluation-metrics/finetuned/` (`base/` for the base model).

### 5. Benchmark Provenance Report (`evaluation-report.json`)
The OCR Workspace shows a model's CER, WER and exact match **only** from an `evaluation-report.json` inside the model folder, never from operational scans. The fine-tuning notebook (`ml/notebooks/trocr-finetuning-code.ipynb`) writes it. The OCR service accepts it only if it has:
- `schema_version: 1` and `split: "test"` (the locked test split), with a positive `sample_count`
- the `dataset` name and the SHA-256 of its manifest (`manifest_sha256`)
- the weights file name (`weights_file`) and its SHA-256 (`weights_sha256`). The service hashes the real weights file and rejects the report if they differ, so metrics from one run cannot be attached to another checkpoint
- `evaluated_at` with a timezone, and `metrics` with `cer`, `wer` and `exact_match`

A model without a report still works; it just shows no benchmark. A report that fails these checks is refused when the model is uploaded.

### 6. Installing Models into the Web Application
1. Log in as **Super Admin** and navigate to the **OCR Workspace**.
2. Click **Add model** and upload either a `.zip` archive or the loose files: `config.json`, `model.safetensors` (or `pytorch_model.bin`), the tokenizer files, and optionally `evaluation-report.json`.
3. Under **Scanning policy**, choose it as the **Approved model** and click **Save policy**. Turn on **Allow Staff model choice** to let Staff pick another installed model for a single document.

---

## OCR Microservice API Reference

The FastAPI service exposes the following endpoints (bound to `127.0.0.1:8001`). "Service key" means the request must carry the header `X-CRMS-Service-Key` with the secret Laravel and the service share (`OCR_UPLOAD_SECRET`, or `APP_KEY` when that is empty); without it the answer is **401**. Laravel's `OcrClient` sends it on every call.

| Method | Endpoint | Description | Access / Authorization |
| :--- | :--- | :--- | :--- |
| `GET` | `/health` | Returns service status, hardware device (`cuda`/`cpu`), active model, and model list. | Open (no key), so "is it up?" always works |
| `GET` | `/models` | Returns available installed model metadata and provenance metrics. | Service key |
| `POST` | `/ocr` | Reads base64 data-URL crops, 16 per GPU pass, and returns text + confidence for each. | Service key |
| `POST` | `/add_model` | Direct-browser multipart upload endpoint for model archives (`.zip` or loose files). | Signed ticket from Laravel, in the `X-OCR-Upload-Authorization` header |
| `POST` | `/rename_model` | Renames a model folder directory on disk. | Service key |
| `POST` | `/delete_model` | Removes an inactive model folder from `ml/models/`. | Service key |

### Sample OCR Request (`POST /ocr`)
Header: `X-CRMS-Service-Key: <the shared secret>`
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

### Sample OCR Response
```json
{
  "results": [
    {
      "name": "child_first_name",
      "text": "MARIA CLARA",
      "confidence": 96.8
    }
  ],
  "model": "TrOCR v1",
  "modelKey": "trocr-v1"
}
```
Results come back in the order the crops were sent. `confidence` is the model's certainty in its own reading (0–100), not accuracy. A crop that could not be read gets `"text": ""`, `"confidence": 0` and an `error` message, and the other crops are still read.

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

# Dedicated secret shared by Laravel and FastAPI: signs model-upload tickets
# and is the X-CRMS-Service-Key on every other call
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
2. **Process Management**: Run FastAPI and `php artisan queue:work --timeout=900 --tries=1` under a supervisor daemon (systemd, Supervisor, NSSM on Windows, or Docker restart policies) rather than development uvicorn reloaders, and run `php artisan schedule:run` every minute so unsubmitted pages are pruned.
3. **Configuration Caching**: Rebuild Laravel caches whenever `.env` parameters change:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
4. **A Real Web Server**: Serve `public/` through Apache or Nginx with PHP-FPM rather than `php artisan serve`, which handles one request at a time on Windows.
5. **Each Deployment**: Run `npm run build` (it also copies the PDF.js worker), `php artisan migrate --force`, then `php artisan queue:restart` so the worker loads the new code. Back up the database before a migration that drops a column.

---

## Testing & Quality Assurance

CRMS maintains a comprehensive test suite across PHP, JavaScript, and Python layers: **455 tests, all passing** (2026-10-03). The code review of 2026-10-02 started from 409; most of its fixes added a test that reproduces the problem they fixed.

| Suite | Tests | Runs with |
| :--- | ---: | :--- |
| PHP (PHPUnit) | 300 | `php artisan test` |
| JavaScript (Node.js test runner) | 86 | `npm run test:js` |
| Python: line detection | 60 | `ml\.venv-kraken\Scripts\python.exe` |
| Python: OCR service | 9 | `.venv\Scripts\python.exe` |

```
tests/
├── Feature/                    # 22 PHPUnit feature test classes (300 tests)
│   ├── AnalyticsDashboardTest.php
│   ├── ArrayQueryParameterTest.php          # An array in the URL (?q[]=x) never gives an error page
│   ├── AuditLogTest.php
│   ├── AuditLogViewerTest.php               # Includes times shown in Philippine time
│   ├── AuthenticationTest.php
│   ├── CapabilityMatrixTest.php
│   ├── ChangeRequestPresentationTest.php
│   ├── ChangeRequestWorkflowTest.php        # Includes long rejection notes and decisions under a row lock
│   ├── DocumentTemplateBuilderTest.php      # Includes refusing to delete a layout records use
│   ├── DocumentUploadWorkflowTest.php       # Includes the missing-required-fields confirmation and TIFF refusal
│   ├── LedgerTemplateAndExportTest.php      # Ledger grids, training CSV export
│   ├── LineOutlinePipelineTest.php          # Page job, Detect, outlines, manual fixes
│   ├── OcrClientTest.php                    # Every call to the OCR service carries the service key
│   ├── OcrModelPerformanceTest.php
│   ├── OcrWorkspaceTest.php
│   ├── RecordArchiveFilterTest.php          # Records filters: bad dates, Philippine days
│   ├── RecordDetailPresentationTest.php     # Includes the straightened page kept with a record
│   ├── ReportExportTest.php                 # Includes CSV injection and Philippine time in the CSV
│   ├── TemplateBuilderGridChecksTest.php    # Grid checks, tilted markers, printed rules, test on sample (queued)
│   ├── TemplateFieldSettingsTest.php        # Roles, value types, required fields, record identity
│   ├── TemplateVersioningTest.php           # New versions of layouts in use, stale saves, duplicates
│   └── UserManagementTest.php
├── JavaScript/                 # Node.js unit tests (SNEAT controls, shortcuts, markers, line geometry)
│   ├── change-request.test.js
│   ├── field-marker.test.js
│   ├── icon-coverage.test.js
│   ├── layout-test.test.js                  # Test-on-sample summary
│   ├── ledger-grid.test.js                  # Row lines following the table, row tools, grid checks
│   ├── line-geometry.test.js
│   ├── person-grouping.test.js
│   ├── record-detail.test.js
│   ├── sneat-controls.test.js
│   ├── template-builder-shortcuts.test.js
│   ├── template-history.test.js             # Undo covering row lines
│   ├── value-types.test.js                  # Date, number and choice checks in Verify
│   └── verification-groups.test.js
└── Python/                     # Python unit tests
    ├── test_evaluation_report.py   # ML evaluation report verification
    ├── test_grid_layouts.py        # Row grid on ledgers of many layouts, and the grid notes (needs ml/.venv-kraken)
    ├── test_line_markers.py        # Line detection on synthetic pages (needs ml/.venv-kraken)
    ├── test_ocr_batch.py           # Batched reading: a broken crop, order across batches, per-crop confidence
    └── test_service_key.py         # The OCR service refuses calls without the right key; /health stays open
```

### Running Test Suites

#### All at Once
```powershell
.\tools\test-all.ps1
```
Runs the four suites in the order of the table above, using the right Python environment for each, and stops with a clear message at the first failure. MySQL must be running, and the `crms_test` database must exist (see below).

#### 1. PHPUnit Automated Tests
Create an isolated test database (`crms_test`) once, then run the test suite. The tests empty and rebuild that database, never your real `crms` one:
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
The Python tests need two environments. The line-detection tests need scipy, scikit-image and shapely, which the Kraken environment has; the OCR service tests need FastAPI and PyTorch, which `.venv` has:
```powershell
ml\.venv-kraken\Scripts\python.exe -m unittest tests.Python.test_line_markers tests.Python.test_grid_layouts
.venv\Scripts\python.exe -m unittest tests.Python.test_evaluation_report tests.Python.test_service_key tests.Python.test_ocr_batch
```
`python -m unittest discover tests/Python` also works, but under one interpreter the tests that need the other environment's packages are skipped or fail to import.
`test_grid_layouts` draws ledgers with different numbers of columns and rows, row heights, headers, missing rules and template mismatches, and checks every line lands in its own row both by Scan with OCR and by Detect. Add a layout there when a real page goes wrong, so the fix cannot be tuned to that one page.

#### 4. Pre-Commit Validation Checklist
```powershell
.\tools\test-all.ps1                     # Every test suite: PHP, JavaScript and both Python environments
npm run check:icons                      # Verify Boxicons icon subset coverage
npm run build                            # Verify production asset compilation (and copy the PDF.js worker)
vendor\bin\pint --test                   # Verify PHP code style
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
| `OCR_UPLOAD_SECRET` | *(Empty / Falls back to `APP_KEY`)* | Secret shared by Laravel and the OCR service: signs direct model-upload tickets and is the `X-CRMS-Service-Key` sent on every other call. Both sides read it from `.env`. |
| `OCR_UPLOAD_TICKET_TTL` | `900` | Validity lifetime in seconds for model upload tickets (max 3600). |
| `QUEUE_CONNECTION` | `database` | Queue for the page job (Detect / outline / read). A worker must be running. |
| `DB_QUEUE_RETRY_AFTER` | `960` | Seconds before a stuck page job is retried; keep it above the worker's `--timeout=900`. |
| `LINE_MARKERS_PYTHON` | *(Empty: uses `ml/.venv-kraken`)* | Python interpreter that runs `ml/line_markers.py`. |
| `LINE_MARKERS_TIMEOUT` | `600` | Seconds one page's line detection may take. |
| `LINE_MARKERS_DEVICE` | `auto` | Where Kraken runs: `auto` (the GPU when `ml/.venv-kraken` has CUDA PyTorch, else the CPU), `cuda`, or `cpu`. |
| `LINE_MARKERS_KEEP_HOURS` | `24` | Unsubmitted pages, and abandoned Test on sample folders, older than this are removed by `documents:prune-pages`. |
| `OCR_BROWSER_ORIGIN_REGEX` | *(Loopback regex)* | Allowed browser origins regex for CORS upload requests. |
| `CRMS_CONFIDENCE_THRESHOLD` | `80` | Default OCR confidence threshold below which fields flag for review. |
| `CRMS_REPORTING_TIMEZONE` | `Asia/Manila` | Timezone every page and the CSV show times in, and whose days the date filters and dashboard use. Stored times stay UTC. |
| `CRMS_SUPER_ADMIN_NAME` | `"Super Admin"` | Initial name for the bootstrap Super Admin seeder. |
| `CRMS_SUPER_ADMIN_EMAIL` | `superadmin@admin.com` | Initial email for the bootstrap Super Admin seeder. |
| `CRMS_SUPER_ADMIN_PASSWORD` | `superadmin@admin.com` | Initial password for the bootstrap Super Admin seeder. |

---

## Known Limitations & Planned Work

The code review of 2026-10-02 ([docs/CODE_REVIEW_TODO.md](docs/CODE_REVIEW_TODO.md)) was worked through in the order set by [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md). These items were deliberately left for after the capstone defense. None of them changes what users see.

- **The document type is stored twice.** The `document_types` table is the source of truth, but the older `doc_type` column (on `records` and `document_templates`) and the `DocumentType` enum still repeat it, and every custom type is saved there as `custom`. Removing them touches most models and controllers.
- **Three very large files.** `resources/views/scan/workspace.blade.php` (4,274 lines, most of it inline JavaScript), `app/Http/Controllers/DocumentTemplateController.php` (1,245 lines) and `ml/line_markers.py` (2,582 lines) are due to be split into smaller modules. The tests that cover them are in place first.
- **Archive search uses `LIKE`.** That is fast enough for a few hundred records. Once there is much more data, a FULLTEXT index on `record_fields.verified_value` would be faster, but it matches whole words only, so search results would change slightly.
- **Old migrations create and later drop `ml_jobs` and `ml_datasets`.** Model training and dataset preparation once ran from the website; that work moved to the command-line scripts in `ml/`. The migrations stay, because a migration that has already run on a database is never edited or deleted.

---

## Technology Stack

- **Backend**: [Laravel 12](https://laravel.com/), PHP 8.2+, Composer
- **Line Detection**: [Kraken](https://kraken.re/) 7 baseline segmentation, SciPy, scikit-image, Shapely
- **OCR & ML Microservice**: [FastAPI](https://fastapi.tiangolo.com/), [PyTorch](https://pytorch.org/), [Hugging Face Transformers](https://huggingface.co/docs/transformers/index) (Microsoft TrOCR), Pillow, Pandas
- **Frontend & UI**: Blade Templates, [Bootstrap 5](https://getbootstrap.com/), SNEAT Design System, Sass, [Vite](https://vitejs.dev/)
- **Charts & Visuals**: [ApexCharts](https://apexcharts.com/)
- **Document Viewing**: [PDF.js](https://mozilla.github.io/pdf.js/) (its worker is served by the app, so it works offline)
- **Icons**: [Boxicons](https://boxicons.com/) (Optimized and subsetted via `@iconify/utils`)
- **Database**: MySQL 8.0+ / MariaDB

---

## License

This project is proprietary civil registry software. All rights reserved.
