import test from 'node:test';
import assert from 'node:assert/strict';

import {
    LINE_TWO,
    NOT_A_FIELD,
    applyRegistry,
    buildReviewModel,
    cellProblem,
    clampSplit,
    combineTables,
    columnType,
    csvSafe,
    exportFileName,
    fieldKeyOf,
    joinTables,
    matchField,
    nextCellPosition,
    parseClipboard,
    registryColumnType,
    ReviewTable,
    rowSpan,
    selectStart,
    sheetName,
    toCsv,
    toSheetData,
} from '../../resources/js/review-table.js';

test('Sex is a dropdown of M and F whatever the layout says', () => {
    assert.deepEqual(columnType('Sex').options, ['M', 'F']);
    assert.equal(columnType(' sex ').input, 'select');
    assert.equal(columnType('Gender', { type: 'text' }).input, 'select');
    assert.equal(columnType('Name').input, 'text');
});

test('a column with layout choices becomes a dropdown; other types keep their check', () => {
    const choice = columnType('Status', { type: 'choice', options: ['Legitimate', 'Illegitimate'] });
    assert.equal(choice.input, 'select');
    assert.deepEqual(choice.options, ['Legitimate', 'Illegitimate']);
    assert.equal(columnType('Born', { type: 'date' }).settings.type, 'date');
});

test('a new rule is all it takes to give a column its own input', () => {
    const rules = [{ matches: (name) => name === 'Civil status', type: { input: 'select', options: ['S', 'M'] } }];
    assert.deepEqual(columnType('Civil status', null, rules).options, ['S', 'M']);
    assert.equal(columnType('Sex', null, rules).input, 'text');
});

test('only empty cells are problems, in every column', () => {
    const sex = columnType('Sex');
    assert.equal(cellProblem('', sex).kind, 'empty');
    assert.equal(cellProblem('Male', sex), null);
    assert.equal(cellProblem('M', sex), null);
    assert.equal(cellProblem('  F ', sex), null);

    const remarks = columnType('Remarks', { required: false });
    assert.equal(cellProblem('  ', remarks).kind, 'empty');

    const born = columnType('Born', { type: 'date' });
    assert.equal(cellProblem('Jan. 03, 1978', born), null);
    assert.equal(cellProblem('soon', born), null);
});

test('a dropdown starts on the choice a read value stands for', () => {
    assert.equal(selectStart('M', ['M', 'F']), 'M');
    assert.equal(selectStart('female', ['M', 'F']), '');
    assert.equal(selectStart('f', ['M', 'F']), 'F');
});

const pages = [
    { number: 1, columns: [{ name: 'Name' }, { name: 'Sex' }, { name: 'Remarks' }] },
    { number: 2, columns: [{ name: 'Father' }, { name: 'Remarks', settings: { required: false } }] },
];

test('one row per person, page 1 columns then page 2, a shared name says its page', () => {
    const model = buildReviewModel({
        pages,
        people: [
            { id: 'person-1', label: '01', fields: [
                { side: 0, column: 'Name', ref: 0 },
                { side: 0, column: 'Sex', ref: 1 },
                { side: 1, column: 'Father', ref: 2 },
                { side: 1, column: 'Remarks', ref: 3 },
            ] },
            { id: 'person-2', label: '02', fields: [{ side: 0, column: 'Name', ref: 4 }, { side: 0, column: 'Name', ref: 5 }] },
        ],
    });

    assert.deepEqual(model.columns.map((column) => column.header), ['Name', 'Sex', 'Remarks', 'Father', 'Remarks (page 2)']);
    assert.equal(model.rows.length, 2);
    assert.deepEqual(model.rows[0].cells['1|remarks'].refs, [3]);
    assert.deepEqual(model.rows[0].cells['0|remarks'].refs, []);
    // Two written lines of one cell are one cell.
    assert.deepEqual(model.rows[1].cells['0|name'].refs, [4, 5]);
    assert.equal(model.columns[4].type.settings.required, false);
});

test('a column only a field has is added after its page\'s columns; details come first', () => {
    const model = buildReviewModel({
        pages: [pages[0]],
        people: [{ id: 'person-1', label: '01', fields: [{ side: 0, column: 'Extra note', ref: 9 }] }],
        details: [{ side: 0, column: 'Registry year', ref: 7 }],
    });
    assert.deepEqual(model.columns.map((column) => column.header), ['Registry year', 'Name', 'Sex', 'Remarks', 'Extra note']);
    assert.deepEqual(model.rows[0].cells['d|0|registry year'].refs, [7]);
});

test('CSV is UTF-8 with a byte-order mark, quoted where needed, formulas made text', () => {
    const csv = toCsv([['Row', 'Name'], ['01', 'Peña, José'], ['02', '=HYPERLINK("x")'], ['03', 'Line "one"']]);
    assert.ok(csv.startsWith('﻿'));
    assert.equal(csv, '﻿Row,Name\r\n01,"Peña, José"\r\n02,"\'=HYPERLINK(""x"")"\r\n03,"Line ""one"""\r\n');
    assert.equal(csvSafe('-5'), "'-5");
    assert.equal(csvSafe('Ñino'), 'Ñino');
});

test('the Excel sheet keeps every value as text with a bold header', () => {
    const sheet = toSheetData([['Row', 'Name'], ['01', 'Ñ']]);
    assert.equal(sheet[0][0].fontWeight, 'bold');
    assert.equal(sheet[1][0].value, '01');
    assert.equal(sheet[1][0].type, String);
    assert.equal(sheet[1][1].fontWeight, undefined);
});

test('file names are readable and dated', () => {
    assert.equal(exportFileName('Book of Birth', 'Book 12 Page 23', '2026-11-25'), 'Book of Birth - Book 12 Page 23 - 2026-11-25');
    assert.equal(exportFileName('Births', 'birth_book_12.pdf', 'pages 1 2', '2026-11-15'), 'Births - birth book 12 - pages 1 2 - 2026-11-15');
    // Characters a file name cannot have become spaces; empty parts are skipped.
    assert.equal(exportFileName('Defunciónes', 'a/b:c?', '', null), 'Defunciónes - a b c');
    assert.equal(exportFileName('', null), 'Records');
});

test('Enter goes down, Tab goes right and wraps, Shift goes back, edges hold', () => {
    assert.deepEqual(nextCellPosition({ row: 0, column: 0 }, 'Enter', false, 3, 4), { row: 1, column: 0 });
    assert.deepEqual(nextCellPosition({ row: 0, column: 0 }, 'Enter', true, 3, 4), { row: 0, column: 0 });
    assert.deepEqual(nextCellPosition({ row: 0, column: 3 }, 'Tab', false, 3, 4), { row: 1, column: 0 });
    assert.deepEqual(nextCellPosition({ row: 1, column: 0 }, 'Tab', true, 3, 4), { row: 0, column: 3 });
    assert.deepEqual(nextCellPosition({ row: 2, column: 3 }, 'Tab', false, 3, 4), { row: 2, column: 3 });
    assert.deepEqual(nextCellPosition({ row: 2, column: 1 }, 'ArrowDown', false, 3, 4), { row: 2, column: 1 });
});

test('the split stays between 20% and 65%, half and half by default', () => {
    assert.equal(clampSplit(null), 50);
    assert.equal(clampSplit('abc'), 50);
    assert.equal(clampSplit(5), 20);
    assert.equal(clampSplit(90), 65);
    assert.equal(clampSplit('41.63'), 41.6);
});

test('cells dragged down or up a column cover every row between, top first', () => {
    assert.deepEqual(rowSpan(1, 4), [1, 2, 3, 4]);
    assert.deepEqual(rowSpan(4, 1), [1, 2, 3, 4]);
    assert.deepEqual(rowSpan(2, 2), [2]);
});

test('clipboard text becomes rows and cells', () => {
    assert.deepEqual(parseClipboard('Filipino'), [['Filipino']]);
    assert.deepEqual(parseClipboard('a\nb\n'), [['a'], ['b']]);
    assert.deepEqual(parseClipboard('a\tb\r\nc\td'), [['a', 'b'], ['c', 'd']]);
    assert.deepEqual(parseClipboard(''), []);
});


test('a batch downloads as one table: each row says its record and document', () => {
    const table = combineTables([
        { recordId: 7, label: 'BIRTHS.pdf · pages 1–2', table: [['Row', 'Name', 'Sex'], ['01', 'Ana', 'F'], ['02', 'Ben', 'M']] },
        { recordId: 8, label: 'BIRTHS.pdf · page 3', table: [['Row', 'Name', 'Father'], ['01', 'Cara', 'Jose']] },
    ]);

    assert.deepEqual(table, [
        ['Record ID', 'Document', 'Row', 'Name', 'Sex', 'Father'],
        ['7', 'BIRTHS.PDF · PAGES 1–2', '01', 'ANA', 'F', ''],
        ['7', 'BIRTHS.PDF · PAGES 1–2', '02', 'BEN', 'M', ''],
        ['8', 'BIRTHS.PDF · PAGE 3', '01', 'CARA', '', 'JOSE'],
    ]);
    assert.deepEqual(combineTables([]), [['Record ID', 'Document']]);
    // A register's records: its own columns only.
    assert.deepEqual(combineTables([
        { recordId: 7, label: 'x', table: [['name', 'sex'], ['Ana', 'f']] },
        { recordId: 8, label: 'y', table: [['name', 'sex'], ['Ben', 'm']] },
    ], { plain: true }), [['name', 'sex'], ['ANA', 'F'], ['BEN', 'M']]);
});

test('several records join into one table by column name, blanks where a record has no such column', () => {
    const table = joinTables([
        { header: ['Record ID', 'Document type', 'Row', 'Name', 'Sex'], rows: [[12, 'Birth Certificate', 1, 'Ana Cruz', 'F']] },
        { header: ['Record ID', 'Document type', 'Row', 'Name', 'Remarks'], rows: [[13, 'Birth Certificate', 1, 'Ben Reyes', 'late'], [13, 'Birth Certificate', 2, 'Cora Lim', null]] },
    ]);

    assert.deepEqual(table, [
        ['Record ID', 'Document type', 'Row', 'Name', 'Sex', 'Remarks'],
        ['12', 'BIRTH CERTIFICATE', '1', 'ANA CRUZ', 'F', ''],
        ['13', 'BIRTH CERTIFICATE', '1', 'BEN REYES', '', 'LATE'],
        ['13', 'BIRTH CERTIFICATE', '2', 'CORA LIM', '', ''],
    ]);
});

test('Excel sheet names are short, without the characters Excel refuses, and never repeated', () => {
    const used = new Set();
    assert.equal(sheetName('All records', used), 'All records');
    assert.equal(sheetName('#12 Birth/Death [old]', used), '#12 Birth Death old');
    assert.equal(sheetName('all RECORDS', used), 'all RECORDS (2)');
    assert.equal(sheetName('x'.repeat(40), used).length, 31);
    assert.equal(sheetName('', used), 'Sheet');
});

test('a value not ticked Verified is shown but left out of the download, as it is not saved', () => {
    const ticked = new Set([0]);
    const values = ['Ana Cruz', 'F'];
    const table = new ReviewTable({
        container: { addEventListener() {} },
        valueOf: (cell) => cell.refs.map((index) => values[index]).join(' '),
        onChange: () => {},
        isUnverified: (cell) => cell.refs.length > 0 && cell.refs.every((index) => !ticked.has(index)),
    });
    table.model = {
        columns: [{ key: 'name', header: 'Name' }, { key: 'sex', header: 'Sex' }],
        rows: [{ id: 'person-1', label: '01', cells: { name: { refs: [0] }, sex: { refs: [1] } } }],
    };
    assert.deepEqual(table.exportTable(), [['Row', 'Name', 'Sex'], ['01', 'ANA CRUZ', '']]);
    ticked.add(1);
    assert.deepEqual(table.exportTable(), [['Row', 'Name', 'Sex'], ['01', 'ANA CRUZ', 'F']]);
});

// ------------------------------------------------- a register's own columns

const BIRTH = [
    { key: 'name', kind: 'text', required: true, manual: false },
    { key: 'sex', kind: 'text', required: false, manual: false },
    { key: 'reg_no', kind: 'text', required: true, manual: false },
    { key: 'mother_age', kind: 'integer', required: false, manual: false },
    { key: 'attendant', kind: 'choice', required: false, manual: true, options: ['PHYSICIAN', 'MIDWIFE'] },
    { key: 'late_registration', kind: 'boolean', required: false, manual: true, options: ['true', 'false'], default: 'false' },
];

const scanned = () => buildReviewModel({
    pages: [{ number: 1, columns: [{ name: 'Column 1' }, { name: 'Name' }, { name: 'Column 3' }] }],
    people: [{ id: 'person-1', label: '01', fields: [
        { side: 0, column: 'Column 1', ref: 0 }, { side: 0, column: 'Name', ref: 1 }, { side: 0, column: 'Column 3', ref: 2 },
    ] }],
});

test('the table is laid out as the register: its fields in order, a column named like one fills it', () => {
    const model = applyRegistry(scanned(), BIRTH);
    assert.deepEqual(model.columns.map((column) => column.header), [
        'name', 'sex', 'reg_no', 'mother_age', 'attendant', 'late_registration',
        'Column 1 · not saved', 'Column 3 · not saved',
    ]);
    // "Name" fills name and keeps its key, so what was typed in it stays.
    assert.equal(model.columns[0].key, '0|name');
    assert.deepEqual(model.rows[0].cells['0|name'].refs, [1]);
    // A field no column fills is typed in.
    assert.equal(model.columns[1].key, 'm|sex');
    assert.deepEqual(model.rows[0].cells['m|sex'].refs, []);
    assert.ok(model.columns.slice(6).every((column) => column.noExport));
});

test('a saved choice of columns is used as it is, and a manual field is never filled from the scan', () => {
    const map = { 'column 1': 'reg_no', 'column 3': 'attendant', name: 'name' };
    const model = applyRegistry(scanned(), BIRTH, () => map);
    const byField = Object.fromEntries(model.columns.filter((column) => column.field).map((column) => [column.field, column.key]));
    assert.equal(byField.reg_no, '0|column 1');
    assert.equal(byField.name, '0|name');
    assert.equal(byField.attendant, 'm|attendant');
    assert.equal(fieldKeyOf('  Name of Mother '), 'name_of_mother');
});

test('a register field checks its value: required, digits, fixed values; a blank optional is fine', () => {
    const [name, sex, , age, attendant] = BIRTH.map(registryColumnType);
    assert.equal(cellProblem('', name).kind, 'empty');
    assert.equal(cellProblem('', sex), null);
    assert.equal(cellProblem('27', age), null);
    assert.equal(cellProblem('27 yrs', age).kind, 'invalid');
    assert.equal(cellProblem('MIDWIFE', attendant), null);
    assert.equal(cellProblem('Doctor', attendant).kind, 'invalid');
    assert.equal(selectStart('phy', attendant.options), 'PHYSICIAN');
});

test('a register downloads with its field names and no Row column, and saves each row by field', () => {
    const values = ['5', 'Ariel Villanujan', 'x'];
    const typed = new Map([['person-1|m|attendant', 'MIDWIFE']]);
    const defaults = { late_registration: 'false' };
    const table = new ReviewTable({
        container: { addEventListener() {} },
        valueOf: (cell) => (cell.refs.length > 0
            ? cell.refs.map((index) => values[index]).join(' ')
            : typed.get(`${cell.row.id}|${cell.column.key}`) ?? defaults[cell.column.field] ?? ''),
        onChange: () => {},
    });
    table.model = applyRegistry(scanned(), BIRTH, () => ({ 'column 1': 'reg_no', name: 'name' }));
    assert.deepEqual(table.exportTable(), [
        ['name', 'sex', 'reg_no', 'mother_age', 'attendant', 'late_registration'],
        ['ARIEL VILLANUJAN', '', '5', '', 'MIDWIFE', 'FALSE'],
    ]);
    assert.deepEqual(table.fieldRows(), [{ row: '01', values: {
        name: 'Ariel Villanujan', sex: '', reg_no: '5', mother_age: '', attendant: 'MIDWIFE', late_registration: 'false',
    } }]);
    assert.equal(table.firstFieldIssue(), null);

    values[0] = '';
    assert.match(table.firstFieldIssue().message, /^Row 01, reg_no: Required/);
});

test('a column named like a field fills it, whatever the order, spelling or case of its words', () => {
    const fields = [
        { key: 'name_of_mother' }, { key: 'husb_nationality' }, { key: 'reg_no' }, { key: 'date_of_birth' }, { key: 'name' },
    ];
    assert.equal(matchField('Name of Mother', fields), 'name_of_mother');
    assert.equal(matchField("Mother's Name", fields), 'name_of_mother');
    assert.equal(matchField('name_of_mother', fields), 'name_of_mother');
    assert.equal(matchField('HUSBAND NATIONALITY', fields), 'husb_nationality');
    assert.equal(matchField('Registration Number', fields), 'reg_no');
    assert.equal(matchField('Date of Birth', fields), 'date_of_birth');
    assert.equal(matchField('Name', fields), 'name');
    assert.equal(matchField('Column 1', fields), null);
    assert.equal(matchField('Mother', fields), null);
});

test('names still match once some columns were chosen; a choice wins, and "Type by hand" stays', () => {
    const model = buildReviewModel({
        pages: [{ number: 1, columns: [{ name: 'Column 1' }, { name: 'Name' }, { name: 'Sex' }] }],
        people: [{ id: 'person-1', label: '01', fields: [] }],
    });
    const byField = (map) => Object.fromEntries(applyRegistry(model, BIRTH, () => map).columns
        .filter((column) => column.field).map((column) => [column.field, column.key]));

    // Column 1 chosen for reg_no: Name and Sex still fill name and sex by their names.
    let fields = byField({ 'column 1': 'reg_no' });
    assert.equal(fields.reg_no, '0|column 1');
    assert.equal(fields.name, '0|name');
    assert.equal(fields.sex, '0|sex');

    // Column 1 chosen for name: it wins over the column named Name.
    fields = byField({ 'column 1': 'name' });
    assert.equal(fields.name, '0|column 1');

    // "Type by hand" for sex keeps the column named Sex apart.
    fields = byField({ sex: NOT_A_FIELD });
    assert.equal(fields.sex, 'm|sex');
});

// ------------------------------------------------- two-line entries (marriage)

const MARRIAGE = ['husband_name', 'wife_name', 'reg_no', 'husband_age', 'wife_age', 'date_of_reg']
    .map((key) => ({ key, kind: key.endsWith('age') ? 'integer' : 'text', required: false, manual: false }));
const PAIRS = [['husband_name', 'wife_name'], ['husband_age', 'wife_age']];

function marriageScan() {
    // Register rows 1-4: husband, wife, husband, wife. Reg no and date written once, on the first line.
    const field = (side, column, ref) => ({ side, column, ref });
    return buildReviewModel({
        pages: [{ number: 1, columns: ['Date of Registration', 'Register Number', 'Names', 'Years'].map((name) => ({ name, settings: null })) }],
        people: [1, 2, 3, 4].map((n) => ({
            id: `person-${n}`,
            label: String(n).padStart(2, '0'),
            fields: [
                ...(n % 2 === 1 ? [field(0, 'Date of Registration', n * 10), field(0, 'Register Number', n * 10 + 1)] : []),
                field(0, 'Names', n * 10 + 2),
                field(0, 'Years', n * 10 + 3),
            ],
        })),
    });
}

test('a marriage entry is two lines: husband, then the wife under him', () => {
    const map = { names: 'husband_name', years: 'husband_age', 'register number': 'reg_no', 'date of registration': 'date_of_reg' };
    const model = applyRegistry(marriageScan(), MARRIAGE, () => map, PAIRS);

    assert.deepEqual(model.columns.map((column) => column.header), MARRIAGE.map((field) => field.key).concat([]));
    const wife = model.columns.find((column) => column.field === 'wife_name');
    assert.equal(wife.key, `0|names${LINE_TWO}`);
    assert.equal(wife.line, 2);
    assert.equal(wife.sourceHeader, 'Names · line 2');

    assert.equal(model.rows.length, 2);
    const [first, second] = model.rows;
    assert.deepEqual([first.id, first.label, first.lines], ['person-1', '01', ['person-1', 'person-2']]);
    assert.deepEqual(first.cells['0|names'].refs, [12]);
    assert.deepEqual(first.cells[`0|names${LINE_TWO}`].refs, [22]);
    assert.deepEqual(first.cells['0|years'].refs, [13]);
    assert.deepEqual(first.cells[`0|years${LINE_TWO}`].refs, [23]);
    // Written once: whatever is on either line.
    assert.deepEqual(first.cells['0|register number'].refs, [11]);
    assert.deepEqual(second.cells['0|names'].refs, [32]);
    assert.deepEqual(second.cells[`0|names${LINE_TWO}`].refs, [42]);
    assert.equal(second.label, '02');
});

test('a wife column of its own, or line 2 turned off, keeps one row per line', () => {
    const own = applyRegistry(marriageScan(), MARRIAGE, () => ({ names: 'husband_name', years: 'wife_age' }), PAIRS);
    // Names still pairs (wife_name has no column); the ages do not.
    assert.equal(own.columns.find((column) => column.field === 'wife_age').key, '0|years');
    assert.equal(own.columns.find((column) => column.field === 'wife_name').line, 2);

    const off = applyRegistry(marriageScan(), MARRIAGE, () => ({ names: 'husband_name', [`names${LINE_TWO}`]: NOT_A_FIELD }), PAIRS);
    assert.equal(off.rows.length, 4);
    assert.equal(off.columns.find((column) => column.field === 'wife_name').manual, true);

    // No pairs (birth, death): unchanged.
    assert.equal(applyRegistry(marriageScan(), MARRIAGE, () => ({ names: 'husband_name' })).rows.length, 4);
});

test('a slip of the pen still names its field', () => {
    const fields = ['place_of_marriage', 'date_of_marriage', 'type_of_birth', 'date_of_birth', 'date_of_death']
        .map((key) => ({ key }));
    assert.equal(matchField('Place of Mirriage', fields), 'place_of_marriage');
    assert.equal(matchField('date of mirriage', fields), 'date_of_marriage');
    // Different words are not slips.
    assert.equal(matchField('Date of Death', fields), 'date_of_death');
    // Short words must be spelled right ("date" is not "data").
    assert.equal(matchField('Typoe of Birth', fields), null);
    assert.equal(matchField('Date of Bath', fields), null);
});

test('a marriage column that does not say whose fills the husband, then the wife', () => {
    const fields = ['husband_name', 'wife_name', 'husband_age', 'wife_age', 'husb_nationality', 'wife_nationality',
        'husb_father', 'wife_father', 'husb_mother', 'wife_mother']
        .map((key) => ({ key, kind: 'text', required: false, manual: false }));
    const pairs = [['husband_name', 'wife_name'], ['husband_age', 'wife_age'], ['husb_nationality', 'wife_nationality'],
        ['husb_father', 'wife_father'], ['husb_mother', 'wife_mother']];
    const names = ['Names of the Contracting Parties', 'Age', 'Husband Wife Nationality', 'Father', 'Mother', 'Father Nationality'];
    const scan = buildReviewModel({
        pages: [{ number: 1, columns: names.map((name) => ({ name, settings: null })) }],
        people: [1, 2].map((n) => ({ id: `person-${n}`, label: `0${n}`, fields: names.map((column, i) => ({ side: 0, column, ref: n * 10 + i })) })),
    });
    const model = applyRegistry(scan, fields, () => null, pairs);
    const source = (field) => model.columns.find((column) => column.field === field)?.sourceHeader;

    assert.equal(source('husband_name'), 'Names of the Contracting Parties');
    assert.equal(source('wife_name'), 'Names of the Contracting Parties · line 2');
    assert.equal(source('husband_age'), 'Age');
    assert.equal(source('wife_nationality'), 'Husband Wife Nationality · line 2');
    assert.equal(source('husb_father'), 'Father');
    assert.equal(source('wife_mother'), 'Mother · line 2');
    // "Father Nationality" is no field here: not saved.
    assert.ok(model.columns.find((column) => column.sourceHeader === 'Father Nationality').noExport);
    assert.equal(model.rows.length, 1);
});

test('a read column can be deleted from the table and its download, a register field cannot', () => {
    const table = new ReviewTable({ container: { addEventListener() {} }, valueOf: () => 'x', onChange: () => {} });
    const model = {
        columns: [
            { key: 'name', header: 'name', field: 'name' },
            { key: '0|column 5', header: 'Column 5 · not saved', noExport: true },
            { key: '0|notes', header: 'Notes' },
        ],
        rows: [{ id: 'person-1', label: '01', cells: { name: { refs: [0] }, '0|column 5': { refs: [1] }, '0|notes': { refs: [2] } } }],
    };
    assert.equal(table.canRemoveColumn(model.columns[0]), false);
    assert.equal(table.canRemoveColumn(model.columns[1]), true);
    assert.equal(table.canRemoveColumn({ key: 'custom|1', custom: true, field: undefined }), true);

    table.hiddenColumns.add('0|notes');
    table.model = table._withAdded(model);
    assert.deepEqual(table.model.columns.map((column) => column.key), ['name', '0|column 5']);
    assert.deepEqual(table.exportTable(), [['name'], ['X']]);
});

test('a register downloads its fields in its own order, whatever the columns were dragged to, in capitals', () => {
    const values = ['5', 'Ana Cruz', 'female', 'extra'];
    const table = new ReviewTable({
        container: { addEventListener() {} },
        valueOf: (cell) => cell.refs.map((index) => values[index]).join(' '),
        onChange: () => {},
    });
    const source = {
        columns: [
            { key: 'n', field: 'name' }, { key: 's', field: 'sex' }, { key: 'r', field: 'reg_no' },
        ],
        rows: [{ id: 'person-1', label: '01', cells: { n: { refs: [1] }, s: { refs: [2] }, r: { refs: [0] }, c: { refs: [3] } } }],
    };
    table.sourceModel = source;
    // Dragged: reg_no first; an added column at the end.
    table.model = { ...source, columns: [source.columns[2], source.columns[0], { key: 'c', header: 'Notes', custom: true }, source.columns[1]] };
    assert.deepEqual(table.exportTable(), [['name', 'sex', 'reg_no'], ['ANA CRUZ', 'FEMALE', '5']]);
});
