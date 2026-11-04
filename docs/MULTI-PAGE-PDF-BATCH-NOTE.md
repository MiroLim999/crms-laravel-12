# Multi-page PDFs in batch mode (note for the `gono` branch)

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
