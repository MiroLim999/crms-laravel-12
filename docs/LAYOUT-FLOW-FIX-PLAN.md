# Layout flow fix plan (Template Builder → Publish → Staff workspace)

Started 2026-11-11 on branch `miro-dev4`. Tick a box (`[x]`) when the task is done and checked.
Item 10 was about keeping "Test on sample" results; instead the whole feature is removed (see 10 below).

Words used here:
- **Main layout**: the layout you open in the Template Builder.
- **Page 2 layout**: the right-hand page of a "Two pages" layout. It is saved as its own hidden layout.

---

## 1. Each layout gets its own page 2 (no more sharing)

Problem: a copy or a new version points at the SAME page 2 as the original, so editing one changes both.
Seen in the real database: layouts 24 and 26 both use page 2 layout 25.

- [x] Add an `is_page_two` yes/no column to `document_templates` (marks hidden page 2 layouts).
- [x] Migration: give every main layout its own page 2 (copy layout 25 for layout 26), mark them `is_page_two`.
      Done on the real `crms`: layout 26 now uses its own copy, layout 28.
- [x] "Duplicate as draft" copies page 2 too (with its fields).
- [x] "Save as new version" copies page 2 too, so the old version keeps its page 2 unchanged.
- [x] Choosing a layout in the "Copy page 2 from" list makes a private copy of it (the chosen one stays as it was).
- [x] Deleting a main layout also deletes its own page 2.
- [x] Tests for all of the above (`tests/Feature/TemplatePublishFlowTest.php`).

## 2. Page 2 layouts are no longer loose in the Template library

- [x] Library hides page 2 layouts; the main layout's row says "Two pages: page 2 is changed inside this layout".
- [x] Server refuses Publish, Edit (sends you to the main layout), Duplicate and Delete on a page 2 layout.
- [x] Page 2 layouts are not offered in the "Copy page 2 from" list.
- [x] Tests.

## 3. A published layout can't be deleted

- [x] Server refuses to delete a published layout ("Unpublish it first").
- [x] Delete window warns when Staff have pages in progress with the layout.
- [x] Tests.

## 4. "Publish for Staff" asks first

- [x] Library Publish button opens a confirm window that lists the publish checks (5–7) before publishing.
- [x] Builder "Save & publish" shows the same checks (and "Save as draft" when something is missing).
- [x] Server runs the same checks, so nothing can skip them. The layout is still saved, as a draft.

## 5. No publishing with default column names

- [x] Publish check: refuse when a column or field is still named "Column N" / "Field N" (also page 2).
- [x] Tests.

## 6. A layout must say which column/field holds the person's name

- [x] Publish check: at least one column or field has "Holds: Name" (page 1 or page 2).
- [x] Tests.

## 7. No publishing without a sample page

- [x] Publish check: the layout needs a stored sample page.
- [x] Tests.

## 8. Detect from sample: follow tilt and stop at the table's bottom

- [x] Server sends `tilt` and `frame_bottom` from the detector to the builder.
- [x] Builder turns the columns to the page's tilt (like Snap does; under 0.1° is no tilt).
- [x] Builder drops row lines found more than half a row below the table's bottom edge (e.g. a signature line).
- [x] Tests (JavaScript). Checked on 16 real sample pages: no real row is dropped.

## 9. Guessed rows are marked and checked before saving

- [x] Detector reports rows it filled in over faint printed lines (`bridged_ys`).
- [x] Builder shows those rows in amber too (and keeps each page's amber marks when switching pages).
- [x] Saving with amber rows asks "N row lines are guesses. Save anyway?"
- [x] Tests (Python + JavaScript).

## 10. "Test on sample" removed

- [x] Builder: the **Test on sample** button and its result window are gone.
- [x] Server: the two test routes, their controller methods and the background job (`TestTemplateLayout`) are deleted.
- [x] Code and styles only it used are deleted (`resources/js/layout-test.js`, the `.layout-test` styles).
- [x] The hourly clean-up deletes any old `template-tests/` folder left on a computer (they hold register pages).
- [x] Its tests are replaced by one that checks the routes are gone and the old folder is cleaned up.
- [x] Docs (`SYSTEM-OVERVIEW.md`, `DEVELOPER-GUIDE.md`) no longer mention it.

## 11. More than one published layout per document type; Staff choose

- [x] Publishing a layout no longer unpublishes the others, except its own older version (a new version replaces the one it came from; a duplicate does not).
- [x] Library gets an "Unpublish (hide from Staff)" item in the ⋮ menu.
- [x] Workspace: when a type has 2+ published layouts, Staff pick one under "Layout" (remembered for next time).
- [x] Every place that asked for "the" published layout handles several (New Document says "2 layouts", workspace, library header).
- [x] Tests.

## Final checks

- [x] PHP tests: 366 pass; the same 6 failures as before these changes (DocumentUploadWorkflow ×2, LedgerTemplateAndExport ×1, MultiPageSource ×3).
- [x] JavaScript: 125 pass, the same 3 old style-check failures. Python: all grid tests pass; the 4 old `fastapi` import errors remain.
- [x] Migration run on the real `crms` database (backup taken first; tried on a copy first; rollback tested).
- [x] Builder, library and workspace checked in a headless browser (nothing saved: every save was blocked).
- [ ] Checked by hand in your own browser.
