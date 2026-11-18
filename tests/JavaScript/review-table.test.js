import test from 'node:test';
import assert from 'node:assert/strict';

import {
    buildReviewModel,
    cellProblem,
    clampSplit,
    combineTables,
    columnType,
    csvSafe,
    exportFileName,
    nextCellPosition,
    parseClipboard,
    rowSpan,
    selectStart,
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

test('file names are plain and dated', () => {
    assert.equal(exportFileName('Births', '2splitpages.pdf', 'pages 1 2', '2026-11-15'), 'births-2splitpages-pages-1-2-2026-11-15');
    assert.equal(exportFileName('Defunciónes'), 'defunciones');
    assert.equal(exportFileName('', null), 'records');
});

test('Enter goes down, Tab goes right and wraps, Shift goes back, edges hold', () => {
    assert.deepEqual(nextCellPosition({ row: 0, column: 0 }, 'Enter', false, 3, 4), { row: 1, column: 0 });
    assert.deepEqual(nextCellPosition({ row: 0, column: 0 }, 'Enter', true, 3, 4), { row: 0, column: 0 });
    assert.deepEqual(nextCellPosition({ row: 0, column: 3 }, 'Tab', false, 3, 4), { row: 1, column: 0 });
    assert.deepEqual(nextCellPosition({ row: 1, column: 0 }, 'Tab', true, 3, 4), { row: 0, column: 3 });
    assert.deepEqual(nextCellPosition({ row: 2, column: 3 }, 'Tab', false, 3, 4), { row: 2, column: 3 });
    assert.deepEqual(nextCellPosition({ row: 2, column: 1 }, 'ArrowDown', false, 3, 4), { row: 2, column: 1 });
});

test('the split stays between 20% and 65%, 30% by default', () => {
    assert.equal(clampSplit(null), 30);
    assert.equal(clampSplit('abc'), 30);
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
        ['7', 'BIRTHS.pdf · pages 1–2', '01', 'Ana', 'F', ''],
        ['7', 'BIRTHS.pdf · pages 1–2', '02', 'Ben', 'M', ''],
        ['8', 'BIRTHS.pdf · page 3', '01', 'Cara', '', 'Jose'],
    ]);
    assert.deepEqual(combineTables([]), [['Record ID', 'Document']]);
});
