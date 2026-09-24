import assert from 'node:assert/strict';
import test from 'node:test';
import {
    alignedGeometry,
    detectionSummary,
    FLAG_NO_ROW,
    FLAG_SHARED_CELL,
    flagExplanation,
    geometryMarkers,
    verificationItems,
} from '../../resources/js/line-geometry.js';
import { polygonBounds, polygonPoints, rectanglePolygon } from '../../resources/js/line-overlay.js';

const templateColumns = [
    { name: 'Name', x: 0.1, y: 0.2, w: 0.3, h: 0.6, kind: 'column', columnIndex: 0 },
    { name: 'Date', x: 0.4, y: 0.2, w: 0.2, h: 0.6, kind: 'column', columnIndex: 1 },
];
const ruledYs = [0.2, 0.4, 0.6, 0.8];

test('ruled row lines follow the columns Staff aligned', () => {
    // Staff moved both columns down by 0.05 and stretched them 10% taller.
    const aligned = templateColumns.map((column) => ({ ...column, y: 0.25, h: 0.66 }));
    const geometry = alignedGeometry(aligned, templateColumns, ruledYs);

    assert.deepEqual(geometry.columns.map((c) => c.name), ['Name', 'Date']);
    assert.deepEqual(geometry.columns[0].box, [0.1, 0.25, 0.3, 0.66]);
    assert.deepEqual(geometry.ruled_ys.map((y) => Number(y.toFixed(4))), [0.25, 0.47, 0.69, 0.91]);
    assert.deepEqual(geometry.fields, []);
});

test('rectangle fields pass straight through with their person grouping', () => {
    const geometry = alignedGeometry([
        { name: 'Registry number', x: 0.1, y: 0.05, w: 0.2, h: 0.04 },
        { name: 'Child', x: 0.4, y: 0.05, w: 0.2, h: 0.04, personGroup: 1, personFieldOrder: 0 },
    ], [], []);

    assert.deepEqual(geometry.columns, []);
    assert.deepEqual(geometry.ruled_ys, []);
    assert.deepEqual(geometry.fields, [
        { name: 'Registry number', box: [0.1, 0.05, 0.2, 0.04], person_group: null, person_field_order: null },
        { name: 'Child', box: [0.4, 0.05, 0.2, 0.04], person_group: 1, person_field_order: 0 },
    ]);
});

test('ruled lines pushed past the page edge are clamped and kept in order', () => {
    const aligned = templateColumns.map((column) => ({ ...column, y: 0.5, h: 0.6 }));
    const geometry = alignedGeometry(aligned, templateColumns, ruledYs);

    assert.equal(geometry.ruled_ys[geometry.ruled_ys.length - 1], 1);
    geometry.ruled_ys.slice(1).forEach((y, index) => assert.ok(y > geometry.ruled_ys[index]));
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
    assert.equal(items[0].label, 'Diseases');

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
    const columns = markers.filter((m) => m.kind === 'column');
    const again = alignedGeometry(markers, columns, fitted.ruled_ys);
    assert.deepEqual(again.columns, fitted.columns);
    assert.deepEqual(again.ruled_ys, fitted.ruled_ys);
});

test('tilted markers send their angle; upright ones send none', () => {
    const geometry = alignedGeometry([
        { name: 'Remarks', x: 0.1, y: 0.05, w: 0.2, h: 0.04, angle: -4.26 },
        { name: 'Child', x: 0.4, y: 0.05, w: 0.2, h: 0.04 },
        ...templateColumns.map((column) => ({ ...column, angle: 2 })),
    ], templateColumns, ruledYs);

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
