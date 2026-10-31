import assert from 'node:assert/strict';
import test from 'node:test';
import {
    alignedGeometry,
    detectionSummary,
    FLAG_NO_ROW,
    FLAG_SHARED_CELL,
    flagExplanation,
    geometryMarkers,
    gridNotes,
    snapRowsMessage,
    verificationItems,
} from '../../resources/js/line-geometry.js';
import {
    dragBounds,
    handlePoint,
    offsetPolygon,
    polygonBounds,
    polygonPoints,
    rectanglePolygon,
    scalePolygon,
} from '../../resources/js/line-overlay.js';

const templateColumns = [
    { name: 'Name', x: 0.1, y: 0.2, w: 0.3, h: 0.6, kind: 'column', columnIndex: 0 },
    { name: 'Date', x: 0.4, y: 0.2, w: 0.2, h: 0.6, kind: 'column', columnIndex: 1 },
];
const ruledYs = [0.2, 0.4, 0.6, 0.8];

test('the row lines go as the Align step holds them, with the columns as aligned', () => {
    // Snap to table took 5 rows from a page whose template has 3.
    const aligned = templateColumns.map((column) => ({ ...column, y: 0.25, h: 0.66 }));
    const rows = [0.25, 0.38, 0.51, 0.64, 0.77, 0.91];
    const geometry = alignedGeometry(aligned, rows);

    assert.deepEqual(geometry.columns.map((c) => c.name), ['Name', 'Date']);
    assert.deepEqual(geometry.columns[0].box, [0.1, 0.25, 0.3, 0.66]);
    assert.deepEqual(geometry.ruled_ys, rows);
    assert.deepEqual(geometry.fields, []);
    assert.equal(geometry.keep_rows, undefined, 'rows nobody set by hand may be taken from the page');
});

test('rows Staff set by hand are marked, so Detect keeps them', () => {
    assert.equal(alignedGeometry(templateColumns, ruledYs, { keepRows: true }).keep_rows, true);
    // Without columns there are no rows to keep.
    assert.equal(alignedGeometry([], ruledYs, { keepRows: true }).keep_rows, undefined);
});

test('rectangle fields pass straight through with their person grouping', () => {
    const geometry = alignedGeometry([
        { name: 'Registry number', x: 0.1, y: 0.05, w: 0.2, h: 0.04 },
        { name: 'Child', x: 0.4, y: 0.05, w: 0.2, h: 0.04, personGroup: 1, personFieldOrder: 0 },
    ], ruledYs);

    assert.deepEqual(geometry.columns, []);
    assert.deepEqual(geometry.ruled_ys, [], 'row lines without columns are not a ledger');
    assert.deepEqual(geometry.fields, [
        { name: 'Registry number', box: [0.1, 0.05, 0.2, 0.04], person_group: null, person_field_order: null },
        { name: 'Child', box: [0.4, 0.05, 0.2, 0.04], person_group: 1, person_field_order: 0 },
    ]);
});

test('row lines past the page edge are clamped and kept in order', () => {
    const geometry = alignedGeometry(templateColumns, [0.5, 0.8, 1.1, 1.4, 0.65]);

    assert.deepEqual(geometry.ruled_ys, [0.5, 0.65, 0.8, 1]);
});

const page = { width: 1000, height: 500 };
const line = (overrides) => ({
    id: 1,
    source: 'kraken',
    column: 'Name',
    columnIndex: 0,
    row: 3,
    personGroup: null,
    personFieldOrder: null,
    polygon: [[100, 100], [300, 100], [300, 150], [100, 150]],
    bbox: [100, 100, 200, 50],
    flags: [],
    text: 'Juan',
    confidence: 91.5,
    error: null,
    cropUrl: '/crop/1',
    ...overrides,
});

test('ledger lines become one person per row, ordered by column', () => {
    const [item] = verificationItems([line()], page);

    assert.equal(item.lineId, 1);
    assert.equal(item.name, 'Name · row 3');
    assert.equal(item.label, 'Name');
    // A ledger line is headed by its column: the person's row is its group.
    assert.equal(item.heading, 'Name');
    assert.equal(item.personGroup, 3);
    assert.equal(item.personFieldOrder, 0);
    assert.deepEqual([item.x, item.y, item.w, item.h], [0.1, 0.2, 0.2, 0.1]);
    assert.equal(item.needsReview, false);
    assert.deepEqual(item.reading, { name: 'Name · row 3', text: 'Juan', confidence: 91.5 });
});

test('a line with no row belongs to no person and needs review', () => {
    const [item] = verificationItems([line({ row: null, flags: [FLAG_NO_ROW] })], page);

    assert.equal(item.personGroup, undefined);
    assert.equal(item.needsReview, true);
    assert.match(item.name, /needs review/);
    assert.match(flagExplanation(item.flags), /between two rows/);
});

test('each ledger line knows its cell, so Verify can move it to another person', () => {
    const [placed, between, added, field] = verificationItems([
        line({ id: 1, columnIndex: 2, row: 3 }),
        line({ id: 2, columnIndex: 1, row: null, flags: [FLAG_NO_ROW] }),
        line({ id: 3, source: 'manual', columnIndex: 0, row: 4 }),
        line({ id: 4, source: 'template', column: 'Registry number', columnIndex: null, row: null }),
    ], page);

    assert.deepEqual([placed.gridColumn, placed.gridRow], [2, 3]);
    // Between two rows: its column is known, its person is not.
    assert.deepEqual([between.gridColumn, between.gridRow], [1, null]);
    assert.equal(added.source, 'manual');
    assert.equal(added.personGroup, 4);
    assert.deepEqual([field.gridColumn, field.gridRow], [null, null]);
});

test('a field added under no column joins its person where it sits across the page', () => {
    const grid = {
        ...page,
        geometry: { columns: [{ name: 'Name', box: [0, 0, 0.3, 1] }, { name: 'Date', box: [0.3, 0, 0.3, 1] }, { name: 'Sex', box: [0.8, 0, 0.2, 1] }] },
    };
    const [added] = verificationItems([
        line({ source: 'manual', column: 'Drawn line', columnIndex: null, row: 4, bbox: [620, 100, 150, 40] }),
    ], grid);

    assert.equal(added.personGroup, 4);
    // Right of Name and Date, left of Sex.
    assert.equal(added.personFieldOrder, 2);
    assert.deepEqual([added.gridColumn, added.gridRow], [null, 4]);
});

test('two lines sharing a cell keep distinct names', () => {
    const items = verificationItems([
        line({ id: 1, flags: [FLAG_SHARED_CELL] }),
        line({ id: 2, flags: [FLAG_SHARED_CELL] }),
    ], page);

    assert.deepEqual(items.map((item) => item.name), ['Name · row 3', 'Name · row 3 (2)']);
    assert.match(flagExplanation(items[1].flags), /more than one line/);
});

test('template rectangles keep their own name and grouping', () => {
    const [item] = verificationItems([
        line({ source: 'template', column: 'Registry number', columnIndex: null, row: null, personGroup: 2, personFieldOrder: 1 }),
    ], page);

    assert.equal(item.name, 'Registry number');
    assert.equal(item.personGroup, 2);
    assert.equal(item.personFieldOrder, 1);
    assert.equal(flagExplanation(item.flags), '');
});

test('a box Detect split into written lines numbers them and keeps the field grouping', () => {
    const fieldLine = (id, row) => line({
        id, source: 'field', column: 'Diseases', columnIndex: null, row, personGroup: 2, personFieldOrder: 1,
    });
    const items = verificationItems([fieldLine(1, 1), fieldLine(2, 2), line({ id: 3, source: 'field', column: 'Remarks', columnIndex: null, row: 1 })], page);

    assert.deepEqual(items.map((item) => item.name), ['Diseases · line 1', 'Diseases · line 2', 'Remarks']);
    assert.deepEqual(items.slice(0, 2).map((item) => [item.personGroup, item.personFieldOrder]), [[2, 1], [2, 1]]);
    assert.equal(items[2].personGroup, undefined);
    // Verify heads each line with its number, so they can be told apart; the
    // label stays the field's own name, which its settings are looked up by.
    assert.deepEqual(items.map((item) => item.heading), ['Diseases · line 1', 'Diseases · line 2', 'Remarks']);
    assert.deepEqual(items.map((item) => item.label), ['Diseases', 'Diseases', 'Remarks']);

    const summary = detectionSummary({
        deskew: 0,
        geometry: { columns: [], fields: [{}] },
        lines: [fieldLine(1, 1), fieldLine(2, 2)],
    });
    assert.equal(summary.text, '1 field · 2 handwritten lines');
    assert.equal(
        detectionSummary({ deskew: 0, geometry: { fields: [{}, {}] }, lines: [line({ source: 'template' }), line({ source: 'template' })] }).text,
        '2 fields',
    );
});

test('Detect\'s fitted geometry becomes markers, and round-trips through alignedGeometry', () => {
    const fitted = {
        columns: [{ name: 'Name', box: [0.12, 0.3, 0.25, 0.5] }, { name: 'Date', box: [0.37, 0.3, 0.2, 0.5] }],
        ruled_ys: [0.3, 0.55, 0.8],
        fields: [{ name: 'Registry number', box: [0.1, 0.05, 0.2, 0.04], person_group: null, person_field_order: null }],
    };
    const markers = geometryMarkers(fitted);

    assert.deepEqual(markers.map((m) => [m.name, m.kind ?? 'field', m.columnIndex ?? null]), [
        ['Registry number', 'field', null],
        ['Name', 'column', 0],
        ['Date', 'column', 1],
    ]);
    const again = alignedGeometry(markers, fitted.ruled_ys);
    assert.deepEqual(again.columns, fitted.columns);
    assert.deepEqual(again.ruled_ys, fitted.ruled_ys);
});

test('tilted markers send their angle; upright ones send none', () => {
    const geometry = alignedGeometry([
        { name: 'Remarks', x: 0.1, y: 0.05, w: 0.2, h: 0.04, angle: -4.26 },
        { name: 'Child', x: 0.4, y: 0.05, w: 0.2, h: 0.04 },
        ...templateColumns.map((column) => ({ ...column, angle: 2 })),
    ], ruledYs);

    assert.deepEqual(geometry.fields.map((field) => field.angle), [-4.3, undefined]);
    assert.deepEqual(geometry.columns.map((column) => column.angle), [2, 2]);
});

test('a field the server moved onto a straightened page comes back as its own box, turned', () => {
    // A 200 x 50 px field turned 10 degrees clockwise about (500, 300) on a 1000 x 600 page.
    const radians = 10 * Math.PI / 180;
    const corner = (dx, dy) => [
        (500 + dx * Math.cos(radians) - dy * Math.sin(radians)) / 1000,
        (300 + dx * Math.sin(radians) + dy * Math.cos(radians)) / 600,
    ];
    const polygon = [corner(-100, -25), corner(100, -25), corner(100, 25), corner(-100, 25)];
    const [marker] = geometryMarkers({ fields: [{ name: 'Remarks', box: [0, 0, 1, 1], polygon }] }, { width: 1000, height: 600 });

    assert.equal(marker.angle, 10);
    assert.ok(Math.abs(marker.x - 0.4) < 1e-9 && Math.abs(marker.w - 0.2) < 1e-9);
    assert.ok(Math.abs(marker.y - 275 / 600) < 1e-9 && Math.abs(marker.h - 50 / 600) < 1e-9);
});

test('a column that runs past the page is cut at its edge before it is sent back', () => {
    // Detect can end a grid below the page when the template has more rows than the page; the server refuses a
    // marker that extends beyond the page, which would fail the next Detect, Scan or Snap.
    const aligned = templateColumns.map((column) => ({ ...column, y: 0.3, h: 0.9 }));
    const geometry = alignedGeometry(aligned, ruledYs);

    geometry.columns.forEach((column) => assert.ok(column.box[1] + column.box[3] <= 1 + 1e-9));
    assert.ok(Math.abs(geometry.columns[0].box[3] - 0.7) < 1e-9);
});

test('Snap to table says where the rows came from, in plain words', () => {
    assert.equal(snapRowsMessage(null), '');
    assert.equal(
        snapRowsMessage({ from_page: true, count: 25, template: 20, estimated: [] }),
        'Rows found on the page (template: 20).',
    );
    assert.equal(
        snapRowsMessage({ from_page: true, count: 20, template: 20, estimated: [20] }),
        'Rows found on the page.',
    );
    assert.equal(
        snapRowsMessage({ from_page: false, count: 20, template: 20, estimated: [], reason: 'no_rows' }),
        'Rows kept from the template: no evenly ruled rows were found on this page.',
    );
    // Amber lines no longer add a note.
    assert.equal(snapRowsMessage(null, 2), '');
    assert.equal(snapRowsMessage({ from_page: false, reason: 'something_new', estimated: [] }), '');
});

test('grid notes are worded as questions for Staff', () => {
    assert.deepEqual(gridNotes(null), []);
    assert.deepEqual(gridNotes([{ code: 'unknown', count: 3 }, { code: 'lines_below_grid', count: 0 }]), []);

    assert.deepEqual(gridNotes([{ code: 'rows_uneven', count: 1, rows: [21] }]), [
        'Row 21 differs a lot in height from the template’s. Check that the row lines sit on the printed lines.',
    ]);
    assert.match(gridNotes([{ code: 'rows_uneven', count: 2, rows: [20, 21] }])[0], /^Rows 20 and 21 differ /);
    assert.match(gridNotes([{ code: 'rows_uneven', count: 8, rows: [3, 4, 5, 6, 7, 8] }])[0], /^Rows 3, 4, 5, 6, 7 and 3 more differ /);

    assert.deepEqual(gridNotes([{ code: 'lines_below_grid', count: 22, rows: 2 }]), [
        '22 written lines lie below the last row and are not read (about 2 more rows). '
        + 'This page may have more rows than the template.',
    ]);
    assert.match(gridNotes([{ code: 'lines_below_grid', count: 1, rows: 1 }])[0], /^1 written line lies below .* and is not read \(about 1 more row\)/);
    assert.match(gridNotes([{ code: 'rows_do_not_fit', count: 1 }])[0], /^This page does not look like the ledger .* The column was read as free text/);
    assert.match(gridNotes([{ code: 'rows_do_not_fit', count: 3 }])[0], /3 columns were read as free text/);
    assert.deepEqual(gridNotes([{ code: 'rows_empty', count: 1, rows: [9] }]), [
        'Nothing was read in row 9, though later rows were. If the page has writing there, check that row before you submit.',
    ]);
    assert.match(gridNotes([{ code: 'rows_empty', count: 2, rows: [3, 9] }])[0], /^Nothing was read in rows 3 and 9, .* check those rows before/);
    assert.deepEqual(gridNotes([{ code: 'rows_empty', count: 0, rows: [] }]), []);
    assert.deepEqual(gridNotes([{ code: 'lines_above_grid', count: 11 }]), [
        '11 written lines sit in the row directly above the first row. If that row holds entries, move the grid up one row.',
    ]);
});

test('the Detect summary carries the grid notes', () => {
    const summary = detectionSummary({
        deskew: 0,
        geometry: { columns: [{}] },
        lines: [line({ row: 1 })],
        notes: [{ code: 'lines_above_grid', count: 4 }],
    });

    assert.equal(summary.notes.length, 1);
    assert.deepEqual(detectionSummary({ deskew: 0, geometry: {}, lines: [line({})] }).notes, []);
});

test('the Detect summary says what was found and what needs review', () => {
    const summary = detectionSummary({
        deskew: -2.46,
        geometry: { columns: [{}, {}, {}] },
        lines: [line({ row: 1 }), line({ row: 2 }), line({ row: null, flags: [FLAG_NO_ROW] })],
    });

    assert.equal(summary.title, 'Page straightened by 2.5°');
    assert.equal(summary.text, '3 columns · 2 rows · 3 handwritten lines · 1 needs review');
    assert.equal(summary.flagged, 1);
    assert.equal(detectionSummary({ deskew: 0, geometry: {}, lines: [line({})] }).title, 'Page is straight');
});

test('overlay polygon helpers', () => {
    assert.equal(polygonPoints([[1.04, 2], [3, 4.55]]), '1,2 3,4.6');
    assert.deepEqual(polygonBounds([[5, 9], [2, 3], [7, 4]]), { left: 2, top: 3, right: 7, bottom: 9 });
    assert.deepEqual(rectanglePolygon(10, 20, 4, 8), [[4, 8], [10, 8], [10, 20], [4, 20]]);
});

const near = (actual, expected) => actual.forEach(([x, y], i) => {
    assert.ok(Math.abs(x - expected[i][0]) < 1e-9 && Math.abs(y - expected[i][1]) < 1e-9, `point ${i}: ${x},${y}`);
});

test('stretching an outline by a handle moves only that side', () => {
    const bounds = { left: 10, top: 20, right: 110, bottom: 60 };
    assert.deepEqual(handlePoint(bounds, 'se'), [110, 60]);
    assert.deepEqual(handlePoint(bounds, 'n'), [60, 20]);

    const taller = dragBounds(bounds, 'n', [999, 5]);
    assert.deepEqual(taller, { left: 10, top: 5, right: 110, bottom: 60 });
    // A handle cannot fold the box inside out.
    assert.equal(dragBounds(bounds, 'w', [500, 0]).left, 106);

    // The outline follows its box: a point halfway down stays halfway down.
    const outline = [[10, 20], [110, 40], [60, 60]];
    near(scalePolygon(outline, bounds, taller), [[10, 5], [110, 32.5], [60, 60]]);
});

test('growing an outline moves every side out by the same distance, either way round', () => {
    const clockwise = [[0, 0], [10, 0], [10, 10], [0, 10]];
    near(offsetPolygon(clockwise, 2), [[-2, -2], [12, -2], [12, 12], [-2, 12]]);
    near(offsetPolygon([...clockwise].reverse(), 2), [[-2, 12], [12, 12], [12, -2], [-2, -2]]);
    near(offsetPolygon(clockwise, -2), [[2, 2], [8, 2], [8, 8], [2, 8]]);
    assert.deepEqual(offsetPolygon(clockwise, 0), clockwise);
});

test('an adjusted line is marked as such for the overlay', () => {
    const [item] = verificationItems([line({ adjusted: true })], page);
    assert.equal(item.adjusted, true);
    assert.equal(verificationItems([line()], page)[0].adjusted, false);
});
