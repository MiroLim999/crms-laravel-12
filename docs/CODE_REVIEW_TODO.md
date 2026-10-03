# CRMS Code Review: To-Do List

Based on the plain-language code review of **2026-10-02**. Each item has a short explanation, the files involved, a rough effort estimate and a checklist. Tick a box by changing `[ ]` to `[x]`.

- **Effort:** Small = under an hour · Medium = a few hours · Large = a day or more (rough guesses)
- **(Tested)** = the problem was reproduced with a real test, not only found by reading the code.
- **Starting point:** all 409 tests passed before any fixes: PHP 262, JavaScript 85, Python 62.

## Contents

1. [Priority 1: Fix before the defense](#priority-1-fix-before-the-defense): items 1, 3–6
2. [Priority 2: Questions the panel will probably ask](#priority-2-questions-the-panel-will-probably-ask): items 8–13, 15–16
3. [Priority 3: Leftover and duplicate code (cleanup)](#priority-3-leftover-and-duplicate-code-cleanup): items 17–28
4. [Priority 4: Speed improvements](#priority-4-speed-improvements): items 29–32
5. [Priority 5: Easier-to-read code](#priority-5-easier-to-read-code): items 33–37

---

## Priority 1: Fix before the defense

A panel member could trigger any of these during a live demo.

### 1. A long rejection note breaks the audit log (Tested)

**Problem:** The audit log's `description` column holds only 255 characters. Rejecting a request adds the whole note (up to 2,000 characters) to it, so the database refuses the entry. The reviewer sees a raw `SQLSTATE` error, the request still ends up **rejected**, and **no audit entry** is written.

**Files:** [ChangeRequestService.php:178](../app/Services/ChangeRequestService.php#L178) · [ChangeRequestController.php:164](../app/Http/Controllers/ChangeRequestController.php#L164) (also lines 222, 241, 254) · [create_audit_logs_table.php:38](../database/migrations/0001_01_01_000100_create_audit_logs_table.php#L38)

**Effort:** Small

- [x] Create a **new** migration that changes `audit_logs.description` to `text`. Don't edit the old migration, because existing databases won't run it again.
- [x] In `reject()`, keep the description short and store the note in `new_values` instead (e.g. `['decision_note' => $note]`).
- [x] Wrap `reject()` and `withdraw()` in `DB::transaction(...)`, as `approve()` already is, so the status change and the audit entry succeed or fail together.
- [x] Create `App\Exceptions\ChangeRequestException` and use it for every `throw new RuntimeException` in `ChangeRequestService`.
- [x] In `ChangeRequestController`, catch `ChangeRequestException` instead of `RuntimeException` (4 places), so database errors stop appearing in the banner.
- [x] **Test:** reject with a 400-character note. The status should be `rejected` **and** one `change_request.rejected` audit row should exist.



### 3. A bad date on the Records page crashes it (Tested)

**Problem:** Opening `/records?from=abc` shows a "500 Server Error" page, because the date is used before anyone checks that it's a real date. The date pickers always send valid dates, so only an edited URL triggers this.

**Files:** [RecordController.php:42](../app/Http/Controllers/RecordController.php#L42)

**Effort:** Small

- [x] At the start of `index()`, validate the filters:
  - `from` and `to` → `nullable|date`, with `to` also `after_or_equal:from`
  - `status` → one of the record statuses
  - `type` → `exists:document_types,key`

  Copy the style of `ReportController::filters()`.
- [x] Use the validated values in the query instead of `$request->date(...)`.
- [x] **Test:** `/records?from=abc` returns a validation error, not a 500.

### 4. The CSV export can run formulas in Excel (Tested)

**Problem:** A value that starts with `=` (for example `=HYPERLINK(...)`) is written to the CSV unchanged, and Excel runs it as a formula when the file is opened. This is called "CSV injection."

**Files:** [ReportController.php:88](../app/Http/Controllers/ReportController.php#L88) (and the `row()` method below it)

**Effort:** Small

- [x] Add a helper, e.g. `private function safeCell(mixed $value): mixed`, that puts `'` in front of any **text** starting with `=`, `+`, `-`, `@`, a tab or a carriage return.
- [x] Run every text cell in `row()` through it: registry number, primary value, people's names and model key.
- [x] Leave the number cells (record ID, field count, average confidence) as they are.
- [x] **Test:** a record whose value is `=1+1` is exported as `'=1+1`.

### 5. Two clicks at the same moment can cause conflicts

**Problem:** The code checks "is this request still pending?" and saves the decision a moment later, without locking the row. Two reviewers, or one double-click, can both get through. For example, the same request could be both approved **and** rejected. A double-submitted change request can also leave **two** pending requests on one record.

**Files:** [ChangeRequestService.php:44](../app/Services/ChangeRequestService.php#L44) (`open`), [line 125](../app/Services/ChangeRequestService.php#L125) (`approve`), [line 178](../app/Services/ChangeRequestService.php#L178) (`reject`), [line 203](../app/Services/ChangeRequestService.php#L203) (`withdraw`)

**Effort:** Medium

- [x] In `approve()`, `reject()` and `withdraw()`, work inside `DB::transaction`:
  - reload the request with `ChangeRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail()`
  - run `guardOpen()` on that fresh copy
  - use the fresh copy for the rest of the method
- [x] In `open()`, lock the record row inside the transaction (`CivilRecord::whereKey(...)->lockForUpdate()->first()`), then check again for a pending request before creating a new one.
- [x] Disable the submit, Approve and Reject buttons after the first click (a small `submit` listener on those forms).
- [x] **Test:** deciding a request that was already decided is refused. Truly simultaneous clicks are hard to test; the lock is the real fix.

### 6. All times are shown 8 hours behind

**Problem:** Times are stored in UTC (world standard time) and also shown in UTC, so 3:00 PM appears as 7:00 AM. The date filters also disagree with each other: Records and Audit Log filter by UTC days, while Reports filters by Philippine days.

**Files:** [config/app.php:68](../config/app.php#L68) · views that call `->format(`, e.g. [audit/index.blade.php:111](../resources/views/audit/index.blade.php#L111) and [change-requests/show.blade.php:40](../resources/views/change-requests/show.blade.php#L40) · [AuditLogController.php:41](../app/Http/Controllers/AuditLogController.php#L41) · [RecordController.php:42](../app/Http/Controllers/RecordController.php#L42) · [ReportController.php](../app/Http/Controllers/ReportController.php) (times in the CSV)

**Effort:** Medium

- [x] Keep **storing** UTC and don't change `config/app.php`. Existing rows are in UTC, and the dashboard and reports already convert from UTC.
- [x] Add one display helper (e.g. a `localTime($date, $format)` function or a Blade directive) that converts to `config('crms.reporting_timezone')` (Asia/Manila) before formatting.
- [x] Replace every timestamp `->format(...)` in `resources/views` with the helper (search for `->format(`).
- [x] Convert the times in the CSV export too: `row()` currently uses `toDateTimeString()`, which is UTC.
- [x] Make the Records and Audit Log date filters use Philippine days, like Reports: `Carbon::parse($from, $tz)->startOfDay()->utc()`. Put this in one shared helper used by all three pages.
- [x] **Test:** a record created at `2026-10-01 23:30 UTC` shows as `2 Oct 2026 07:30` and is found by `from=2026-10-02`.


## Priority 2: Questions the panel will probably ask

These don't crash anything, but they're weak spots a panelist could point out.

### 8. Required fields aren't really required

**Problem:** Templates can mark fields as required, but Staff can submit a record with only one checked field. The "missing required" note is only a hint, and the server doesn't check either.

**Files:** [workspace.blade.php:3919](../resources/views/scan/workspace.blade.php#L3919) (the submit check) · [workspace.blade.php:3129](../resources/views/scan/workspace.blade.php#L3129) (`missingRequired()`) · [DocumentScanController.php:139](../app/Http/Controllers/DocumentScanController.php#L139) (`store`)

**Effort:** Medium

- [x] **Decision made: ask for confirmation** ("These required fields are empty: … Submit anyway?"), because some real certificates leave a field blank. Submission is not hard-blocked.
- [x] In the submit handler, run `missingRequired()` for every person or row group. If anything is missing, show a confirmation dialog that lists the missing field names, with "Go back" and "Submit anyway" buttons.
- [x] Only when Staff choose "Submit anyway", add `allow_missing=1` to the form data.
- [x] On the server, in `store()`, check that every required template field (for normal, non-ledger templates) is among the submitted fields. If some are missing and `allow_missing` is not set, return a validation error that names them (Staff then see the same confirmation).
- [x] When the flag is set, save the names of the missing fields in the `record.submitted` audit entry (e.g. `missing_required_fields`), so the omission is on record.
- [x] **Test:** submitting without a required field is refused without the flag, succeeds with it, and the audit entry lists the missing fields.

### 9. The AI service has no password

**Problem:** Anyone who can reach the Python service can call `/ocr`, `/delete_model` and `/rename_model`. The only protection is that it runs on this computer, and with XAMPP, any other site on `localhost` could still call it.

**Files:** [main.py:590](../ml/api/main.py#L590) (`/ocr`) · [main.py:993](../ml/api/main.py#L993) (`/delete_model`) · [main.py:1030](../ml/api/main.py#L1030) (`/rename_model`) · [OcrClient.php:175](../app/Services/Ocr/OcrClient.php#L175) (`request()`)

**Effort:** Medium

- [x] Reuse the existing shared secret (`OCR_UPLOAD_SECRET`, which falls back to `APP_KEY`). Laravel and FastAPI both read it already.
- [x] In `OcrClient::request()`, send the secret in a header on every call (e.g. `X-CRMS-Service-Key`).
- [x] In FastAPI, add a dependency that rejects requests without the right key (compare with `hmac.compare_digest`) on `/ocr`, `/models`, `/delete_model` and `/rename_model`. `/add_model` keeps its signed ticket, and `/health` can stay open.
- [x] Keep the service on `127.0.0.1`, never `0.0.0.0`. (unchanged: `serve.ps1` still starts it with `--host 127.0.0.1`)
- [x] **Test (Python):** `/delete_model` without the key returns 401.
- [x] **Test (PHP):** with `Http::fake()`, check that `OcrClient` sends the header.

### 10. The auto-delete of abandoned scans never runs

**Problem:** Unsubmitted scans are supposed to be deleted every hour. Scheduled tasks only run while `php artisan schedule:work` is running, though, and `serve.ps1` never starts it, so personal documents stay on disk forever.

**Files:** [routes/console.php:12](../routes/console.php#L12) · [serve.ps1](../serve.ps1) (the `-Only` options at line 31 and the start-up section)

**Effort:** Small

- [x] Add `'scheduler'` to the `-Only` options (the `ValidateSet` at line 31), with a branch that runs `php artisan schedule:work`.
- [x] Start it along with the other services (a 4th `Start-ServiceWindow 'scheduler'`), and also in `.vscode/tasks.json` if you use that.
- [x] Check that `php artisan schedule:list` shows `documents:prune-pages` running every hour.
- [x] Try `php artisan documents:prune-pages --hours=1` on some test pages. (not needed: not part of the plan, and on this machine it would delete real unsubmitted pages; `LedgerTemplateAndExportTest` already runs the command)
- [x] Mention the scheduler in the README's start-up instructions.

### 11. PDF scanning needs internet

**Problem:** The PDF reader's helper file (the "worker") is downloaded from an online CDN. Without internet, PDF uploads stop working. This also contradicts the claim that "nothing leaves the computer."

**Files:** [field-marker.js:27](../resources/js/field-marker.js#L27) · [vite.config.js](../vite.config.js) (the NOTE about `pdf.worker`)

**Effort:** Small to Medium

- [x] First find out **why** it was moved to the CDN: read the NOTE in `vite.config.js` and run `git log -S "pdf.worker" --oneline`. (it was there from the first commit, `da10de4`, only to avoid bundling the 2.2 MB worker)
- [x] Load the worker from your own build instead, e.g. `import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'` in `field-marker.js`, followed by `GlobalWorkerOptions.workerSrc = workerUrl`. (done the plan's way: `tools/copy-pdf-worker.mjs` copies it to `public/vendor/pdfjs` before every build, because a `?url` import would break the Node tests)
- [x] Run `npm run build`.
- [x] **Test:** turn off the internet, then open a PDF in the scanning workspace and in the Template Builder. (rehearsed in headless Chrome with outside requests blocked, then confirmed by hand by the user on 2026-10-03)
- [x] Optional: self-host the Public Sans font as well. Offline, the browser just uses a fallback font, which isn't a failure. (not needed: optional, and the plan leaves it out)

### 12. Highlight boxes on saved records might be in the wrong place

**Problem:** When Detect straightens a tilted page, the field positions are measured on the **straightened** page. The record page then draws them on the **original** upload. The rotation (`scan_rotation`) is saved but never used. This hasn't been confirmed yet.

**Files:** [scan-card.blade.php:24](../resources/views/records/partials/scan-card.blade.php#L24) · [DocumentScanController.php:230](../app/Http/Controllers/DocumentScanController.php#L230) (`scan_rotation` saved) · [DocumentScanController.php:289](../app/Http/Controllers/DocumentScanController.php#L289) (the page folder is deleted here)

**Effort:** Small to check, Medium to fix

- [x] **Check first:** scan a visibly tilted page, use Detect, submit, then open the record. Do the boxes sit on the right words? (measured on 2026-10-03 instead of by eye: a 3° tilt put boxes a median 37 px, about one line, off; see Task 2.3 in the plan)
- [x] If they're off, pick one fix:
  - [x] Keep the straightened page image with the record and show that image on the record page. Copy `pages/{id}/page.png` before line 289 deletes the folder, and store its path in a new column. This matches exactly, but adds one more file per record.
  - [x] Or rotate the displayed scan by `scan_rotation` with CSS. It's less work, but may be slightly off if straightening changed the page size. (not needed: chose the first fix)
- [ ] **Test:** the record page uses the right image or rotation (feature test), then check it by eye. (feature tests pass; the by-eye check is still open)

### 13. A template that records use can be deleted

**Problem:** The template system promises that records keep the layout they were read with. Deleting a template quietly breaks that promise, because those records end up pointing at nothing.

**Files:** [DocumentTemplateController.php:428](../app/Http/Controllers/DocumentTemplateController.php#L428) · [templates/index.blade.php:270](../resources/views/templates/index.blade.php#L270)

**Effort:** Small

- [x] In `destroy()`, refuse when `$template->records()->exists()`, with a message such as "This layout was used by N records and can't be deleted."
- [x] Consider also refusing when unsubmitted pages use the template (`$template->pages()->exists()`). (not needed: the plan decided pages in progress don't block the delete; they are only unlinked and then pruned)
- [x] In the template list, hide or disable "Delete layout" for layouts in use, and show why.
- [x] **Test:** deleting a template that has records is refused, and the template still exists.
- [x] Update any existing test that expects a template with records to be deletable.



### 15. The AI service may look "offline" right after it starts

**Problem:** When a model has an evaluation report, the first health check after start-up calculates a fingerprint (SHA-256) of that model's file, which is about 1.3 GB. That can take longer than the 5 seconds Laravel waits for an answer. This hasn't been confirmed yet.

**Files:** [main.py:216](../ml/api/main.py#L216) (`_model_info` → `_read_evaluation_report`) · [OcrClient.php:63](../app/Services/Ocr/OcrClient.php#L63) (5-second health timeout)

**Effort:** Small to check, Medium to fix

- [x] **Check first:** restart the AI service and open the OCR page right away. Does it say "unreachable" for a few seconds? (checked the plan's way on 2026-10-03: no model has an `evaluation-report.json`, and without one nothing is hashed, so it can't happen yet)
- [x] If yes, compute the fingerprints in a background thread at start-up (in `lifespan`), and let `/health` skip a model's evaluation until its fingerprint is ready. (not needed: no model has an evaluation report)
- [x] **Test:** the OCR page shows the service as online right after start-up. (not needed: no model has an evaluation report)

### 16. TIFF scans are offered but don't work (Tested)

**Problem:** The file pickers accept TIFF files, but:
- Chrome and Edge can't display TIFF images.
- The server's submit rule rejects real TIFF files. Laravel recognises them as `tif`, and the rule only lists `tiff`.

**Files:** [DocumentScanController.php:153](../app/Http/Controllers/DocumentScanController.php#L153) (the `scan` rule) · [workspace.blade.php:51](../resources/views/scan/workspace.blade.php#L51) · [templates/edit.blade.php:133](../resources/views/templates/edit.blade.php#L133) · [DocumentTemplateController.php:506](../app/Http/Controllers/DocumentTemplateController.php#L506)

**Effort:** Small

- [x] Simplest fix: remove TIFF from both file pickers (`accept=...`) and from the upload rules, and tell users to scan as PDF, PNG or JPG.
- [x] Or, to keep TIFF support: convert TIFF to PNG in the browser with a TIFF library (e.g. `utif`), and add `tif` to the submit rule. (not needed: chose the simplest fix)
- [x] **Test:** a real `.tiff` file is either refused with a clear message at upload, or makes it all the way to a saved record.

---

## Priority 3: Leftover and duplicate code (cleanup)

None of these break anything. They make the code confusing to read and harder to explain.

### 17. The document type is stored in two places

**Problem:** The `document_types` table holds the types, but the older `doc_type` column (on `records` and `document_templates`) and the hard-coded `DocumentType` enum still repeat the same information. Every custom type is saved as `'custom'` there.

**Files:** [DocumentType.php](../app/Enums/DocumentType.php) · [CivilRecord.php](../app/Models/CivilRecord.php) · [DocumentTemplate.php](../app/Models/DocumentTemplate.php) · [create_records_table.php:21](../database/migrations/2026_01_01_000200_create_records_table.php#L21)

**Effort:** Large. It's fine to leave this until after the defense.

- [ ] Find every use: search for `doc_type` in `app/` and `resources/views`.
- [ ] Switch each read to `document_type_id` / `documentTypeDefinition`.
- [ ] Keep only what's still needed from the enum (the starter boxes in `defaultFields()` for new templates), or move them to a seeder or config file.
- [ ] In a new migration, drop the `doc_type` columns and their indexes, then remove the enum casts.
- [ ] Run the full test suite.

### 18. The "Draft" status is never used

**Problem:** Every record is saved as "Submitted" right away, yet the Draft status is still in the code, the reports and the database default.

**Files:** [RecordStatus.php](../app/Enums/RecordStatus.php) · [CivilRecord.php:84](../app/Models/CivilRecord.php#L84) (`isDraft`) · [ReportController.php:186](../app/Http/Controllers/ReportController.php#L186) · [reports/index.blade.php:70](../resources/views/reports/index.blade.php#L70) · [ChangeRequestService.php:41](../app/Services/ChangeRequestService.php#L41) · [CivilRecordFactory.php:31](../database/factories/CivilRecordFactory.php#L31)

**Effort:** Medium

- [ ] Decide whether to remove it, or keep it as a planned feature (and say so in the defense).
- [ ] If removing:
  - delete `RecordStatus::Draft`, `isDraft()`, the "Drafts" summary and the draft message in `ChangeRequestService::open()`
  - remove the "not locked" checks that can no longer happen
- [ ] Change the factory's default status to `Submitted`. Tests create draft records today, so fix the tests that rely on them.
- [ ] In a new migration, change the default of `records.status` to `'submitted'`.
- [ ] Run the full test suite.

### 19. An unused permission and an unused column

**Files:** [AuthServiceProvider.php:30](../app/Providers/AuthServiceProvider.php#L30) · [CapabilityMatrixTest.php:82](../tests/Feature/CapabilityMatrixTest.php#L82) (and line 127) · [User.php:36](../app/Models/User.php#L36) · [UserFactory.php:30](../database/factories/UserFactory.php#L30) (and the `unverified()` state at line 76)

**Effort:** Small

- [ ] Remove the `records.submit` permission (only a test uses it) and its rows in `CapabilityMatrixTest`.
- [ ] Decide about `users.email_verified_at`:
  - drop it in a new migration and remove it from `User.php`'s casts and from `UserFactory`, or
  - keep it if you plan to add email verification.
- [ ] Run the tests.

### 20. Tables created and then deleted (old migrations)

**Problem:** The migrations create `ml_jobs` and `ml_datasets`, change them and later drop them. This is history from a removed feature. It's harmless, but it's noise.

**Files:** `database/migrations/2026_01_01_000500_…` through `2026_01_01_000900_…`

**Effort:** Small

- [ ] Don't edit or delete the old migrations, because they've already run on existing databases.
- [ ] Optional: `php artisan schema:dump --prune` replaces the history with one schema file. Only do this if every teammate will rebuild their database afterwards.
- [ ] Prepare a one-sentence explanation, e.g. "the training and dataset features moved to command-line scripts."

### 21. Dead Python code in dataset_registry.py

**Problem:** About 300 lines (creating, listing and deleting datasets) come from a removed web feature, and nothing calls them anymore.

**Files:** [dataset_registry.py](../ml/dataset_registry.py): `list_datasets` (line 175) and everything from line 326 to the end of the file

**Effort:** Small

- [ ] Confirm nothing uses them: search `ml/` and `tests/Python/` for `list_datasets`, `create_from_zip`, `create_from_directory` and `delete_dataset`.
- [ ] Delete those four functions, plus the helpers that only they use: `_safe_extract`, `_find_manifest_root`, `_remove_install_artifact`, `_installation_paths`, `_commit_install` and `_assert_regular_directory_tree`.
- [ ] Remove the imports that become unused (`shutil` and `zipfile`).
- [ ] Fix the docstring at the top, which mentions an API `/datasets` call that no longer exists.
- [ ] Run the Python tests.

### 22. Copy-pasted Python functions

**Problem:** The same helper functions are copied into several files. If you fix one copy, the others stay broken.

**Files:** [predict.py:68](../ml/predict.py#L68) (`resolve_model`, `_load`, `eos_token_id`, `sequence_confidence`) · [test_finetuned.py:71](../ml/test_finetuned.py#L71) (`resolve_model`, `_load`) · [main.py:390](../ml/api/main.py#L390) (`_sequence_confidence`, plus the EOS lookup at line 271)

**Effort:** Medium

- [ ] Create one shared module, e.g. `ml/trocr_common.py`, containing `resolve_model`, `load_model`, `eos_token_id` and `sequence_confidence`. Compare the copies first and keep the most careful version.
- [ ] Import it in `predict.py`, `test_finetuned.py` and `api/main.py`, and delete the copies.
- [ ] Run the Python tests, do one `predict.py` run, and do one OCR read through the app.

### 23. Repeated code in DocumentPageController

**Files:** [DocumentPageController.php](../app/Http/Controllers/DocumentPageController.php)

**Effort:** Small

- [ ] Add `private function lineFlags(DocumentPage $page)` for the `$page->lines()->get()->mapWithKeys(...)` code that appears 4 times (lines 371, 428, 461, 482).
- [ ] Add `private function clampPolygon(array $points, DocumentPage $page)` for the clamping code in `updateLine()` (line 291) and `storeLine()` (line 395).
- [ ] Move the stray comment at [line 93](../app/Http/Controllers/DocumentPageController.php#L93) ("Scan with OCR after Detect…") to the `read()` method.
- [ ] The `abort_unless(... document_page_id ...)` checks repeat what `->scopeBindings()` already guarantees. Either remove them, or keep them with a comment saying they're a deliberate double check.
- [ ] Run `php artisan test --filter=LineOutlinePipelineTest`.

### 24. Comments that are no longer true

**Effort:** Small

- [ ] [DocumentScanController.php:34](../app/Http/Controllers/DocumentScanController.php#L34) says cropping happens in the browser. The server crops now.
- [ ] [DocumentScanController.php:70](../app/Http/Controllers/DocumentScanController.php#L70) says the scan stays in the browser until submission. Pages are uploaded at the Align step and pruned later.
- [ ] [DocumentTemplateController.php:519](../app/Http/Controllers/DocumentTemplateController.php#L519) says names are stored in 255 characters. The columns are 500 characters now.
- [ ] The `dataset_registry.py` docstring (see item 21).
- [ ] While you're editing, watch for other comments that describe old behaviour.

### 25. A link to a file that isn't in the repo

**Problem:** Two comments point to `.kiro/steering/product.md`, but the `.kiro/` folder is excluded from Git, so teammates and panelists can't open it.

**Files:** [AuthServiceProvider.php:14](../app/Providers/AuthServiceProvider.php#L14) · [RoleSlug.php:39](../app/Enums/RoleSlug.php#L39)

**Effort:** Small

- [ ] Copy the role and capability table from `.kiro/steering/product.md` into the README (or into `docs/roles.md`).
- [ ] Point both comments to the new location.

### 26. Different limits for the same value

**Problem:** A person-group number can be at most 450 when a record is submitted, but up to 65,535 in templates and in line detection.

**Files:** [DocumentScanController.php:160](../app/Http/Controllers/DocumentScanController.php#L160) · [DocumentTemplateController.php:535](../app/Http/Controllers/DocumentTemplateController.php#L535) · [GeometryInput.php:57](../app/Services/Lines/GeometryInput.php#L57)

**Effort:** Small

- [ ] Pick one limit and use it everywhere, through a shared constant.
- [ ] Run the tests.

### 27. Clutter in the main folder

**Effort:** Small

- [ ] Move `CIVIC_PALETTE_EXECUTION_PLAN.md` into `docs/`, or delete it if that work is finished.
- [ ] Move `trocr-finetuning-code.ipynb` into `ml/notebooks/`, and update any README link to it.
- [ ] Delete the empty `test-results/` folder, and add it to `.gitignore` if a tool keeps recreating it.

### 28. An unused route: documents.recognise

**Problem:** This route belongs to the old flow, where the browser cut out the crops. The scanning page still puts its URL in its config but never calls it. Only tests use it.

**Files:** [routes/web.php:88](../routes/web.php#L88) · [DocumentScanController.php:110](../app/Http/Controllers/DocumentScanController.php#L110) (`recognise()`) and [line 419](../app/Http/Controllers/DocumentScanController.php#L419) (`resolveModelKey()`) · [workspace.blade.php:624](../resources/views/scan/workspace.blade.php#L624) · [DocumentUploadWorkflowTest.php:144](../tests/Feature/DocumentUploadWorkflowTest.php#L144) · [OcrWorkspaceTest.php:509](../tests/Feature/OcrWorkspaceTest.php#L509) (also lines 544 and 576)

**Effort:** Small to Medium

- [ ] Double-check that nothing calls it: search `resources/` for `recogniseUrl` and `documents/recognise`.
- [ ] Remove the route, `recognise()`, `resolveModelKey()` and the `recogniseUrl` config line.
- [ ] The tests that call it check the model-choice rules. Move those checks to the endpoint used today (`documents.pages.store`) instead of just deleting them.
- [ ] Run the full test suite.

---

## Priority 4: Speed improvements

### 29. The AI reads one image at a time

**Problem:** Laravel sends up to 40 line images at once, but the AI service reads them one by one in a loop. A GPU is fastest when it processes many images together ("batching"). This is the biggest speed win available.

**Files:** [main.py:603](../ml/api/main.py#L603) · [main.py:390](../ml/api/main.py#L390) (`_sequence_confidence`)

**Effort:** Medium

- [ ] Time one full page now and write the number down.
- [ ] Decode all the images first. Any that fail become error rows, so one bad crop doesn't fail the whole batch.
- [ ] Run the processor and `model.generate(...)` once for the whole list (or in chunks of 8–16 if GPU memory is tight).
- [ ] Work out each crop's confidence from its own row of the scores. The current function only reads row `[0]`.
- [ ] Check that the batched texts match the one-by-one texts on the same crops.
- [ ] Time the same page again, and keep both numbers for the defense.

### 30. List pages load more data than they show

**Problem:** The records list, the reports page and the change-request list load **every field of every record**, just to show a title and an average. A ledger record can have hundreds of fields.

**Files:** [RecordController.php:29](../app/Http/Controllers/RecordController.php#L29) · [ReportController.php:35](../app/Http/Controllers/ReportController.php#L35) (and line 85 for the export) · [ChangeRequestController.php:47](../app/Http/Controllers/ChangeRequestController.php#L47)

**Effort:** Medium

- [ ] Check what each list view actually shows. The records list only uses `title()`; the reports page also shows the average confidence.
- [ ] Load only that. For example, use `withAvg('fields', 'ocr_confidence')` for the average and a limited eager load for the title (Laravel 11+ supports `->limit()` inside `with()`). Update the views to match.
- [ ] The change-request list needs the field groups for its headings, so measure it first and only change it if it's slow.
- [ ] Measure the number of queries and rows before and after (e.g. with `DB::enableQueryLog()` or Laravel Debugbar), using a big ledger record.

### 31. Missing database indexes

**Effort:** Small

- [ ] In a new migration, add an index on `records.created_at` (Reports filters on it).
- [ ] Optional, once there's a lot of data: add a FULLTEXT index on `record_fields.verified_value` and switch the archive search to `whereFullText(...)`. Note that full-text search matches whole words, so results change slightly.
- [ ] Run the tests.

### 32. Slow AI work inside normal page requests

**Problem:** "Test layout" in the Template Builder runs line detection (about 30 seconds) while the browser waits. On Windows, `php artisan serve` handles **one request at a time**, because PHP can't run several server workers there. (The first review said 4 workers; that was wrong.) So while Test layout runs, every other page waits too.

**Files:** [DocumentTemplateController.php:120](../app/Http/Controllers/DocumentTemplateController.php#L120) (`testLayout`) · [serve.ps1](../serve.ps1)

**Effort:** Medium

- [ ] **Option A:** move Test layout to the queue, like Detect. Save the sample, dispatch a job, return an ID, and let the builder poll for the result.
- [ ] **Option B:** serve the app through XAMPP's Apache instead of `php artisan serve`. Apache handles requests in parallel.
- [ ] **Check:** while Test layout is running, can another browser tab open the dashboard?

---

## Priority 5: Easier-to-read code

### 33. Move the scanning page's JavaScript out of the template

**Problem:** [workspace.blade.php](../resources/views/scan/workspace.blade.php) is 4,040 lines long, and about 3,450 of those lines are JavaScript inside the HTML template (lines 589–4039).

**Effort:** Large

- [ ] Create `resources/js/scan-workspace/` and move the script into modules, one per step: upload and align, detect and progress, verify, submit.
- [ ] Pass server data through one JSON config block (like `templateBuilderConfig` in [templates/edit.blade.php:692](../resources/views/templates/edit.blade.php#L692)) instead of mixing Blade into the JavaScript.
- [ ] Add the entry file to `vite.config.js` and run `npm run build`.
- [ ] Add node tests for the pure functions, e.g. building the submission data and `missingRequired()`.
- [ ] Click through a full scan → verify → submit to confirm nothing broke.

### 34. Slim down DocumentTemplateController

**Problem:** [DocumentTemplateController.php](../app/Http/Controllers/DocumentTemplateController.php) is 1,232 lines long. A controller should mostly receive the request and return a response.

**Effort:** Large

- [ ] Move `validatePayload()` and its helpers into Form Request classes (e.g. `StoreTemplateRequest` and `UpdateTemplateRequest`).
- [ ] Move versioning and saving (`createLayout`, `saveAsNewVersion`, `layoutChanged`, `layoutSignature`, `syncFields`, `publishTemplate`) into a `TemplateLayoutService`.
- [ ] Keep each controller method short: validate → call the service → redirect.
- [ ] Run `DocumentTemplateBuilderTest`, `TemplateVersioningTest`, `TemplateFieldSettingsTest` and `TemplateBuilderGridChecksTest`.

### 35. Split line_markers.py

**Problem:** [line_markers.py](../ml/line_markers.py) is 2,582 lines in one file.

**Effort:** Large

- [ ] Split it into a package (e.g. `ml/line_markers_lib/` with `detect.py`, `grid.py` and `crop.py`). Keep `ml/line_markers.py` as the entry point, because Laravel runs that exact file (see `config/services.php`).
- [ ] Keep the names the tests import (`import line_markers as lm`) working, e.g. by re-exporting them from `line_markers.py`.
- [ ] Run the Python tests.

### 36. Use logging instead of print() in the AI service

**Files:** [main.py](../ml/api/main.py) (lines 265, 280, 507, 508, 989, 1026, 1075)

**Effort:** Small

- [x] Add `logger = logging.getLogger("ocr-api")` and replace each `print(...)` with `logger.info(...)` or `logger.warning(...)`.
- [x] Optional: also write the log to a file, for troubleshooting. (not needed: optional, and the plan leaves it out)

### 37. One command to run all the tests

**Problem:** The Python tests need two different virtual environments, and there's no single command that runs everything.

**Effort:** Small

- [x] Add `tools/test-all.ps1` that runs these in order:
  - `php artisan test`
  - `npm run test:js`
  - `ml\.venv-kraken\Scripts\python.exe -m unittest tests.Python.test_line_markers tests.Python.test_grid_layouts`
  - `.venv\Scripts\python.exe -m unittest tests.Python.test_evaluation_report`
- [x] Make it stop with a clear message as soon as one suite fails.
- [x] Add it to the README ("Before you commit, run `.\tools\test-all.ps1`").
- [x] Baseline to keep: PHP 262, JavaScript 85, Python 62 (60 + 2). All passed on 2026-10-02.
