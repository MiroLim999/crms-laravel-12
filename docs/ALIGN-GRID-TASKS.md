# Align Step Grid: Tasks

Rows that follow the page, and rows and columns Staff can add and remove, in the Staff **Align** step (New Document → workspace). Tick each box (`[x]`) when its task is done and checked.

**Branch:** `dashboard-new-layout` (your choice: no separate branch).

**Decisions (2026-10-27):**

1. **One button.** **Snap to table** takes the row lines from the page, the way the Template Builder's **Detect from sample** does. **Detect fields and ink** does the same, unless Staff already changed the rows by hand.
2. **Removing a required column is allowed.** That column is left out of the "missing" check for that page, and the removal is written to the audit log.
3. **The template never changes.** Every change here applies only to the page being scanned.

**Why:** with the template's grid on altered copies of its own sample, Snap kept 20 rows every time:

| Page | Snap keeps | The page has |
| :--- | :--- | :--- |
| The sample as it is | 20 rows | 20 |
| Table 5 rows shorter | 20 rows (5 run below the table) | 15 |
| Table 5 rows longer | 20 rows (the last 5 entries are never read) | 25 |

---

## Phase 1: Python finds the rows on the page

`ml/line_markers.py`

- [x] `detect_grid()` also reports where the table's column lines end (`frame_bottom`), so rows guessed below the last printed line stop at the table.
- [x] New `_rows_from_page()` builds the row lines from the page's printed lines, starting at the grid's first line.
  - [x] Keeps the template's lines where the page lacks printed lines: a faded line at the top or the bottom, or unruled rows inside the table.
  - [x] Falls back to the template's rows, with a reason, when the template's rows differ in height, the page's rows are a different height, or no evenly ruled rows are found.
  - [x] Reports which row lines have no printed line under them (estimated), so the Align step can draw them amber.
- [x] `snap_page()` uses it, so Snap returns the page's rows.
- [x] `detect_page()` uses it unless the geometry says `keep_rows`. It also keeps template rows below the printed table that hold writing.
- [x] The geometry Detect saves lists its estimated row lines (`estimated_rows`).
- [x] Python tests: page with more rows, page with fewer rows, faded top or bottom line, fallback cases, `keep_rows` (9 new in `test_grid_layouts.py`). All 75 Python tests pass.
  - On copies of the real sample: Snap now gives 20 / 15 / 15 / 25 rows, matching each page.

## Phase 2: Laravel

- [x] `GeometryInput` accepts `keep_rows` and passes it on to Python.
- [x] The Snap endpoint returns where the rows came from (page or template, count, estimated lines, reason).
- [x] Submitting checks required columns only against the columns on this page's grid (decision 2).
- [x] Submitting writes the grid changes to the audit log as `grid_changes`: rows compared with the template, and columns added or removed.
- [x] PHP feature tests for all of the above (`LineOutlinePipelineTest`, `DocumentUploadWorkflowTest`).

## Phase 3: The Align step in the browser

- [x] The row lines are drawn in Align from the start (the template's rows) and follow the columns when the table is moved or stretched.
- [x] Each row line has a grip at both ends (`resources/js/row-lines.js`):
  - click it to select the line, drag it to move the line (it snaps to printed lines; <kbd>Alt</kbd> places it freely);
  - <kbd>Shift</kbd>+drag moves every line, and double-clicking a grip removes the line;
  - a selected line moves with the arrow keys and is removed with <kbd>Delete</kbd>.
- [x] A **Ledger grid** card: "11 columns · 25 rows (template: 20)", where the rows came from, **Add row**, **Remove row**, and **Add column** with its name.
- [x] **Add column** splits the selected column in two, or adds one at the right end of the table. A column is removed by selecting it and pressing Delete.
- [x] **Snap to table** applies the page's rows as one undoable step.
- [x] Detect and Scan send the rows as they are on screen (with `keep_rows` when Staff changed them by hand). After Detect, its rows are shown.
- [x] Undo, Reset, and "markers moved after Detect" all include the row lines.
- [x] Verify's "missing" list uses this page's columns.
- [x] JS tests for the new helpers (`ledger-grid.test.js`, `line-geometry.test.js`).

## Phase 4: Check that it works

- [x] `npm run build`, then all test suites pass: 321 PHP, 92 JS and 75 Python tests.
- [x] Browser check in Edge on the sample page's copies (26 checks, no script errors):
  - Snap gives 20, 15, 15 and 25 rows, matching each page, and the Ledger grid card says so.
  - Add and remove a row, drag a row line, double-click and Delete to remove one, and Ctrl+Z all work.
  - Add a named column, remove it with Delete, and a duplicate name is refused.
  - Moving the whole table carries its rows along, and Reset restores the template.
  - Detect on the 25-row page kept 25 rows and outlined 275 lines (25 rows × 11 columns). Changing a row after Detect sets the result aside, and Ctrl+Z brings it back.
  - The test page Detect created was deleted afterwards.
  - Scan → Verify → submit was not run in the browser, so no test record lands in the archive. The submit rules are covered by the PHP tests instead.
- [x] The developer guide's entries for Snap to table, Detect, rows and grid notes are updated (new entry: **Rows and Columns for One Page**).

---

## Notes

- A header band exactly one row tall still looks like a row to any geometry. Staff now see the row lines and can remove the top one.
- The Template Builder's **Test on sample** runs Detect, so it now shows the sample's own rows too, which is what Staff will get.
- Snap to table only places the grid on the printed table. Kraken then finds and crops the writing inside each row and column (on Scan or Detect), and TrOCR reads the crops. Rows inside the table are kept even where a row line is faded or missing (drawn amber), and the grid ends where the table does. Writing below the table with no lines at all (a note under the table, for example) is not in any row. Scan says so ("written lines below the last row"), and **Add row** includes it if it is a real entry.
- Later, possibly: the Template Builder could use the same row-line module (`row-lines.js`) as the Align step.
