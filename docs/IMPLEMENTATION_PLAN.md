# CRMS Implementation Plan

This plan sets the order for working through [CODE_REVIEW_TODO.md](CODE_REVIEW_TODO.md), as you edited it, so that no task undoes or clashes with another. Each task names its item number (**#N**). The to-do file explains each problem; this file says **when** to fix it and **how to check** it's done.

It's written so you can hand any task to Claude Code with a one-line prompt (see below).

**Not in this plan:** #2, #7, #14, #38 and #39, because they were removed from the to-do list.

---

## Running this plan with Claude Code

Type one of these in Claude Code. The `@` attaches the file.

| To… | Type |
|---|---|
| Do one task | `Execute Task 1.1 from @docs/IMPLEMENTATION_PLAN.md` |
| Do the next unfinished task | `Execute the next task in @docs/IMPLEMENTATION_PLAN.md` |
| Do a whole phase | `Execute Phase 1 of @docs/IMPLEMENTATION_PLAN.md, one task at a time` |
| Do a task and commit it | `Execute Task 1.3 from @docs/IMPLEMENTATION_PLAN.md and commit it` |
| See progress | `Show the status of @docs/IMPLEMENTATION_PLAN.md` |
| Answer a question Claude asked | Reply with your choice, e.g. `A` |
| Report a check you did by hand | e.g. `Task 2.3 check: the boxes are off` |

Tips:
- **One task per prompt is safest.** Look at what changed before you ask for a commit.
- **Start a new conversation for each phase.** The plan file keeps the progress (ticked boxes and the `Decision:` and `Done:` lines), so nothing is lost.

**Markers used in the steps:**
- **[You]**: only you can do this step, e.g. a browser check, restarting a service window or scanning paper. Claude stops and asks you to report back.
- **[Decision]**: Claude asks you to choose before writing any code. The recommended option is listed first.
- **[Ask first]**: Claude needs your OK in the conversation before doing it.

---

## Rules for every task

Claude follows these exactly. They're good rules for people too.

1. **Pick the task.** "The next task" means the first task or checkpoint, in file order, that still has an unticked box.
2. **Check before starting.**
   - Run `git status` and `git branch --show-current`. Never work on `main`. After Task 0.1, work on the branch recorded there; if the current branch is different, stop and ask.
   - Every task listed under **Depends on** must be finished (all boxes ticked, or marked not needed). If one isn't, stop and say which.
   - Read the task here and its item in `CODE_REVIEW_TODO.md`. **If the two disagree, follow this plan.** It's newer and was checked against the code.
3. **Find code by name, not by line number.** Line numbers were taken on 2026-10-02 and move as tasks are done.
4. **Stay in scope.** Change only what the task says, in the files listed under **Touches**. If another file has to change, say why in the report. Don't start the next task unless the prompt asks for it.
5. **[Decision] steps:** ask with the listed options before writing any code. Then add a line under the task: `Decision: A, <short reason> (YYYY-MM-DD)`.
6. **[You] steps:** don't pretend to do them. Ask the user to do the step and report back, and leave the box unticked until they confirm.
7. **[Ask first] steps:** get the user's OK in this conversation first.
8. **Test.**
   - Add or update the tests the task names, then run the task's **Verify** commands, then all tests. Everything must pass.
   - If a test fails for a reason unrelated to the task, stop and report it instead of changing that test. Changing tests the task tells you to change is fine.
9. **After the change:**
   - If you changed anything in `resources/js/`, run `npm run build`.
   - If you changed PHP that the queue worker runs (`app/Jobs`, `app/Services/Lines`, `app/Services/Ocr`), run `php artisan queue:restart`.
   - If you added a migration, tell the user, then run `php artisan migrate`. A migration that drops a column is **[Ask first]**, and only after a **[You]** database backup.
   - If you changed anything in `ml/api/`, the user must restart the AI service window (**[You]**).
10. **Record progress.**
    - Tick each finished step here and the matching boxes in `CODE_REVIEW_TODO.md`.
    - If a step turns out not to be needed, tick it and add `(not needed: <reason>)`.
    - When the task is finished, add a line under it: `Done: YYYY-MM-DD · PHP n · JS n · Python n passed`.
11. **Commit only when the prompt asks.**
    - Make one commit per task, with a message like `fix(#N): …` (or `refactor`, `perf`, `chore`, `docs`).
    - Stage only this task's files, and never stage `storage/framework/lsp-*.php`.
    - Never commit to `main`.
12. **Report** in a few lines: the files changed, the test results, any decisions made, any **[You]** steps still waiting, and the next task.

### Test commands

Run these from the project root. MySQL (XAMPP) must be running, because the PHP tests use the `crms_test` database.

```powershell
php artisan test
npm run test:js
ml\.venv-kraken\Scripts\python.exe -m unittest tests.Python.test_line_markers tests.Python.test_grid_layouts
.venv\Scripts\python.exe -m unittest tests.Python.test_evaluation_report
```

After Task 0.2, `.\tools\test-all.ps1` runs all four. The baseline on 2026-10-02 was PHP 262, JavaScript 85 and Python 62 (60 + 2), all passing. The PHP suite and the first Python suite take about a minute each.

---

## How the order was chosen

1. **Safety net first.** #37 (one test command) comes first, so every task after it can finish with one command.
2. **One file, one task at a time.** When several tasks change the same file, the one that lays the foundation goes first. For example, #1 creates the exception class and the transactions that #5 then adds locks to. The [table at the end](#appendix-which-tasks-touch-the-same-file) lists every shared file.
3. **Delete, then tidy, then move.** Dead code is removed and small fixes are made **before** the big refactors (#33, #34, #35) move code into new files. Otherwise every edit would have to be redone in the new location.
4. **Priorities stay in order (1 → 5), with three deliberate moves:**
   - #37 moves to the very start.
   - #36 moves into the AI-service tasks of Phase 2, so the new code there uses the logger from day one.
   - #20 moves to the very end, after every new migration.

---

## Phase 0: Prepare

### Task 0.1: Branch and baseline

**Depends on:** nothing
**Touches:** Git only

- [ ] **[Decision]** What should the branch for this work be called? A) `review-fixes`, created from `fieldmarker-experimental-v2` (recommended) B) another name. Record it here as `Branch: …`. Always type it in full, because `fieldmarker-experimental-2` and `fieldmarker-experimental-v2` are easy to mix up.
- [ ] Create the branch from `fieldmarker-experimental-v2` with `git switch -c <name>`. Apart from `docs/`, there must be no uncommitted changes.
- [ ] **[Ask first]** Commit the `docs/` folder on the new branch.
- [ ] Run the four test commands. If the numbers differ from the baseline, record the new numbers here and tell the user.

**Done when:** you're on the new branch and the baseline is confirmed.

### Task 0.2: #37 One command to run all tests (Small)

**Depends on:** 0.1
**Why first:** every later task ends with "run all tests".
**Touches:** new `tools/test-all.ps1`, `README.md`

- [ ] Create `tools/test-all.ps1`. It should run the four test commands in order from the project root, check `$LASTEXITCODE` after each one, and stop with a clear message and exit code 1 at the first failure. Keep it compatible with Windows PowerShell 5.1, so don't use `&&`.
- [ ] Add a short "Running the tests" note to the README.
- [ ] Run `.\tools\test-all.ps1`.

**Done when:** one command runs all four suites and reports 409 passing tests.

---

## Phase 1: Priority 1 bug fixes

### Task 1.1: #1 A long rejection note breaks the audit log (Small)

**Depends on:** nothing
**Why first:** it creates `ChangeRequestException` and the transactions that Task 1.2 builds on.
**Touches:** `app/Services/ChangeRequestService.php`, `app/Http/Controllers/ChangeRequestController.php`, new `app/Exceptions/ChangeRequestException.php`, a new migration, `tests/Feature/ChangeRequestWorkflowTest.php`

- [ ] New migration: change `audit_logs.description` to `text`, keeping `->nullable()`. In Laravel 12, `->change()` must repeat every modifier.
- [ ] Create `App\Exceptions\ChangeRequestException` (extending `RuntimeException`) and throw it instead of `RuntimeException` everywhere in `ChangeRequestService`.
- [ ] In `ChangeRequestController`, catch `ChangeRequestException` instead of `RuntimeException` in `store`, `approve`, `reject` and `withdraw`.
- [ ] In `reject()`, set the description to `"Rejected change request #N."` and pass the note as `new: ['decision_note' => $note]`. The Audit Log page already displays `new_values`, so the note stays visible.
- [ ] Wrap `reject()` and `withdraw()` in `DB::transaction(...)`.
- [ ] Test: rejecting with a 400-character note sets the status to `rejected` and writes exactly one `change_request.rejected` audit row that holds the note.
- [ ] Run `php artisan migrate`.

**Verify:** `php artisan test --filter=ChangeRequestWorkflowTest`, then all tests.
**Done when:** the new test passes and `ChangeRequestController` no longer catches `RuntimeException`.

### Task 1.2: #5 Double-click conflicts (Medium)

**Depends on:** 1.1
**Why here:** it adds locks inside the transactions from Task 1.1, in the same four methods.
**Touches:** `app/Services/ChangeRequestService.php`, `resources/views/change-requests/show.blade.php`, `resources/views/change-requests/create.blade.php`, `resources/js/change-request.js`, `tests/Feature/ChangeRequestWorkflowTest.php`

- [ ] In `approve()`, `reject()` and `withdraw()`:
  - inside the transaction, reload the request with `ChangeRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail()`
  - run `guardOpen()` on that fresh copy
  - use the fresh copy for the rest of the method, and return it
- [ ] In `open()`, move the "already has a pending request" check inside the transaction, right after locking the record row with `CivilRecord::whereKey($record->getKey())->lockForUpdate()->first()`.
- [ ] Disable the submit, Approve and Reject buttons on the first submit. If the code goes in `resources/js/change-request.js`, run `npm run build`.
- [ ] Test: load a pending request, change its status to `rejected` directly in the database, then call `approve()` with the old copy. It must be refused with the "already rejected" message, and the status must stay `rejected`.

**Verify:** `php artisan test --filter=ChangeRequestWorkflowTest`, then all tests.
**Done when:** every decision and every new request re-checks the state under a row lock.

### Task 1.3: #3 A bad date crashes the Records page (Small)

**Depends on:** nothing
**Touches:** `app/Http/Controllers/RecordController.php`, `resources/views/records/index.blade.php`, new `tests/Feature/RecordArchiveFilterTest.php`

- [ ] In `index()`, validate the filters, following the style of `ReportController::filters()`:
  - `q`: nullable string, max 255
  - `type`: nullable, `exists:document_types,key`
  - `status`: nullable, one of the `RecordStatus` values
  - `from`: nullable date
  - `to`: nullable date, `after_or_equal:from`
- [ ] Use the validated values in the query, instead of `$request->date(...)`.
- [ ] The Records page doesn't show validation errors yet (checked 2026-10-02), so add `@error` messages under the filter inputs.
- [ ] Tests:
  - `/records?from=abc` gives a validation error for `from`, not a 500
  - a valid date range still filters correctly

**Verify:** `php artisan test --filter=RecordArchiveFilterTest`, then all tests.
**Done when:** a bad filter shows a message instead of an error page.

### Task 1.4: #4 CSV formula injection (Small)

**Depends on:** nothing
**Why before 1.5:** Task 1.5 also edits `row()`.
**Touches:** `app/Http/Controllers/ReportController.php`, `tests/Feature/ReportExportTest.php`

- [ ] Add `private function safeCell(mixed $value): mixed`. It returns non-strings unchanged, and puts `'` in front of strings that start with `=`, `+`, `-`, `@`, a tab or a carriage return.
- [ ] Run every text cell in `row()` through it.
- [ ] Test: a record with registry number `=HYPERLINK("x","y")` and a field value of `=1+1` exports both with a leading `'`, while the number columns are unchanged.

**Verify:** `php artisan test --filter=ReportExportTest`, then all tests.
**Done when:** no exported text cell can start with a formula character.

### Task 1.5: #6 Times shown 8 hours behind (Medium)

**Depends on:** 1.2, 1.3, 1.4
**Why last in this phase:** it touches the most files, including the ones Tasks 1.2–1.4 just changed.
**Touches:** new `app/Support/LocalTime.php`, the views found by `grep -rn -- "->format(" resources/views`, `app/Http/Controllers/RecordController.php`, `app/Http/Controllers/AuditLogController.php`, `app/Http/Controllers/ReportController.php`, tests

- [ ] Keep **storing** UTC. Don't change `config/app.php`.
- [ ] Create `App\Support\LocalTime` with three methods:
  - `format(?CarbonInterface $date, string $format): string` converts to `config('crms.reporting_timezone')` and returns `''` for null
  - `dayStart(string $date)` returns the UTC moment when that Philippine day begins
  - `dayEnd(string $date)` returns the UTC moment when that Philippine day ends
- [ ] Replace every timestamp `->format(...)` in `resources/views` with `LocalTime::format(...)`. Leave `diffForHumans()` alone.
- [ ] Convert the times in the CSV export (`row()`) with the same helper.
- [ ] Use `dayStart()` and `dayEnd()` for the date filters in `RecordController`, `AuditLogController` and `ReportController`, replacing the inline `Carbon::parse(...)` calls.
- [ ] Tests:
  - a `2026-10-01 23:30 UTC` timestamp shows as `07:30` on the Audit Log
  - `from=2026-10-02` finds that record on the Records page and in Reports
  - the CSV shows Philippine time

**Verify:** `php artisan test --filter="AuditLogViewerTest|ReportExportTest|RecordArchiveFilterTest"`, then all tests.
**Done when:** no view formats a timestamp without the helper, and the tests pass.

### Phase 1 checkpoint

- [ ] All tests pass.
- [ ] **[You]** In the browser:
  - Reject a change request with a long note. There should be no error, and the Audit Log should show the note.
  - Open `/records?from=abc`. You should see a friendly message.
  - Export a CSV and open it in Excel. Values starting with `=` should show as plain text.
  - Check that times on the Audit Log are Philippine time.

---

## Phase 2: Priority 2

### Task 2.1: #16 TIFF scans (Small)

**Depends on:** nothing
**Why first here:** it's a one-line rule change in `store()`, which Tasks 2.2 and 2.3 edit next.
**Touches:**
- `app/Http/Controllers/DocumentScanController.php` (the `scan` rule in `store()`)
- `resources/views/scan/workspace.blade.php` (the file input's `accept`)
- `resources/views/templates/edit.blade.php` (the sample input's `accept`)
- `app/Http/Controllers/DocumentTemplateController.php` (the `sample_document` rule)
- `tests/Feature/DocumentUploadWorkflowTest.php`, `tests/Feature/DocumentTemplateBuilderTest.php`

- [ ] **[Decision]** A) Remove TIFF support (recommended: simplest, and browsers can't display TIFF anyway). B) Keep TIFF: convert it to PNG in the browser with a TIFF library, and add `tif` to the submit rule.
- [ ] For A:
  - remove `image/tiff` from both `accept` lists
  - remove `tif` and `tiff` from both rules
  - make any on-screen text that lists formats say "PDF, PNG, JPG, WEBP or BMP"
- [ ] Tests: a TIFF upload is refused with a clear message, both at submit and as a template sample. `UploadedFile::fake()->create('scan.tiff', 10, 'image/tiff')` is enough for this.

**Verify:** `php artisan test --filter="DocumentUploadWorkflowTest|DocumentTemplateBuilderTest"`, then all tests.
**Done when:** TIFF is either refused clearly or works end to end.

### Task 2.2: #8 Required fields: ask for confirmation (Medium)

**Depends on:** 2.1
**Why here:** 2.1 edits the same validation block, so 2.1 goes first. This task must also come **before** Tasks 3.1 and 5.3, which edit and then move the same workspace script.
**Touches:** `resources/views/scan/workspace.blade.php` (the submit handler and `missingRequired()`), `app/Http/Controllers/DocumentScanController.php` (`store()`), `tests/Feature/DocumentUploadWorkflowTest.php`

The decision is already made: ask for confirmation with a **Submit anyway** button.

- [ ] In the browser, before sending, build one list without duplicates of what won't be submitted:
  - required template fields (not ledger columns) whose names aren't among the checked fields
  - plus whatever `missingRequired()` returns for each person or row group
- [ ] If the list isn't empty, show a Bootstrap modal that lists the fields, with **Go back** and **Submit anyway** buttons. Only **Submit anyway** continues, and it adds `allow_missing=1` to the form data.
- [ ] On the server, in `store()`, for templates that aren't ledgers:
  - find the required template fields missing from the submitted names (compare trimmed and case-insensitive)
  - without `allow_missing`, throw a validation error under the key `missing_required` that lists them
  - validate `allow_missing` as `sometimes|boolean`

  Ledger rows are only checked in the browser.
- [ ] When missing fields are accepted, add `missing_required_fields` (the list) to the new values of the `record.submitted` audit entry.
- [ ] In the browser, when the server answers 422 with `missing_required`, show the same modal.
- [ ] Tests:
  - a missing required field gives a 422 `missing_required` without the flag
  - the same submission is saved with `allow_missing=1`
  - the audit entry lists the missing names
  - a complete submission is unaffected
- [ ] **[You]** In the browser:
  1. Leave a required field unchecked and submit. The modal should appear.
  2. Click **Go back**. Nothing should be sent.
  3. Submit again and click **Submit anyway**. The record should be saved.

**Verify:** `php artisan test --filter=DocumentUploadWorkflowTest`, then all tests.
**Done when:** missing required fields always need a confirmation, and an accepted omission is recorded in the audit log.

### Task 2.3: #12 Highlight boxes on tilted pages (check first)

**Depends on:** 2.2
**Why here:** the fix also edits `store()`, so it comes after 2.2 to keep the two `store()` changes apart.
**Touches (only if a fix is needed):** `app/Http/Controllers/DocumentScanController.php`, a new migration, `app/Http/Controllers/RecordController.php`, `routes/web.php`, `resources/views/records/partials/scan-card.blade.php`, `tests/Feature/RecordDetailPresentationTest.php`

- [ ] **[You]** Scan a visibly tilted page, press Detect, submit, and open the record. Tell Claude whether the highlight boxes sit on the right words.
- [ ] If they do, tick the remaining steps as `(not needed: boxes line up)`.
- [ ] **[Decision]** If they're off, pick a fix:
  - A) Keep the straightened page with the record (recommended: an exact match). In `store()`, copy `pages/{id}/page.png` to `records/{id}/page.png` **before** the page folder is deleted. Save the path in a new nullable `records.page_image_path` column. Show that image in `scan-card.blade.php`, served by a new route with the same access rule as `records.scan`.
  - B) Rotate the displayed scan by `scan_rotation` with CSS.
- [ ] Make the chosen fix. For A, run `php artisan migrate`.
- [ ] Test: for A, a submitted record keeps its page image and the record page uses it. For B, the record page renders the rotation.
- [ ] **[You]** Check the tilted page again by eye.

**Verify:** `php artisan test --filter=RecordDetailPresentationTest`, then all tests.
**Done when:** the boxes line up on a tilted page.

### Task 2.4: #13 Protect templates that records use (Small)

**Depends on:** nothing
**Why here:** before Tasks 3.10 and 5.2, which rewrite the same controller.
**Touches:** `app/Http/Controllers/DocumentTemplateController.php` (`destroy()`), `resources/views/templates/index.blade.php`, `tests/Feature/DocumentTemplateBuilderTest.php`

- [ ] In `destroy()`, refuse when `$template->records()->exists()`, and redirect back with an error such as "This layout was used by N records and can't be deleted." Pages still in progress don't block the delete; they're pruned within 24 hours once Task 2.5 is done.
- [ ] In the template list, disable "Delete layout" for layouts that have records, and say why. The list already loads `records_count`.
- [ ] Rewrite `test_deleting_a_used_template_keeps_its_existing_records`, which currently expects the delete to succeed, so that it expects the refusal. Keep a test showing an unused template can still be deleted.

**Verify:** `php artisan test --filter=DocumentTemplateBuilderTest`, then all tests.
**Done when:** a layout with records can't be deleted, and the screen says why.

### Task 2.5: #10 Start the scheduler (Small)

**Depends on:** nothing
**Why here:** it's independent, but it must come before Task 4.4 Option B, which also changes `serve.ps1`.
**Touches:** `serve.ps1`, `.vscode/tasks.json` (if it exists), `README.md`

- [ ] Add `'scheduler'` to the `-Only` `ValidateSet`, with a branch that runs `php artisan schedule:work` in a loop (like the worker), and a check so it isn't started twice.
- [ ] Start it along with the other services (`Start-ServiceWindow 'scheduler'`), including with `-NoOcr`.
- [ ] If `.vscode/tasks.json` exists, add a scheduler task like the others.
- [ ] Mention the fourth service in the README's start-up steps.
- [ ] **[You]** Restart `serve.ps1` and confirm the scheduler is running.

**Verify:** `php artisan schedule:list` shows `documents:prune-pages` every hour, and `powershell -NoProfile -File serve.ps1 -Check` still passes.
**Done when:** `serve.ps1` starts four services.

### Task 2.6: #11 Serve the PDF worker locally (Small–Medium)

**Depends on:** nothing
**Why here:** it's independent, but it must come before Task 5.3, which also edits `vite.config.js`.
**Touches:** `resources/js/field-marker.js`, `package.json`, new `tools/copy-pdf-worker.mjs`, `.gitignore`, `vite.config.js` (the NOTE only)

**Important:** `tests/JavaScript/field-marker.test.js` imports `field-marker.js` directly in Node. So `field-marker.js` must **not** use Vite-only imports such as `?url`, or `npm run test:js` breaks. This replaces the `?url` suggestion in the to-do list.

- [ ] Add `tools/copy-pdf-worker.mjs`, which copies `node_modules/pdfjs-dist/build/pdf.worker.min.mjs` to `public/vendor/pdfjs/pdf.worker.min.mjs`. Run it from `prebuild` and `predev` scripts in `package.json`, so it always matches the installed version.
- [ ] Add `/public/vendor/pdfjs` to `.gitignore`.
- [ ] In `field-marker.js`, set `workerSrc` to `/vendor/pdfjs/pdf.worker.min.mjs` instead of the CDN URL, and update the comment above it. The old comment's reason (not bundling the 2.2 MB worker) still holds, because a copied file isn't bundled.
- [ ] Update the NOTE in `vite.config.js` to match.
- [ ] Run `npm run build`, then check the worker file exists in `public/vendor/pdfjs/`.
- [ ] **[You]** With the internet off, open a PDF in the scanning workspace and in the Template Builder.

**Verify:** `npm run build`, then `npm run test:js`.
**Done when:** PDFs open without internet, and the JavaScript tests still pass.

### Task 2.7: #36 Logging in the AI service (Small, moved up from Priority 5)

**Depends on:** nothing
**Why here:** it takes about 10 minutes, and Tasks 2.8 and 2.9 add new messages that should use the logger, not `print()`.
**Touches:** `ml/api/main.py`

- [ ] Create `logger = logging.getLogger("ocr-api")` and set it up:
  - give it its own `StreamHandler` with the format `[ocr-api] %(message)s`
  - set its level to `INFO`
  - set `logger.propagate = False`

  Uvicorn only configures its own loggers, so without a handler `INFO` messages would silently disappear. Don't call `logging.basicConfig()`, because it would make other libraries noisy (see `ml/hf_quiet.py`).
- [ ] Replace each `print(...)` (there are 7) with `logger.info(...)`, or `logger.warning(...)` for problems.
- [ ] **[You]** Restart the AI service window and check that the start-up messages still appear.

**Verify:** `.venv\Scripts\python.exe -m unittest tests.Python.test_evaluation_report`, then all tests.
**Done when:** there's no `print(` left in `ml/api/main.py`, and the messages still show.

### Task 2.8: #9 A shared key for the AI service (Medium)

**Depends on:** 2.7
**Why here:** it comes after 2.7, which changes the same file. **Follow the steps in order**, or scanning breaks partway through.
**Touches:** `app/Services/Ocr/OcrClient.php`, `app/Providers/AppServiceProvider.php`, `ml/api/main.py`, `tests/Feature/OcrWorkspaceTest.php` (or a new `tests/Feature/OcrClientTest.php`), new `tests/Python/test_service_key.py`, `tools/test-all.ps1`

- [ ] **Laravel first:**
  - make `OcrClient::request()` send `X-CRMS-Service-Key: <config('services.ocr.upload_secret')>` on every call
  - pass the key in through the constructor in `AppServiceProvider`

  The current AI service ignores an extra header, so nothing breaks yet.
- [ ] PHP test with `Http::fake()`: every request from `OcrClient` carries the header. Call `OcrClient` directly, **not** through `documents.recognise`, because Task 3.1 deletes that route.
- [ ] Run `php artisan queue:restart`. The worker reads crops through `OcrClient`, so an old worker would keep sending requests without the key.
- [ ] **Then FastAPI:**
  - add a dependency that compares the header with `_upload_secret()` using `hmac.compare_digest`, and returns 401 when the key is missing or wrong
  - apply it to `/ocr`, `/models`, `/delete_model` and `/rename_model`
  - leave `/health` open, and leave `/add_model` on its signed ticket
- [ ] Python test `tests/Python/test_service_key.py`, using FastAPI's `TestClient` (`httpx` is installed in `.venv`):
  - `/delete_model` without the key returns 401
  - with the right key (from `main._upload_secret()`), the request gets past the check (e.g. a 404 for an unknown model, not 401)

  Add this test file to `tools/test-all.ps1`.
- [ ] **[You]** Restart the AI service, then scan one page from start to finish.

**Verify:** `php artisan test --filter=Ocr`, then `.venv\Scripts\python.exe -m unittest tests.Python.test_service_key tests.Python.test_evaluation_report`, then all tests.
**Done when:** the AI service refuses requests without the key, and scanning still works.

### Task 2.9: #15 The AI service looks offline after start-up (check first)

**Depends on:** 2.7, 2.8
**Why here:** it's the last change to `main.py` in this phase.
**Touches (only if needed):** `ml/api/main.py`, a new Python test

- [ ] Check: list `ml/models/*/evaluation-report.json`. On 2026-10-02 there were none, so a slow first health check can't happen yet. If there are still none, tick the remaining steps as `(not needed: no model has an evaluation report)`.
- [ ] If a report exists: **[You]** restart the AI service and open the OCR page right away. Tell Claude whether it shows "unreachable".
- [ ] If it does:
  - compute the fingerprints in a background thread started in `lifespan`, logging through the logger from Task 2.7
  - make `/health` leave out a model's evaluation until its fingerprint is ready
- [ ] Test: `/health` answers quickly while the fingerprints are still being computed. Patch `_sha256_file` to make it slow.

**Verify:** the Python tests.
**Done when:** the service shows as online right after start-up, or the check proves this can't happen yet.

### Phase 2 checkpoint

- [ ] All tests pass.
- [ ] **[You]** In the browser:
  - Do a full scan → Detect → verify. Leave a required field unchecked; you should see the confirmation. Then submit.
  - Open the new record.
  - With the internet off, open a PDF.
  - Restart `serve.ps1` and check that four services start: website, worker, AI service and scheduler.

---

## Phase 3: Cleanup (Priority 3)

### Task 3.1: #28 Remove the unused `documents.recognise` route (Small–Medium)

**Depends on:** 2.2
**Why first here:** it deletes code that Task 3.5 (comments) and Task 5.3 (moving the JavaScript) would otherwise have to deal with.
**Touches:** `routes/web.php`, `app/Http/Controllers/DocumentScanController.php`, `resources/views/scan/workspace.blade.php`, `tests/Feature/OcrWorkspaceTest.php`, `tests/Feature/DocumentUploadWorkflowTest.php`

- [ ] Confirm nothing calls it: search `resources/` and `app/` for `recogniseUrl`, `documents.recognise` and `documents/recognise`.
- [ ] Remove the route, `recognise()`, `resolveModelKey()` and the `recogniseUrl` config line. Keep `selectableModels()`, because the workspace still uses it.
- [ ] Move these tests to `documents.pages.store`, where the chosen model ends up in the page's `ocr_model_key`. Use `Queue::fake()` and the stub `LineMarkers`, the same way `LineOutlinePipelineTest` does, so Kraken doesn't run:
  - `test_staff_cannot_choose_a_model_unless_the_setting_allows_it`
  - `test_staff_may_pick_an_installed_model_when_the_setting_allows_it`
  - `test_an_unknown_model_key_falls_back_to_the_selected_model`
- [ ] Replace `test_ocr_request_rejects_more_than_four_hundred_fifty_fields` with the same check on `documents.store` (submitting a record), which has the same 450-field limit.

**Verify:** all tests.
**Done when:** `php artisan route:list` no longer shows `documents.recognise`, and the model-choice rules are still tested.

### Task 3.2: #21 Dead Python code (Small)

**Depends on:** nothing
**Touches:** `ml/dataset_registry.py`

- [ ] Confirm nothing uses them: search `ml/` and `tests/Python/` for `list_datasets`, `create_from_zip`, `create_from_directory` and `delete_dataset`.
- [ ] Delete those four functions, plus:
  - the helpers only they use: `_safe_extract`, `_find_manifest_root`, `_remove_install_artifact`, `_installation_paths`, `_commit_install` and `_assert_regular_directory_tree`
  - the `shutil` and `zipfile` imports
  - any constants only they used
- [ ] Fix the module docstring, which still mentions a `/datasets` API. This also covers the `dataset_registry.py` line of #24.

**Verify:** the Python tests, plus `--help` runs of `ml\train_trocr.py`, `ml\predict.py` and `ml\test_finetuned.py` with `.venv\Scripts\python.exe`. All three import this module.
**Done when:** the module only contains what training and evaluation still use.

### Task 3.3: #22 One shared Python module (Medium)

**Depends on:** 2.9
**Why before 4.3:** batching (Task 4.3) rewrites the confidence function. Moving it into the shared module first means it only gets rewritten once.
**Touches:** new `ml/trocr_common.py`, `ml/predict.py`, `ml/test_finetuned.py`, `ml/api/main.py`

- [ ] Compare the copies and list any differences in the report:
  - in `predict.py`: `resolve_model`, `_load`, `eos_token_id` and `sequence_confidence`
  - in `test_finetuned.py`: `resolve_model` and `_load`
  - in `main.py`: `_sequence_confidence`, and the EOS lookup in `_load_model()`
- [ ] Create `ml/trocr_common.py` with one version of each function. Import `hf_quiet` before `torch`, like the other scripts do.
- [ ] Import it in the three files and delete the copies. `main.py` already puts `ml/` on `sys.path`, so `import trocr_common` works there.
- [ ] **[You]** Restart the AI service and read one page through the app.

**Verify:** the Python tests, plus `--help` runs of `predict.py` and `test_finetuned.py`.
**Done when:** each function exists only once.

### Task 3.4: #23 Repeated code in DocumentPageController (Small)

**Depends on:** nothing
**Touches:** `app/Http/Controllers/DocumentPageController.php`

- [ ] Add `private function lineFlags(DocumentPage $page)` and use it in place of the four copies of `$page->lines()->get()->mapWithKeys(...)`.
- [ ] Add `private function clampPolygon(array $points, DocumentPage $page): array` and use it in `updateLine()` and `storeLine()`.
- [ ] Move the stray "Scan with OCR after Detect…" docblock, which sits above `snap()`, to `read()`.
- [ ] Keep the `abort_unless(... document_page_id ...)` checks, and add a one-line comment saying they double-check `scopeBindings()`.

**Verify:** `php artisan test --filter=LineOutlinePipelineTest`, then all tests.
**Done when:** each repeated block exists once, and behaviour is unchanged.

### Task 3.5: #24 Comments that are no longer true (Small)

**Depends on:** 3.1, 3.2
**Why here:** after Task 3.1, because the `DocumentScanController` comment describes the flow that 3.1 removed. Before Task 5.2, which moves the template controller code.
**Touches:** `app/Http/Controllers/DocumentScanController.php`, `app/Http/Controllers/DocumentTemplateController.php`

- [ ] Rewrite the class docblock in `DocumentScanController` ("Cropping happens in the browser…") to describe how it works now: Align uploads the page, the queue job outlines, crops and reads it, Verify reviews it, and submitting copies the crops to the record.
- [ ] Rewrite the `workspace()` docblock: the page is uploaded at Align and pruned after `LINE_MARKERS_KEEP_HOURS` if it's never submitted.
- [ ] In `DocumentTemplateController::validatePayload()`, change "stored in 255 characters" to 500.
- [ ] Fix other outdated comments in these two files, or list them in the report.

**Verify:** all tests.
**Done when:** the comments describe the current behaviour.

### Task 3.6: #26 One limit for person groups (Small)

**Depends on:** 2.1, 2.2
**Why before 5.2:** the template rules move into Form Requests there.
**Touches:** new `app/Support/Limits.php`, `app/Http/Controllers/DocumentScanController.php`, `app/Http/Controllers/DocumentTemplateController.php`, `app/Services/Lines/GeometryInput.php`, tests

- [ ] Check the existing data first, read-only: the highest `person_group` in `document_template_fields` and in `record_fields` must be 450 or less. Report the numbers.
- [ ] Add `App\Support\Limits` with `MAX_FIELDS = 450`.
- [ ] In all three places, use it for the field-count limits, for `person_group` (`max:` MAX_FIELDS) and for `person_field_order` (`max:` MAX_FIELDS − 1). This is safe because templates renumber person groups from 1, so a group number can never exceed the number of fields.
- [ ] Test: a person group of 451 is refused when saving a template and when submitting a record.

**Verify:** `php artisan test --filter="DocumentTemplateBuilderTest|DocumentUploadWorkflowTest|LineOutlinePipelineTest"`, then all tests.
**Done when:** the limit is defined once and used in all three places.

### Task 3.7: #19 + #25 Tidy the permissions file (Small, two items together)

**Depends on:** nothing
**Why together:** both edit `AuthServiceProvider.php`.
**Touches:**
- `app/Providers/AuthServiceProvider.php`, `app/Enums/RoleSlug.php`, `tests/Feature/CapabilityMatrixTest.php`
- new `docs/roles.md`
- if dropping the column: `app/Models/User.php`, `database/factories/UserFactory.php`, a new migration

- [ ] Remove the `records.submit` gate and its rows in `CapabilityMatrixTest`.
- [ ] **[Decision]** What should happen to `users.email_verified_at`?
  - A) Drop it (recommended: nothing uses it). That means a new migration, removing the cast in `User.php`, and removing it from `UserFactory`, including the `unverified()` state.
  - B) Keep it.
- [ ] For A: **[You]** back up the database, then **[Ask first]** run `php artisan migrate`.
- [ ] Write `docs/roles.md` with the capability table, built from the gates in `AuthServiceProvider` and the role descriptions in `RoleSlug`. `.kiro/steering/product.md` doesn't exist on this machine either (checked 2026-10-02), so it can't be copied.
- [ ] Point the comments in `AuthServiceProvider.php` and `RoleSlug.php` to `docs/roles.md`.

**Verify:** `php artisan test --filter="CapabilityMatrixTest|UserManagementTest|AuthenticationTest"`, then all tests.
**Done when:** no unused gate is left, and the comments point to a file that exists.

### Task 3.8: #27 Clutter in the main folder (Small)

**Depends on:** nothing
**Touches:** `CIVIC_PALETTE_EXECUTION_PLAN.md`, `trocr-finetuning-code.ipynb`, `README.md`, `test-results/`, `.gitignore`

- [ ] **[Decision]** What should happen to `CIVIC_PALETTE_EXECUTION_PLAN.md`? A) Move it to `docs/` with `git mv` (recommended). B) Delete it.
- [ ] Run `git mv trocr-finetuning-code.ipynb ml/notebooks/`, and update any README link to it.
- [ ] Delete the empty `test-results/` folder (it isn't tracked). If a tool keeps recreating it, add it to `.gitignore`.

**Verify:** `git status` shows the moves, and the README links work.
**Done when:** only project files are left in the root folder.

### Task 3.9: #18 The "Draft" status (Medium)

**Depends on:** 1.1, 1.2, 1.4, 1.5
**Why here:** it edits the same `ChangeRequestService::open()` as Phase 1. It must come before Task 3.10 (same model and factory) and Task 4.2 (same reports summary).
**Touches:**
- `app/Enums/RecordStatus.php`, `app/Models/CivilRecord.php`
- `app/Http/Controllers/ReportController.php`, `resources/views/reports/index.blade.php`
- `app/Services/ChangeRequestService.php`, `app/Http/Controllers/ChangeRequestController.php`
- `database/factories/CivilRecordFactory.php`, a new migration, tests

- [ ] **[Decision]** A) Remove Draft (recommended: no code path creates drafts). B) Keep it as a planned feature, and tick the remaining steps as `(not needed: kept by decision)`.
- [ ] For A: first check that no record in the database has status `draft`. If any do, stop and report, because removing the enum case would break loading them.
- [ ] For A, remove:
  - `RecordStatus::Draft` and `CivilRecord::isDraft()`
  - the "Drafts" summary, in both the controller and the view
  - the "still a draft" check in `ChangeRequestService::open()`
  - the "not locked" redirect in `ChangeRequestController::create()`
- [ ] Change the factory's default status to `Submitted`, and update the tests that create drafts on purpose.
- [ ] New migration: set the default of `records.status` to `'submitted'`. Then run `php artisan migrate`.

**Verify:** all tests. Expect several tests to need updating.
**Done when:** Draft is either gone everywhere or deliberately kept.

### Task 3.10: #17 The document type stored in two places (Large)

**Depends on:** 2.3, 2.4, 3.1, 3.5, 3.6, 3.9
**Why last in cleanup:** it touches the most models and controllers, including `store()` (Tasks 2.1–2.3 and 3.1) and the template controller (Tasks 2.4, 3.5 and 3.6). It must also come before Task 5.2.
**Touches:**
- `app/Enums/DocumentType.php`
- `app/Models/CivilRecord.php`, `app/Models/DocumentTemplate.php`, `app/Models/DocumentTypeDefinition.php`
- `app/Http/Controllers/DocumentScanController.php`, `app/Http/Controllers/DocumentTemplateController.php`
- `app/Services/TemplateSampleStorage.php`
- factories and seeders, a new migration, tests

- [ ] **[Decision]** A) Do it after the defense (recommended: it's the biggest cleanup and nothing visible changes). If so, tick the remaining steps as `(not needed: postponed)`. B) Do it now.
- [ ] Search for `doc_type` in `app/`, `database/`, `resources/views` and `tests/`, and switch every read to `document_type_id` / `documentTypeDefinition`.
- [ ] Keep `DocumentType::defaultFields()` for the starter boxes, or move them to a seeder or config file. Remove the label and icon fallbacks that the table already covers.
- [ ] Write a new migration that drops `doc_type` from `records` and `document_templates`, together with the indexes `records(doc_type, status)` and `document_templates(doc_type, is_active)`.
- [ ] **[You]** Back up the database, then **[Ask first]** run the migration.

**Verify:** all tests.
**Done when:** `doc_type` no longer appears in the code or the database.

### Phase 3 checkpoint

- [ ] All tests pass.
- [ ] **[You]** Click through these: scan and submit a record, request and approve a change, then open Reports and the Template Builder.

---

## Phase 4: Speed (Priority 4)

### Task 4.1: #31 Index on `records.created_at` (Small)

**Depends on:** nothing
**Touches:** a new migration

- [ ] Write a new migration that adds an index on `records.created_at`, then run `php artisan migrate`.
- [ ] Leave the optional FULLTEXT index for later, and mention it in the report.

**Verify:** all tests.
**Done when:** the index exists.

### Task 4.2: #30 List pages load less data (Medium)

**Depends on:** 1.5, 3.9
**Why here:** Tasks 1.4, 1.5 and 3.9 changed the same report code.
**Touches:** `app/Http/Controllers/RecordController.php`, `app/Http/Controllers/ReportController.php`, `app/Http/Controllers/ChangeRequestController.php` and their list views

- [ ] Measure first. Count the queries and rows for `/records`, `/reports` and `/change-requests` with a large ledger record, using `DB::enableQueryLog()` in a throwaway test. Write the numbers here.
- [ ] Records list: load only what `title()` needs. Laravel 12 supports `->limit()` inside `with()`.
- [ ] Reports: use `withAvg('fields', 'ocr_confidence')` and `withCount('fields')` instead of loading every field, and update `row()` and the view to match. The CSV columns must stay identical.
- [ ] Change requests: leave this list alone unless the measurement shows it's slow.
- [ ] Measure again, and write the before and after numbers here.

**Verify:** `php artisan test --filter="ReportExportTest|RecordDetailPresentationTest|ChangeRequestPresentationTest|RecordArchiveFilterTest"`, then all tests.
**Done when:** the list pages load far fewer rows, and every output is unchanged.

### Task 4.3: #29 Read AI crops in batches (Medium)

**Depends on:** 2.8, 3.3
**Why here:** after Task 3.3 (the shared confidence function) and Task 2.8 (`/ocr` now checks the key).
**Touches:** `ml/api/main.py`, `ml/trocr_common.py`, a new Python test

- [ ] Measure first. Time one `/ocr` call with 40 real crops from `storage/app/private/records/*/crops/`, using a throwaway script (don't commit it) that sends the `X-CRMS-Service-Key` header. Write the time here.
- [ ] Rewrite the `/ocr` loop:
  - decode every image first; a broken image becomes an error row
  - generate in chunks of 8–16, with `output_scores=True` and `return_dict_in_generate=True`

  The `_predict_batch()` function in `ml/test_finetuned.py` shows the batching pattern.
- [ ] Make the confidence function in `trocr_common.py` work per row by passing the row index. Keep `predict.py` working by passing row 0.
- [ ] Python test: a batch containing one broken image returns an error row for it, and results for the others, in order.
- [ ] Check that the batched texts match the one-by-one texts for the same 40 crops, and report any differences.
- [ ] **[You]** Restart the AI service. Claude then times the same call again and writes the number here.

**Verify:** the Python tests and the timing script.
**Done when:** the page reads faster, and the results are unchanged.

### Task 4.4: #32 Slow "Test layout" (Medium)

**Depends on:** 2.5 (only for Option B)
**Why here:** Option A edits `testLayout()`, so it must come before Task 5.2. Option B edits `serve.ps1`, so it must come after Task 2.5.
**Touches:**
- **Option A:** `app/Http/Controllers/DocumentTemplateController.php`, a new job, `routes/web.php`, `resources/js/template-builder.js`, `app/Console/Commands/PruneDocumentPages.php`, `tests/Feature/TemplateBuilderGridChecksTest.php`
- **Option B:** `serve.ps1`, `README.md`, and Apache's configuration, which lives outside the repo

- [ ] **[Decision]** How should Test layout stop blocking the app?
  - A) Run Test layout in the queue, like Detect (recommended: it stays inside the project and can be tested).
  - B) Serve the app with XAMPP's Apache. This changes your setup.
- [ ] For A:
  1. Store the sample under `template-tests/{uuid}`.
  2. Dispatch a job that runs `LineMarkers::detect()` and saves the result as JSON, and return the id.
  3. Add a status route, and make the builder poll it (like the Verify step does) and show the same result as today.
  4. Have `documents:prune-pages` also clear old `template-tests/` folders.
  5. Run `npm run build`.
- [ ] For B: **[You]** set up an Apache virtual host that points at `public/`. Then update `serve.ps1` and the README.
- [ ] Tests (A only):
  - the endpoint returns an id right away
  - after the job runs, the status route returns the result (use a fake `LineMarkers`)
- [ ] **[You]** While Test layout runs, open the dashboard in another tab. It should load right away.

**Verify:** `php artisan test --filter=TemplateBuilderGridChecksTest`, then all tests.
**Done when:** Test layout no longer blocks other pages.

---

## Phase 5: Easier-to-read code (Priority 5)

Do these after the defense, or only if there's time left. They're ordered so the riskiest one comes last. Claude asks before starting each one.

### Task 5.1: #35 Split line_markers.py (Large)

**Depends on:** nothing
**Why first here:** no other task touches this file, and 60 Python tests protect it.
**Touches:** `ml/line_markers.py`, new package `ml/line_markers_lib/`

- [ ] **[Ask first]** Confirm that you want to do this now.
- [ ] Split the code by job (e.g. `detect.py`, `grid.py`, `crop.py` and `geometry.py`) inside `ml/line_markers_lib/`.
- [ ] Keep `ml/line_markers.py` as the command-line entry point that Laravel runs, and have it re-export every name the tests use (`import line_markers as lm`).
- [ ] **[You]** Detect one real page in the app.

**Verify:** `ml\.venv-kraken\Scripts\python.exe -m unittest tests.Python.test_line_markers tests.Python.test_grid_layouts`
**Done when:** the 60 tests pass unchanged, and detection works in the app.

### Task 5.2: #34 Slim down DocumentTemplateController (Large)

**Depends on:** 2.1, 2.4, 3.5, 3.6, 3.10, 4.4
**Why here:** it comes after every task that edits this controller.
**Touches:** `app/Http/Controllers/DocumentTemplateController.php`, new `app/Http/Requests/StoreTemplateRequest.php` and `UpdateTemplateRequest.php`, new `app/Services/TemplateLayoutService.php`

- [ ] **[Ask first]** Confirm that you want to do this now.
- [ ] Move `validatePayload()` and its helpers into the two Form Requests.
- [ ] Move these methods, and their helpers, into `TemplateLayoutService`: `createLayout`, `saveAsNewVersion`, `layoutChanged`, `layoutSignature`, `syncFields` and `publishTemplate`.
- [ ] Each controller method should now just validate, call the service and redirect. Behaviour must not change.

**Verify:** `php artisan test --filter="DocumentTemplateBuilderTest|TemplateVersioningTest|TemplateFieldSettingsTest|TemplateBuilderGridChecksTest|LedgerTemplateAndExportTest"`, then all tests.
**Done when:** the controller is short, and all the template tests pass unchanged.

### Task 5.3: #33 Move the scanning page's JavaScript out of the template (Large)

**Depends on:** 2.1, 2.2, 2.6, 3.1
**Why last:** it's the largest change to the most-used screen, and it comes after every task that edits that script.
**Touches:** `resources/views/scan/workspace.blade.php`, new `resources/js/scan-workspace/` modules, `vite.config.js`, new `tests/JavaScript/*.test.js`

- [ ] **[Ask first]** Confirm that you want to do this now.
- [ ] Move the inline `<script type="module">` into modules under `resources/js/scan-workspace/` (upload & align, detect & progress, verify, submit), with one entry file.
- [ ] Pass server data through one JSON block (`<script type="application/json" id="scanWorkspaceConfig">`, like `templateBuilderConfig` in `templates/edit.blade.php`), with no Blade inside the JavaScript.
- [ ] Add the entry file to `vite.config.js`, then run `npm run build`.
- [ ] Add Node tests for the pure functions (building the submission data, `missingRequired()`). Keep Vite-only imports out of anything the Node tests import.
- [ ] **[You]** In the browser, do a full scan → Detect → verify → submit, including the missing-fields confirmation.

**Verify:** `npm run test:js`, `npm run build`, then all tests.
**Done when:** the view holds no inline script, and the full flow works.

---

## Phase 6: Wrap-up

### Task 6.1: #20 Old migrations

**Depends on:** every task that adds a migration (1.1, 2.3, 3.7, 3.9, 3.10, 4.1), finished or marked not needed
**Why last:** a schema dump has to include every migration this plan adds.

- [ ] **[Decision]** A) Leave the old migrations as they are (recommended). B) Run `php artisan schema:dump --prune`, but only if every teammate will rebuild their database afterwards.
- [ ] Write the one-sentence explanation here for the defense, e.g. "The ml_jobs and ml_datasets tables came from an early feature that moved to command-line scripts."

**Done when:** the decision is recorded, and the explanation is written.

### Task 6.2: Final regression

**Depends on:** every other task, finished or marked not needed

- [ ] `.\tools\test-all.ps1` passes. Write the final counts here.
- [ ] Run `npm run build`.
- [ ] **[You]** Restart all services (website, queue worker, AI service and scheduler), then repeat the by-hand checks from the Phase 1 and Phase 2 checkpoints.

**Done when:** everything passes, by test and by hand.

---

## Appendix: which tasks touch the same file

Never work on two tasks from the same row at the same time. Do them in the order shown.

| File | Tasks, in order |
|---|---|
| `app/Services/ChangeRequestService.php` | 1.1 → 1.2 → 3.9 |
| `app/Http/Controllers/ChangeRequestController.php` | 1.1 → 3.9 → 4.2 |
| `tests/Feature/ChangeRequestWorkflowTest.php` | 1.1 → 1.2 → 3.9 |
| `resources/views/change-requests/*`, `resources/js/change-request.js` | 1.2 → 1.5 |
| `app/Http/Controllers/RecordController.php`, `resources/views/records/*` | 1.3 → 1.5 → 2.3 → 4.2 |
| `app/Http/Controllers/ReportController.php`, `resources/views/reports/index.blade.php` | 1.4 → 1.5 → 3.9 → 4.2 |
| `app/Http/Controllers/DocumentScanController.php` | 2.1 → 2.2 → 2.3 → 3.1 → 3.5 → 3.6 → 3.10 |
| `resources/views/scan/workspace.blade.php` | 2.1 → 2.2 → 3.1 → 5.3 |
| `app/Http/Controllers/DocumentTemplateController.php` | 2.1 → 2.4 → 3.5 → 3.6 → 3.10 → 4.4 → 5.2 |
| `tests/Feature/DocumentUploadWorkflowTest.php` | 2.1 → 2.2 → 3.1 → 3.6 |
| `tests/Feature/DocumentTemplateBuilderTest.php` | 2.1 → 2.4 → 3.6 |
| `tests/Feature/OcrWorkspaceTest.php` | 2.8 → 3.1 |
| `ml/api/main.py` | 2.7 → 2.8 → 2.9 → 3.3 → 4.3 |
| `ml/trocr_common.py` (new) | 3.3 → 4.3 |
| `app/Services/Ocr/OcrClient.php`, `app/Providers/AppServiceProvider.php` | 2.8 |
| `routes/web.php` | 2.3 → 3.1 → 4.4 |
| `app/Models/CivilRecord.php`, `database/factories/CivilRecordFactory.php` | 3.9 → 3.10 |
| `serve.ps1` | 2.5 → 4.4 |
| `vite.config.js`, `package.json` | 2.6 → 5.3 |
| `.gitignore` | 2.6 → 3.8 |
| `tools/test-all.ps1` | 0.2 → 2.8 |
| `README.md` | 0.2 → 2.5 → 3.8 → 4.4 |
| New migrations | 1.1 → 2.3 → 3.7 → 3.9 → 3.10 → 4.1 → 6.1 |
