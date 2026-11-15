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
 * A column's input and check: { input: 'text' | 'select', options?, settings? }.
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
 * The table on the page.
 *
 *   container       where the table goes (it scrolls; the header row sticks)
 *   valueOf(cell)   a cell's value now ({ refs, row, column })
 *   onChange(cell, value)  a cell was confirmed with a new value
 *   onLocate(cell)  a cell was picked: show it on the scan
 *   onIssues({ invalid, empty })  the issue counts changed
 */
export class ReviewTable {
    constructor({ container, valueOf, onChange, onLocate = () => {}, onIssues = () => {} }) {
        this.container = container;
        this.valueOf = valueOf;
        this.onChange = onChange;
        this.onLocate = onLocate;
        this.onIssues = onIssues;
        this.model = { columns: [], rows: [] };
        this.active = null;
        this.editor = null;

        container.addEventListener('pointerdown', (event) => this._pointerDown(event));
        container.addEventListener('keydown', (event) => this._keyDown(event));
    }

    /** Draw the table for `model` (see buildReviewModel); keeps the picked cell where it can. */
    render(model) {
        const keep = this.active;
        this._closeEditor(false);
        this.model = model;
        this.cellNodes = [];

        const table = document.createElement('table');
        table.className = 'review-table';
        table.setAttribute('role', 'grid');
        table.setAttribute('aria-rowcount', String(model.rows.length + 1));

        const head = table.createTHead().insertRow();
        const corner = document.createElement('th');
        corner.scope = 'col';
        corner.className = 'review-table__row-head';
        corner.textContent = 'Row';
        head.appendChild(corner);
        model.columns.forEach((column) => {
            const th = document.createElement('th');
            th.scope = 'col';
            th.textContent = column.header;
            th.title = column.header;
            if (column.type.input === 'select') th.dataset.choices = column.type.options.join(' / ');
            head.appendChild(th);
        });

        const body = table.createTBody();
        model.rows.forEach((row, rowIndex) => {
            const tr = body.insertRow();
            const label = document.createElement('th');
            label.scope = 'row';
            label.className = 'review-table__row-head';
            label.textContent = row.label;
            tr.appendChild(label);
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
    }

    _paint(rowIndex, columnIndex) {
        const td = this.cellNodes[rowIndex]?.[columnIndex];
        const cell = this.cell(rowIndex, columnIndex);
        if (!td || !cell) return null;
        const value = String(this.valueOf(cell) ?? '');
        const problem = cellProblem(value, cell.column.type);
        td.textContent = value;
        td.classList.toggle('is-empty', problem?.kind === 'empty');
        td.classList.toggle('is-invalid', problem?.kind === 'invalid');
        td.title = problem?.message ?? '';
        td.setAttribute('aria-invalid', String(Boolean(problem)));
        return problem;
    }

    /** Pick the cell holding `ref` (a field picked on the scan); no locate, no edit. */
    selectRef(ref) {
        for (let row = 0; row < this.model.rows.length; row += 1) {
            for (let column = 0; column < this.model.columns.length; column += 1) {
                if (this.cell(row, column).refs.includes(ref)) {
                    this._closeEditor(true);
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
        if (!this._mark(row, column)) return;
        this._scrollTo(row, column);
        if (locate) this.onLocate(this.cell(row, column));
        if (edit) this.edit();
        else this.cellNodes[row][column].focus({ preventScroll: true });
    }

    _mark(row, column) {
        const td = this.cellNodes[row]?.[column];
        if (!td) return false;
        this.container.querySelectorAll('.review-table__cell.is-active').forEach((node) => node.classList.remove('is-active'));
        this.container.querySelectorAll('tr.is-active').forEach((node) => node.classList.remove('is-active'));
        td.classList.add('is-active');
        td.parentElement?.classList.add('is-active');
        this.active = { row, column };
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

    /** Open the picked cell for typing; `typed` starts it with that text (a key pressed on it). */
    edit(typed = null) {
        if (!this.active || this.editor) return;
        const { row, column } = this.active;
        const td = this.cellNodes[row][column];
        const cell = this.cell(row, column);
        const original = String(this.valueOf(cell) ?? '');
        const type = cell.column.type;
        let input;

        if (type.input === 'select') {
            input = document.createElement('select');
            input.className = 'form-select form-select-sm review-table__editor';
            const choices = ['', ...type.options];
            const start = selectStart(typed ?? original, type.options);
            // A value that is none of the choices stays on offer, so Esc or Enter keeps it.
            if (original.trim() !== '' && !type.options.includes(original.trim())) choices.splice(1, 0, original);
            choices.forEach((choice) => {
                const option = document.createElement('option');
                option.value = choice;
                option.textContent = choice === '' ? '—' : choice === original && !type.options.includes(choice)
                    ? `${choice} (as read)` : choice;
                input.appendChild(option);
            });
            input.value = start || (original.trim() === '' ? '' : original);
            // Picked with the mouse: confirmed at once.
            input.addEventListener('change', () => {
                if (this.editor?.input === input && !this.editor.keyed) this._closeEditor(true);
            });
        } else {
            input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-sm review-table__editor';
            input.maxLength = 2000;
            input.autocomplete = 'off';
            input.spellcheck = false;
            input.value = typed ?? original;
        }
        input.setAttribute('aria-label', `${cell.row.label}, ${cell.column.header}`);
        input.addEventListener('blur', () => {
            // Clicking elsewhere confirms, as in a spreadsheet.
            window.setTimeout(() => {
                if (this.editor?.input === input && document.activeElement !== input) this._closeEditor(true);
            }, 0);
        });

        this.editor = { input, row, column, original, keyed: false };
        td.classList.add('is-editing');
        td.replaceChildren(input);
        input.focus({ preventScroll: true });
        if (input instanceof HTMLInputElement) {
            const end = input.value.length;
            input.setSelectionRange(end, end);
        }
    }

    /** Keep what is being typed (before a save, an export or leaving the table). */
    commit() {
        this._closeEditor(true);
    }

    /** Close the editor: `commit` keeps the typed value, otherwise it is dropped. */
    _closeEditor(commit) {
        const editor = this.editor;
        if (!editor) return;
        this.editor = null;
        const td = this.cellNodes[editor.row]?.[editor.column];
        td?.classList.remove('is-editing');
        const value = editor.input.value;
        if (commit && value !== editor.original) {
            this.onChange(this.cell(editor.row, editor.column), value);
        }
        this.refresh();
    }

    _pointerDown(event) {
        const td = event.target instanceof Element ? event.target.closest('.review-table__cell') : null;
        if (!td || td.classList.contains('is-editing')) return;
        event.preventDefault();
        this.select(Number(td.dataset.row), Number(td.dataset.column), { edit: true });
    }

    _keyDown(event) {
        if (!this.active) return;
        const { row, column } = this.active;
        const rows = this.model.rows.length;
        const columns = this.model.columns.length;

        if (this.editor) {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this._closeEditor(false);
                this.cellNodes[row][column].focus({ preventScroll: true });
                return;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                event.stopPropagation();
                this.editor.keyed = true;
                this._closeEditor(true);
                const next = nextCellPosition(this.active, event.key, event.shiftKey, rows, columns);
                this.select(next.row, next.column);
                return;
            }
            // A select's arrows change its choice; mark it keyed so change waits for Enter.
            if (this.editor.input instanceof HTMLSelectElement) this.editor.keyed = true;
            return;
        }

        if (event.ctrlKey || event.metaKey || event.altKey) return;
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
        if (event.key === 'Escape') return;
        // Typing on a picked cell replaces its value, as in a spreadsheet.
        if (event.key.length === 1) {
            event.preventDefault();
            this.edit(event.key);
        }
    }

    /** [header, ...rows] of what the table shows now, for the files. */
    exportTable() {
        const header = ['Row', ...this.model.columns.map((column) => column.header)];
        const rows = this.model.rows.map((row, rowIndex) => [
            row.label,
            ...this.model.columns.map((_, columnIndex) => String(this.valueOf(this.cell(rowIndex, columnIndex)) ?? '')),
        ]);
        return [header, ...rows];
    }
}
