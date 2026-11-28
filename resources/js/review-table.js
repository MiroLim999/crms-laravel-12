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
import { dateOrderOf, matchingChoice, repairedDate, toIsoDate, usualMonthOf } from './value-types.js';

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

/**
 * What is wrong with a cell, or null. An empty cell is flagged, except in a
 * column that may be left blank (`type.optional`; `type.required` says so in
 * its message). A register field's column also checks its value:
 * `type.integer` takes plain digits only, `type.strict` one of `type.options`.
 */
export function cellProblem(value, type = null) {
    const text = String(value ?? '').trim();
    if (text === '') {
        if (type?.optional) return null;
        return { kind: 'empty', message: type?.required ? 'Required: every row needs a value' : 'Empty' };
    }
    if (type?.integer && !/^\d+$/.test(text)) return { kind: 'invalid', message: 'Plain digits only, e.g. 27' };
    if (type?.date && !toIsoDate(text, undefined, { dayFirst: Boolean(type.dayFirst) })
        && !repairedDate(text, type.usualMonth, { dayFirst: Boolean(type.dayFirst) })) return { kind: 'invalid', message: 'Not a whole date: write it as YYYY-MM-DD, e.g. 1957-03-11' };
    if (type?.strict && Array.isArray(type.options) && !type.options.includes(text)) {
        return { kind: 'invalid', message: `One of: ${type.options.join(', ')}` };
    }
    return null;
}

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

// --------------------------------------------------------- register fields

/** A column name as a field key: "Name of Mother" -> "name_of_mother". */
export function fieldKeyOf(name) {
    return String(name ?? '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
}

/** A layout column kept apart from every field ("Type by hand" chosen for the field it matched). */
export const NOT_A_FIELD = '-';

// Words a column name and a field may differ by without meaning something else.
const FIELD_WORD = {
    husb: 'husband', number: 'no', num: 'no', nr: 'no', registration: 'reg', registry: 'reg', register: 'reg',
    minutes: 'mins', minute: 'mins', min: 'mins',
};
const FIELD_FILLER = new Set(['of', 'the', 'a', 's', 'and']);

/** A name's words, the same whichever order or spelling: "Mother's Name" -> "mother name". */
function fieldWords(name) {
    return String(name ?? '').toLowerCase().replace(/['’]s\b/g, '')
        .split(/[^a-z0-9]+/)
        .filter((word) => word !== '' && !FIELD_FILLER.has(word))
        .map((word) => FIELD_WORD[word] ?? word)
        .sort()
        .join(' ');
}

/**
 * The field a column's name stands for, or null: the same words in any order
 * ("Name of Mother", "Mother's Name" and "name_of_mother" are name_of_mother;
 * "Husband Nationality" is husb_nationality).
 */
export function matchField(name, fields) {
    const words = fieldWords(name);
    if (words === '') return null;
    return fields.find((field) => fieldWords(field.key) === words)?.key
        // Else a slip of the pen: "Place of Mirriage" is place_of_marriage.
        ?? fields.find((field) => sameWords(words, fieldWords(field.key)))?.key
        ?? null;
}

/** Two word lists alike but for one letter in a long word each ("mirriage", "marriage"). */
function sameWords(a, b) {
    const left = a.split(' ');
    const right = b.split(' ');
    if (left.length !== right.length) return false;
    const free = [...right];
    return left.every((word) => {
        const at = free.findIndex((other) => other === word || (Math.min(word.length, other.length) >= 5 && oneEditApart(word, other)));
        if (at < 0) return false;
        free.splice(at, 1);
        return true;
    });
}

function oneEditApart(a, b) {
    if (Math.abs(a.length - b.length) > 1) return false;
    let i = 0;
    let j = 0;
    let edits = 0;
    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) { i += 1; j += 1; continue; }
        edits += 1;
        if (edits > 1) return false;
        if (a.length > b.length) i += 1;
        else if (b.length > a.length) j += 1;
        else { i += 1; j += 1; }
    }
    return edits + (a.length - i) + (b.length - j) <= 1;
}

// The words that only say whose line it is, in a two-line entry's column name.
const PAIR_WORDS = new Set(['husband', 'wife', 'groom', 'bride', 'contracting', 'party', 'parties']);

/**
 * For a two-line entry: the first field of the pair a column's name stands for
 * without saying whose ("Name", "Age", "Father", "Husband / Wife Nationality"),
 * or null.
 */
export function matchPair(name, pairs) {
    // "Names of the Contracting Parties" is "name": whose words go, and a plural is one.
    const shared = (text) => fieldWords(text).split(' ')
        .filter((word) => !PAIR_WORDS.has(word))
        .map((word) => (word.length > 3 && word.endsWith('s') ? word.slice(0, -1) : word))
        .join(' ');
    const words = shared(name);
    if (words === '') return null;
    return pairs.find(([first]) => {
        const base = shared(first);
        return base !== '' && (base === words || sameWords(words, base));
    })?.[0] ?? null;
}

/**
 * The register fields a layout's column names fill, as Review & Export will
 * match them (same words, a slip of the pen, the two lines of a pair): a Set of
 * field keys. A manual field is never filled from the scan.
 */
export function fieldsFilledBy(names, fields, pairs = []) {
    const fillable = fields.filter((field) => !field.manual);
    const filled = new Set();
    names.forEach((name) => {
        const field = matchField(name, fillable.filter((candidate) => !filled.has(candidate.key)))
            ?? matchPair(name, pairs);
        if (field) filled.add(field);
    });
    // A column for the first line of a pair gives the second line too (the wife under the husband).
    pairs.forEach(([first, second]) => {
        if (filled.has(first)) filled.add(second);
    });
    return filled;
}

/** A field key as people read it: "husb_nationality" -> "husband nationality". */
export function fieldLabelOf(key) {
    const words = { husb: 'husband', reg: 'registration', no: 'number', mins: 'minutes' };
    return String(key ?? '').split('_').map((word) => words[word] ?? word).join(' ');
}

/**
 * A register field's column check (see App\Support\RegistryFields): a field
 * that may be blank is not flagged when empty; integers take digits; a field
 * with fixed values takes one of them (typing "phy" gives PHYSICIAN).
 */
export function registryColumnType(field) {
    const options = Array.isArray(field.options) ? field.options : null;
    return {
        input: options ? 'select' : 'text',
        ...(options ? { options, strict: true } : {}),
        settings: {},
        required: Boolean(field.required),
        optional: !field.required,
        integer: field.kind === 'integer',
        // Shown, saved and downloaded as YYYY-MM-DD.
        date: field.kind === 'date',
    };
}

/**
 * The table as a register's: one column per field, in the register's order.
 *
 *   model    from buildReviewModel (the scanned columns)
 *   fields   the register's fields ([{ key, kind, required, manual, options?, default? }])
 *   mapOf(column)  the saved { "column name": field } of the layout the column
 *            is on, or null when that layout has none yet: then a column
 *            named like a field ("Name of Mother") fills it
 *
 * A field a scanned column fills is that column (its key, so what was typed in
 * it stays), with the field's name and check; any other field is a column to
 * type into (key "m|<field>"). A manual field is never filled from the scan.
 * Scanned columns no field takes come last, marked noExport ("not saved").
 * Each column has `field` (its field) and `sourceHeader` (the scanned name).
 */
export function applyRegistry(model, fields, mapOf = () => null, pairs = []) {
    const fillable = fields.filter((field) => !field.manual);
    const keys = new Set(fillable.map((field) => field.key));
    // What was chosen for a column: a field, NOT_A_FIELD ("Type by hand"), or undefined (nothing).
    const chosen = (column) => (mapOf(column) ?? {})[nameKey(column.name)];

    // First what was chosen; then a column named like a field it fills, unless the
    // field or the column is taken already, or the column was set apart on purpose.
    const taken = new Map();
    const placed = new Set();
    model.columns.forEach((column) => {
        const field = chosen(column);
        if (field === NOT_A_FIELD) placed.add(column.key);
        if (!keys.has(field) || taken.has(field)) return;
        taken.set(field, column);
        placed.add(column.key);
    });
    model.columns.forEach((column) => {
        if (placed.has(column.key) || chosen(column) !== undefined) return;
        const field = matchField(column.name, fillable) ?? matchPair(column.name, pairs);
        if (!field || taken.has(field)) return;
        taken.set(field, column);
        placed.add(column.key);
    });
    const used = new Set([...taken.values()].map((column) => column.key));

    // Two-line entries (the marriage book: husband, then wife under him): a column
    // read for the first field of a pair gives the second from its second line,
    // unless the second has a column of its own or that was turned off ("-").
    const fillableKeys = new Set(fillable.map((field) => field.key));
    const lineTwo = new Map();
    pairs.forEach(([first, second]) => {
        const source = taken.get(first);
        if (!source || taken.has(second) || !fillableKeys.has(second)) return;
        if ((mapOf(source) ?? {})[nameKey(source.name) + LINE_TWO] === NOT_A_FIELD) return;
        lineTwo.set(second, source);
    });
    const firstOfPair = new Set([...lineTwo.values()].map((column) => column.key));

    const columns = fields.map((field) => {
        const type = registryColumnType(field);
        const source = taken.get(field.key);
        if (source) {
            return {
                ...source, header: field.key, type, field: field.key, sourceHeader: source.header,
                ...(firstOfPair.has(source.key) ? { line: 1 } : {}),
            };
        }
        const pairedWith = lineTwo.get(field.key);
        if (pairedWith) {
            return {
                ...pairedWith, key: pairedWith.key + LINE_TWO, header: field.key, type, field: field.key,
                sourceHeader: `${pairedWith.header} · line 2`, line: 2,
            };
        }
        return { key: `m|${field.key}`, side: 0, name: field.key, header: field.key, type, detail: false, field: field.key, manual: true };
    });
    const leftover = model.columns
        .filter((column) => !used.has(column.key))
        .map((column) => ({ ...column, header: `${column.header} · not saved`, noExport: true, sourceHeader: column.header }));

    const typed = columns.filter((column) => column.manual);
    const withTyped = (cells) => ({ ...cells, ...Object.fromEntries(typed.map((column) => [column.key, { refs: [] }])) });
    if (lineTwo.size === 0) {
        return { columns: [...columns, ...leftover], rows: model.rows.map((row) => ({ ...row, cells: withTyped(row.cells) })) };
    }
    return { columns: [...columns, ...leftover], rows: pairRows(model, columns, firstOfPair) };
}

/** The key suffix of a column's second line (and of its "Columns → fields" choice). */
export const LINE_TWO = '#2';

/**
 * Each two ruled lines (register rows 1+2, 3+4, …) as one entry. A column of a
 * pair keeps its first line, and its "#2" column takes the second; any other
 * read column holds what is on either line (a date or number written once).
 * `lines` are the two rows' ids (null where a line was not read).
 */
function pairRows(model, columns, firstOfPair) {
    const entries = new Map();
    model.rows.forEach((row, index) => {
        const number = Number(String(row.id).match(/^person-(\d+)$/)?.[1]) || index + 1;
        const entry = Math.floor((number - 1) / 2);
        if (!entries.has(entry)) entries.set(entry, [null, null]);
        entries.get(entry)[(number - 1) % 2] = row;
    });

    const scanned = model.columns;
    return [...entries.entries()].sort(([a], [b]) => a - b).map(([entry, [first, second]]) => {
        const lead = first ?? second;
        const cellOf = (row, key) => row?.cells[key] ?? { refs: [] };
        const cells = {};
        scanned.forEach((column) => {
            if (column.detail) {
                cells[column.key] = cellOf(lead, column.key);
            } else if (firstOfPair.has(column.key)) {
                cells[column.key] = cellOf(first, column.key);
                cells[column.key + LINE_TWO] = cellOf(second, column.key);
            } else {
                cells[column.key] = { refs: [...cellOf(first, column.key).refs, ...cellOf(second, column.key).refs] };
            }
        });
        columns.filter((column) => column.manual).forEach((column) => { cells[column.key] = { refs: [] }; });
        return {
            id: lead.id,
            label: String(entry + 1).padStart(2, '0'),
            lines: [first?.id ?? null, second?.id ?? null],
            cells,
        };
    });
}

// ------------------------------------------------------------------ export

/** Keep a spreadsheet from running a typed value as a formula (as App\Support\CsvCell). */
export function csvSafe(value) {
    const text = String(value ?? '');
    return /^[=+\-@\t\r]/.test(text) ? `'${text}` : text;
}

/** One CSV cell, quoted when it has to be. */
function csvCell(value) {
    // A date (1957-02-11) is written as ="1957-02-11", so Excel keeps it as text
    // and does not show it as 2/11/1957. Only an exact date gets it: nothing typed becomes a formula.
    if (/^\d{4}-\d{2}-\d{2}$/.test(String(value ?? ''))) return `"=""${value}"""`;
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

/**
 * A batch's records as one table, for "Download all": each part is one
 * record's table ([header, ...rows], as ReviewTable.exportTable() gives it),
 * with its record number and the document it was read from in front of every
 * row. Columns are joined by their header, in the order they first appear;
 * a record without a column leaves its cells empty.
 *
 * @param {{ recordId?: number|string|null, label?: string, table: string[][] }[]} parts
 */
export function combineTables(parts, { plain = false } = {}) {
    const headers = [];
    const seen = new Set();
    parts.forEach(({ table }) => (table?.[0] ?? []).forEach((header) => {
        const key = String(header);
        if (seen.has(key)) return;
        seen.add(key);
        headers.push(key);
    }));

    const rows = parts.flatMap(({ recordId = null, label = '', table }) => {
        const [header = [], ...body] = table ?? [];
        const position = new Map(header.map((name, index) => [String(name), index]));
        const cells = (row) => headers.map((name) => (position.has(name) ? exportCase(row[position.get(name)]) : ''));
        // A register's tables (plain): its own columns only, as each record downloads.
        if (plain) return body.map(cells);
        return body.map((row) => [
            recordId === null ? '' : String(recordId),
            exportCase(label),
            ...cells(row),
        ]);
    });

    if (plain) return [headers, ...rows];
    return [['Record ID', 'Document', ...headers], ...rows];
}

/**
 * A download's file name, readable and safe on any computer: the parts that are
 * not empty (a file's extension dropped), joined by " - ":
 * "Book of Birth - Book 12 Page 23 - 2026-11-25" (as App\Support\RegistryFields::fileName).
 */
export function exportFileName(...parts) {
    const name = parts
        .map((part) => String(part ?? '')
            .replace(/\.(pdf|png|jpe?g|webp|bmp|csv|xlsx)$/i, '')
            .replace(/[_]+/g, ' ')
            .replace(/[\/:*?"<>|\p{Cc}]+/gu, ' ')
            .replace(/\s+/g, ' ')
            .trim())
        .filter((part) => part !== '')
        .join(' - ')
        .slice(0, 150)
        .trim();
    return name || 'Records';
}

/** late_registration downloads as 1 or 0, as the database keeps it (not TRUE / FALSE). */
const downloadedFlag = (column, value) => {
    if (column?.field !== 'late_registration') return value;
    const flag = String(value ?? '').trim().toLowerCase();
    return flag === 'true' ? '1' : flag === 'false' ? '0' : value;
};

/** A downloaded value: all capitals, as the registers are kept. */
const exportCase = (value) => String(value ?? '').toLocaleUpperCase('en-US');

/**
 * Wire the "Removed columns" dropdown (records.partials.review-add-column) found
 * in `root` to `table`: read columns removed from their header's × are listed,
 * each with Show again. The button shows only while one is removed. (Adding an
 * empty column to type into was taken out.)
 */
export function attachAddColumn(table, root = document) {
    const wrap = root.querySelector('[data-review-add-column]');
    const hiddenList = wrap?.querySelector('[data-hidden-columns]');
    if (!wrap || !hiddenList) return;
    const toggle = wrap.querySelector('[data-bs-toggle="dropdown"]');

    const paint = () => {
        const hidden = table.hiddenColumnList();
        // Nothing removed: nothing to show again, so no button.
        if (hidden.length === 0 && wrap.querySelector('.dropdown-menu.show')) toggle?.click();
        wrap.classList.toggle('d-none', hidden.length === 0);
        hiddenList.replaceChildren(...hidden.map((column) => {
            const item = document.createElement('li');
            item.className = 'd-flex align-items-center justify-content-between gap-2';
            const label = document.createElement('span');
            label.className = 'text-truncate';
            label.textContent = column.header;
            const show = document.createElement('button');
            show.type = 'button';
            show.className = 'btn btn-sm btn-link p-0';
            show.textContent = 'Show again';
            show.setAttribute('aria-label', `Show column ${column.header} again`);
            show.addEventListener('click', () => {
                table.showColumn(column.key);
                paint();
            });
            item.append(label, show);
            return item;
        }));
    };

    wrap.addEventListener('show.bs.dropdown', paint);
    // A column removed from its header's ×, or shown again (Ctrl+Z), repaints the list.
    const before = table.onColumnsChange;
    table.onColumnsChange = () => {
        before();
        paint();
    };
    paint();
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

/** Each column as wide as its longest value (within reason), for write-excel-file. */
function sheetColumns(table) {
    return (table[0] ?? []).map((_, column) => ({
        width: Math.min(48, Math.max(6, ...table.map((row) => String(row[column] ?? '').length + 2))),
    }));
}

export async function downloadXlsx(table, baseName) {
    await downloadXlsxSheets([{ name: 'Records', table }], baseName);
}

/** An Excel file of several sheets, each `{ name, table }` (a bold, fixed header row). */
export async function downloadXlsxSheets(sheets, baseName) {
    // Loaded only when asked for, so the page does not carry it.
    const { default: writeExcelFile } = await import('write-excel-file/browser');
    const blob = await writeExcelFile(sheets.map(({ name, table }) => ({
        data: toSheetData(table),
        sheet: name,
        stickyRowsCount: 1,
        columns: sheetColumns(table),
    }))).toBlob();
    saveBlob(blob, `${baseName}.xlsx`);
}

// ------------------------------------------------- several records at once

/**
 * Several records' tables (as the server's RecordCsv gives them: Record ID,
 * Document type, Row, then the columns) as one table. Columns are joined by
 * their header, in the order they first appear; a record without a column
 * leaves its cells empty.
 *
 * @param {{ header: string[], rows: (string|number|null)[][] }[]} records
 */
export function joinTables(records) {
    const headers = [];
    const seen = new Set();
    records.forEach(({ header }) => (header ?? []).forEach((name) => {
        const key = String(name);
        if (seen.has(key)) return;
        seen.add(key);
        headers.push(key);
    }));

    const rows = records.flatMap(({ header = [], rows: body = [] }) => {
        const position = new Map(header.map((name, index) => [String(name), index]));
        return body.map((row) => headers.map((name) => (position.has(name) ? exportCase(row[position.get(name)]) : '')));
    });

    return [headers, ...rows];
}

/**
 * An Excel sheet name: at most 31 characters, none of [ ] : * ? / \, and not
 * one already in `used` (any case), which it is added to.
 */
export function sheetName(text, used = new Set()) {
    const base = String(text ?? '').replace(/[[\]:*?/\\]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 31) || 'Sheet';
    let name = base;
    for (let copy = 2; used.has(name.toLowerCase()); copy += 1) {
        const suffix = ` (${copy})`;
        name = `${base.slice(0, 31 - suffix.length).trim()}${suffix}`;
    }
    used.add(name.toLowerCase());
    return name;
}

/** Up to this many records, the Excel file also has a sheet for each one. */
export const SHEET_PER_RECORD_LIMIT = 50;

/**
 * Records' tables as one download: CSV, one table; or Excel, an "All records"
 * sheet and, for 2 to SHEET_PER_RECORD_LIMIT records, a sheet for each ("Record #12").
 *
 * @param {{ id: number, type?: string, header: string[], rows: (string|number|null)[][] }[]} records
 * @param {string} baseName
 * @param {'csv'|'xlsx'} format
 */
export async function downloadRecordTables(records, baseName, format) {
    const all = joinTables(records);
    if (format === 'csv') {
        await downloadCsv(all, baseName);
        return;
    }
    const used = new Set();
    const sheets = [{ name: sheetName('All records', used), table: all }];
    if (records.length > 1 && records.length <= SHEET_PER_RECORD_LIMIT) {
        records.forEach((record) => sheets.push({
            // The type is in every row; a long type name would only be cut short here.
            name: sheetName(`Record #${record.id}`, used),
            table: [record.header, ...record.rows],
        }));
    }
    await downloadXlsxSheets(sheets, baseName);
}

/**
 * Records' tables from the server (route records.export). `params` picks them:
 * `ids[]`, or `all=1` with the archive's filters. Throws with the server's message.
 *
 * @param {string} url
 * @param {URLSearchParams} params
 */
export async function fetchRecordTables(url, params) {
    const response = await fetch(`${url}?${params}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    const payload = (response.headers.get('content-type') || '').includes('application/json')
        ? await response.json()
        : null;
    if (!response.ok) {
        const first = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
        throw new Error(first || payload?.message || `The server answered ${response.status}.`);
    }
    return payload?.records ?? [];
}

// ------------------------------------------------------- resizable split

export const SPLIT_MIN = 20;
export const SPLIT_MAX = 65;
export const SPLIT_DEFAULT = 50;

export function clampSplit(value, fallback = SPLIT_DEFAULT, min = SPLIT_MIN, max = SPLIT_MAX) {
    if (value === null || value === undefined || value === '') return fallback;
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return fallback;
    // Tenths of a percent: whole percents would make the bar jump under the pointer.
    return Math.min(max, Math.max(min, Math.round(numeric * 10) / 10));
}

/**
 * Drag, arrow keys or Home/End on `splitter` set how much of `split` the
 * left-hand pane takes (the CSS custom property `property`); a double-click
 * puts it back at its starting width. The width is not remembered: each
 * visit (a refresh, or coming back from another page) starts at the starting
 * width. `onResize` runs after every change.
 *
 * `initial` is the starting width in percent, or a function giving it (for a
 * layout whose natural width depends on the screen or the page). Until the
 * user drags, `refresh()` re-reads it, e.g. after the layout changed.
 */
export function attachSplit({
    split,
    splitter,
    onResize = () => {},
    min = SPLIT_MIN,
    max = SPLIT_MAX,
    initial = SPLIT_DEFAULT,
    property = '--review-scan-width',
    describe = (left) => `Scan ${left}%, table ${100 - left}%`,
}) {
    const starting = () => clampSplit(typeof initial === 'function' ? initial() : initial, SPLIT_DEFAULT, min, max);
    let percentage = starting();
    // Set by the user on this visit (dragged, or keys): the starting width no longer applies.
    let custom = false;
    let dragging = false;
    // Where on the bar it was grabbed, so it does not jump to the pointer.
    let grab = 0;

    const update = (next, byUser = false) => {
        percentage = clampSplit(next, percentage, min, max);
        split.style.setProperty(property, `${percentage}%`);
        splitter.setAttribute('aria-valuemin', String(min));
        splitter.setAttribute('aria-valuemax', String(max));
        splitter.setAttribute('aria-valuenow', String(Math.round(percentage)));
        splitter.setAttribute('aria-valuetext', describe(Math.round(percentage)));
        onResize();
        if (byUser) custom = true;
    };
    /** Back to the starting width. */
    const reset = () => {
        custom = false;
        update(starting());
    };
    const fromPointer = (event) => {
        const bounds = split.getBoundingClientRect();
        if (bounds.width <= 0) return;
        // Dragged by the user from the first move: refresh() leaves it alone.
        update(((event.clientX - grab - bounds.left) / bounds.width) * 100, true);
        // The other pane may have a least width (CSS) that stops the bar short:
        // keep the width where the bar really is, so dragging back moves it at once.
        const shown = ((splitter.getBoundingClientRect().left - bounds.left) / bounds.width) * 100;
        if (Number.isFinite(shown) && Math.abs(shown - percentage) > 0.5) update(shown, true);
    };

    // A second press soon after the first, in the same place: back to the start.
    // (pointerdown's preventDefault keeps the browser from sending dblclick.)
    let lastPress = { time: 0, x: 0 };
    splitter.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) return;
        event.preventDefault();
        const now = event.timeStamp;
        if (now - lastPress.time < 400 && Math.abs(event.clientX - lastPress.x) < 6) {
            lastPress = { time: 0, x: 0 };
            reset();
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
            Home: min,
            End: max,
        }[event.key];
        if (next === undefined) return;
        event.preventDefault();
        update(next, true);
    });

    update(percentage);
    return {
        reset,
        // The layout changed (a wider screen, a second page): a width the user
        // did not set follows its new starting width.
        refresh: () => {
            if (dragging || custom) return;
            const next = starting();
            if (next !== percentage) update(next);
        },
    };
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
 *   isUnverified(cell)  its value is shown but will not be saved (Verify: no
 *                   field of the cell ticked Verified). Shown greyed, and left
 *                   out of the downloads (optional)
 *   isLow(cell)     what was read needs a closer look (low confidence): marked (optional)
 *   onToggle(cells) Space on the picked cells: check them, or uncheck them (optional)
 *   showChecked     a read value that is checked shows light green (optional)
 *   onActive(cell)  the picked cell changed (null: none), e.g. to show its crop (optional)
 *   isRowLeftOut(row)  nothing on the row is checked or typed: shown, but not
 *                   saved, downloaded or checked for missing values (optional)
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
        isUnverified = () => false, onColumnsChange = () => {}, removableFields = false,
        isReadOnly = () => null, isLow = () => false, onToggle = null, onActive = () => {},
        isRowLeftOut = () => false, showChecked = false,
    }) {
        this.showChecked = showChecked;
        this.isRowLeftOut = isRowLeftOut;
        this.isLow = isLow;
        this.onToggle = onToggle;
        this.onActive = onActive;
        // A cell that cannot be changed here (step 4's book_no and page_no come from step 1):
        // answers why, as its tooltip, or null when it can be typed into.
        this.isReadOnly = isReadOnly;
        // A column was removed from its header, or shown again (the Removed columns menu lists them).
        this.onColumnsChange = onColumnsChange;
        // A register field that is not required may be removed too (its values then
        // stay blank in the register). Step 4 only: a saved record keeps its fields.
        this.removableFields = removableFields;
        this.isUnverified = isUnverified;
        this.onRange = onRange;
        this.onCleared = onCleared;
        this.container = container;
        // Columns the reviewer added (empty, typed into) live here, not in the page:
        // their values never reach `valueOf` / `onChange`, and are not saved with the record.
        this.addedColumns = [];
        this.addedValues = new Map();
        // Read columns deleted from the table (keys): left out of it and its downloads.
        this.hiddenColumns = new Set();
        // How dates are written: 'auto', 'month' (5-14-79) or 'day' (14-5-79). See setDateOrder.
        this.dateOrder = 'auto';
        this.addedCount = 0;
        this.valueOf = (cell) => (cell?.column?.custom
            ? this.addedValues.get(`${cell.row.id}|${cell.column.key}`) ?? ''
            : valueOf(cell));
        this.onChange = (cell, value) => {
            if (!cell.column.custom) {
                onChange(cell, value);
                return;
            }
            const key = `${cell.row.id}|${cell.column.key}`;
            const text = String(value).trim();
            if (text === '') this.addedValues.delete(key);
            else this.addedValues.set(key, text);
        };
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
        // Column keys in the order the reviewer dragged them to (empty: as built).
        this.columnOrder = [];
        this.sourceModel = null;

        container.addEventListener('pointerdown', (event) => this._pointerDown(event));
        container.addEventListener('keydown', (event) => this._keyDown(event));
        container.addEventListener('dblclick', (event) => this._doubleClick(event));
        // An added column's × in its header deletes it.
        container.addEventListener('click', (event) => {
            const button = event.target instanceof Element ? event.target.closest('.review-table__remove') : null;
            if (!button) return;
            event.preventDefault();
            event.stopPropagation();
            this._confirmRemove(button.dataset.key, button);
        });
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
        this.columnOrder = [];
        this.addedColumns = [];
        this.addedValues.clear();
        this.hiddenColumns.clear();
        this.range = null;
        this.onCleared([]);
        // Nothing removed any more: the Removed columns button goes.
        this.onColumnsChange();
    }

    /** The model with the added columns after the others, each cell empty of fields. */
    _withAdded(model) {
        const shown = (columns) => (this.hiddenColumns.size === 0 ? columns : columns.filter((column) => !this.hiddenColumns.has(column.key)));
        if (this.addedColumns.length === 0) return { ...model, columns: shown(model.columns) };
        const empty = Object.fromEntries(this.addedColumns.map((column) => [column.key, { refs: [] }]));
        return {
            columns: shown([...model.columns, ...this.addedColumns]),
            rows: model.rows.map((row) => ({ ...row, cells: { ...row.cells, ...empty } })),
        };
    }

    /**
     * Whether a column can be deleted: any but a register field (the register's
     * columns are the download's format; a read column no field takes can go).
     */
    canRemoveColumn(column) {
        if (!column) return false;
        if (column.custom || !column.field) return true;
        // A register field: when allowed, and not one every row needs.
        return this.removableFields && !column.type?.required;
    }

    /** The removed read columns, to show again (the Removed columns menu lists them): [{ key, header }]. */
    hiddenColumnList() {
        const all = this.sourceModel?.columns ?? [];
        return [...this.hiddenColumns]
            .map((key) => all.find((column) => column.key === key))
            .filter(Boolean)
            .map((column) => ({ key: column.key, header: this.headerOf(column) }));
    }

    /** A removed read column back in the table (Ctrl+Z removes it again). */
    showColumn(key) {
        if (!this.hiddenColumns.has(key)) return;
        this._closeEditor(true);
        this.hiddenColumns.delete(key);
        this.render(this.sourceModel);
        this._remember({ columnChange: 'show', key, snapshot: null });
        this.onColumnsChange();
    }

    /**
     * Add an empty column at the end, named `name` (a name already in the table
     * gets " (2)"), and pick its first cell. Returns the column, or null for no name.
     */
    addColumn(name) {
        const text = String(name ?? '').trim().slice(0, 120);
        if (text === '' || !this.sourceModel) return null;
        this._closeEditor(true);
        const taken = new Set(this.model.columns.map((column) => nameKey(this.headerOf(column))));
        let header = text;
        for (let copy = 2; taken.has(nameKey(header)); copy += 1) header = `${text} (${copy})`;
        this.addedCount += 1;
        const column = {
            key: `custom|${this.addedCount}`,
            side: 0,
            name: header,
            header,
            type: columnType(header),
            detail: false,
            custom: true,
        };
        this.addedColumns.push(column);
        this.render(this.sourceModel);
        // Ctrl+Z takes the new column away again (its snapshot is taken then, for Ctrl+Y).
        this._remember({ columnChange: 'add', key: column.key, snapshot: null });
        const at = this.model.columns.findIndex((candidate) => candidate.key === column.key);
        if (at !== -1) this.select(0, at, { locate: false });
        return column;
    }

    /** Take an added column away, with what was typed in it (Ctrl+Z brings it back). */
    removeColumn(key) {
        if (!this.addedColumns.some((column) => column.key === key)) {
            // A read column: left out of the table (and its downloads); Ctrl+Z shows it again.
            const column = this.model?.columns.find((candidate) => candidate.key === key);
            if (!this.canRemoveColumn(column) || this.hiddenColumns.has(key)) return;
            this._closeEditor(true);
            this.hiddenColumns.add(key);
            this.render(this.sourceModel);
            this._remember({ columnChange: 'hide', key, snapshot: null });
            return;
        }
        // A value still being typed is kept first, so the snapshot holds it.
        this._closeEditor(true);
        const snapshot = this._takeColumn(key);
        if (snapshot) this._remember({ columnChange: 'remove', key, snapshot });
    }

    /**
     * Take an added column out of the table and return all it had: the column,
     * its place, its values, its new name, width and dragged position.
     */
    _takeColumn(key) {
        const index = this.addedColumns.findIndex((column) => column.key === key);
        if (index === -1) return null;
        this._closeEditor(true);
        const snapshot = {
            column: this.addedColumns[index],
            index,
            values: [...this.addedValues].filter(([id]) => id.endsWith(`|${key}`)),
            name: this.headerNames.get(key) ?? null,
            width: this.widths.get(key) ?? null,
            order: this.columnOrder.indexOf(key),
        };
        this.addedColumns.splice(index, 1);
        snapshot.values.forEach(([id]) => this.addedValues.delete(id));
        this.headerNames.delete(key);
        this.widths.delete(key);
        this.columnOrder = this.columnOrder.filter((candidate) => candidate !== key);
        this.render(this.sourceModel);
        return snapshot;
    }

    /** Put a column taken out (see _takeColumn) back as it was, and pick it. */
    _putColumn(snapshot) {
        this._closeEditor(true);
        const { column, index, values, name, width, order } = snapshot;
        this.addedColumns.splice(Math.min(index, this.addedColumns.length), 0, column);
        values.forEach(([id, value]) => this.addedValues.set(id, value));
        if (name !== null) this.headerNames.set(column.key, name);
        if (width !== null) this.widths.set(column.key, width);
        if (order !== -1 && this.columnOrder.length > 0) {
            this.columnOrder.splice(Math.min(order, this.columnOrder.length), 0, column.key);
        }
        this.render(this.sourceModel);
        const at = this.model.columns.findIndex((candidate) => candidate.key === column.key);
        if (at !== -1 && this.model.rows.length > 0) {
            this.select(Math.min(this.active?.row ?? 0, this.model.rows.length - 1), at, { locate: false });
        }
    }

    /** The added columns as shown now: [{ key, header }]. */
    customColumns() {
        return this.addedColumns.map((column) => ({ key: column.key, header: this.headerOf(column) }));
    }

    /** The model with its columns in the order the reviewer dragged them to. */
    _ordered(model) {
        if (this.columnOrder.length === 0) return model;
        const rank = (column) => {
            const at = this.columnOrder.indexOf(column.key);
            return at === -1 ? this.columnOrder.length : at;
        };
        const columns = model.columns
            .map((column, index) => ({ column, index }))
            .sort((a, b) => rank(a.column) - rank(b.column) || a.index - b.index)
            .map((entry) => entry.column);
        return { ...model, columns };
    }

    /**
     * A header pressed: once the pointer moves a little, the column follows it.
     * Its header and cells fade, a label with its name rides under the pointer,
     * and a line shows where it will land; it can be let go anywhere over the
     * table (header or cells), and near the left or right edge the table
     * scrolls by itself. Esc puts it back. A press without a move does nothing
     * (a double-click still renames).
     */
    _columnDrag(event, th) {
        const from = Number(th.dataset.column);
        const key = th.dataset.sizeKey;
        const startX = event.clientX;
        const startY = event.clientY;
        const scroller = this.container;
        let drag = null;
        let pointer = { x: startX, y: startY };

        const columnNodes = (index) => [this.headerNodes[index], ...this.cellNodes.map((cells) => cells[index])].filter(Boolean);

        // Where the column would land: the gap before header `gap` (headers.length: after the last).
        const gapAt = (x) => {
            const gap = this.headerNodes.findIndex((header) => {
                const box = header.getBoundingClientRect();
                return x < box.left + box.width / 2;
            });
            return gap === -1 ? this.headerNodes.length : gap;
        };

        const showGap = () => {
            drag.gap = gapAt(pointer.x);
            // Its own place (just before or after itself): no line, nothing would move.
            const moves = drag.gap !== from && drag.gap !== from + 1;
            drag.line.classList.toggle('is-shown', moves);
            if (!moves) return;
            const edge = drag.gap < this.headerNodes.length
                ? this.headerNodes[drag.gap].getBoundingClientRect().left
                : this.headerNodes[this.headerNodes.length - 1].getBoundingClientRect().right;
            const box = scroller.getBoundingClientRect();
            drag.line.style.left = `${edge - box.left + scroller.scrollLeft - 1.5}px`;
            drag.line.style.height = `${scroller.querySelector('table')?.offsetHeight ?? scroller.scrollHeight}px`;
        };

        // Near the left or right edge, the table scrolls toward it, faster the closer:
        // up to 1.2 px a millisecond, by the time passed (so a slow computer scrolls as fast).
        const EDGE = 48;
        let last = null;
        const scroll = (now) => {
            if (!drag) return;
            const elapsed = last === null ? 16 : Math.min(100, now - last);
            last = now;
            const box = scroller.getBoundingClientRect();
            const left = Math.min(EDGE, box.left + EDGE - pointer.x);
            const right = Math.min(EDGE, pointer.x - (box.right - EDGE));
            const speed = (closeness) => Math.ceil((closeness / EDGE) * 1.2 * elapsed);
            const step = left > 0 ? -speed(left) : (right > 0 ? speed(right) : 0);
            if (step !== 0) {
                const before = scroller.scrollLeft;
                scroller.scrollLeft += step;
                if (scroller.scrollLeft !== before) showGap();
            }
            drag.frame = window.requestAnimationFrame(scroll);
        };

        const begin = () => {
            this._closeEditor(true);
            const ghost = document.createElement('div');
            ghost.className = 'review-table__drag-ghost';
            ghost.textContent = th.querySelector('.review-table__label')?.textContent || key;
            document.body.appendChild(ghost);
            const line = document.createElement('div');
            line.className = 'review-table__drop-line';
            line.setAttribute('aria-hidden', 'true');
            scroller.appendChild(line);
            columnNodes(from).forEach((node) => node.classList.add('is-moving'));
            scroller.classList.add('is-moving-column');
            document.body.classList.add('review-table-dragging');
            drag = { ghost, line, gap: null, frame: window.requestAnimationFrame(scroll) };
        };

        const place = () => {
            drag.ghost.style.transform = `translate(${pointer.x + 12}px, ${pointer.y + 14}px)`;
            showGap();
        };

        const finish = (drop) => {
            document.removeEventListener('pointermove', move, true);
            document.removeEventListener('pointerup', up, true);
            document.removeEventListener('pointercancel', cancel, true);
            document.removeEventListener('keydown', escape, true);
            if (!drag) return;
            window.cancelAnimationFrame(drag.frame);
            drag.ghost.remove();
            drag.line.remove();
            columnNodes(from).forEach((node) => node.classList.remove('is-moving'));
            scroller.classList.remove('is-moving-column');
            document.body.classList.remove('review-table-dragging');
            const { gap } = drag;
            drag = null;
            if (!drop || gap === null || gap === from || gap === from + 1) return;
            const keys = this.model.columns.map((column) => column.key);
            keys.splice(from, 1);
            keys.splice(gap > from ? gap - 1 : gap, 0, key);
            this._reorder(keys);
        };

        const move = (moveEvent) => {
            pointer = { x: moveEvent.clientX, y: moveEvent.clientY };
            if (!drag) {
                if (Math.abs(pointer.x - startX) < 5 && Math.abs(pointer.y - startY) < 5) return;
                begin();
            }
            moveEvent.preventDefault();
            place();
        };
        const up = () => finish(true);
        const cancel = () => finish(false);
        const escape = (keyEvent) => {
            if (keyEvent.key !== 'Escape' || !drag) return;
            keyEvent.preventDefault();
            keyEvent.stopPropagation();
            finish(false);
        };

        document.addEventListener('pointermove', move, true);
        document.addEventListener('pointerup', up, true);
        document.addEventListener('pointercancel', cancel, true);
        document.addEventListener('keydown', escape, true);
    }

    /** The picked cell's column one place left (-1) or right (+1): Alt+Shift+← / →. */
    _moveActiveColumn(step) {
        if (!this.active) return false;
        const keys = this.model.columns.map((column) => column.key);
        const from = this.active.column;
        const to = from + step;
        if (to < 0 || to >= keys.length) return true;
        [keys[from], keys[to]] = [keys[to], keys[from]];
        this._reorder(keys);
        return true;
    }

    /**
     * Draw the columns again in `keys` order; the picked cell stays on its column.
     * A move by the reviewer (`remember`) is one change Ctrl+Z can take back.
     * The columns that moved slide to their new places.
     */
    _reorder(keys, remember = true) {
        this._closeEditor(true);
        const activeKey = this.active ? this.model.columns[this.active.column]?.key : null;
        const before = new Map((this.headerNodes ?? []).map((th) => [th.dataset.sizeKey, th.getBoundingClientRect().left]));
        if (remember) this._remember({ order: true, before: [...this.columnOrder], after: [...keys] });
        this.columnOrder = keys;
        this.render(this.sourceModel);
        if (activeKey != null) {
            const column = this.model.columns.findIndex((candidate) => candidate.key === activeKey);
            if (column !== -1 && this.active) this.select(this.active.row, column, { locate: false });
        }
        this._slideColumns(before);
    }

    /** Each column that moved slides from where it was (`before`: key -> left edge) to where it is. */
    _slideColumns(before) {
        if (before.size === 0 || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
        const moved = [];
        this.headerNodes.forEach((th, index) => {
            const was = before.get(th.dataset.sizeKey);
            if (was === undefined) return;
            const shift = was - th.getBoundingClientRect().left;
            if (Math.abs(shift) < 1) return;
            [th, ...this.cellNodes.map((cells) => cells[index])].filter(Boolean).forEach((node) => {
                node.style.transition = 'none';
                node.style.transform = `translateX(${shift}px)`;
                moved.push(node);
            });
        });
        if (moved.length === 0) return;
        // One frame at the old places, then the slide to the new ones.
        window.requestAnimationFrame(() => {
            moved.forEach((node) => {
                node.style.transition = 'transform 180ms cubic-bezier(.2, .8, .2, 1)';
                node.style.transform = '';
            });
            window.setTimeout(() => moved.forEach((node) => { node.style.transition = ''; }), 220);
        });
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
        const kind = this._undoKind(event);
        if (!kind) return false;
        event.preventDefault();
        event.stopPropagation();
        this._closeEditor(true);
        if (kind === 'undo') this.undo();
        else this.redo();
        return true;
    }

    /** 'undo' (Ctrl+Z), 'redo' (Ctrl+Y, Ctrl+Shift+Z; Cmd on a Mac) or null. */
    _undoKind(event) {
        if (!(event.ctrlKey || event.metaKey) || event.altKey) return null;
        const key = String(event.key ?? '').toLowerCase();
        const z = key === 'z' || event.code === 'KeyZ';
        const y = key === 'y' || event.code === 'KeyY';
        if (z && !event.shiftKey) return 'undo';
        return z || y ? 'redo' : null;
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
        this.sourceModel = model;
        model = this._ordered(this._withAdded(model));
        this.model = model;
        this._applyDateOrder();
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
            if (column.custom) th.classList.add('is-custom');
            if (column.type.input === 'select') th.dataset.choices = column.type.options.join(' / ');
            const label = document.createElement('span');
            label.className = 'review-table__label';
            th.append(label, ...this._headerExtras(column), this._resizer());
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
            this.onActive(null);
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
        // An added column is the reviewer's own: an empty cell in it is not an issue.
        const problem = cell.column.custom ? null : cellProblem(value, cell.column.type);
        // A cell being typed in keeps its box; it is drawn again once closed.
        if (td.classList.contains('is-editing')) return problem;
        // Not checked yet: shown, but not saved (greyed, with why; an edit saves it).
        const unverified = value.trim() !== '' && Boolean(this.isUnverified(cell));
        // A register date shows as it is saved (YYYY-MM-DD) once checked; until then
        // as it is written on the page (2-18-57), to compare with the handwriting.
        const iso = cell.column.type?.date ? this._isoDate(cell.column, value, rowIndex) : null;
        td.textContent = iso && !unverified ? iso : value;
        // A misread date repaired from its column: red, saying what was read.
        const repaired = Boolean(cell.column.type?.date) && !problem && value.trim() !== ''
            && !toIsoDate(value, this._rowToday(rowIndex), { dayFirst: Boolean(cell.column.type.dayFirst) });
        td.classList.toggle('is-guessed', repaired);
        td.classList.toggle('is-empty', problem?.kind === 'empty');
        td.classList.toggle('is-cleared', problem?.kind === 'empty' && this.cleared.has(`${cell.row.id}|${cell.column.key}`));
        td.classList.toggle('is-invalid', problem?.kind === 'invalid');
        td.classList.toggle('is-unverified', unverified);
        td.classList.toggle('is-low', value.trim() !== '' && Boolean(this.isLow(cell)));
        // Checked against the handwriting: light green (the text as it is).
        td.classList.toggle('is-checked', this.showChecked && cell.refs.length > 0 && value.trim() !== '' && !unverified);
        td.title = problem?.message
            ?? (repaired ? `Read as "${value.trim()}": taken as ${iso ?? value.trim()} from the other dates in this column. Check it on the scan.` : null)
            ?? (unverified ? 'Not checked yet: compare it with the handwriting, then press Space (or edit it) to keep it. Unchecked values are not saved.' : '');
        td.setAttribute('aria-invalid', String(Boolean(problem)));
        const readOnly = this.isReadOnly(cell);
        td.classList.toggle('is-readonly', Boolean(readOnly));
        td.setAttribute('aria-readonly', String(Boolean(readOnly)));
        if (readOnly && !problem) td.title = String(readOnly);
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
                ? `${shown} (was ${column.header}). Drag to move it · double-click to rename it.`
                : `${shown}. Drag to move it · double-click to rename it.`;
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
            th.replaceChildren(label, ...this._headerExtras(this.model.columns[Number(th.dataset.column)]), this._resizer());
        }
        return label;
    }

    /**
     * What a header has besides its name: × to remove it, when it can go (a
     * register field every row needs cannot). Shown when the header is pointed
     * at (an added column's always). A double-click on the header renames it.
     */
    _headerExtras(column) {
        if (!this.canRemoveColumn(column)) return [];
        const tools = document.createElement('span');
        tools.className = 'review-table__header-tools';
        const header = this.headerOf(column);
        const button = (className, text, title, label) => {
            const node = document.createElement('button');
            node.type = 'button';
            node.className = className;
            node.dataset.key = column.key;
            node.tabIndex = -1;
            node.title = title;
            node.setAttribute('aria-label', label);
            node.innerHTML = text;
            return node;
        };
        tools.append(button('review-table__remove', '×', 'Remove this column', `Remove column ${header}`));
        return [tools];
    }

    /**
     * A question in a window over the page: `title`, `message`, an optional
     * `warning` (shown in amber), an optional text box (`input`: its starting
     * value), and Cancel / `confirm`. Resolves with true (or the typed text),
     * or null when cancelled.
     */
    _ask({ title, message, warning = null, input = null, confirm, danger = false }) {
        this._closeConfirm();
        this._closeEditor(true);
        const Modal = window.bootstrap?.Modal;
        if (!Modal) {
            // No Bootstrap (tests): the browser's own questions.
            if (input !== null) return Promise.resolve(window.prompt(`${title}\n${message}`, input));
            return Promise.resolve(window.confirm(`${title}\n${message}`) ? true : null);
        }

        const element = document.createElement('div');
        element.className = 'modal fade review-table-modal';
        element.tabIndex = -1;
        element.setAttribute('aria-hidden', 'true');
        element.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3" data-message></p>
                        <div class="alert alert-warning d-flex gap-2 small mb-3" role="alert" data-warning>
                            <i class="icon-base bx bx-error mt-1" aria-hidden="true"></i><span></span>
                        </div>
                        <input type="text" class="form-control" maxlength="120" autocomplete="off" spellcheck="false" data-input>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" data-confirm></button>
                    </div>
                </form>
            </div>`;
        element.querySelector('.modal-title').textContent = title;
        element.querySelector('[data-message]').textContent = message;
        const warningBox = element.querySelector('[data-warning]');
        if (warning) warningBox.querySelector('span').textContent = warning;
        else warningBox.remove();
        const box = element.querySelector('[data-input]');
        if (input === null) box.remove();
        else {
            box.value = input;
            box.setAttribute('aria-label', title);
        }
        const confirmButton = element.querySelector('[data-confirm]');
        confirmButton.textContent = confirm;
        confirmButton.classList.add(danger ? 'btn-danger' : 'btn-primary');
        if (input === null) element.querySelector('[data-message]').classList.toggle('mb-0', !warning);
        document.body.appendChild(element);

        const modal = new Modal(element);
        let answer = null;
        return new Promise((resolve) => {
            element.querySelector('form').addEventListener('submit', (event) => {
                event.preventDefault();
                answer = input === null ? true : box.value.trim();
                modal.hide();
            });
            element.addEventListener('shown.bs.modal', () => {
                if (input === null) confirmButton.focus();
                else {
                    box.focus();
                    box.select();
                }
            });
            element.addEventListener('hidden.bs.modal', () => {
                modal.dispose();
                element.remove();
                if (this._confirm?.element === element) this._confirm = null;
                // Back to the table, on the picked cell.
                if (this.active) this.cellNodes[this.active.row]?.[this.active.column]?.focus({ preventScroll: true });
                resolve(answer);
            });
            this._confirm = { element, modal };
            modal.show();
        });
    }

    /**
     * Rename a column, in a window: its new name is used in this table and in
     * the downloads made here. A register field keeps its name in the register
     * (the database and Saved Records' downloads), which the window says. An
     * empty name, or the old one, puts the original back. Ctrl+Z undoes it.
     */
    async renameColumn(key) {
        const column = this.model?.columns.find((candidate) => candidate.key === key);
        if (!column) return;
        const current = this.headerOf(column);
        const name = await this._ask({
            title: `Rename column "${current}"`,
            message: 'The new name shows in this table and in the downloads you make here. Leave it empty to go back to '
                + `"${column.header}".`,
            warning: column.field
                ? `"${column.field}" is a field of the register. The database keeps that name, so Saved Records `
                    + `and book downloads still say "${column.field}".`
                : null,
            input: current,
            confirm: 'Rename',
        });
        if (name === null) return;
        const text = String(name).trim().slice(0, 120);
        this._rename(this.headerNames, 'header', column.key, text === '' || text === column.header ? null : text);
        this._paintLabels();
    }

    /**
     * Ask, in a window, before removing a column: an added one goes with what was
     * typed in it; a read one leaves the table and its downloads; a register field
     * is not saved for this document (left blank). Ctrl+Z, or the Removed columns list,
     * brings it back.
     */
    async _confirmRemove(key) {
        const column = this.addedColumns.find((candidate) => candidate.key === key)
            ?? this.model?.columns.find((candidate) => candidate.key === key && this.canRemoveColumn(candidate));
        if (!column) return;
        const header = this.headerOf(column);
        const typed = [...this.addedValues.keys()].filter((id) => id.endsWith(`|${key}`)).length;
        const back = column.custom ? 'Ctrl+Z brings it back.' : 'Ctrl+Z, or Removed columns, brings it back.';
        const message = column.custom
            ? `${typed > 0 ? `${typed} typed ${typed === 1 ? 'value' : 'values'} in it will be removed too.` : 'Nothing has been typed in it yet.'} ${back}`
            : `It leaves this table and its downloads. ${back}`;
        const ok = await this._ask({
            title: `Remove column "${header}"?`,
            message,
            warning: column.field
                ? `"${column.field}" will not be saved for this document: it stays blank in the register.`
                : null,
            confirm: 'Remove column',
            danger: true,
        });
        if (!ok) return;
        this.removeColumn(key);
        this.onColumnsChange();
    }

    /** Ask, then remove a column (`anchor` is no longer used: the question is a window). */
    confirmRemoveColumn(key) {
        this._confirmRemove(key);
    }

    /** Close the question window, if one is open (nothing changes). */
    _closeConfirm() {
        const confirm = this._confirm;
        if (!confirm) return;
        this._confirm = null;
        confirm.modal?.hide();
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
                // An added column's header also holds its × (about 18px).
                widthOf(column ? this.headerOf(column) : th.textContent, th) + 8 + (column?.custom ? 18 : 0),
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
        const before = this.widths.get(key) ?? null;
        document.body.classList.add('is-resizing-column');
        const move = (moveEvent) => this._setWidth(key, startWidth + moveEvent.clientX - startX);
        const end = () => {
            // The whole drag is one change for Ctrl+Z (a press without a move is none).
            const after = this.widths.get(key) ?? null;
            if (after !== before) this._remember({ width: true, key, before, after });
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
        const before = this.widths.get(key) ?? null;
        // Even a long value: a double-click shows all of it (the limit is for the automatic width).
        this._setWidth(key, this._contentWidth(th, true));
        const after = this.widths.get(key) ?? null;
        if (after !== before) this._remember({ width: true, key, before, after });
    }

    /** A column's width as a change left it (null: as wide as its longest value again). */
    _restoreWidth(key, width) {
        if (width === null) {
            this.widths.delete(key);
            this._autoSizeColumns();
        } else {
            this._setWidth(key, width);
        }
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

    /**
     * Pick the next cell still to check after the picked one: across its row, then
     * the rows below (wraps round). Stays put when every value is checked.
     */
    selectNextToCheck() {
        const rows = this.model.rows.length;
        const columns = this.model.columns.length;
        if (!this.active || rows * columns === 0) return false;
        const start = this.active.row * columns + this.active.column + 1;
        for (let step = 0; step < rows * columns - 1; step += 1) {
            const index = (start + step) % (rows * columns);
            const row = Math.floor(index / columns);
            const column = index % columns;
            const cell = this.cell(row, column);
            if (cell.refs.length > 0 && String(this.valueOf(cell) ?? '').trim() !== '' && this.isUnverified(cell)) {
                this.select(row, column);
                return true;
            }
        }
        return false;
    }

    /**
     * Write the date cells among `cells` as they are saved (YYYY-MM-DD: "2-18-57"
     * becomes 1957-02-18), so a checked date is the converted one. One change for
     * Ctrl+Z; a date that cannot be read whole is left as it is.
     */
    writeDates(cells) {
        const changes = [];
        cells.forEach((cell) => {
            if (!cell?.column?.type?.date || cell.refs.length === 0) return;
            const row = this.model.rows.indexOf(cell.row);
            const column = this.model.columns.indexOf(cell.column);
            const value = String(this.valueOf(cell) ?? '').trim();
            const iso = value === '' ? null : this._isoDate(cell.column, value, row);
            if (row < 0 || column < 0 || !iso || iso === value) return;
            changes.push({ row, column, value: iso });
        });
        return changes.length > 0 ? this._apply(changes) : 0;
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
            if (!cell.column.custom && cellProblem(this.valueOf(cell), cell.column.type)) {
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
        this.onActive(this.cell(row, column));
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
        // Shown, not typed into: it says where it is changed.
        if (this.isReadOnly(cell)) return;
        const type = cell.column.type;
        // The box starts from what the cell shows: a checked date as it is saved
        // (1957-02-18), one not checked yet as written (2-18-57). Left as it is,
        // the cell is not changed (nor checked).
        const read = String(this.valueOf(cell) ?? '');
        const original = type?.date && read.trim() !== '' && !this.isUnverified(cell)
            ? (this._isoDate(cell.column, read.trim(), row) ?? read)
            : read;
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
        if (Array.isArray(type.options) && type.options.length > 0) this._openSuggestions(input, type.options, typed !== undefined && typed !== null && typed !== '');
    }

    /**
     * A choice column's list under the box: the choices that start with what is typed
     * (PHY → PHYSICIAN). Up / Down mark one, Enter or a click puts it in the cell.
     */
    _openSuggestions(input, options, initial = false) {
        const list = document.createElement('ul');
        list.className = 'review-table__suggestions list-group shadow-sm';
        list.setAttribute('role', 'listbox');
        // Solid, above the table: the cells under it must not show through.
        Object.assign(list.style, {
            position: 'fixed', zIndex: '2000', maxHeight: '14rem', overflowY: 'auto', margin: '0', padding: '0',
            background: 'var(--bs-paper-bg, var(--bs-body-bg, #fff))', border: '1px solid var(--bs-border-color, #d9dee3)',
            borderRadius: '.375rem', boxShadow: '0 .25rem 1rem rgba(0, 0, 0, .15)',
        });
        // Opened by a click, the list holds every choice (the cell's own marked); it narrows once typing starts.
        const state = { list, items: [], marked: -1, filtering: initial };
        const paint = () => {
            const typed = input.value.trim().toLowerCase();
            const shown = options.map(String).filter((option) => !state.filtering || option.toLowerCase().startsWith(typed));
            state.items = shown;
            state.marked = state.filtering
                ? (shown.length > 0 && typed !== '' ? 0 : -1)
                : shown.findIndex((option) => option.toLowerCase() === typed);
            list.replaceChildren(...shown.map((option, position) => {
                const item = document.createElement('li');
                item.className = 'list-group-item list-group-item-action py-1 px-2 small';
                item.setAttribute('role', 'option');
                item.textContent = option;
                item.style.cursor = 'pointer';
                item.style.border = '0';
                item.style.background = position === state.marked ? '' : 'transparent';
                item.addEventListener('mousedown', (event) => {
                    // Keep the focus in the box, so the click is not a blur.
                    event.preventDefault();
                    input.value = shown[position];
                    this.editor?.input === input && this.commitChoice();
                });
                return item;
            }));
            mark();
            const box = input.getBoundingClientRect();
            list.style.left = `${box.left}px`;
            list.style.top = `${box.bottom + 2}px`;
            list.style.minWidth = `${Math.max(box.width, 160)}px`;
            list.hidden = shown.length === 0;
        };
        const mark = () => [...list.children].forEach((item, position) => {
            item.classList.toggle('active', position === state.marked);
            item.style.background = position === state.marked ? '' : 'transparent';
        });
        state.move = (step) => {
            if (state.items.length === 0) return false;
            state.marked = (state.marked + step + state.items.length) % state.items.length;
            mark();
            list.children[state.marked]?.scrollIntoView({ block: 'nearest' });
            return true;
        };
        state.pick = () => {
            if (state.marked < 0 || !state.items[state.marked]) return;
            input.value = state.items[state.marked];
        };
        // The whole word shows in the box as it is typed: "phy" reads PHYSICIAN, the rest
        // marked (the next key replaces it; Backspace takes it off).
        const complete = () => {
            const typed = input.value;
            const full = state.items[state.marked];
            if (!full || typed === '') return;
            if (full.length > typed.length && full.toLowerCase().startsWith(typed.toLowerCase())) {
                input.value = full;
                input.setSelectionRange(typed.length, full.length);
            }
        };
        input.addEventListener('input', (event) => {
            state.filtering = true;
            paint();
            // Not while deleting.
            if (String(event.inputType ?? '').startsWith('insert')) complete();
        });
        document.body.append(list);
        paint();
        // A cell opened by typing a letter (no input event for that first key) completes too.
        if (initial) complete();
        this.suggestions = state;
    }

    /** A choice clicked in the list: it is kept, and the cursor stays on the cell. */
    commitChoice() {
        const at = this.editor;
        if (!at) return;
        this._closeEditor(true);
        this.cellNodes[at.row]?.[at.column]?.focus({ preventScroll: true });
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
        this.suggestions?.list.remove();
        this.suggestions = null;
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
            // Deleted, pasted over or cut: a read-only cell keeps its value.
            if (!cell || this.isReadOnly(cell)) return;
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
        if (change.width) {
            this._closeEditor(true);
            this._restoreWidth(change.key, forward ? change.after : change.before);
            return;
        }
        if (change.order) {
            this._reorder([...(forward ? change.after : change.before)], false);
            return;
        }
        if (change.columnChange === 'hide' || change.columnChange === 'show') {
            if ((change.columnChange === 'hide') === forward) this.hiddenColumns.add(change.key);
            else this.hiddenColumns.delete(change.key);
            this._closeEditor(true);
            this.render(this.sourceModel);
            this.onColumnsChange();
            return;
        }
        if (change.columnChange) {
            // Added forward / removed backward: the column comes back; else it goes again.
            const bringBack = (change.columnChange === 'add') === forward;
            if (bringBack) {
                if (change.snapshot) this._putColumn(change.snapshot);
            } else {
                change.snapshot = this._takeColumn(change.key) ?? change.snapshot;
            }
            this.onColumnsChange();
            return;
        }
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
        const remove = event.target instanceof Element ? event.target.closest('.review-table__remove') : null;
        // Pressing a header's resize grip or its × must not start a column drag.
        const pressed = event.target instanceof Element ? event.target.closest('thead th[data-column]') : null;
        if (pressed) pressed.dataset.noDrag = grip || remove ? '1' : '0';
        // The delete button acts on its click (see the constructor).
        if (remove) {
            event.preventDefault();
            return;
        }
        if (grip) {
            this._resizeStart(event, grip);
            return;
        }
        if (pressed && !pressed.classList.contains('is-renaming')) {
            this._columnDrag(event, pressed);
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
            // Several cells of a choice column dragged over: its list opens at once, and the
            // choice clicked (or Enter) goes in every picked cell.
            const options = this.model.columns[column]?.type?.options;
            if (drag.moved && !this.editor && Array.isArray(options) && options.length > 0 && this.selectedRows().length > 1) this.edit();
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
            if (column) this.renameColumn(column.key);
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
            // A click opens the cell's box, so the cursor is nearly always in one. While what
            // was typed there is not yet undone, Ctrl+Z is the box's own; once the box holds the
            // cell's value again (or nothing was typed), Ctrl+Z / Ctrl+Y undo and redo the
            // table: a cell deleted by accident comes back.
            if (this.editor.input.value === this.editor.original && this._undoKey(event)) return;
            if (this._undoKind(event) === 'undo') {
                // The box's own undo goes first. When it has nothing left to take back (the
                // first key typed on a picked cell is put in by the table, not the box), the
                // cell's value comes back instead, and the next Ctrl+Z is the table's.
                const { input } = this.editor;
                const shown = input.value;
                window.setTimeout(() => {
                    if (this.editor?.input !== input || input.value !== shown) return;
                    input.value = this.editor.original;
                    input.dispatchEvent(new Event('input'));
                }, 0);
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                const at = this.editor;
                this._closeEditor(false);
                this.cellNodes[at.row][at.column].focus({ preventScroll: true });
                return;
            }
            if (this.suggestions && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
                if (this.suggestions.move(event.key === 'ArrowDown' ? 1 : -1)) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                return;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                event.stopPropagation();
                // The marked choice of the list goes in first.
                this.suggestions?.pick();
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
        // Alt+Shift+← / → (Option on a Mac): the picked cell's column one place over.
        if (event.altKey && event.shiftKey && !event.ctrlKey && !event.metaKey
            && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
            event.preventDefault();
            event.stopPropagation();
            this._moveActiveColumn(event.key === 'ArrowLeft' ? -1 : 1);
            return;
        }
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
        // Space: the picked cells checked against the handwriting (or unchecked).
        if (event.key === ' ' && this.onToggle) {
            event.preventDefault();
            event.stopPropagation();
            const cells = this.selectedRows().map((picked) => this.cell(picked, column)).filter(Boolean);
            const checked = this.onToggle(cells);
            // One cell checked: on to the next one still to check (the next column, then the next row).
            if (checked && cells.length === 1) this.selectNextToCheck();
            return;
        }
        // Typing on a picked cell replaces its value, as in a spreadsheet.
        if (event.key.length === 1) {
            event.preventDefault();
            this.edit(event.key);
        }
    }

    /**
     * [header, ...rows] of what the table shows now, for the files (with any
     * renamed labels). A value not verified is left out, as it is not saved.
     */
    exportTable() {
        this._applyDateOrder();
        // A register's table (its columns are register fields) downloads as the
        // register does: its field names as headers, no Row column, and without
        // the scanned columns no field is filled from (noExport).
        // A register's download is its fields only, in the register's order
        // (whatever order the columns were dragged to; added columns are not saved).
        const register = this.model.columns.some((column) => column.field);
        const placeOf = new Map((this.sourceModel?.columns ?? this.model.columns).map((column, index) => [column.key, index]));
        const shown = this.model.columns
            .map((column, index) => ({ column, index }))
            .filter(({ column }) => !column.noExport && (!register || column.field))
            .sort((a, b) => (register ? (placeOf.get(a.column.key) ?? a.index) - (placeOf.get(b.column.key) ?? b.index) : 0));
        const header = [...(register ? [] : ['Row']), ...shown.map(({ column }) => (register ? (this.headerNames.get(column.key) ?? column.field) : this.headerOf(column)))];
        // Every value in capitals. A row with nothing checked or typed is not saved, so not downloaded.
        const rows = this.model.rows
            .map((row, rowIndex) => ({ row, rowIndex }))
            .filter(({ row }) => !this.isRowLeftOut(row))
            .map(({ row, rowIndex }) => [
                ...(register ? [] : [this.rowLabelOf(row)]),
                ...shown.map(({ index }) => exportCase(downloadedFlag(this.model.columns[index], this._exported(this.cell(rowIndex, index))))),
            ]);
        return [header, ...rows];
    }

    /** A cell's value as it is saved and downloaded: a value not verified is left out. */
    _exported(cell) {
        if (this.isUnverified(cell)) return '';
        const value = String(this.valueOf(cell) ?? '').trim();
        // A register date is saved and downloaded as YYYY-MM-DD.
        return cell.column.type?.date ? (this._isoDate(cell.column, value, this.model.rows.indexOf(cell.row)) ?? value) : value;
    }

    /** A date of `column` as YYYY-MM-DD (null: not a whole date), read as the column is written. */
    _isoDate(column, value, rowIndex = -1) {
        const dayFirst = Boolean(column.type?.dayFirst);
        const today = this._rowToday(rowIndex);
        return toIsoDate(value, today, { dayFirst }) ?? repairedDate(value, column.type?.usualMonth, { dayFirst, today });
    }

    /**
     * The latest a row's two-digit years can be: the end of the year it was
     * registered (its date_of_reg), as nothing in an entry is later than that
     * ("7-15-25" on a 1957 entry is 1925); without one, today.
     */
    _rowToday(rowIndex) {
        const now = new Date();
        const regAt = this.model?.columns.findIndex((column) => column.field === 'date_of_reg' && column.type?.date) ?? -1;
        if (rowIndex < 0 || regAt < 0 || !this.model.rows[rowIndex]) return now;
        const iso = toIsoDate(String(this.valueOf(this.cell(rowIndex, regAt)) ?? ''), now, { dayFirst: Boolean(this.model.columns[regAt].type?.dayFirst) });
        // Not readable on this row: the year the page was registered (the column's usual month).
        const year = iso ? Number(iso.slice(0, 4)) : this.model.columns[regAt].type?.usualMonth?.year;
        return year ? new Date(Date.UTC(year, 11, 31)) : now;
    }

    /**
     * How the dates are written: 'auto' (each date column by what it holds: one
     * date that can only be day first, "25-3-57", makes the whole column day
     * first), 'month' or 'day'. The table is drawn again.
     */
    setDateOrder(order) {
        this.dateOrder = ['month', 'day'].includes(order) ? order : 'auto';
        if (this.sourceModel) this.render(this.sourceModel);
    }

    /** Each date column's order worked out again (on its type: dayFirst). */
    _applyDateOrder() {
        if (!this.model) return;
        this.model.columns.forEach((column, index) => {
            if (!column.type?.date) return;
            let dayFirst = this.dateOrder === 'day';
            if (this.dateOrder !== 'month' && this.dateOrder !== 'day') {
                const values = this.model.rows.map((_, row) => String(this.valueOf(this.cell(row, index)) ?? ''));
                dayFirst = dateOrderOf(values) === 'day';
            }
            // The column's usual month, for a date misread by one digit (repairedDate).
            const read = this.model.rows.map((_, row) => toIsoDate(String(this.valueOf(this.cell(row, index)) ?? ''), this._rowToday(row), { dayFirst }));
            const usualMonth = usualMonthOf(read);
            const same = (a, b) => (a?.year === b?.year && a?.month === b?.month);
            if (Boolean(column.type.dayFirst) !== dayFirst || !same(column.type.usualMonth, usualMonth)) {
                column.type = { ...column.type, dayFirst, usualMonth };
            }
        });
    }

    /**
     * Each row as the register's table saves it: { row: "06", values: { field: value } },
     * from the columns that are register fields (column.field).
     */
    fieldRows() {
        this._applyDateOrder();
        const fields = this.model.columns
            .map((column, index) => ({ column, index }))
            .filter(({ column }) => column.field && !column.noExport);
        return this.model.rows
            .map((row, rowIndex) => ({ row, rowIndex }))
            .filter(({ row }) => !this.isRowLeftOut(row))
            .map(({ row, rowIndex }) => ({
                row: this.rowLabelOf(row),
                values: Object.fromEntries(fields.map(({ column, index }) => [column.field, this._exported(this.cell(rowIndex, index))])),
            }));
    }

    /**
     * The first cell a register's row cannot be saved with: { row, column, message },
     * or null. A required value shown but not verified counts as missing.
     */
    firstFieldIssue() {
        this._applyDateOrder();
        for (let row = 0; row < this.model.rows.length; row += 1) {
            if (this.isRowLeftOut(this.model.rows[row])) continue;
            for (let column = 0; column < this.model.columns.length; column += 1) {
                const cell = this.cell(row, column);
                if (!cell.column.field || cell.column.noExport) continue;
                const value = this._exported(cell);
                const problem = cellProblem(value, cell.column.type);
                if (!problem) continue;
                const where = `Row ${this.rowLabelOf(cell.row)}, ${cell.column.field}`;
                const unverified = value === '' && String(this.valueOf(cell) ?? '').trim() !== '';
                return {
                    row,
                    column,
                    message: unverified
                        ? `${where}: the value is not checked yet. Compare it with the handwriting, then press Space (or edit it).`
                        : `${where}: ${problem.message}.`,
                };
            }
        }
        // A register number may repeat on a page (the books have repeats): not a problem.
        return null;
    }
}
