/**
 * Review & Export: a document's people as one table, a row per person and a
 * column per field, edited like a spreadsheet and downloaded as CSV or Excel.
 *
 * The scanning workspace's fourth step uses it before a record is saved, and
 * a saved record's "Edit in Review & Export" page uses it afterwards. Each
 * page says what a cell is (its fields), what to do when one is picked
 * (show it on the scan) and what to do with a changed value.
 *
 * The parts above ReviewTable are pure, so they are unit-tested without a browser.
 */
import { matchingChoice, valueProblem } from './value-types.js';

// ------------------------------------------------------------- column types

/**
 * How a column is entered and checked, by its name. The first rule whose
 * `matches` is true wins. A column no rule names takes its layout field's own
 * settings from the Template Builder (a date, a number, a list of choices).
 *
 * To give another column its own input or check, add a rule here, e.g.
 *     { matches: (name) => /^civil status$/i.test(name),
 *       type: { input: 'select', options: ['S', 'M', 'W'] } },
 *     { matches: (name) => /date/i.test(name),
 *       type: { input: 'text', settings: { type: 'date' } } },
 */
export const COLUMN_RULES = [
    {
        // The registers write sex as M or F.
        matches: (name) => /^(sex|gender)$/i.test(name.trim()),
        type: { input: 'select', options: ['M', 'F'] },
    },
];

/**
 * A column's check: { input: 'text' | 'select', options?, settings? }. Every
 * cell is typed into; 'select' means its value must be one of `options`
 * (typed "m" is saved as M, anything else is shown as invalid).
 * `settings` is the layout field's own (type, options, required).
 */
export function columnType(name, settings = null, rules = COLUMN_RULES) {
    const rule = rules.find((candidate) => candidate.matches(String(name ?? '')));
    if (rule) return { ...rule.type, settings: { ...(settings ?? {}), ...(rule.type.settings ?? {}) } };
    const options = (settings?.options ?? []).map(String).filter((option) => option.trim() !== '');
    if (settings?.type === 'choice' && options.length > 0) return { input: 'select', options, settings };
    return { input: 'text', settings: settings ?? {} };
}

/** What is wrong with a cell's value, or null: { kind: 'empty' | 'invalid', message }. */
export function cellProblem(value, type) {
    const text = String(value ?? '').trim();
    if (text === '') return { kind: 'empty', message: 'Empty' };
    if (type?.input === 'select') {
        return type.options.includes(text) ? null : { kind: 'invalid', message: `Expected ${listed(type.options)}.` };
    }
    const problem = valueProblem(text, type?.settings ?? {});
    return problem ? { kind: 'invalid', message: problem } : null;
}

const listed = (options) => (options.length <= 2 ? options.join(' or ') : `one of ${options.join(', ')}`);

/** The option a select starts on: the value, or the option it stands for ("male" -> M). */
export function selectStart(value, options) {
    const text = String(value ?? '').trim();
    if (options.includes(text)) return text;
    return matchingChoice(text, options) ?? '';
}

// ------------------------------------------------------------------- model

const nameKey = (name) => String(name ?? '').trim().toLowerCase();

/**
 * The table, from a document's people.
 *
 *   pages:   [{ number, columns: [{ name, settings }], settings? }], one per page
 *            side; `settings` ({ "lower-cased name": settings }) covers fields
 *            that are not grid columns
 *   people:  [{ id, label, fields: [{ side, column, ref }] }]
 *   details: [{ side, column, ref }]  fields of no person (repeated on every row)
 *
 * `ref` is whatever the caller uses to find the field again (Verify: its
 * index in `cropped`; a saved record: the field's id). Several fields of one
 * person in one column (two written lines) are one cell.
 *
 * Returns { columns, rows }:
 *   columns: [{ key, side, name, header, type, detail }]
 *   rows:    [{ id, label, cells: { [key]: { refs: [...] } } }]
 *
 * Headers follow a saved record's CSV: a name page 2 shares with page 1 gets
 * "(page 2)".
 */
export function buildReviewModel({ pages = [], people = [], details = [] }) {
    const columns = [];
    const byKey = new Map();
    const headers = new Set();

    const addColumn = (key, side, name, detail) => {
        if (byKey.has(key)) return byKey.get(key);
        const settings = pages[side]?.columns.find((column) => nameKey(column.name) === nameKey(name))?.settings
            ?? pages[side]?.settings?.[nameKey(name)]
            ?? null;
        let header = String(name);
        if (headers.has(nameKey(header))) header = `${name} (page ${pages[side]?.number ?? side + 1})`;
        headers.add(nameKey(header));
        const column = { key, side, name: String(name), header, type: columnType(name, settings), detail };
        columns.push(column);
        byKey.set(key, column);
        return column;
    };

    // Document details first (a saved record's CSV puts them first too).
    details.forEach((field) => addColumn(`d|${field.side}|${nameKey(field.column)}`, field.side, field.column, true));

    // Each page's columns in the layout's order, then any column only a field has.
    pages.forEach((page, side) => {
        page.columns.forEach((column) => addColumn(`${side}|${nameKey(column.name)}`, side, column.name, false));
        people.forEach((person) => person.fields
            .filter((field) => field.side === side)
            .forEach((field) => addColumn(`${side}|${nameKey(field.column)}`, side, field.column, false)));
    });

    const detailCells = {};
    details.forEach((field) => {
        const key = `d|${field.side}|${nameKey(field.column)}`;
        (detailCells[key] ??= { refs: [] }).refs.push(field.ref);
    });

    const rows = people.map((person) => {
        const cells = {};
        columns.forEach((column) => {
            cells[column.key] = column.detail ? detailCells[column.key] : { refs: [] };
        });
        person.fields.forEach((field) => {
            cells[`${field.side}|${nameKey(field.column)}`].refs.push(field.ref);
        });
        return { id: person.id, label: person.label, cells };
    });

    return { columns, rows };
}

// ------------------------------------------------------------------ export

/** Keep a spreadsheet from running a typed value as a formula (as App\Support\CsvCell). */
export function csvSafe(value) {
    const text = String(value ?? '');
    return /^[=+\-@\t\r]/.test(text) ? `'${text}` : text;
}

/** One CSV cell, quoted when it has to be. */
function csvCell(value) {
    const text = csvSafe(value);
    return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/**
 * The table as CSV: UTF-8 with a byte-order mark, so Excel shows ñ and
 * accents, and Windows line ends. `table` is [header, ...rows].
 */
export function toCsv(table) {
    return `﻿${table.map((row) => row.map(csvCell).join(',')).join('\r\n')}\r\n`;
}

/** The table for write-excel-file: a bold header, every value as text. */
export function toSheetData(table) {
    return table.map((row, index) => row.map((value) => ({
        value: String(value ?? ''),
        type: String,
        ...(index === 0 ? { fontWeight: 'bold' } : {}),
    })));
}

/** "births-2splitpages-2026-11-15", safe for a file name. */
export function exportFileName(...parts) {
    const slug = parts
        .filter((part) => String(part ?? '').trim() !== '')
        .map((part) => String(part).replace(/\.[a-z0-9]{2,4}$/i, ''))
        .join('-')
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    return slug || 'records';
}

/** Hand the browser a file to save. */
export function saveBlob(blob, fileName) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export async function downloadCsv(table, baseName) {
    saveBlob(new Blob([toCsv(table)], { type: 'text/csv;charset=utf-8' }), `${baseName}.csv`);
}

export async function downloadXlsx(table, baseName) {
    // Loaded only when asked for, so the page does not carry it.
    const { default: writeExcelFile } = await import('write-excel-file/browser');
    const widths = table[0].map((_, column) => Math.min(48, Math.max(6,
        ...table.map((row) => String(row[column] ?? '').length + 2))));
    const blob = await writeExcelFile(toSheetData(table), {
        sheet: 'Records',
        stickyRowsCount: 1,
        columns: widths.map((width) => ({ width })),
    }).toBlob();
    saveBlob(blob, `${baseName}.xlsx`);
}

// ------------------------------------------------------- resizable split

export const SPLIT_MIN = 20;
export const SPLIT_MAX = 65;
export const SPLIT_DEFAULT = 30;

export function clampSplit(value, fallback = SPLIT_DEFAULT) {
    if (value === null || value === undefined || value === '') return fallback;
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return fallback;
    // Tenths of a percent: whole percents would make the bar jump under the pointer.
    return Math.min(SPLIT_MAX, Math.max(SPLIT_MIN, Math.round(numeric * 10) / 10));
}

/**
 * Drag, arrow keys or Home/End on `splitter` set how much of `split` the scan
 * takes (CSS --review-scan-width); a double-click puts it back at 30%. The
 * width is remembered in this browser. `onResize` runs after every change.
 */
export function attachSplit({ split, splitter, storageKey = 'crms.review.scan-width', onResize = () => {} }) {
    let percentage = SPLIT_DEFAULT;
    let dragging = false;
    // Where on the bar it was grabbed, so it does not jump to the pointer.
    let grab = 0;
    try {
        percentage = clampSplit(window.localStorage.getItem(storageKey));
    } catch {
        // Storage may be blocked; the split still works for this visit.
    }

    const update = (next, persist = false) => {
        percentage = clampSplit(next);
        split.style.setProperty('--review-scan-width', `${percentage}%`);
        splitter.setAttribute('aria-valuenow', String(Math.round(percentage)));
        splitter.setAttribute('aria-valuetext', `Scan ${Math.round(percentage)}%, table ${100 - Math.round(percentage)}%`);
        onResize();
        if (!persist) return;
        try {
            window.localStorage.setItem(storageKey, String(percentage));
        } catch {
            // Not remembered; nothing else depends on it.
        }
    };
    const fromPointer = (event) => {
        const bounds = split.getBoundingClientRect();
        if (bounds.width > 0) update(((event.clientX - grab - bounds.left) / bounds.width) * 100);
    };

    // A second press soon after the first, in the same place: back to 30%.
    // (pointerdown's preventDefault keeps the browser from sending dblclick.)
    let lastPress = { time: 0, x: 0 };
    splitter.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) return;
        event.preventDefault();
        const now = event.timeStamp;
        if (now - lastPress.time < 400 && Math.abs(event.clientX - lastPress.x) < 6) {
            lastPress = { time: 0, x: 0 };
            update(SPLIT_DEFAULT, true);
            return;
        }
        lastPress = { time: now, x: event.clientX };
        dragging = true;
        grab = event.clientX - splitter.getBoundingClientRect().left;
        splitter.setPointerCapture?.(event.pointerId);
        split.classList.add('is-resizing');
    });
    splitter.addEventListener('pointermove', (event) => {
        if (dragging) fromPointer(event);
    });
    const finish = (event) => {
        if (!dragging) return;
        dragging = false;
        split.classList.remove('is-resizing');
        if (splitter.hasPointerCapture?.(event.pointerId)) splitter.releasePointerCapture(event.pointerId);
        update(percentage, true);
    };
    splitter.addEventListener('pointerup', finish);
    splitter.addEventListener('pointercancel', finish);
    splitter.addEventListener('keydown', (event) => {
        const step = event.shiftKey ? 5 : 2;
        const next = {
            ArrowLeft: percentage - step,
            ArrowRight: percentage + step,
            Home: SPLIT_MIN,
            End: SPLIT_MAX,
        }[event.key];
        if (next === undefined) return;
        event.preventDefault();
        update(next, true);
    });

    update(percentage);
    return { reset: () => update(SPLIT_DEFAULT, true) };
}

// ------------------------------------------------------------- the table

/** The cell's place after a key: Enter goes down, Tab goes right (Shift: back). */
export function nextCellPosition({ row, column }, key, shift, rowCount, columnCount) {
    if (rowCount === 0 || columnCount === 0) return { row: 0, column: 0 };
    if (key === 'Tab') {
        let index = row * columnCount + column + (shift ? -1 : 1);
        index = Math.min(rowCount * columnCount - 1, Math.max(0, index));
        return { row: Math.floor(index / columnCount), column: index % columnCount };
    }
    const moves = {
        Enter: [shift ? -1 : 1, 0],
        ArrowDown: [1, 0],
        ArrowUp: [-1, 0],
        ArrowRight: [0, 1],
        ArrowLeft: [0, -1],
    };
    const [down, right] = moves[key] ?? [0, 0];
    return {
        row: Math.min(rowCount - 1, Math.max(0, row + down)),
        column: Math.min(columnCount - 1, Math.max(0, column + right)),
    };
}

/**
 * Clipboard text as rows of cells: lines are rows, tabs split cells (an
 * Excel block). One closing line break is ignored; no text gives no rows.
 */
export function parseClipboard(text) {
    const clean = String(text ?? '').replace(/\r\n?/g, '\n').replace(/\n$/, '');
    if (clean === '') return [];
    return clean.split('\n').map((line) => line.split('\t').map((cell) => cell.trim()));
}

/** The rows from `anchor` to `focus`, top first. */
export function rowSpan(anchor, focus) {
    const top = Math.min(anchor, focus);
    return Array.from({ length: Math.abs(focus - anchor) + 1 }, (_, step) => top + step);
}

// Columns are never narrower than this, and size themselves to their longest value up to this.
const MIN_COLUMN_WIDTH = 44;
const MAX_AUTO_WIDTH = 420;

/**
 * The table on the page.
 *
 *   container       where the table goes (it scrolls; the header row sticks)
 *   valueOf(cell)   a cell's value now ({ refs, row, column })
 *   onChange(cell, value)  a cell was confirmed with a new value
 *   onLocate(cell)  a cell was picked: show it on the scan
 *   onIssues({ invalid, empty })  the issue counts changed
 *   snapshotOf(cell)  what else a change alters, kept so Ctrl+Z can put it
 *                   back (Verify: each field's Verified tick); optional
 *   restore(cell, snapshot)  put that back after the value (optional)
 *   onCleared(refs)  the fields whose cell the reviewer emptied (for the scan)
 *   onRange(cells)  the cells picked together changed ([] when one or none);
 *                   the page shows them on the scan (optional)
 *
 * The picked cell (blue) and its row (orange) are marked is-active. Several
 * cells of one column can be picked together, by dragging down or up it, by
 * Shift+click, or by Shift+Up/Down: typing or Enter then edits them all at
 * once (starting from the top one's value), Delete empties them. A header
 * or row number double-clicked can be renamed: only the downloads use the
 * new name. Ctrl+Z (Cmd+Z) takes back the last change of any of these, and
 * Ctrl+Y (Cmd+Shift+Z, Ctrl+Shift+Z) does it again.
 */
export class ReviewTable {
    constructor({
        container, valueOf, onChange, onLocate = () => {}, onIssues = () => {},
        snapshotOf = () => null, restore = () => {}, onRange = () => {}, onCleared = () => {},
    }) {
        this.onRange = onRange;
        this.onCleared = onCleared;
        this.container = container;
        this.valueOf = valueOf;
        this.onChange = onChange;
        this.snapshotOf = snapshotOf;
        this.restore = restore;
        this.onLocate = onLocate;
        this.onIssues = onIssues;
        this.model = { columns: [], rows: [] };
        this.active = null;
        // Several cells of the active cell's column: { column, anchor, focus } rows.
        this.range = null;
        this.editor = null;
        this.drag = null;
        // Changes Ctrl+Z takes back, newest last, and those Ctrl+Y does again:
        //   { cells: [{ rowId, columnKey, before, after, snapshot }] }
        //   { label: 'header' | 'row', key, before, after }   (names; null = the original)
        this.history = [];
        this.redoList = [];
        // Cells the reviewer emptied (Delete, cut, typing nothing): shown red, not as never read.
        this.cleared = new Set();
        // Column widths the reviewer set (dragged or double-clicked), in pixels ('row' is
        // the Row column). Any other column is as wide as its longest value.
        this.widths = new Map();
        // Names typed over a header or a row number, for the downloads.
        this.headerNames = new Map();
        this.rowNames = new Map();

        container.addEventListener('pointerdown', (event) => this._pointerDown(event));
        container.addEventListener('keydown', (event) => this._keyDown(event));
        container.addEventListener('dblclick', (event) => this._doubleClick(event));
        container.addEventListener('copy', (event) => this._copy(event, false));
        container.addEventListener('cut', (event) => this._copy(event, true));
        container.addEventListener('paste', (event) => this._paste(event));
    }

    /** Ctrl+C / Ctrl+X on picked cells: their values, one per line (cut also empties them). */
    _copy(event, cut) {
        if (!this.active || this.editor || event.target instanceof HTMLInputElement) return;
        const { column } = this.active;
        const rows = this.selectedRows();
        const text = rows
            .map((row) => String(this.valueOf(this.cell(row, column)) ?? '').replace(/[\t\r\n]+/g, ' '))
            .join('\n');
        event.clipboardData?.setData('text/plain', text);
        event.preventDefault();
        if (cut) this._apply(rows.map((row) => ({ row, column, value: '' })));
        // The copied cells are marked (a dashed frame) until Esc, a change or the next copy.
        this._showCopied({ column, rows });
    }

    /** Mark the copied cells (`copied`: { column, rows }), or clear the mark (null). */
    _showCopied(copied) {
        this.container.querySelectorAll('.review-table__cell.is-copied').forEach((node) => node.classList.remove('is-copied', 'is-copied-top', 'is-copied-bottom'));
        this.copied = copied;
        if (!copied) return;
        const top = Math.min(...copied.rows);
        const bottom = Math.max(...copied.rows);
        copied.rows.forEach((row) => {
            const td = this.cellNodes[row]?.[copied.column];
            if (!td) return;
            td.classList.add('is-copied');
            td.classList.toggle('is-copied-top', row === top);
            td.classList.toggle('is-copied-bottom', row === bottom);
        });
    }

    /**
     * Ctrl+V: one value goes into every picked cell; several lines (or an
     * Excel block, tab-separated) fill down and across from the top picked
     * cell, as far as the table goes. One change for Ctrl+Z.
     */
    _paste(event) {
        if (!this.active || this.editor || event.target instanceof HTMLInputElement) return;
        const grid = parseClipboard(event.clipboardData?.getData('text/plain') ?? '');
        event.preventDefault();
        if (grid.length === 0) return;

        const picked = this.selectedRows();
        const startRow = picked[0];
        const startColumn = this.active.column;
        const changes = [];
        if (grid.length === 1 && grid[0].length === 1) {
            picked.forEach((row) => changes.push({ row, column: startColumn, value: this._typed(startColumn, grid[0][0]) }));
        } else {
            grid.forEach((cells, down) => cells.forEach((value, across) => {
                const row = startRow + down;
                const column = startColumn + across;
                if (row < this.model.rows.length && column < this.model.columns.length) {
                    changes.push({ row, column, value: this._typed(column, value) });
                }
            }));
        }
        if (this._apply(changes) === 0 || changes.length === 0) return;

        // The cells it filled stay picked, to see them (down the first column).
        const last = Math.max(...changes.filter((change) => change.column === startColumn).map((change) => change.row));
        this.select(startRow, startColumn, { locate: false });
        if (last > startRow) this._extendTo(last);
    }

    /** A pasted or typed value for a column: a choice column takes "f" as F, anything else as it is. */
    _typed(columnIndex, text) {
        const options = this.model.columns[columnIndex]?.type.options;
        const value = String(text).slice(0, 2000);
        return options ? (selectStart(value, options) || value) : value;
    }

    /** A new document: renamed labels and the undo list are dropped. */
    reset() {
        this._closeEditor(false);
        this.headerNames.clear();
        this.rowNames.clear();
        this.history = [];
        this.redoList = [];
        this.cleared.clear();
        this.widths.clear();
        this.range = null;
        this.onCleared([]);
    }

    /**
     * Ctrl+Z / Ctrl+Y (Cmd+Z / Cmd+Shift+Z on a Mac) pressed outside the table,
     * e.g. after a click on the scan: the table's undo, unless a text box has
     * the keys (it undoes its own typing). `isActive()` says when the table is
     * shown. Returns a function that stops listening.
     */
    listenForUndoKeys(isActive = () => true, target = document) {
        const listener = (event) => {
            if (!isActive() || this.container.contains(event.target)) return;
            const typing = event.target instanceof Element
                && event.target.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]');
            if (typing || document.querySelector('.modal.show')) return;
            this._undoKey(event);
        };
        target.addEventListener('keydown', listener);
        return () => target.removeEventListener('keydown', listener);
    }

    /** Ctrl+Z: undo; Ctrl+Y or Ctrl+Shift+Z: redo (Cmd on a Mac). True when the key was one of them. */
    _undoKey(event) {
        if (!(event.ctrlKey || event.metaKey) || event.altKey) return false;
        const key = event.key.toLowerCase();
        const z = key === 'z' || event.code === 'KeyZ';
        const y = key === 'y' || event.code === 'KeyY';
        if (!z && !y) return false;
        event.preventDefault();
        event.stopPropagation();
        this._closeEditor(true);
        if (z && !event.shiftKey) this.undo();
        else this.redo();
        return true;
    }

    /** A column's header as shown and downloaded (renamed or not). */
    headerOf(column) {
        return this.headerNames.get(column.key) ?? column.header;
    }

    /** A row's number as shown and downloaded (renamed or not). */
    rowLabelOf(row) {
        return this.rowNames.get(row.id) ?? row.label;
    }

    /** Draw the table for `model` (see buildReviewModel); keeps the picked cell where it can. */
    render(model) {
        const keep = this.active;
        this._closeEditor(false);
        this.model = model;
        this.cellNodes = [];
        this.range = null;
        this.copied = null;

        const table = document.createElement('table');
        table.className = 'review-table';
        table.setAttribute('role', 'grid');
        table.setAttribute('aria-rowcount', String(model.rows.length + 1));

        const head = table.createTHead().insertRow();
        const corner = document.createElement('th');
        corner.scope = 'col';
        corner.className = 'review-table__row-head';
        corner.dataset.sizeKey = 'row';
        const cornerLabel = document.createElement('span');
        cornerLabel.className = 'review-table__label';
        cornerLabel.textContent = 'Row';
        corner.append(cornerLabel, this._resizer());
        head.appendChild(corner);
        this.headerNodes = model.columns.map((column, columnIndex) => {
            const th = document.createElement('th');
            th.scope = 'col';
            th.dataset.column = String(columnIndex);
            th.dataset.sizeKey = column.key;
            if (column.type.input === 'select') th.dataset.choices = column.type.options.join(' / ');
            const label = document.createElement('span');
            label.className = 'review-table__label';
            th.append(label, this._resizer());
            head.appendChild(th);
            return th;
        });

        const body = table.createTBody();
        this.rowHeadNodes = [];
        model.rows.forEach((row, rowIndex) => {
            const tr = body.insertRow();
            tr.dataset.row = String(rowIndex);
            const label = document.createElement('th');
            label.scope = 'row';
            label.className = 'review-table__row-head';
            tr.appendChild(label);
            this.rowHeadNodes.push(label);
            const nodes = model.columns.map((column, columnIndex) => {
                const td = tr.insertCell();
                td.className = 'review-table__cell';
                td.tabIndex = -1;
                td.dataset.row = String(rowIndex);
                td.dataset.column = String(columnIndex);
                td.setAttribute('role', 'gridcell');
                if (column.type.input === 'select') td.classList.add('is-choice');
                return td;
            });
            this.cellNodes.push(nodes);
        });

        this.container.replaceChildren(table);
        this._paintLabels();
        this._sizeColumns(table);
        this.refresh();
        if (keep && keep.row < model.rows.length && keep.column < model.columns.length) {
            this._mark(keep.row, keep.column);
        } else {
            this.active = null;
        }
    }

    /** A cell's data: { refs, row, column }. */
    cell(row, column) {
        const rowData = this.model.rows[row];
        const columnData = this.model.columns[column];
        if (!rowData || !columnData) return null;
        return { refs: rowData.cells[columnData.key]?.refs ?? [], row: rowData, column: columnData };
    }

    /** Show every cell's value and problem again (after a change made elsewhere). */
    refresh() {
        let invalid = 0;
        let empty = 0;
        this.model.rows.forEach((_, rowIndex) => this.model.columns.forEach((column, columnIndex) => {
            const problem = this._paint(rowIndex, columnIndex);
            if (problem?.kind === 'invalid') invalid += 1;
            if (problem?.kind === 'empty') empty += 1;
        }));
        this.issueCounts = { invalid, empty };
        this.onIssues(this.issueCounts);
        this._reportCleared();
        this._autoSizeColumns();
    }

    /** Tell the page which fields hold an emptied cell, to mark them on the scan. */
    _reportCleared() {
        const refs = [];
        this.model.rows.forEach((row) => this.model.columns.forEach((column) => {
            if (this.cleared.has(`${row.id}|${column.key}`)) refs.push(...(row.cells[column.key]?.refs ?? []));
        }));
        this.onCleared(refs);
    }

    _paint(rowIndex, columnIndex) {
        const td = this.cellNodes[rowIndex]?.[columnIndex];
        const cell = this.cell(rowIndex, columnIndex);
        if (!td || !cell) return null;
        const value = String(this.valueOf(cell) ?? '');
        const problem = cellProblem(value, cell.column.type);
        // A cell being typed in keeps its box; it is drawn again once closed.
        if (td.classList.contains('is-editing')) return problem;
        td.textContent = value;
        td.classList.toggle('is-empty', problem?.kind === 'empty');
        td.classList.toggle('is-cleared', problem?.kind === 'empty' && this.cleared.has(`${cell.row.id}|${cell.column.key}`));
        td.classList.toggle('is-invalid', problem?.kind === 'invalid');
        td.title = problem?.message ?? '';
        td.setAttribute('aria-invalid', String(Boolean(problem)));
        return problem;
    }

    _paintLabels() {
        this.model.columns.forEach((column, columnIndex) => {
            const th = this.headerNodes[columnIndex];
            if (!th || th.classList.contains('is-renaming')) return;
            const shown = this.headerOf(column);
            const renamed = shown !== column.header;
            this._labelOf(th).textContent = shown;
            th.classList.toggle('is-renamed', renamed);
            th.title = renamed
                ? `${shown} (was ${column.header}). Double-click to rename it in the downloads.`
                : `${shown}. Double-click to rename it in the downloads.`;
        });
        this.model.rows.forEach((row, rowIndex) => {
            const th = this.rowHeadNodes[rowIndex];
            if (!th || th.classList.contains('is-renaming')) return;
            const shown = this.rowLabelOf(row);
            const renamed = shown !== row.label;
            th.textContent = shown;
            th.classList.toggle('is-renamed', renamed);
            th.title = renamed
                ? `Row ${shown} (was ${row.label}). Double-click to change it in the downloads.`
                : 'Double-click to change this row number in the downloads.';
        });
    }

    /** A header's label span (made again when a rename box replaced it). */
    _labelOf(th) {
        let label = th.querySelector(':scope > .review-table__label');
        if (!label) {
            label = document.createElement('span');
            label.className = 'review-table__label';
            th.replaceChildren(label, this._resizer());
        }
        return label;
    }

    /** The grip on a header's right edge: drag to resize, double-click to fit. */
    _resizer() {
        const grip = document.createElement('span');
        grip.className = 'review-table__resizer';
        grip.setAttribute('aria-hidden', 'true');
        grip.title = 'Drag to resize, double-click to fit';
        return grip;
    }

    /**
     * Fix every column's width (as it came out, or as it was dragged before),
     * so one can be resized without the others moving.
     */
    _sizeColumns(table) {
        const heads = [...table.tHead.rows[0].cells];
        // Not on screen (nothing to measure): the browser's own layout stays.
        if (heads.every((th) => th.getBoundingClientRect().width === 0)) return;
        const group = document.createElement('colgroup');
        heads.forEach((th) => {
            const col = document.createElement('col');
            const width = this.widths.get(th.dataset.sizeKey) ?? this._contentWidth(th);
            col.style.width = `${width}px`;
            col.dataset.sizeKey = th.dataset.sizeKey;
            group.appendChild(col);
        });
        table.prepend(group);
        table.classList.add('is-sized');
        this._fitTable();
    }

    /**
     * The width a column needs for its longest value (and its header), from the
     * values the table holds, within a limit; the Row column's is the row numbers.
     */
    _contentWidth(th, unlimited = false) {
        const key = th.dataset.sizeKey;
        const probe = this.cellNodes?.[0]?.[0] ?? th;
        this._measureContext ??= document.createElement('canvas').getContext('2d');
        const context = this._measureContext;
        const widthOf = (text, node) => {
            context.font = getComputedStyle(node).font;
            return context.measureText(String(text ?? '')).width;
        };
        let widest;
        if (key === 'row') {
            widest = Math.max(widthOf(th.textContent, th), ...this.model.rows.map((row) => widthOf(this.rowLabelOf(row), th)));
        } else {
            const columnIndex = Number(th.dataset.column);
            const column = this.model.columns[columnIndex];
            widest = Math.max(
                widthOf(column ? this.headerOf(column) : th.textContent, th) + 8,
                ...this.model.rows.map((_, rowIndex) => widthOf(this.valueOf(this.cell(rowIndex, columnIndex)), probe)),
            );
        }
        // Text, the cell's padding either side, and the border.
        return Math.round(Math.min(unlimited ? 900 : MAX_AUTO_WIDTH, Math.max(MIN_COLUMN_WIDTH, widest + 2 * 8.8 + 3)));
    }

    /** Columns the reviewer has not sized follow their longest value as it changes. */
    _autoSizeColumns() {
        const table = this.container.querySelector('table');
        if (!table?.classList.contains('is-sized') || this.editor) return;
        [...table.tHead.rows[0].cells].forEach((th) => {
            const key = th.dataset.sizeKey;
            if (this.widths.has(key)) return;
            const col = table.querySelector(`col[data-size-key="${CSS.escape(key)}"]`);
            if (col) col.style.width = `${this._contentWidth(th)}px`;
        });
        this._fitTable();
    }

    /** The table as wide as its columns. */
    _fitTable() {
        const table = this.container.querySelector('table');
        if (!table?.classList.contains('is-sized')) return;
        const total = [...table.querySelectorAll('col')].reduce((sum, col) => sum + parseFloat(col.style.width), 0);
        table.style.width = `${total}px`;
    }

    /** Set a column's width (px), at least a few characters. */
    _setWidth(key, width) {
        const next = Math.round(Math.min(900, Math.max(MIN_COLUMN_WIDTH, width)));
        this.widths.set(key, next);
        const col = this.container.querySelector(`col[data-size-key="${CSS.escape(key)}"]`);
        if (col) col.style.width = `${next}px`;
        this._fitTable();
    }

    _resizeStart(event, grip) {
        const th = grip.closest('th');
        const key = th?.dataset.sizeKey;
        if (key === undefined) return;
        event.preventDefault();
        event.stopPropagation();
        this._closeEditor(true);
        const startX = event.clientX;
        const startWidth = th.getBoundingClientRect().width;
        document.body.classList.add('is-resizing-column');
        const move = (moveEvent) => this._setWidth(key, startWidth + moveEvent.clientX - startX);
        const end = () => {
            document.body.classList.remove('is-resizing-column');
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', end);
            document.removeEventListener('pointercancel', end);
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', end);
        document.addEventListener('pointercancel', end);
    }

    /** Fit a column to its longest value (double-click on its edge). */
    _fitColumn(grip) {
        const th = grip.closest('th');
        const key = th?.dataset.sizeKey;
        if (key === undefined) return;
        // Even a long value: a double-click shows all of it (the limit is for the automatic width).
        this._setWidth(key, this._contentWidth(th, true));
    }

    /** Pick the cell holding `ref` (a field picked on the scan); no locate, no edit. */
    selectRef(ref) {
        for (let row = 0; row < this.model.rows.length; row += 1) {
            for (let column = 0; column < this.model.columns.length; column += 1) {
                if (this.cell(row, column).refs.includes(ref)) {
                    this._closeEditor(true);
                    this.range = null;
                    this._mark(row, column);
                    this._scrollTo(row, column);
                    return true;
                }
            }
        }
        return false;
    }

    /** Pick the next empty or invalid cell after the picked one (wraps round). */
    nextIssue() {
        const rows = this.model.rows.length;
        const columns = this.model.columns.length;
        const start = this.active ? this.active.row * columns + this.active.column + 1 : 0;
        for (let step = 0; step < rows * columns; step += 1) {
            const index = (start + step) % (rows * columns);
            const row = Math.floor(index / columns);
            const column = index % columns;
            const cell = this.cell(row, column);
            if (cellProblem(this.valueOf(cell), cell.column.type)) {
                this.select(row, column);
                return true;
            }
        }
        return false;
    }

    /** Pick a cell and show it on the scan; `edit` opens it for typing. */
    select(row, column, { edit = false, locate = true } = {}) {
        this._closeEditor(true);
        this.range = null;
        if (!this._mark(row, column)) return;
        this._scrollTo(row, column);
        if (locate) this.onLocate(this.cell(row, column));
        if (edit) this.edit();
        else this.cellNodes[row][column].focus({ preventScroll: true });
    }

    /** The picked rows of the active cell's column, top first (just the active one when one is picked). */
    selectedRows() {
        if (!this.active) return [];
        const range = this.range;
        if (!range || range.column !== this.active.column) return [this.active.row];
        return rowSpan(range.anchor, range.focus);
    }

    /** Pick from the active cell down or up its column to `row`. */
    _extendTo(row) {
        if (!this.active) return;
        const focus = Math.min(this.model.rows.length - 1, Math.max(0, row));
        this.range = focus === this.active.row ? null : { column: this.active.column, anchor: this.active.row, focus };
        this._showRange();
        this._scrollTo(focus, this.active.column);
    }

    _showRange() {
        const rows = new Set(this.selectedRows());
        const column = this.active?.column;
        this.container.querySelectorAll('.review-table__cell.is-selected').forEach((node) => node.classList.remove('is-selected'));
        if (rows.size > 1) {
            rows.forEach((row) => this.cellNodes[row]?.[column]?.classList.add('is-selected'));
        }
        this.onRange(rows.size > 1 ? [...rows].map((row) => this.cell(row, column)).filter(Boolean) : []);
    }

    _mark(row, column) {
        const td = this.cellNodes[row]?.[column];
        if (!td) return false;
        this.container.querySelectorAll('.review-table__cell.is-active').forEach((node) => node.classList.remove('is-active'));
        this.container.querySelectorAll('tr.is-active').forEach((node) => node.classList.remove('is-active'));
        td.classList.add('is-active');
        td.parentElement?.classList.add('is-active');
        this.active = { row, column };
        this._showRange();
        return true;
    }

    _scrollTo(row, column) {
        const td = this.cellNodes[row]?.[column];
        if (!td) return;
        // Clear of the sticky header and Row column.
        const head = this.container.querySelector('thead');
        const rowHead = td.parentElement?.querySelector('.review-table__row-head');
        const top = td.offsetTop - (head?.offsetHeight ?? 0);
        const bottom = td.offsetTop + td.offsetHeight;
        const left = td.offsetLeft - (rowHead?.offsetWidth ?? 0);
        const right = td.offsetLeft + td.offsetWidth;
        const box = this.container;
        if (top < box.scrollTop) box.scrollTop = top;
        else if (bottom > box.scrollTop + box.clientHeight) box.scrollTop = bottom - box.clientHeight;
        if (left < box.scrollLeft) box.scrollLeft = left;
        else if (right > box.scrollLeft + box.clientWidth) box.scrollLeft = right - box.clientWidth;
    }

    /**
     * Open the picked cell for typing; `typed` starts it with that text (a key
     * pressed on it). With several cells picked, the box opens on the top one,
     * starting from its value, and what is confirmed goes into every one.
     */
    edit(typed = null) {
        if (!this.active || this.editor) return;
        const rows = this.selectedRows();
        const row = rows[0];
        const { column } = this.active;
        const td = this.cellNodes[row][column];
        const cell = this.cell(row, column);
        const original = String(this.valueOf(cell) ?? '');
        const type = cell.column.type;
        // Every column is typed into, choices too (Sex): a typed value that
        // stands for one of them is saved as it ("m" -> M), and any other is
        // shown as invalid, as a value read wrong is.
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm review-table__editor';
        input.maxLength = 2000;
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.value = typed ?? original;
        input.setAttribute('aria-label', rows.length > 1
            ? `${rows.length} cells of ${this.headerOf(cell.column)}`
            : `${this.rowLabelOf(cell.row)}, ${this.headerOf(cell.column)}`);
        input.addEventListener('blur', () => {
            // Clicking elsewhere confirms, as in a spreadsheet.
            window.setTimeout(() => {
                if (this.editor?.input === input && document.activeElement !== input) this._closeEditor(true);
            }, 0);
        });

        // Several cells picked: what is typed shows in all of them as it is typed
        // (Enter keeps it in every one, Esc puts them back).
        if (rows.length > 1) {
            input.addEventListener('input', () => {
                rows.slice(1).forEach((picked) => {
                    const other = this.cellNodes[picked]?.[column];
                    if (other) other.textContent = input.value;
                });
            });
        }

        this.editor = { input, row, column, rows, original };
        // The box fills the cell as it is: the column and row do not grow.
        const size = td.getBoundingClientRect();
        td.style.inlineSize = td.style.minInlineSize = td.style.maxInlineSize = `${size.width}px`;
        td.style.blockSize = `${size.height}px`;
        td.classList.add('is-editing');
        td.replaceChildren(input);
        input.focus({ preventScroll: true });
        const end = input.value.length;
        input.setSelectionRange(end, end);
    }

    /** Keep what is being typed (before a save, an export or leaving the table). */
    commit() {
        this._closeEditor(true);
    }

    /** Close the editor: `commit` keeps the typed value (in every picked cell), otherwise it is dropped. */
    _closeEditor(commit) {
        const editor = this.editor;
        if (!editor) return;
        this.editor = null;
        const td = this.cellNodes[editor.row]?.[editor.column];
        td?.classList.remove('is-editing');
        ['inline-size', 'min-inline-size', 'max-inline-size', 'block-size'].forEach((name) => td?.style.removeProperty(name));
        const options = this.model.columns[editor.column]?.type.options;
        const typed = editor.input.value;
        const value = options ? (selectStart(typed, options) || typed) : typed;
        // One cell: changed only when its value was. Several: each that differs.
        if (commit && (editor.rows.length > 1 || value !== editor.original)) {
            this._apply(editor.rows.map((row) => ({ row, column: editor.column, value })));
        }
        this.refresh();
    }

    /** Set cells ([{ row, column, value }]) as one change Ctrl+Z can take back. */
    _apply(changes) {
        const cells = [];
        changes.forEach(({ row, column, value }) => {
            const cell = this.cell(row, column);
            if (!cell) return;
            const before = String(this.valueOf(cell) ?? '');
            if (before === value) return;
            cells.push({ rowId: cell.row.id, columnKey: cell.column.key, before, after: value, snapshot: this.snapshotOf(cell) });
            this._markCleared(cell, value, before);
            this.onChange(cell, value);
        });
        if (cells.length > 0) this._remember({ cells });
        // A change ends the "copied" mark, except the cut that makes it (set again by _copy).
        if (cells.length > 0) this._showCopied(null);
        this.refresh();
        return cells.length;
    }

    /** A cell emptied by the reviewer is remembered (red); one given a value, or that was empty already, is not. */
    _markCleared(cell, value, before) {
        const key = `${cell.row.id}|${cell.column.key}`;
        if (String(value).trim() === '' && String(before).trim() !== '') this.cleared.add(key);
        else this.cleared.delete(key);
    }

    /** A new change: Ctrl+Z can take it back, and what was undone can no longer be done again. */
    _remember(change) {
        this.history.push(change);
        if (this.history.length > 200) this.history.shift();
        this.redoList = [];
    }

    /** Take back the last change: a cell, every cell of an edit of several, or a renamed label. */
    undo() {
        const change = this.history.pop();
        if (!change) return false;
        this._replay(change, false);
        this.redoList.push(change);
        return true;
    }

    /** Make the last undone change again. */
    redo() {
        const change = this.redoList.pop();
        if (!change) return false;
        this._replay(change, true);
        this.history.push(change);
        return true;
    }

    /** Put a change's cells or label back as they were (`forward` false), or as it made them. */
    _replay(change, forward) {
        if (change.label) {
            const names = change.label === 'header' ? this.headerNames : this.rowNames;
            const name = forward ? change.after : change.before;
            if (name === null) names.delete(change.key);
            else names.set(change.key, name);
            this._paintLabels();
            return;
        }

        const places = [];
        change.cells.forEach((entry) => {
            const row = this.model.rows.findIndex((candidate) => candidate.id === entry.rowId);
            const column = this.model.columns.findIndex((candidate) => candidate.key === entry.columnKey);
            if (row < 0 || column < 0) return;
            const cell = this.cell(row, column);
            if (forward) {
                // What the redo alters is kept again, for the next Ctrl+Z.
                entry.snapshot = this.snapshotOf(cell);
                this._markCleared(cell, entry.after, entry.before);
                this.onChange(cell, entry.after);
            } else {
                this.cleared.delete(`${cell.row.id}|${cell.column.key}`);
                this.onChange(cell, entry.before);
                this.restore(cell, entry.snapshot);
            }
            places.push({ row, column });
        });
        this.refresh();
        if (places.length > 0) {
            // The cells it touched are picked again, to see them.
            const rows = places.map((place) => place.row);
            const top = Math.min(...rows);
            const bottom = Math.max(...rows);
            this.select(top, places[0].column, { locate: false });
            if (bottom > top && places.every((place) => place.column === places[0].column)) this._extendTo(bottom);
        }
    }

    _pointerDown(event) {
        if (event.button !== 0) return;
        const grip = event.target instanceof Element ? event.target.closest('.review-table__resizer') : null;
        if (grip) {
            this._resizeStart(event, grip);
            return;
        }
        const td = event.target instanceof Element ? event.target.closest('.review-table__cell') : null;
        if (!td) return;
        const row = Number(td.dataset.row);
        const column = Number(td.dataset.column);

        // In the open typing box a press places the cursor as usual; dragged out of
        // it down or up the column, the box is confirmed and the cells are picked.
        if (td.classList.contains('is-editing')) {
            this._followDrag(row, column, false);
            return;
        }
        event.preventDefault();

        // Shift+click down or up the picked cell's column: those cells too.
        if (event.shiftKey && this.active && this.active.column === column) {
            this._closeEditor(true);
            this._extendTo(row);
            this.cellNodes[this.active.row][column].focus({ preventScroll: true });
            return;
        }

        this.select(row, column);
        // A click opens the cell for typing; a drag down or up the column picks cells.
        this._followDrag(row, column, true);
    }

    /** Follow the pointer until it is let go: down or up the column picks cells; `openOnClick` types on a plain click. */
    _followDrag(row, column, openOnClick) {
        const drag = { column, moved: false };
        this.drag = drag;
        const move = (moveEvent) => this._dragMove(moveEvent, drag);
        const end = () => {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', end);
            document.removeEventListener('pointercancel', end);
            if (this.drag === drag) this.drag = null;
            if (openOnClick && !drag.moved && this.active?.row === row && this.active?.column === column) this.edit();
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', end);
        document.addEventListener('pointercancel', end);
    }

    _dragMove(event, drag) {
        if (!this.active) return;
        const box = this.container.getBoundingClientRect();
        const headHeight = this.container.querySelector('thead')?.offsetHeight ?? 0;
        // Near the top or bottom edge, the table scrolls on.
        if (event.clientY > box.bottom - 24) this.container.scrollTop += 18;
        else if (event.clientY < box.top + headHeight + 24) this.container.scrollTop -= 18;

        // Only down or up the column the drag began in, wherever the pointer is sideways.
        const anchor = this.cellNodes[this.active.row]?.[drag.column]?.getBoundingClientRect();
        if (!anchor) return;
        const x = Math.min(box.right - 2, Math.max(box.left + 2, anchor.left + anchor.width / 2));
        const y = Math.min(box.bottom - 2, Math.max(box.top + headHeight + 2, event.clientY));
        const hit = document.elementFromPoint(x, y)?.closest?.('.review-table__cell');
        if (!hit || Number(hit.dataset.column) !== drag.column) return;
        const row = Number(hit.dataset.row);
        if (row === this.active.row && !drag.moved) return;
        if (!drag.moved) {
            drag.moved = true;
            // Began in the open typing box: what was typed is kept, and the box closes.
            if (this.editor) {
                this._closeEditor(true);
                window.getSelection()?.removeAllRanges();
                this.cellNodes[this.active.row]?.[drag.column]?.focus({ preventScroll: true });
            }
        }
        this._extendTo(row);
    }

    _doubleClick(event) {
        const grip = event.target instanceof Element ? event.target.closest('.review-table__resizer') : null;
        if (grip) {
            this._fitColumn(grip);
            return;
        }
        const th = event.target instanceof Element ? event.target.closest('th') : null;
        if (!th || !this.container.contains(th) || th.classList.contains('is-renaming')) return;
        if (th.closest('thead')) {
            if (th.dataset.column === undefined) return;
            const column = this.model.columns[Number(th.dataset.column)];
            if (column) this._renameLabel(th, this.headerOf(column), (name) => {
                this._rename(this.headerNames, 'header', column.key, name === '' || name === column.header ? null : name);
            });
            return;
        }
        const tr = th.closest('tr');
        const row = tr ? this.model.rows[Number(tr.dataset.row)] : null;
        if (row) this._renameLabel(th, this.rowLabelOf(row), (name) => {
            this._rename(this.rowNames, 'row', row.id, name === '' || name === row.label ? null : name);
        });
    }

    /** A label renamed (`name` null: back to the original), as a change Ctrl+Z can take back. */
    _rename(names, label, key, name) {
        const before = names.get(key) ?? null;
        if (before === name) return;
        if (name === null) names.delete(key);
        else names.set(key, name);
        this._remember({ label, key, before, after: name });
    }

    /** A header or row number typed over in place: Enter keeps it, Esc drops it, empty puts the old one back. */
    _renameLabel(th, current, keep) {
        this._closeEditor(true);
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm review-table__label-editor';
        input.maxLength = 120;
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.value = current;
        input.setAttribute('aria-label', 'New name for the downloads');
        let done = false;
        const finish = (save) => {
            if (done) return;
            done = true;
            if (save) keep(input.value.trim());
            th.classList.remove('is-renaming');
            this._paintLabels();
            if (this.active) this.cellNodes[this.active.row]?.[this.active.column]?.focus({ preventScroll: true });
        };
        input.addEventListener('keydown', (event) => {
            event.stopPropagation();
            if (event.key === 'Enter') {
                event.preventDefault();
                finish(true);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                finish(false);
            }
        });
        input.addEventListener('blur', () => finish(true));
        th.classList.add('is-renaming');
        th.replaceChildren(input);
        input.focus();
        input.select();
    }

    _keyDown(event) {
        if (!this.active) return;
        if (event.target instanceof Element && event.target.closest('.review-table__label-editor')) return;
        const { row, column } = this.active;
        const rows = this.model.rows.length;
        const columns = this.model.columns.length;

        if (this.editor) {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                const at = this.editor;
                this._closeEditor(false);
                this.cellNodes[at.row][at.column].focus({ preventScroll: true });
                return;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                event.stopPropagation();
                const several = this.editor.rows.length > 1;
                this._closeEditor(true);
                // Several cells changed with Enter stay picked, to see them.
                if (several && event.key === 'Enter') {
                    this.cellNodes[row][column].focus({ preventScroll: true });
                    return;
                }
                const next = nextCellPosition(this.active, event.key, event.shiftKey, rows, columns);
                this.select(next.row, next.column);
                return;
            }
            return;
        }

        // Here, Ctrl+Z and Ctrl+Y belong to the table (not to the page's other tools).
        if (this._undoKey(event)) return;
        if (event.ctrlKey || event.metaKey || event.altKey) return;
        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            event.stopPropagation();
            this._apply(this.selectedRows().map((picked) => ({ row: picked, column, value: '' })));
            return;
        }
        if (event.shiftKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
            event.preventDefault();
            const focus = this.range && this.range.column === column ? this.range.focus : row;
            this._extendTo(focus + (event.key === 'ArrowDown' ? 1 : -1));
            return;
        }
        if (['Enter', 'F2'].includes(event.key) && !event.shiftKey) {
            event.preventDefault();
            this.edit();
            return;
        }
        if (['Tab', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key) || (event.key === 'Enter' && event.shiftKey)) {
            event.preventDefault();
            const next = nextCellPosition(this.active, event.key, event.shiftKey, rows, columns);
            this.select(next.row, next.column);
            return;
        }
        if (event.key === 'Escape') {
            // The copied mark goes first; then several picked cells go back to the one.
            if (this.copied) {
                event.preventDefault();
                event.stopPropagation();
                this._showCopied(null);
                return;
            }
            if (this.range) {
                event.preventDefault();
                event.stopPropagation();
                this.range = null;
                this._showRange();
            }
            return;
        }
        // Typing on a picked cell replaces its value, as in a spreadsheet.
        if (event.key.length === 1) {
            event.preventDefault();
            this.edit(event.key);
        }
    }

    /** [header, ...rows] of what the table shows now, for the files (with any renamed labels). */
    exportTable() {
        const header = ['Row', ...this.model.columns.map((column) => this.headerOf(column))];
        const rows = this.model.rows.map((row, rowIndex) => [
            this.rowLabelOf(row),
            ...this.model.columns.map((_, columnIndex) => String(this.valueOf(this.cell(rowIndex, columnIndex)) ?? '')),
        ]);
        return [header, ...rows];
    }
}
