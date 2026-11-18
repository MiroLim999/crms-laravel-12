# Multi-page PDFs in batch mode (note for the `gono` branch)

> **Status (miro-dev4, batch improvement plan):** the batch flow was reworked
> as `docs/BATCH-IMPROVEMENT-PLAN.md` describes. In short:
>
> - **Per-page states.** `batchState.phase` is gone. A page's `status`
>   (`pending`, `saving`, `saved`, `scanning`, `ready`, `verifying`, `imported`,
>   `rejected`, `failed`) decides what can be done with it: `pageCanAlign`,
>   `recordState` / `recordReadiness` and `batchSummaryPhase` in
>   `scan-source.js`. In the workspace, `batchAligning()` says whether the page
>   open now is in Align, and `continueBatch()` picks what opens next (a record
>   read, a record still being read, a page to align, or the summary).
> - **Reading.** Scan while I align (default; `crms.scan.batchReading` in the
>   browser) sends each page to the worker as it is queued; pages being read
>   are polled in the background. Scan all at the end keeps `runBatchScanAll`.
>   `documents.pages.queue` takes `read=1` for a Smoke-tested page.
> - **Align.** A new page starts on the template and is snapped
>   (`autoSnapBatchPage`); the next new page is snapped ahead off-screen.
>   `item.alignCheck` flags a page Snap could not fit.
> - **Queue panel and page bar.** `renderBatchQueuePanel` (files, pages,
>   filters from `QUEUE_FILTERS`, totals from `queueCounts`) and
>   `updateBatchPageBar`. Pages are removed one at a time or a file at once
>   (`removeBatchPages`); Add files merges with `appendToQueue`.
> - **Re-align** (`realignBatchPages`) sends a record's page(s) back to Align.
> - **Kept on the server.** `ScanBatchController` (`documents.batches.*`),
>   tables `scan_batches`, `scan_batch_pages`, `scan_batch_drafts`. The
>   workspace sends changed pages (`syncBatchPages`) and drafts
>   (`syncBatchDraft`) one write at a time, each with the batch's version and
>   the tab's name; 409 means another tab took the batch over (`batchLost`).
>   Resume uses `rebuildBatchState` and `matchFilesToBatch`. A record saved
>   from a batch sends `scan_batch_id` and `scan_batch_pages[]`, which links
>   the batch pages to it.

> **Status (review-export-page-creation):** done in `workspace.blade.php`. Batch
> mode now saves pages as single mode does: a page is its own record, or a pair
> (1–2, 3–4) when the layout has a page 2 (`recordLeadPage` in `scan-source.js`).
> A pair is verified side by side as one document (`verifyDoc`). Each file is
> fingerprinted, pages already saved or already queued are left out, and records
> keep `source_*`. Every record goes Verify → Review & Export → Save. A page
> Smoke test went over is queued as it is (`documents.pages.queue`), so Scan all
> only reads it. The batch ends on a summary with **Download all**
> (`combineTables` in `review-table.js`). The plan below is kept for history.

Real LGU scans arrive as one PDF per register book, many pages each. `main` now
reads such a PDF one page at a time in single mode, and saves each page as its
own record. Batch mode on `gono` still opens only page 1 of each PDF. This note
lists what `main` added and the small changes that make batch mode queue one
item per page.

## 1. What `main` added (shared, ready to use)

Run after merging `main` into `gono`:

```
npm install            # adds pdf-lib
php artisan migrate    # adds source_* columns to records
npm run build
```

Browser, `resources/js/scan-source.js` (a Vite entry; import it like the others):

| Export | What it does |
| --- | --- |
| `fileFingerprint(file)` | The file's SHA-256 (64 hex). Works over plain http too. |
| `pdfPageFile(file, n)` | Page `n` (from 1) as a one-page PDF `File`, original quality. pdf-lib loads on first use; a PDF is parsed once. |
| `isPdfFile(file)` | PDF by type or `.pdf` name. |
| `MAX_PDF_BYTES` / `MAX_SCAN_BYTES` | 200 MB to open a PDF / 20 MB for what the server keeps. |
| `firstUnsavedPage`, `nextUnsavedPage`, `pageRanges` | Page choice and "1–3 and 5" text. |

`FieldMarker` (`resources/js/field-marker.js`):

- `marker.load(file, { page: n })` draws page `n` of a PDF. The PDF is kept, so another page only redraws.
- `marker.pdfPageCount(file)` gives the page count without drawing.

Workspace (`resources/views/scan/workspace.blade.php`) state used by Submit:
`scanFile` (the whole PDF), `scanPageNumber`, `scanPageCount`, `scanFingerprint`, `savedPages`.
`submitRecord()` sends the cut page as `scan` (`scanForRecord()`), plus `source_name`,
`source_page`, `source_page_count`, `source_hash`.

Server (`DocumentScanController`):

- `store` accepts the four `source_*` fields and `allow_duplicate`.
- The same page of the same file (hash + page) saved before answers **409**
  `{ duplicate_page: true, message, record: { page, recordId, title, url } }`
  until the request has `allow_duplicate=1`. The audit log then notes `saved_again_after_record`.
- A successful JSON save now also returns `record: { page, recordId, title, url }`.
- `GET documents/sources/{hash}/pages` (route `documents.sources.pages`) lists
  the saved pages of a file: `{ pages: [{ page, recordId, title, url, submittedAt }] }`.
  The workspace has it as `config.sourcePagesUrl` (64 zeros stand in for the hash).

## 2. Changes for batch mode

All in `workspace.blade.php` on `gono`:

1. **`startBatchQueue`**: allow PDFs up to `MAX_PDF_BYTES` (images keep 20 MB). For each PDF,
   `const count = await marker.pdfPageCount(file)` and push **one item per page**:
   `{ file, pageNumber: n, pageCount: count, name: count > 1 ? \`${file.name} · page ${n}\` : file.name, ... }`.
   Work out `item.fingerprint = await fileFingerprint(file)` once per file and give it to all of its items.
2. **Optional, skip saved pages**: fetch `config.sourcePagesUrl.replace('0'.repeat(64), fingerprint)`
   once per file. Leave out or mark the pages it lists.
3. **`openBatchQueueItem`**: `await marker.load(item.file, { page: item.pageNumber ?? 1 })`. Before
   `updateScanNotices()`, set `scanPageNumber = item.pageNumber ?? 1` and `scanPageCount = item.pageCount ?? 1`.
4. **Save to queue** (`saveQueueNextBtn`): it sends `form.set('scan', item.file, …)`. For a page of a
   multi-page PDF, send `await pdfPageFile(item.file, item.pageNumber)` instead, so the whole book is not
   uploaded once per page.
5. **`openBatchVerifyItem`**: besides `scanFile = item.file`, set `scanPageNumber`, `scanPageCount` and
   `scanFingerprint = item.fingerprint`. `submitRecord()` then sends the right page and `source_*`
   fields unchanged.
6. **409 in batch**: `submitRecord()` shows the "Already saved" window (`askToSaveAgain`). In batch you may
   rather mark the item rejected and move on, as `checkAndShowExistingRecord` does for `existing_record`.

## 3. Expect merge conflicts here

- `workspace.blade.php`: `openDocument`, `updateScanNotices`, `submitRecord` (the 409 branch and the success
  branch, which now offers "Next page"), and `config`.
- `DocumentScanController::store`: the validation rules and the block before `pageLinesFor`. Both checks can
  stay. The exact file + page check runs first; `findExistingRecord` (same values) runs after it.

Tests: `tests/Feature/MultiPageSourceTest.php` (server), `tests/JavaScript/scan-source.test.js` (helpers).
