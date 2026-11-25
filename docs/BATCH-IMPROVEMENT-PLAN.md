# Batch File Upload: improvement plan

> **Changed 2026-11-20 (after the first review):** decisions 1 and 2 no longer apply as written.
> - **Snap is manual** (B1 and B2 removed): no automatic Snap when a page opens, no Snap ahead, no "Check alignment" flag.
> - **Reading is Scan all at the end only** (D1 reduced): no *Scan while I align* switch, no Scan remaining, no background polling, no wait window.
>   A page sent back by Re-align (E1) is read by Scan all, for that page, once it is queued again.
> - Scan all has no 30-minute limit.
> - A page that cannot be **uploaded** stays as it was and shows a message; it is not marked Failed ("could not be read").
> - **Skip record is removed** (Verify and Review & Export): a record not to be saved is taken out of the batch with its × in the queue list. Re-align shows a "‹" icon.
>
> The sections below are kept as they were written.

Status: **built, waiting for the manual review (G3)**. Every automated step is done and committed phase by phase (`e2e9811` … the Phase G commit); the boxes left open are the *(manual)* browser checks. Branch: **`miro-dev4`** (at `e35c5a6`, which includes the batch work `de556d5`).
Scope: the batch flow in `resources/views/scan/workspace.blade.php` and the
endpoints behind it. **Single Upload must keep working as it does now.**

How to use this file (**continuous run**):
- Work top to bottom, **without pausing between phases**. Each phase builds on the one before.
- Tick a box (`- [x]`) when the step is done **and** its automated check passes.
- A task is done when all of its boxes, including **Verify**, are ticked. Boxes marked *(manual)* need a person in the browser: leave them unticked and carry on. They are collected in the final review (G3).
- **Gate between phases = automated checks only:** `npm run build`, `npm run test:js`, `php -d extension=gd vendor/bin/phpunit tests/Feature`, Blade compile and the workspace script syntax check. No new failures compared with the baseline (0.4) means go on.
- **Commit after each phase** on `miro-dev4` (message names the phase), so any phase can be reverted alone.
- **Stop only when:**
  - an automated check fails and cannot be fixed within the phase;
  - a task needs a decision not already recorded in section 1;
  - a step would delete data or change the database outside this plan.

The batch flow today: add files or a folder, align each page, Save & Queue,
**Scan all**, then Verify and Review & Export each record, then the summary
with Download all.

Revision of 2026-11-18 (second review):
- **Added A0** (per-page states).
- **Swapped two phases:** reading choice is now **D**, Re-align is now **E**.
- **Added:**
  - a status table (section 3);
  - the phpunit fix (0.5);
  - three missing resume cases;
  - effort sizes.
- **Tightened** A1's check and B1's Smoke-test rule.
- **Changed** Cancel to keep the batch for resume.
- **Ticked** 0.4 with the baseline measured on `miro-dev4`.

---

## 1. Decisions (review of 2026-11-18)

| # | Topic | Decision |
|---|---|---|
| 1 | How a page starts in Align | From the **template** (already aligned for the type), then **Snap to table runs automatically**. Pages Snap cannot fit are flagged **"Check alignment"**. |
| 2 | When pages are read | A **choice**: *Scan while I align* (default) or *Scan all at the end* (today's way). |
| 3 | A page that fails to read | The batch carries on. The failed page can be re-aligned or removed. **Every page and every file can be removed** (except saved or rejected ones). |
| 4 | Resume after a reload or crash | **Option A:** the queue, alignments and drafts are kept on the server; on resume the user **re-picks the files**, matched by fingerprint. |
| 5 | Page list for big PDFs | Yes |
| 6 | Send a page back to Align from Verify | Yes |
| 7 | Keyboard shortcuts | Pass |
| 8 | Queue panel for many files | Yes |
| 9 | Save clean records straight from Verify | Pass: every record goes through Review & Export. |
| 10 | One word per step ("Verify", not "Validate") | Yes |
| 11 | "Add more files" (button missing) | Fix, with tests |
| 12 | Scan all | Kept: the "at the end" choice, and **Scan remaining** in background mode. |
| 13 | DEV Review & Export shortcut | Removed (2026-11-25). |
| 14 | Smoke test scan | Retain as it is. |

---

## 2. Things to keep in mind while building

- **One phase for the whole batch is the main obstacle.**
  - Today the batch moves through `queuing` → `scanning` → `verifying` → `done`. That one phase decides what is possible in **15 places** in `workspace.blade.php`, and nothing goes back from `verifying`.
  - Failed pages (A3), reading while aligning (D1) and Re-align (E1) all need a page to be aligned or read **after** others are already in Verify.
  - **A0 replaces the phase checks with each page's own state first**, so the later tasks do not each fight the phase.
- **Auto Snap (#1):**
  - Only a page **never opened** is snapped automatically.
  - A page the user adjusted, or one that has a **Smoke test result**, is left alone.
  - Fields moved by hand stay where they are, as Snap already does.
- **Background reading (#2):**
  - The worker takes jobs in order, so a **Smoke test waits behind pages already being read**. Its progress window must say so.
  - On a PC without an NVIDIA card (like this one), reading runs on the CPU, so Snap and drawing may feel slower meanwhile.
  - Re-saving a page that is already read cancels that reading and reads it again.
  - First version: a record opens in **Verify once its pages are read**. Aligning and verifying at the same time is not part of this plan.
- **Removal (#3):**
  - Removing one page of a **pair** (two-page layout) leaves its partner as a record on its own. The dialog says so.
  - Saved and rejected pages are records: they cannot be removed and stay listed in the summary.
- **Re-align (#6):**
  - For a pair, both pages go back to Verify, because the record holds both.
  - Typed values are kept on the page that did not change. A re-read page starts fresh.
- **Shared UI:** #3 (removal), #5 (page list) and #8 (queue panel) are one piece of UI, the queue panel (task C1). The remove dialog from A3 is reused there; build the panel once.
- **Resume (#4):** leaving the page or pressing Cancel **keeps** the batch for later. Only **Discard** throws it away.

---

## 3. Page states: code names and what the user sees

Use the code names in code and these labels on screen. **Flags** sit on top of a state; they are not states.

| Code status (`item.status`) | Label on screen | Meaning |
|---|---|---|
| `pending` | Pending | Not aligned and queued yet |
| `saving` | Saving | Being sent to the queue |
| `saved` | Queued | Queued, not read yet |
| `scanning` | Reading | Being read |
| `ready` | Scanned | Read, waiting to be verified |
| `verifying` | Verifying | Open in Verify or Review & Export |
| `imported` | Saved | Saved as a record |
| `rejected` | Rejected, or **Skipped** when `item.skipped` | Left out of the batch |
| `failed` | Failed | Could not be read (`item.error` says why) |

| Flag | Label | Meaning |
|---|---|---|
| `item.alignCheck` (new, B1) | Check alignment | Snap could not fit this page |
| `item.skipped` | Skipped | Left out by Skip record (shown instead of "Rejected") |
| `item.existingAccepted` | (none) | "Continue anyway" was chosen for a matched record |

---

## 4. Tasks

Effort: **S** small (hours), **M** medium (about a day), **L** large (several days).

| Phase | What | Effort |
|---|---|---|
| 0 | Before starting | S |
| A0 | Per-page states | M |
| A | Quick fixes | S |
| B | Align takes less work | S–M |
| C | Finding your way in a big batch | M |
| D | When pages are read | M |
| E | Re-align from Verify | M |
| F | Never lose a batch | L |
| G | Finish | S |

### Phase 0: before starting (S)

- [x] **0.1** Work on `miro-dev4`. It is in sync with GitHub (`origin/miro-dev4`), 41 commits ahead of GitHub `main` and 0 behind, so no rebase is needed.
  - [ ] Optional: update the local `main` copy (`git fetch` then `git branch -f main origin/main`). It is 126 commits behind GitHub, which only matters for comparing against it.
- [x] **0.2** The batch work (`de556d5`) is on `miro-dev4` and pushed (also on `review-export-page-creation`).
- [x] **0.3** Commit this plan on `miro-dev4` (done in `80e603f` and `60f4add`).
- [x] **0.4** Baseline, measured on `miro-dev4` on 2026-11-18. New failures are compared with this:
  - [x] `npm run test:js`: 151 tests, 149 pass, 2 old failures (style-lint, tests 124 and 125).
  - [x] `php -d extension=gd vendor/bin/phpunit tests/Feature`: 355 tests, 2 old failures:
    - `LedgerTemplateAndExportTest` › rectangle template;
    - `MultiPageSourceTest` › saved pages.
- [x] **0.5** Make plain `vendor/bin/phpunit` work. It fails with "tests/Unit not found", because `phpunit.xml` lists a folder that does not exist.
  - [x] Add `tests/Unit/.gitkeep`, or drop the Unit suite from `phpunit.xml`.
  - [x] Note in `docs/DEVELOPER-GUIDE.md` that the tests need GD (`php -d extension=gd …`, or enable `extension=gd` in XAMPP's `php.ini`).
  - [x] **Verify:** `php -d extension=gd vendor/bin/phpunit` runs every suite with the same 2 old failures.

### Phase A0: per-page states (M)

The foundation for A3, D1 and E1. No visible change on its own.

- [x] List the 15 places that check `batchState.phase` (`grep -n "batchState.phase" resources/views/scan/workspace.blade.php`) and decide for each whether it becomes a per-page or per-record check.
- [x] Add small pure helpers in `resources/js/scan-source.js`, using the states in section 3:
  - [x] `pageCanAlign(item)`: pending, queued, failed, or sent back by Re-align;
  - [x] `recordReadiness(records)`: each record as `ready` (all pages read), `waiting` (some pages not read yet) or `blocked` (a page failed);
  - [x] `batchSummaryPhase(items)`: the phase shown on screen (aligning, reading, verifying, done), **worked out from the pages** instead of stored.
- [x] Replace the phase checks:
  - [x] Align allows any page that `pageCanAlign`;
  - [x] Verify opens any record that is `ready`;
  - [x] the queue bar shows `batchSummaryPhase`.
- [x] Keep today's flow exactly: align all → Scan all → verify. This task only changes how it is decided.
- [ ] **Tests:**
  - [x] JS: each helper with page mixes (all pending; some read; a pair with one failed page; everything saved).
  - [ ] *(manual)* a full batch as today (section 6, items 1, 3 end mode, 8 and 10) behaves exactly as before.
- [x] **Verify:** `grep -c "batchState.phase ==="` is down to display-only uses, and every remaining one is commented. (Result: 0. The stored phase is gone; the bar's wording uses a local `phase` from `batchSummaryPhase`, and `batchAligning()` tells whether the open page is in Align.)

### Phase A: quick fixes (S)

**A1. One word per step (#10)**
- [x] Change the two user-facing texts in `workspace.blade.php`:
  - queue badge `text: 'Validating'` → `'Verifying'`;
  - remove dialog `ready: 'Scanned, waiting for validation'` → `'Scanned, waiting to be verified'`.
- [x] Check the batch bar, dialogs and summary for any other user-facing "validate" or "validation". Internal names such as `validationRow` stay. (Also the progress window: "Preparing Verify", "Opening the results in Verify.")
- [x] **Verify:** `grep -nE "'Validating'|waiting for validation" resources/views/scan/workspace.blade.php` finds nothing. Blade compiles. JS tests unchanged.

**A2. "Add more files" (#11)**
- [x] Add the button to the queue bar (the code already looks for `batchAddMoreBtn`).
  - It shows in Align while pages can still be aligned.
  - It opens the file picker; a small menu also offers "Add a folder".
- [x] Added files go through the normal intake: fingerprint, the left-out window, natural order.
- [x] Added pages go **after** the current queue. The page the user is on stays open.
- [x] Put the merge rule in a pure helper, `appendToQueue(items, added)` in `resources/js/scan-source.js`.
- [ ] **Tests:**
  - [x] JS (`tests/JavaScript/scan-source.test.js`): added pages go last and keep their order; a page already queued (same hash and page) is not added twice.
  - [ ] *(manual)* queue 2 files, add 1 more mid-queue. Then add a copy of a queued file: it appears in the left-out window.
- [ ] **Verify:** the new JS tests pass; the manual steps behave as described. (JS part passes; the manual part is left for G3.)

**A3. A failed page does not stop the batch, and anything can be removed (#3)**
- [x] `runBatchScanAll`: on a failed page, mark it `failed` with the server's reason (`item.error`) and keep reading the others.
- [x] Records that are `ready` open in Verify. Records that are `blocked` wait, and the banner says "N pages failed: re-align or remove".
- [x] A failed page can be opened in Align (`pageCanAlign`), re-aligned and queued again. It is then read by Scan all, or, once Phase D exists, by Scan remaining.
- [x] Remove dialog: **Remove this page** and **Remove the whole file**.
  - [x] Removing cancels the page(s) on the server.
  - [x] A page of a pair warns that its partner will be saved alone.
  - [x] Saved and rejected pages are not offered.
- [ ] **Tests:**
  - [x] JS: `recordReadiness` with a failed page, and after that page is removed (its partner becomes `ready`).
  - [ ] *(manual)* stop the OCR service in the middle of Scan all. The other pages still finish, and the failed one can be re-aligned or removed.
  - [ ] *(manual)* remove a single page, a page of a pair, and a whole file.
- [ ] **Verify:** tests pass; the summary still lists saved records after removals. (Tests pass. Saved and rejected pages are never removed, so the summary keeps them; to confirm in the browser in G3.)

> **Phase A gate:** automated checks pass, then commit and go straight on. *(manual)* browser items to try later (section 6): 1, 4, 5, 8 and 10.

### Phase B: Align takes less work (S–M)

**B1. Auto Snap when a page opens (#1)**
- [x] `openBatchQueueItem`: for a page **never opened** (`item.boxes` empty) **and with no Smoke-test result** (`item.detected` empty), draw it on the template grid, then run Snap to table.
- [x] On success: apply the fitted grid as one undoable change; fields moved by hand stay put.
- [x] On failure or no table:
  - keep the template grid;
  - set `item.alignCheck = true`;
  - show the reason in the Align message area.
- [x] A page opened before, or one that was Smoke-tested (alone or as the page beside another), keeps its own markers.
- [x] Show the spinner on Snap to table while it runs; Save & Queue waits for it.
- [ ] *(manual)* **Test** with two different PDFs.
  - Each new page opens already snapped.
  - A page with faint lines is flagged, not moved.
  - Ctrl+Z undoes the automatic Snap.
  - A Smoke-tested page is not snapped over.
- [ ] **Verify:** going back to an adjusted page keeps the adjustments.

**B2. Snap the next page ahead (optional speed-up)**
- [x] While the user is on page N, draw page N+1 off-screen and run its Snap. Keep the result on the item.
- [x] Opening N+1 applies the kept result at once (no second request).
- [x] Only one page ahead. Drop it if the user opens another page.
- [ ] **Verify:** Save & Next opens an already-fitted page in under half a second on this PC. (Needs a timing in the browser: left for G3.)

> **Phase B gate:** automated checks pass, then commit and go straight on. *(manual)* browser items to try later (section 6): 2 and 10.

### Phase C: finding your way in a big batch (M)

**C1. Queue panel: files, pages, state, removal (#8, #5, #3)**
- [x] Replace the single row of queue labels with a collapsible panel in the queue bar.
- [x] **Collapsed:** one line with the current file and page, and the totals (as the bar shows today).
- [x] **Expanded:**
  - one row per file, with its page count, state counts and a remove button;
  - expanding a file lists its pages, each with its label and flags from section 3, open-on-click, and the A3 remove dialog.
- [x] Filters: All · Needs attention (Failed, Check alignment) · Pending · Saved.
- [x] Totals: pages queued, read and saved.
- [x] Dragging whole files to change their order still works.
- [x] Render only the expanded file's pages, so a 300-page PDF stays quick.
- [x] Pure helper `queueCounts(items)` for the totals and filter counts.
- [ ] **Tests:**
  - [x] JS: `queueCounts` for each state, and for "Needs attention".
  - [ ] *(manual)* a folder of 10 PDFs × 2 pages, and one 100-page PDF.
- [ ] **Verify:** every page can be found, opened and removed from the panel. (By code: every file row, and every page of the file expanded, opens and removes; to confirm in the browser in G3.)

**C2. Page bar for the current file (#5)**
- [x] In Align, show the page bar (‹ Page 3 of 12 ›, with a dropdown) in batch too, reusing `scanPageBar`.
- [x] The dropdown shows each page's state icon; choosing a page opens its queue item.
- [x] Prev and next move within the current file.
- [ ] *(manual)* **Test:** a 12-page PDF: jump to page 9, back to 3, then next and previous.
- [ ] **Verify:** Single Upload's page bar behaves exactly as before. (Single mode's code path is unchanged apart from a menu reset; to confirm in the browser in G3.)

> **Phase C gate:** automated checks pass, then commit and go straight on. *(manual)* browser items to try later (section 6): 5, 6 and 10.

### Phase D: when pages are read (M)

**D1. Scan while aligning, or all at the end (#2, #12)**
- [x] Add a switch to the queue bar: **Scan while I align** (default) or **Scan all at the end**, remembered per browser.
- [x] **While I align:**
  - [x] Save & Queue sends the page without `defer_scan`, so reading starts at once.
  - [x] A Smoke-tested page is queued and read at once.
  - [x] The queue panel shows each page's reading state.
  - [x] A record opens in Verify as soon as it is `ready`. When the last page is queued, the bar offers **Verify**, with a short progress line for pages still being read.
  - [x] **Scan remaining** reads pages queued in "at the end" mode, or queued again after a failure.
  - [x] Re-saving a page that was read already cancels the reading and reads it again. (The page queued before is let go on the server, its reading with it, and the new one is read.)
  - [x] Smoke test's progress window says when it is waiting behind pages being read.
- [x] **At the end:** the Scan all button and behaviour stay as today.
- [x] Server: `documents.pages.queue` accepts `read=1` to start reading a Smoke-tested page.
- [ ] **Tests:**
  - [x] PHP: queuing a Smoke-tested page with `read=1` starts its reading (`Queue::assertPushed`); without it, nothing starts.
  - [ ] *(manual)* 6 pages in background mode. Align steadily; Verify starts with little or no wait.
  - [ ] *(manual)* switch the mode mid-batch.
- [ ] **Verify:** end mode works exactly as before. (End mode keeps Scan all's code path; to confirm in the browser in G3.)

> **Phase D gate:** automated checks pass, then commit and go straight on. *(manual)* browser items to try later (section 6): 3, 4 and 10.

### Phase E: fix a page without losing your place (M)

Needs A0 (pages go back from Verify) and D1 (the page is read again).

**E1. Re-align from Verify or Review & Export (#6)**
- [x] Add a **Re-align** button beside Skip record, in Verify and in Review & Export (batch only). (A two-page record asks which page, or both.)
- [x] On click:
  - [x] keep the record's typed and ticked values;
  - [x] put its page (or both pages of a pair) back to `pending`, with the markers they were read with;
  - [x] cancel the old reading on the server;
  - [x] open the page in Align.
- [x] Once queued again, the page is read **at once, whichever reading mode is set**, so the user is never stuck waiting for Scan all.
- [x] The record returns to Verify in its old place in the queue.
  - Fields on an unchanged page keep their values.
  - A re-read page starts fresh.
- [x] Back to marking stays hidden in batch.
- [ ] *(manual)* **Test:** re-align one page of a pair, queue it, verify, Review & Export, save. The record has both pages, and the unchanged page kept its typed values.
- [ ] **Verify:** Skip record and Reject still work after a re-align. (Their code is unchanged; to confirm in the browser in G3.)

> **Phase E gate:** automated checks pass, then commit and go straight on. *(manual)* browser items to try later (section 6): 7 and 8.

### Phase F: never lose a batch (Option A) (L)

**F1. Server: keep the batch**
- [x] Migration:
  - [x] `scan_batches`: id, user, document type, template (and page-two template), key, status (`open`, `done`, `abandoned`), timestamps.
  - [x] `scan_batch_pages`: batch id, order, source name, source hash, page no, page total, record key, status, `document_page_id`, workspace scan path, alignment JSON (boxes, rows, tilts, geometry), flags (skipped, align check, existing accepted), error, record id, timestamps.
  - [x] `scan_batch_drafts`: batch id, record key, draft JSON (Verify values and ticks, Review & Export cells), updated at.
- [x] Models and relations.
- [x] Endpoints under `documents.batches.*`, each limited to the user's own batches (404 for others):
  - [x] create;
  - [x] show (for resume);
  - [x] update pages (state, alignment, flags);
  - [x] save draft;
  - [x] mark done;
  - [x] abandon.
- [x] A version number on the batch: an update from an older copy (a second tab) is refused with 409, so two tabs cannot overwrite each other. (Also claim, for taking a batch over in another tab, and ping.)
- [x] Saving a record from a batch links it to its batch page(s).
- [x] Pruning (`documents:prune-pages`):
  - [x] keep pages of an **open** batch until the batch passes its own limit (default 7 days, in config);
  - [x] then remove the batch, its pages and their queued originals;
  - [x] remove abandoned batches the same way.
- [x] **Tests (PHP):**
  - [x] create, show and update work only for the batch's owner;
  - [x] an update with an old version is refused;
  - [x] pruning keeps an open batch's pages and removes an expired one with its files;
  - [x] a record saved from a batch links back to it.

**F2. Browser: save as you go**
- [x] Create the server batch when the first files are queued.
- [x] Send each page's state, alignment and flags when they change (small requests, retried on failure, never blocking the UI).
- [x] Save the record's draft a moment after typing stops in Verify or Review & Export.
- [x] Mark the batch done at the summary.
- [x] **Cancel asks:** **Keep for later** (the batch stays open) or **Discard batch** (abandoned).
  - Leaving the page any other way keeps it open.
- [ ] **Verify:** the network tab shows small saves while working, with no noticeable slow-down. (Needs the browser: left for G3.)

**F3. Browser: resume**
- [x] Opening the workspace with an open batch **of this document type** shows a banner: **Resume batch (18 of 40 records saved)** or **Discard**.
  - An open batch of **another** type is mentioned with a link to its workspace.
- [x] Resume rebuilds the queue from the server. Pages already queued or read need no file.
- [x] Files still needed are listed by name and page count. The user re-picks them (files or a folder), and each is matched by **fingerprint**, so names may differ.
  - A picked file that matches nothing is reported.
  - A file not picked leaves its pages waiting, and they can be added later.
- [x] **The layout changed since:** pages already queued keep the layout they were read with.
  - Pending pages use the **current** published layout.
  - If the batch's layout was deleted (archived), its pending pages say so and use the current one, after a confirmation.
- [x] **The same batch open in another tab:** the second tab says so and offers to take over (the first tab then shows "This batch continued in another tab").
- [x] Verify and Review & Export drafts come back on their records.
- [x] Discard abandons the batch on the server.
- [x] Pure helpers: `rebuildBatchState(serverBatch)` and `matchFilesToBatch(files, pages)`.
- [ ] **Tests:**
  - [x] JS: rebuild from a sample server batch; match by fingerprint with renamed files, a missing file and an extra file.
  - [ ] *(manual)*
    - [ ] reload during Align, during Verify (with typed values) and during Review & Export, then resume each;
    - [ ] resume after the layout was changed;
    - [ ] open the batch in two tabs.
- [ ] **Verify:** after a resume, the summary and Download all include records saved before the reload. (Saved records come back with their links and saved tables (rebuildBatchState test); to confirm in the browser in G3.)

> **Phase F gate:** automated checks pass, then commit and go straight on. *(manual)* browser items to try later (section 6): 9 and 10.

### Phase G: finish (S)

- [x] **G1.** Update `docs/MULTI-PAGE-PDF-BATCH-NOTE.md` and the batch section of `docs/DEVELOPER-GUIDE.md`.
- [x] **G2.** Run the full checks, with no new failures compared with the baseline (0.4):
  - [x] `npm run check:icons`
  - [x] `npm run build`
  - [x] `npm run test:js` (166 tests, the same 2 old failures.)
  - [x] `php -d extension=gd vendor/bin/phpunit` (all suites, after 0.5) (362 tests, the same 2 old failures.)
- [ ] **G3.** *(manual)* Final review: run the whole manual checklist (section 6) in the browser, including every *(manual)* box left open above.
- [ ] **G4.** Set this file's status to **done**.

---

## 5. Resume design (Option A), for reference

**Goal:** a reload, a closed tab, a crash or a power cut never loses more than the page being worked on.

| What | Where it lives today | Where it lives after Phase F |
|---|---|---|
| Queue: files, pages, order, state, records | browser memory | `scan_batch_pages` |
| Each page's alignment | browser memory | `scan_batch_pages` (JSON) |
| Queued and read pages | server, pruned after 24 h | kept while the batch is open (up to 7 days) |
| Typed and ticked values | browser memory | `scan_batch_drafts` |
| Original files of pages not queued yet | browser only | **stay on the user's PC**: re-picked on resume, matched by fingerprint |
| Saved records | server | unchanged |

**Why Option A:**
- Nothing extra is uploaded.
- Unsubmitted civil registry scans stay on the user's PC until they are queued.
- The server change is small.

**Cost:** one extra step on resume, and only when files still have pages not queued. Uploading every file up front (Option B) can be added later as a setting, if users need to resume on another computer.

---

## 6. Manual checklist (done once, in G3)

Use a folder of **10 PDFs × 2 pages** and one **PDF of 12 or more pages**.

- [ ] 1. Add the folder: natural order, the left-out window for duplicates, "Add more" mid-queue.
- [ ] 2. Align: every new page opens snapped; a page with faint lines is flagged "Check alignment"; a Smoke-tested page is not snapped over.
- [ ] 3. Background mode: after aligning every page, Verify starts with little or no wait. End mode: Scan all works as before.
- [ ] 4. Make a page fail: the batch continues; the page can be re-aligned or removed.
- [ ] 5. Remove a single page, a page of a pair, and a whole file.
- [ ] 6. Queue panel: filters, page states, jump to page 9 of the 12-page PDF.
- [ ] 7. Verify → Re-align a page → queue it (read at once) → Verify → Review & Export → save.
- [ ] 8. Skip record, Reject, the duplicate "Skip this record", the summary, Download all.
- [ ] 9. (Phase F) Reload in Align, in Verify with typed values, and in Review & Export; resume each. Resume after a layout change. Two tabs.
- [ ] 10. Single Upload still works end to end.
