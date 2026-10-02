# CRMS Implementation Plan

This plan sets the order for working through [CODE_REVIEW_TODO.md](CODE_REVIEW_TODO.md), as you edited it, so that no task undoes or clashes with another. Each task names its item number (**#N**). The to-do file explains the problem and gives the full steps, and this file says **when** to do each one. Tick boxes here as you go.

**Not in this plan:** #2, #7, #14, #38 and #39, because they were removed from the to-do list.

---

## How the order was chosen

1. **Safety net first.** #37 (one test command) comes first, so every task after it can finish with one command.
2. **One file, one task at a time.** When several tasks change the same file, the one that lays the foundation goes first. For example, #1 creates the exception class and the transactions that #5 then adds locks to. The [table at the end](#appendix-which-tasks-touch-the-same-file) lists every shared file.
3. **Delete, then tidy, then move.** Dead code is removed and small fixes are made **before** the big refactors (#33, #34, #35) move code into new files. Otherwise every edit would have to be redone in the new location.
4. **Priorities stay in order (1 → 5), with three deliberate moves:**
   - #37 moves to the very start.
   - #36 moves into the AI-service tasks of Phase 2, so the new code there uses the logger from day one.
   - #20 moves to the very end, after every new migration.

## Rules for every task (definition of done)

Copy this checklist into your head for each task:

- [ ] Read the item in `CODE_REVIEW_TODO.md`.
- [ ] Make the change and add its test.
- [ ] Run all tests (`.\tools\test-all.ps1` once Task 0.2 is done).
- [ ] If you changed a file in `resources/js/`, run `npm run build`. Scripts written inside Blade views don't need a build.
- [ ] If you changed PHP that the queue worker runs (`app/Jobs`, `app/Services/Lines`, `app/Services/Ocr/OcrClient.php`), run `php artisan queue:restart`. The worker loop in `serve.ps1` starts it again with the new code.
- [ ] If you changed anything in `ml/api/`, close and restart the AI service window.
- [ ] If you added a migration, run `php artisan migrate`.
- [ ] Tick the item here and in `CODE_REVIEW_TODO.md`, then commit. Use one commit per task, e.g. `fix(#1): keep rejection notes out of the audit description`.

---

## Phase 0: Prepare

### Task 0.1: Branch and baseline

- [ ] Commit the `docs/` folder (this plan and the to-do list).
- [ ] Create a branch for this work from `fieldmarker-experimental-v2`, e.g. `review-fixes`. Keep `main` as the checkpoint. Always type the branch name in full, because `fieldmarker-experimental-2` and `fieldmarker-experimental-v2` are easy to mix up.
- [ ] Run the four test commands from #37 by hand and confirm the baseline: PHP 262, JavaScript 85, Python 62.

### Task 0.2: #37 One command to run all tests (Small)

**Why first:** every later task ends with "run all tests".
**Touches:** new `tools/test-all.ps1`, `README.md`

- [ ] Create `tools/test-all.ps1` with the four commands, stopping at the first failure.
- [ ] Add it to the README.
- [ ] Run it and see all 409 tests pass.

---

## Phase 1: Priority 1 bug fixes

### Task 1.1: #1 A long rejection note breaks the audit log (Small)

**Why first:** it creates `ChangeRequestException` and the transactions that Task 1.2 builds on.
**Touches:** `ChangeRequestService.php`, `ChangeRequestController.php`, a new migration, `ChangeRequestWorkflowTest.php`

- [ ] New migration: change `audit_logs.description` to `text`.
- [ ] In `reject()`, keep the description short and put the note in `new_values`. The Audit Log page already displays `new_values`, so the note stays visible.
- [ ] Wrap `reject()` and `withdraw()` in `DB::transaction(...)`.
- [ ] Use `ChangeRequestException` instead of `RuntimeException` in the service, and catch it in the controller (4 places).
- [ ] **Test:** a 400-character note leaves the request rejected **and** writes one audit row.

### Task 1.2: #5 Double-click conflicts (Medium)

**Why here:** it adds locks inside the transactions from Task 1.1, in the same four methods.
**Touches:** `ChangeRequestService.php` (`open`, `approve`, `reject`, `withdraw`), the change-request forms (`create.blade.php`, `show.blade.php`)

- [ ] In `approve()`, `reject()` and `withdraw()`, reload the request with `lockForUpdate()` inside the transaction and run `guardOpen()` on that fresh copy.
- [ ] In `open()`, lock the record row, then check again for a pending request inside the transaction.
- [ ] Disable the submit, Approve and Reject buttons after the first click.
- [ ] **Test:** deciding an already-decided request is refused with a clear message, not a crash.

### Task 1.3: #3 A bad date crashes the Records page (Small)

**Why before 1.5:** Task 1.5 changes the same filter lines, so they should be validated first.
**Touches:** `RecordController::index()`

- [ ] Validate `from`, `to`, `status` and `type`.
- [ ] Use the validated values in the query.
- [ ] **Test:** `/records?from=abc` returns a validation error, not a 500.

### Task 1.4: #4 CSV formula injection (Small)

**Why before 1.5:** Task 1.5 also edits `row()`.
**Touches:** `ReportController::row()`

- [ ] Add a `safeCell()` helper for text cells.
- [ ] Apply it in `row()` and leave number cells alone.
- [ ] **Test:** `=1+1` is exported as `'=1+1`.

### Task 1.5: #6 Times shown 8 hours behind (Medium)

**Why last in this phase:** it touches the most files, including the ones Tasks 1.2–1.4 just changed.
**Touches:** a new display helper, every view that formats a timestamp, `RecordController`, `AuditLogController`, `ReportController` (filters and CSV)

- [ ] Keep **storing** UTC. Don't change `config/app.php`.
- [ ] Add one display helper that converts to Asia/Manila.
- [ ] Replace every timestamp `->format(...)` in the views with the helper.
- [ ] Convert the times in the CSV export too.
- [ ] Add one shared date-filter helper and use it on the Records, Audit Log and Reports pages.
- [ ] **Test:** `2026-10-01 23:30 UTC` shows as `2 Oct 2026 07:30` and is found by `from=2026-10-02`.

### Phase 1 checkpoint

- [ ] All tests pass.
- [ ] By hand:
  - reject a change request with a long note
  - open `/records?from=abc`
  - export a CSV and open it in Excel
  - check that times on the Audit Log are Philippine time

---

## Phase 2: Priority 2

### Task 2.1: #16 TIFF scans (Small)

**Why first here:** it's a one-line rule change in `store()`, which Tasks 2.2 and 2.3 edit next.
**Touches:** `DocumentScanController.php:153`, `workspace.blade.php:51`, `templates/edit.blade.php:133`, `DocumentTemplateController.php:506`

- [ ] Decide: remove TIFF (simplest), or convert it in the browser.
- [ ] Apply the decision to both file pickers and both upload rules.
- [ ] **Test** with a real `.tiff` file.

### Task 2.2: #8 Required fields: ask for confirmation (Medium)

**Why here:** it comes after 2.1, which edits the same validation block. It must also come **before** Tasks 3.1 and 5.3, which edit and then move the same workspace script.
**Touches:** `workspace.blade.php` (the submit handler near line 3919 and `missingRequired()` near line 3129), `DocumentScanController::store()`

- [ ] Show a dialog that lists the missing fields, with **Go back** and **Submit anyway** buttons.
- [ ] Send `allow_missing=1` only after **Submit anyway**.
- [ ] Add the server check in `store()` for normal templates. Without the flag, it returns an error that names the missing fields.
- [ ] When the flag is used, save `missing_required_fields` in the audit entry.
- [ ] **Test:** refused without the flag, saved with it, and the audit entry lists the missing fields.

### Task 2.3: #12 Highlight boxes on tilted pages (check first)

**Why here:** the fix also edits `store()`, so it comes after 2.2 to keep the two `store()` changes separate.
**Touches:** check only, or else `store()` near line 289, a new migration, `scan-card.blade.php`

- [ ] **Check:** scan a tilted page, use Detect, submit, then open the record. If the boxes are in the right place, tick everything below and move on.
- [ ] If they're off, choose a fix: copy the straightened page into a new column (before line 289 deletes the folder), or rotate the image with CSS.
- [ ] **Test**, then check it by eye.

### Task 2.4: #13 Protect templates that records use (Small)

**Why here:** before Tasks 3.10 and 5.2, which rewrite the same controller.
**Touches:** `DocumentTemplateController::destroy()`, `templates/index.blade.php`

- [ ] Refuse to delete a template that has records (and possibly one with unsubmitted pages).
- [ ] Hide or disable the delete button for those templates, and show why.
- [ ] Update any test that expects such a template to be deletable.
- [ ] **Test:** the delete is refused and the template still exists.

### Task 2.5: #10 Start the scheduler (Small)

**Why here:** it's independent, but must come before Task 4.4 Option B, which also changes `serve.ps1`.
**Touches:** `serve.ps1`, `.vscode/tasks.json`, `README.md`

- [ ] Add `scheduler` to the `-Only` options and start it along with the other services.
- [ ] Check that `php artisan schedule:list` shows `documents:prune-pages` every hour.
- [ ] Try `php artisan documents:prune-pages --hours=1` on test pages.
- [ ] Update the README start-up steps.

### Task 2.6: #11 Load the PDF worker from your own build (Small–Medium)

**Why here:** it's independent, but must come before Task 5.3, which also edits `vite.config.js`.
**Touches:** `field-marker.js`, `vite.config.js`

- [ ] Find out why the worker was moved to the CDN: `git log -S "pdf.worker" --oneline`.
- [ ] Import the worker with `?url` and point `workerSrc` at it.
- [ ] Run `npm run build`.
- [ ] **Test** offline: open a PDF in the workspace and in the Template Builder.

### Task 2.7: #36 Logging in the AI service (Small, moved up from Priority 5)

**Why here:** it takes about 10 minutes, and Tasks 2.8 and 2.9 add new messages that should use the logger, not `print()`.
**Touches:** `ml/api/main.py`

- [ ] Add a `logger` and replace every `print(...)`.
- [ ] Restart the AI service and check that the messages appear.

### Task 2.8: #9 A shared key for the AI service (Medium)

**Why here:** it comes after 2.7, which changes the same file. **The rollout order matters**, or scanning breaks in the middle.
**Touches:** `OcrClient::request()`, `ml/api/main.py`, tests

- [ ] **Laravel side first:** make `OcrClient` send `X-CRMS-Service-Key` on every request. The old AI service ignores an extra header, so nothing breaks yet.
- [ ] Run `php artisan queue:restart`. The worker reads crops through `OcrClient`, and an old worker would keep sending requests without the key.
- [ ] **Then the FastAPI side:** require the key on `/ocr`, `/models`, `/delete_model` and `/rename_model`.
- [ ] Restart the AI service and scan one page from start to finish.
- [ ] **Tests:**
  - Python: a request without the key gets a 401.
  - PHP: `Http::fake()` shows the header is sent. Test `OcrClient` directly, **not** through `documents.recognise`, because Task 3.1 deletes that route.

### Task 2.9: #15 The AI service looks offline after start-up (check first)

**Why here:** it's the last change to `main.py` in this phase.
**Touches:** `ml/api/main.py`

- [ ] **Check:** restart the service and open the OCR page right away. If it shows as online, tick everything below.
- [ ] If not, compute the fingerprints in a background thread inside `lifespan`, have `/health` skip them until they're ready, and log through the logger from Task 2.7.
- [ ] **Test:** the OCR page shows the service as online right after start-up.

### Phase 2 checkpoint

- [ ] All tests pass.
- [ ] By hand:
  - do a full scan → Detect → verify, leave one required field empty (you should see the confirmation), then submit
  - open the new record
  - open a PDF with the internet off
  - restart all services and check that the scheduler window runs

---

## Phase 3: Cleanup (Priority 3)

### Task 3.1: #28 Remove the unused `documents.recognise` route (Small–Medium)

**Why first here:** it deletes code that Task 3.5 (comments) and Task 5.3 (moving the JavaScript) would otherwise have to deal with.
**Touches:** `routes/web.php`, `DocumentScanController.php` (`recognise()`, `resolveModelKey()`), `workspace.blade.php:624`, `DocumentUploadWorkflowTest.php`, `OcrWorkspaceTest.php`

- [ ] Confirm nothing calls the route.
- [ ] Remove the route, the two methods and the config line.
- [ ] Move the model-choice tests to `documents.pages.store`.
- [ ] Run the full test suite.

### Task 3.2: #21 Dead Python code (Small)

**Touches:** `ml/dataset_registry.py`

- [ ] Delete the four dead functions and their helpers, plus the `shutil` and `zipfile` imports.
- [ ] Fix the docstring. That also takes care of the `dataset_registry.py` line in #24.
- [ ] Run the Python tests.

### Task 3.3: #22 One shared Python module (Medium)

**Why before 4.3:** batching (Task 4.3) rewrites the confidence function. Moving it into the shared module first means it only gets rewritten once.
**Touches:** new `ml/trocr_common.py`, `predict.py`, `test_finetuned.py`, `ml/api/main.py`

- [ ] Create the module, keeping the most careful version of each copied function.
- [ ] Import it in the three files and delete the copies.
- [ ] Run the Python tests, do one `predict.py` run, restart the AI service and do one OCR read.

### Task 3.4: #23 Repeated code in DocumentPageController (Small)

**Touches:** `DocumentPageController.php` only

- [ ] Add `lineFlags()` and `clampPolygon()`, move the stray comment, and decide what to do about the `scopeBindings` double checks.
- [ ] Run `php artisan test --filter=LineOutlinePipelineTest`.

### Task 3.5: #24 Comments that are no longer true (Small)

**Why here:** after Task 3.1, because the `DocumentScanController` comment describes the flow 3.1 removed. Before Task 5.2, which moves the template controller code.
**Touches:** `DocumentScanController.php` lines 34 and 70, `DocumentTemplateController.php:519`

- [ ] Rewrite the three comments (the docstring was already fixed in Task 3.2).

### Task 3.6: #26 One limit for person groups (Small)

**Why before 5.2:** the template rules move into Form Requests there.
**Touches:** `DocumentScanController.php:160`, `DocumentTemplateController.php:535`, `GeometryInput.php:57`

- [ ] Add one shared constant and use it in all three places.
- [ ] Run the tests.

### Task 3.7: #19 + #25 Tidy the permissions file (Small, two items together)

**Why together:** both edit `AuthServiceProvider.php`.
**Touches:** `AuthServiceProvider.php`, `RoleSlug.php`, `CapabilityMatrixTest.php`, `User.php`, `UserFactory.php`, maybe a migration, `README.md` or `docs/roles.md`

- [ ] Remove `records.submit` and its test rows.
- [ ] Decide about `email_verified_at`, and add a migration if you drop it.
- [ ] Copy the capability table into the repo and point both comments to it.
- [ ] Run the tests.

### Task 3.8: #27 Clutter in the main folder (Small)

- [ ] Move the plan and the notebook, fix the README link, and delete `test-results/`.

### Task 3.9: #18 The "Draft" status (Medium)

**Why here:** it comes after Phase 1, because it edits the same `ChangeRequestService::open()`. It must come before Task 3.10 (same model and factory) and Task 4.2 (same reports summary).
**Touches:** `RecordStatus.php`, `CivilRecord.php`, `ReportController.php`, `reports/index.blade.php`, `ChangeRequestService.php`, `ChangeRequestController.php`, `CivilRecordFactory.php`, a migration, many tests

- [ ] Decide whether to keep or remove it.
- [ ] If removing: delete the status and every place that uses it, and change the factory default to `Submitted`.
- [ ] Add a migration that sets `records.status` to default to `'submitted'`.
- [ ] Run the full test suite. Expect several tests to need updating.

### Task 3.10: #17 The document type stored in two places (Large, fine to do after the defense)

**Why last in cleanup:** it touches the most models and controllers, including `store()` (Tasks 2.1–2.3, 3.1) and the template controller (Tasks 2.4, 3.5, 3.6). It must also come before Task 5.2.
**Touches:** `DocumentType.php`, `CivilRecord.php`, `DocumentTemplate.php`, `DocumentScanController.php`, `DocumentTemplateController.php`, factories, a migration

- [ ] Find every use of `doc_type` and switch each one to `document_type_id`.
- [ ] Keep `defaultFields()` for the starter boxes.
- [ ] Add a migration that drops the `doc_type` columns **and their indexes**.
- [ ] Run the full test suite.

---

## Phase 4: Speed (Priority 4)

### Task 4.1: #31 Index on `records.created_at` (Small)

- [ ] Add the migration. Leave the optional FULLTEXT index for later.
- [ ] Run the tests.

### Task 4.2: #30 List pages load less data (Medium)

**Why here:** Tasks 1.4, 1.5 and 3.9 changed the same report code.
**Touches:** `RecordController.php`, `ReportController.php`, `ChangeRequestController.php` and their views

- [ ] Check what each list page actually shows.
- [ ] Load only that, using `withAvg` and a limited eager load.
- [ ] Measure the queries before and after.

### Task 4.3: #29 Read AI crops in batches (Medium)

**Why here:** after Task 3.3 (the shared confidence function) and Task 2.8 (`/ocr` now checks the key).
**Tip:** `test_finetuned.py` already reads images in batches in `_predict_batch()`. Copy that pattern and add `output_scores=True` so each row gets its own confidence.
**Touches:** `ml/api/main.py`, `ml/trocr_common.py`

- [ ] Time one full page first.
- [ ] Decode all images, generate in chunks of 8–16, and compute confidence per row.
- [ ] Check that the batched texts match the one-by-one texts.
- [ ] Restart the AI service and time the same page again. Keep both numbers.

### Task 4.4: #32 Slow "Test layout" (Medium)

**Why here:** Option A edits `testLayout()`, so it must come before Task 5.2. Option B edits `serve.ps1`, so it must come after Task 2.5.

- [ ] Choose Option A (a queue job) or Option B (Apache).
- [ ] Implement it.
- [ ] **Check:** while Test layout runs, another tab can still open the dashboard.

---

## Phase 5: Easier-to-read code (Priority 5)

Do these after the defense, or only if there's time left. They're ordered so the riskiest one comes last.

### Task 5.1: #35 Split line_markers.py (Large)

**Why first here:** no other task touches this file, and 60 Python tests protect it.

- [ ] Split it into a package, but keep `ml/line_markers.py` as the entry point and keep the names the tests import.
- [ ] Run the Python tests.

### Task 5.2: #34 Slim down DocumentTemplateController (Large)

**Why here:** it comes after every task that edits this controller (2.1, 2.4, 3.5, 3.6, 3.10, 4.4).

- [ ] Move validation into Form Requests and the saving and versioning into `TemplateLayoutService`.
- [ ] Run the four template test files.

### Task 5.3: #33 Move the scanning page's JavaScript out of the template (Large)

**Why last:** it's the largest change to the most-used screen, and it comes after every task that edits that script (2.1, 2.2, 3.1).

- [ ] Move the script into `resources/js/scan-workspace/` modules and pass server data through one JSON config block.
- [ ] Update `vite.config.js` and run `npm run build`.
- [ ] Add node tests for the pure functions.
- [ ] Click through a full scan → verify → submit.

---

## Phase 6: Wrap-up

### Task 6.1: #20 Old migrations

**Why last:** a schema dump has to include every migration this plan adds (Tasks 1.1, 2.3, 3.7, 3.9, 3.10 and 4.1).

- [ ] Decide whether to run `php artisan schema:dump --prune`. Only do it if every teammate will rebuild their database afterwards.
- [ ] Either way, prepare the one-sentence explanation.

### Task 6.2: Final regression

- [ ] Run `.\tools\test-all.ps1`.
- [ ] Run `npm run build`.
- [ ] Restart all services: website, queue worker, AI service and scheduler.
- [ ] Repeat the by-hand checks from the Phase 1 and Phase 2 checkpoints.

---

## Appendix: which tasks touch the same file

Never work on two tasks from the same row at the same time. Do them in the order shown.

| File | Tasks, in order |
|---|---|
| `app/Services/ChangeRequestService.php` | 1.1 → 1.2 → 3.9 |
| `app/Http/Controllers/ChangeRequestController.php` | 1.1 → 3.9 → 4.2 |
| `tests/Feature/ChangeRequestWorkflowTest.php` | 1.1 → 1.2 → 3.9 |
| `resources/views/change-requests/show.blade.php` | 1.2 → 1.5 |
| `app/Http/Controllers/RecordController.php` | 1.3 → 1.5 → 4.2 |
| `app/Http/Controllers/ReportController.php` | 1.4 → 1.5 → 3.9 → 4.2 |
| `app/Http/Controllers/DocumentScanController.php` | 2.1 → 2.2 → 2.3 → 3.1 → 3.5 → 3.6 → 3.10 |
| `resources/views/scan/workspace.blade.php` | 2.1 → 2.2 → 3.1 → 5.3 |
| `app/Http/Controllers/DocumentTemplateController.php` | 2.1 → 2.4 → 3.5 → 3.6 → 3.10 → 4.4 → 5.2 |
| `ml/api/main.py` | 2.7 → 2.8 → 2.9 → 3.3 → 4.3 |
| `app/Services/Ocr/OcrClient.php` | 2.8 |
| `app/Models/CivilRecord.php`, `database/factories/CivilRecordFactory.php` | 3.9 → 3.10 |
| `serve.ps1` | 2.5 → 4.4 |
| `vite.config.js` | 2.6 → 5.3 |
| `README.md` | 0.2 → 2.5 → 3.7 → 3.8 |
| New migrations | 1.1 → 2.3 → 3.7 → 3.9 → 3.10 → 4.1 → 6.1 |
