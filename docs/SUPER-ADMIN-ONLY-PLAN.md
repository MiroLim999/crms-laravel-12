# Super Admin only — removal plan

**Status: BUILT on branch `miro-dev5` (2026-11-11), NOT COMMITTED.** Phases 0–10 done; Phase 11 (your click-through) and the commit are left.
**All answered (2026-11-11):** D1 = a with reason optional, D3 = a, D4 = b, E1 = add it, and every other item = the
*(suggested)* option.
Written 2026-11-11 on branch `miro-dev4` (commit `96fa33c`). Line numbers are from that commit and move once files change.
Team summary of the same review: the shared doc "Staff and Admin Removal Review".

The client will run CRMS on one offline PC with one account, the Super Admin. This plan removes the Staff and
Admin roles and everything that exists only because several people with different jobs used the system.

## How to use this file

1. **Part A is for you.** For each item, put an `x` in one box (`- [x]`). My suggestion is marked *(suggested)*.
2. Save the file and tell me "run the plan" (or one phase, for example "run Phase 1").
3. **Part B is for me.** I tick each task when it is done and checked, skip the tasks of anything you marked
   Keep, and stop at every **[You]** step so you can check it in the browser.
4. Nothing is committed until you say so.

Words used here:
- **Live database**: the real `crms` database on this PC.
- **The account**: the one Super Admin account that stays.

What the live database holds today (checked 2026-11-11):
- 5 accounts: 1 Super Admin, 2 Staff (Miro Lim, Demo Staff), 2 Admin (Demo Admin, account id 4).
- 68 records. Demo Staff made 4 of them (records 1, 5, 6 and 7), which blocks deleting that account.
- 3 change requests, all asked and approved by the Super Admin.
- 24 Audit Log entries by Staff and Admin accounts. They keep the person's name after the account is gone.

---

# Part A — Decisions (you)

## Big decisions

### D1. What replaces change requests? (item R5)
Today Staff ask for a change to a saved record and an Admin approves or rejects it. With one account you would
ask, then approve yourself. All 3 requests in the live database were asked and approved by the Super Admin.

- [x] **a. "Correct record"** *(suggested)*. The same form: pick the fields, type the right values, give a reason.
      Saving changes the record at once. The old and new values go to the record's Change history and the
      Audit Log. The sidebar's "Change Requests" becomes "Corrections", a read-only list.
- [ ] **b. Approve automatically.** Keep the Change Requests pages, but a request is approved the moment you
      submit it.
- [ ] **c. Keep it as it is.** You submit, then approve your own request.

Your note: "the Super Admin can edit any time after a successful scan, since each output must be
downloadable as CSV/Excel for the client to import into their legacy system's database." That points to **a**.
**Open:** confirm a, and choose whether each edit needs a reason:
- [ ] Reason required (as today)
- [x] Reason optional *(suggested: faster for one person; the Audit Log still keeps the old and new values)*

### D2. How far do roles go? (item R1)
- [x] **a. Keep "Super Admin" as the only role** *(suggested)*. Small and safe: the permission checks stay,
      but they always say yes.
- [ ] **b. Remove roles completely.** No role column and no permission checks. Cleaner code, but many more
      files and tests change.

**Open:** no box ticked yet (the note that was here is now under D1).

### D3. Demo Staff's 4 Birth records (records 1, 5, 6 and 7, saved 5–6 Aug 2026)
The database will not delete the Demo Staff account while these records point to it.

- [x] **a. Move them to the Super Admin** *(suggested)*. They stay in the archive. The Audit Log still shows that
      Demo Staff saved them.
- [ ] **b. Delete them for good**, with their scan pictures. Choose this only if they are test data.

### D4. Teammates' new work
Michael pushed a change today to the words under the sign-in button (commit `98ac202`). The branch
`feat/batch-file-upload` has 3 commits from 9 Nov that are not on this branch. Both touch pages this plan changes.

- [ ] **a. Merge Michael's `98ac202` first** *(suggested)*, ask the team whether `feat/batch-file-upload` is
      still needed, then start.
- [x] **b. Start now** and sort out conflicts afterwards.
      Known clash: Michael's `98ac202` changes the same sign-in line as W4. When it is merged later, keep W4's version.

## New feature

### E1. Download one document's values as CSV (your request, 2026-11-11)
Each saved document (one scanned PDF of 2 pages = one record) gets a **Download CSV** button. The file holds the
values as checked in Verify, plus any later corrections, so the client can import it into their old system.
Today's Reports → Export is different: it only lists a summary per record (ID, type, dates, number of fields),
not the values. It stays as it is.

Your answers:
- CSV is enough (Excel opens it). No .xlsx add-on.
- One file per document, both pages together.
- One row per person.
- The old system's exact columns are set aside for now. The columns follow the layout's field names and can be
  matched to the old system later.

- [x] Add it

## Remove

**R1. The Staff and Admin roles.** Required, because this is the client's request. How deep it goes: D2.

### R2. User Accounts page
The sidebar page with the account list, role and status filters, Add and Edit account, the role picker,
temporary passwords, and Deactivate/Reactivate. With one account there is nobody to add or manage.
Keep it only if the client may want a second login later. It could then only make more Super Admins.
- [x] Remove *(suggested)*
- [ ] Keep

### R3. Staff dashboard
The "Welcome back" page with "Your submissions this month" and "Your recent activity". Only Staff ever saw it.
- [x] Remove *(suggested)*
- [ ] Keep

### R4. Dashboard parts about people
The per-person table under "Processing and staff activity" (Staff, Records submitted, Pages scanned,
Last sign-in), the "5 of 5 accounts active · Manage users" line, and the layout that hides New document for
Admin. With one person the table has one row. The processing numbers above the table stay.
- [x] Remove *(suggested)*
- [ ] Keep

**R5. Change-request approval** (Approve, Reject, Withdraw, "Waiting for review", the pending number on the
sidebar). Decided by D1.

### R6. "Staff may choose the OCR model"
A setting that lets Staff pick a model for each scan, shown as an "OCR model" box in Align. It is already dead:
it is off in the live database, and no screen can turn it on. You choose the model in OCR Workspace.
- [x] Remove *(suggested)*
- [ ] Keep

### R7. Turning an account off
Deactivate/Reactivate, and the "This account has been deactivated" message at sign-in. Turning off the only
account would lock everyone out.
- [x] Remove *(suggested)*
- [ ] Keep

### R8. "Created by" on accounts, and the temporary-password maker
Only used when one person made an account for another. If you choose G3, its password maker moves there.
- [x] Remove *(suggested)*
- [ ] Keep

### R9. Demo logins for Staff and Admin
Test accounts that share a simple password. Risky to leave on the client's PC.
- [x] Remove *(suggested)*
- [ ] Keep

### R10. Your role shown on screen
The role under your name (top right), "Signed in as Super Admin" in the footer, and on Settings the Role row
and "managed by an administrator".
- [x] Remove *(suggested)*
- [ ] Keep

### R11. "Submitted by" in Reports and Records Archive
The Reports "Submitted by" filter, and the person's name under each record in Reports and Records Archive.
It is always the same name.
- [x] Remove *(suggested)*
- [ ] Keep
- [ ] Also drop the "Created by" and "Submitted by" columns from the CSV export *(not suggested: the CSV keeps its shape)*

### R12. Audit Log "Actor" filter and role badges
Every new entry is by you. The log itself and its Actor column stay, so old entries keep their names.
- [x] Remove *(suggested)*
- [ ] Keep

### R13. Old /analytics link
It only sent old Admin bookmarks to the Dashboard.
- [x] Remove *(suggested)*
- [ ] Keep

## Change the words (the feature stays)

### W1. Template Builder (about 35 places)
"Publish for Staff", "Published for Staff", "Hidden from Staff", "Hint for Staff", "Staff currently use…"
→ "Publish", "Published", "Hidden", "Hint", "Used for scanning".
- [x] Change *(suggested)*
- [ ] Leave as is

### W2. OCR Workspace
"Staff now scan with '…'", "Make this the model Staff scan with", "Activate one so Staff can scan handwriting"
→ "Now scanning with '…'", "Use this model for scanning", "Activate one to scan handwriting".
- [x] Change *(suggested)*
- [ ] Leave as is

### W3. New Document
"A Super Admin needs to activate one before scans are read" → "Activate one in OCR Workspace before scanning".
"A Super Admin must publish one first" → "Publish one in Template Builder first".
- [x] Change *(suggested)*
- [ ] Leave as is

### W4. Sign-in page
The line under the button: "Accounts are issued by your administrator…", or Michael's new "Need access? Please
contact your system administrator."
- [x] Remove the line *(suggested)*
- [ ] Leave as is

### W5. Small texts
Audit Log "…including a Super Admin" → "…by anyone". Dashboard tooltip "Runs Staff cancelled" → "Runs cancelled".
The training-export command's "Staff changed the reading…" → "The reading was changed…".
- [x] Change *(suggested)*
- [ ] Leave as is

### W6. Sidebar group "Oversight"
After R2 it holds Reports and Audit Log, and there is nobody to oversee.
- [x] Rename it "Reports & history" *(suggested)*
- [ ] Leave as is

## Fill the gaps

### G1. Edit your name and email
Today only the User Accounts page can change them. Without it, the account is called "Super Admin" forever.
- [x] Add a "Your details" card to Settings *(suggested)*
- [ ] Skip

### G2. Choose your own password at first sign-in
The default login is written in `config/crms.php`, and the system never asks to change it. The live account most
likely still uses it: its password date equals its install time (3 Aug 2026), and the Audit Log has no password
change for it. Also, running `php artisan db:seed` again would reset the live password to the default.
- [x] Ask for a new password at first sign-in, and make `db:seed` never reset an existing account *(suggested)*
- [ ] Skip
- [x] Also ask the live account for a new password at its next sign-in *(suggested)*

### G3. Forgotten password
Nobody else can reset it, and there is no email when offline.
- [x] Add a reset command (`php artisan crms:reset-password`) and a "Forgot the password?" note in the README *(suggested)*
- [ ] Skip (then the seeder must keep its reset behaviour, and the README says to re-run it)

---

# Part B — Tasks (me, after Part A is filled in)

Order matters. The database clean-up (Phase 1) runs before the role code changes (Phase 2), because the code
reads role names from the database and would crash on a leftover Staff or Admin row.

Each phase ends with a **Check**. A **[You]** step means I stop and you look in the browser.

## Phase 0 — Get ready (nothing visible changes)
- [x] 0.1 [D4 = b] No merge first. Leave `98ac202` and `feat/batch-file-upload` for later.
- [x] 0.2 Make a new branch from `miro-dev4`, named `super-admin-only`.
- [x] 0.3 Back up the live database outside the project folder, for example
      `C:\mysql\bin\mysqldump.exe -u root -p crms > C:\xampp\crms-backups\crms-before-super-admin-only.sql`.
      Check that the file is not empty.
- [x] 0.4 Run all tests once (`tools/test-all.ps1`) and write the result here, so old failures are not blamed
      on this work. Result: PHP 364 passed / 6 failed (DocumentUploadWorkflowTest ×2, LedgerTemplateAndExportTest
      "existing rectangle template", MultiPageSourceTest ×3); JS 125 passed / 3 failed (sneat-controls 101, 102, 104).
      Python suites not run (this work does not touch them). After the work: PHP 338 passed / the same 6 failed; JS the same.

## Phase 1 — Clean up the live database [R1, D2, D3]
- [x] 1.1 New migration `database/migrations/<date>_remove_staff_and_admin_accounts.php`, using plain database
      queries (not models, which would trip over the removed role names):
      - Keep the first active Super Admin. If there are other accounts but no Super Admin, stop with a clear
        error. If there are no accounts at all (a fresh install), do nothing.
      - [D3 = a] Move `records.created_by`, `records.submitted_by`, `change_requests.requested_by` and
        `change_requests.reviewed_by` from the other accounts to the Super Admin.
      - [D3 = b] Delete those records instead. Their fields, pages and change requests go with them in the
        database. Also delete their stored pictures (scan, page image, extra pages).
      - Delete the other accounts. The database then clears their links by itself: Audit Log entries keep the
        person's name, unsaved scan pages are deleted, page runs lose the name.
      - [D2 = a] Delete the `staff` and `admin` rows from `roles`.
      - [G2, if "also ask the live account" is ticked] Switch on "choose a new password" for the Super Admin.
      - Write one Audit Log entry saying how many accounts were removed.
      - `down()` does nothing. The backup from 0.3 is the way back.
- [x] 1.2 Run `php artisan migrate` on the live database.
- **Check:** `users` has 1 row, `roles` has 1 row, `records` has 68 rows (64 if D3 = b), and the Audit Log page
  still opens.

## Phase 2 — Roles in the code [R1, D2]
For D2 = a:
- [x] 2.1 `app/Enums/RoleSlug.php`: keep only `SuperAdmin`. Delete `canEnterData()` and `hasOversight()`.
- [x] 2.2 `app/Models/User.php`: delete `isStaff()`, `isAdmin()`, `canEnterData()` and `hasOversight()`.
- [x] 2.3 `app/Providers/AuthServiceProvider.php`: every remaining ability becomes "is the Super Admin".
      Delete `users.manage-role`, `users.update` and `users.deactivate`; also `users.manage` (R2),
      `change-requests.moderate` (D1 = a or b), `analytics.view` (R13) and `Gate::before` (R7).
      Drop the mention of `docs/roles.md` (that file does not exist).
- [x] 2.4 `database/factories/UserFactory.php`: the default role becomes Super Admin. Delete `staff()` and `admin()`.
- [x] 2.5 `app/Services/DashboardAnalytics.php` (line 557) and `app/Http/Controllers/ReportController.php`
      (line 219): drop the Staff and Super Admin role lists (done with R4 and R11).

Only if D2 = b, also:
- [ ] 2.b1 *(not needed: D2 = a)* Migration: drop `users.role_id` (its foreign key and index first), then the `roles` table.
- [ ] 2.b2 Delete `app/Enums/RoleSlug.php`, `app/Models/Role.php` and `RoleSeeder`. Take `RoleSeeder` out of
      `DatabaseSeeder` and `tests/TestCase.php`.
- [ ] 2.b3 Remove every `can:` from `routes/web.php`, every `@can` from the views, the abilities from
      `app/Support/Navigation.php`, and the gates from `AuthServiceProvider`.
- [ ] 2.b4 `SuperAdminSeeder` stops setting `role_id`. `AuditLogger` stops writing `actor_role`; old rows keep theirs.

- **Check:** `php artisan route:list` runs. Dashboard, Records Archive, Template Builder and OCR Workspace open.

## Phase 3 — User accounts [R2, R7, R8, R9]
- [x] 3.1 [R2] Delete `app/Http/Controllers/UserController.php`, `app/Services/UserProvisioner.php`,
      `app/Http/Requests/StoreUserRequest.php`, `app/Http/Requests/UpdateUserRequest.php` and
      `resources/views/users/` (3 files). If G3 is chosen, move `generateTemporaryPassword()` to the new command first.
- [x] 3.2 [R2] `routes/web.php`: delete the 8 `users.*` routes. `app/Support/Navigation.php`: delete "User Accounts".
- [x] 3.3 [R7] Delete the deactivated check in `app/Http/Controllers/Auth/LoginController.php` (lines 36–41),
      `app/Http/Middleware/EnsureAccountIsActive.php` and its line in `bootstrap/app.php`, and `inactive()` in
      `UserFactory`.
- [x] 3.4 [R7, R8] Migration: drop `users.is_active` and `users.created_by` (the foreign key first). Mind the
      shared `role_id` + `is_active` index: the `role_id` foreign key needs an index to stay.
      Remove both columns from `User` (`$fillable`, casts, `creator()`) and from `SuperAdminSeeder`.
- [x] 3.5 [R8] `config/crms.php`: delete `temporary_password_length` (keep it if G3 uses it).
- [x] 3.6 [R9] Delete `database/seeders/DemoUsersSeeder.php`.
- Tests: delete `tests/Feature/UserManagementTest.php`. In `tests/Feature/AuthenticationTest.php`, delete
  `test_deactivated_accounts_cannot_sign_in` and `test_an_account_deactivated_mid_session_is_signed_out`.
- **Check:** the tests above pass. **[You]** The sidebar has no "User Accounts", and opening `/users` shows
  "Not Found".

## Phase 4 — Dashboard [R3, R4]
- [x] 4.1 [R3] `app/Http/Controllers/DashboardController.php`: always build the full dashboard. Delete
      `staffOverview`, `recentActivity` and the `hasOversight()` checks.
- [x] 4.2 [R3] `resources/views/dashboard.blade.php`: delete the Staff page (lines 687–776) and the
      `@if ($analytics)` around the rest. Delete `DashboardAnalytics::staff()`.
- [x] 4.3 [R4] Delete the per-person table and the accounts line (lines 542–585), `accountHealth()`, and the
      people part of `processing()`. Rename the card "Processing".
- [x] 4.4 [R4] The New document card always shows. Remove the `$canScan` layout switch.
- [x] 4.5 [D1 = a or b] The "Change requests" card becomes a count of corrections in its period, with a link to
      the list. [D1 = c] It stays as it is.
- Tests: in `tests/Feature/AnalyticsDashboardTest.php`, delete
  `test_admin_gets_no_new_document_card_because_admin_cannot_scan`, turn `test_dashboard_content_is_role_aware`
  into "the account sees the full dashboard", and update the tests that look for the people table.
- **Check:** dashboard tests pass. **[You]** The Dashboard shows the New document card, both OCR cards, the
  trend, the totals and the processing numbers, with no people table and no "Manage users".

## Phase 5 — Corrections [D1, R5]
For D1 = a:
- [x] 5.1 `app/Services/ChangeRequestService.php`: a new `correct()` that, in one step, saves the request with its
      items, applies the values, and marks it Approved by the same person. This keeps the record's Change
      history and the dashboard's correction rate working, since that rate reads approved requests.
      Keep the two checks: nothing changed → refused; a required field left blank → refused.
      Write one Audit Log entry, `record.corrected`, with the old and new values and the reason.
      Delete `approve()`, `reject()` and `withdraw()`.
- [x] 5.2 `app/Http/Controllers/ChangeRequestController.php`: `store()` calls `correct()` and returns to the
      record with "Correction saved." Delete `approve()`, `reject()` and `withdraw()`, and their 3 routes.
- [x] 5.3 Record page (`resources/views/records/show.blade.php`): "Request a change" → "Correct record".
      Delete the "Change request pending" badge.
- [x] 5.4 Form (`resources/views/change-requests/create.blade.php`): new texts ("Saving updates the record right
      away. The old values stay in its history.") and the button "Save correction".
- [x] 5.5 List and detail pages: the sidebar item "Change Requests" → "Corrections", with no pending number
      (`app/Support/Navigation.php` lines 88–94). Delete the decision panel, "Waiting for review" and the status
      tabs. "Requested by / Reviewed by" → "Corrected by".
- [x] 5.6 Dashboard "Recent changes": give `record.corrected` an icon.
- Tests: rewrite `tests/Feature/ChangeRequestWorkflowTest.php` around `correct()` (values applied, Audit Log old
  and new, no-change refused, required field kept, optional field can be cleared). Delete the approve, reject,
  withdraw, Staff and Admin tests. Update `tests/Feature/ChangeRequestPresentationTest.php`.

For D1 = b:
- [ ] 5.b1 *(not needed: D1 = a)* `store()` submits and approves in one step. Delete the Approve, Reject and Withdraw buttons and routes,
      the pending number and "Waiting for review". Keep the "Change Requests" names.

For D1 = c: skip this phase, and change "until an Admin approves" to "until you approve it".

- **Check:** change-request tests pass. **[You]** Open a record → Correct record → change one value, give a
  reason, Save. The value changes, and Change history and the Audit Log show the old and new values.

## Phase 6 — Smaller removals [R6, R10, R11, R12, R13]
- [x] 6.1 [R6] `resources/views/scan/workspace.blade.php`: delete the "OCR model" box (lines 341–360) and the
      6 `modelSelect` lines (1189, 3994, 4094, 5175, 5218, 5251). `DocumentScanController`: delete
      `selectableModels()`. `app/Services/Ocr/ScanModelChoice.php`: delete `selectable()`; `resolve()` always
      returns the active model. `app/Models/OcrSetting.php`: delete `staffMayChooseModel()` and the column from
      `$fillable` and casts. `OcrModelController`: delete `saveSettings()` and the `ocr.settings` route.
      Migration: drop `ocr_settings.allow_staff_model_choice`.
- [x] 6.2 [R10] Delete the role under your name (`resources/views/layouts/partials/navbar.blade.php` line 79),
      the "Signed in as" line (`footer.blade.php` line 5), and the Role row and "managed by an administrator"
      (`resources/views/settings/edit.blade.php` lines 18–20 and 36).
- [x] 6.3 [R11] `ReportController`: delete the `submitted_by` filter and `dataEntryUsers()`.
      `resources/views/reports/index.blade.php`: delete the filter (lines 53–60) and the name under each record
      (line 108). `resources/views/records/index.blade.php`: delete the name (lines 115–117).
      Change the CSV columns only if ticked in R11.
- [x] 6.4 [R12] `AuditLogController`: delete the `actor` filter and `knownActors()`.
      `resources/views/audit/index.blade.php`: delete the Actor filter (lines 27–36) and the role badge
      (around line 124); keep the name.
- [x] 6.5 [R13] Delete `app/Http/Controllers/AnalyticsController.php` and the `analytics` route.
- Tests: `OcrWorkspaceTest.php` (delete the 4 Staff-choice and settings tests and the `ocr.settings` row),
  `ReportExportTest.php` (the `submitted_by` filter and `test_staff_are_denied_the_report_page_and_the_export`),
  `AuditLogViewerTest.php` (`test_staff_are_denied` and the actor filter), and every test that opens
  `analytics.index`.
- **Check:** those tests pass. **[You]** Align has no OCR model box, Reports has no "Submitted by", and the
  Audit Log has no Actor filter.

## Phase 7 — Fill the gaps [G1, G2, G3]
- [x] 7.1 [G1] Settings: a "Your details" card with name and email. `AccountSettingsController::updateProfile()`,
      route `PUT settings/profile` (`settings.profile.update`). The email must be unique. Write an Audit Log entry
      with the old and new values. Add a test.
- [x] 7.2 [G2] `database/seeders/SuperAdminSeeder.php`: create the account only if it is missing (never reset an
      existing one), with "choose a new password" switched on. `resources/views/auth/change-password.blade.php`:
      "Choose your own password before you start" and "Current password" instead of "temporary password".
      Add a test.
- [x] 7.3 [G3] New command `app/Console/Commands/ResetPassword.php` (`php artisan crms:reset-password`): it gives
      the account a new temporary password, prints it once, asks for a new password at the next sign-in, and
      writes an Audit Log entry. README: a "Forgot the password?" section. Add a test.
- **Check:** the new tests pass. **[You]** In Settings, change your name, sign out and back in: the new name shows
  top right. Then run the reset command in the terminal and sign in with the printed password: you are asked to
  choose a new one.

## Phase 8 — Wording [W1–W6]
- [x] 8.1 [W1] `resources/views/templates/index.blade.php`, `resources/views/templates/edit.blade.php`,
      `resources/js/template-builder.js`, `resources/js/ledger-grid.js`, and the messages in
      `DocumentTemplateController.php` (lines 145, 533, 534, 683, 731, 1206, 1374).
      Tests that check the old words: `DocumentTemplateBuilderTest.php` (lines 58, 559, 709),
      `TemplatePublishFlowTest.php` (247), `DocumentTypeArchiveTest.php` (79).
- [x] 8.2 [W2] `OcrModelController.php` line 80, `resources/views/ocr/partials/models.blade.php` (69, 204),
      `resources/views/ocr/partials/modals.blade.php` (115), `app/Services/Ocr/OcrModelManager.php` (433).
- [x] 8.3 [W3] `resources/views/scan/partials/document-step.blade.php` line 77, `DocumentScanController.php` line 137.
- [x] 8.4 [W4] `resources/views/auth/login.blade.php` line 52 (after D4).
- [x] 8.5 [W5] `resources/views/audit/index.blade.php` line 13, `resources/views/dashboard.blade.php` line 521,
      `app/Console/Commands/ExportTrainingLines.php` line 195.
- [x] 8.6 [W6] `app/Support/Navigation.php`: the "Oversight" header.
- [x] 8.7 Last sweep: search `resources/views`, `resources/js` and `app` for on-screen "Staff", "Admin" and
      "administrator". Update code comments in the same pass.
- **Check:** the tests above pass. **[You]** Template Builder and OCR Workspace show the new words.

## Phase 8b — Download a document as CSV [E1]
- [x] 8b.1 Route `GET records/{record}/csv` (`records.csv`) in the Records group of `routes/web.php`;
      `RecordController::csv()` streams the file, named like `birth-record-123.csv`.
- [x] 8b.2 Rows from `app/Services/RecordFieldGrouper.php`: one row per person. Columns: record ID, document type,
      then each field name in the layout's order, page 1 then page 2. Fields that belong to no person
      (for example Page No.) repeat on every person row. Values = `verified_value`, so corrections are included.
- [x] 8b.3 Two-page check: a person whose entry runs across both pages must come out as ONE row. Confirm how the
      two pages' fields share a person (`record_fields.page_no` + `person_group`); if page 2 has its own groups,
      join them by row number. Try it on a real two-page record.
- [x] 8b.4 Excel-friendly: UTF-8 with a BOM so ñ and accents show correctly, and the same formula guard as
      `ReportController::safeCell()` (moved into one shared helper both use).
- [x] 8b.5 Audit Log entry `record.exported` (who, which record, when).
- [x] 8b.6 A **Download CSV** button on the record page top bar (`resources/views/records/show.blade.php`).
- Tests: a one-page record, a two-page record (one row per person), accented names, the formula guard, the
  audit entry.
- **Check:** the tests pass. **[You]** Open a two-page record → Download CSV → open it in Excel: one row per
  person, both pages' columns side by side, names with ñ shown correctly.

## Phase 9 — All tests
- [x] 9.1 Replace `tests/Feature/CapabilityMatrixTest.php` with a short test: the account opens every page, a guest
      is sent to sign-in, and removed pages (`/users`, `/analytics`) give "Not Found".
- [x] 9.2 Search the tests for `->staff()`, `->admin()`, `->inactive()`, `RoleSlug::Staff` and `RoleSlug::Admin`,
      and fix each one.
- [x] 9.3 Run `tools/test-all.ps1` and compare with 0.4: no new failures.

## Phase 10 — Documents
- [x] 10.1 `README.md`, `docs/DEVELOPER-GUIDE.md` and `docs/SYSTEM-OVERVIEW.md`: one account, no demo logins, and
      "Forgot the password?" (G3).
- [x] 10.2 Remove the `docs/roles.md` mentions from code comments (`RoleSlug.php`, `AuthServiceProvider.php`).
- [x] 10.3 Tell the documenter: any manuscript diagram or table with three user types now needs one.
- [x] 10.4 Update the shared doc "Staff and Admin Removal Review" with the final choices.

## Phase 11 — Final check
- [ ] 11.1 **[You]** Click through:
      1. Sign in. With G2 you are asked for a new password: choose one.
      2. The sidebar shows Dashboard · New Document, Records Archive, Corrections (or Change Requests) ·
         Reports, Audit Log · OCR Workspace, Template Builder. No User Accounts.
      3. The Dashboard loads every card, with no people table.
      4. New Document: scan one page, verify it and save. It appears in Records Archive.
      5. Open that record → Correct record → save a change. Change history and the Audit Log show it.
      6. Reports: a filter and Export CSV work. A record's Download CSV opens in Excel with one row per person.
      7. Settings: change your name and your password.
- [ ] 11.2 Commit, only when you say so.
