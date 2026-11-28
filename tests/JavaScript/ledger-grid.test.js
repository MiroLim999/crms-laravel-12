import assert from 'node:assert/strict';
import test from 'node:test';

import {
    columnsSideBySide,
    addColumn,
    addColumnWhereRoom,
    clampRuledY,
    columnBand,
    columnsOnLines,
    distributeRuledYs,
    fieldsOverLedger,
    fieldsToColumns,
    fitColumnsToPrinted,
    clampRowAngle,
    fitTilts,
    followFirstAndLastLine,
    gridTilt,
    insertRuledY,
    insertTilt,
    mergeFieldsIntoTable,
    ledgerGridProblems,
    MIN_ROW_GAP,
    nearestLine,
    remapRuledYs,
    respaceTilts,
    rowLineY,
    shiftRuledYs,
    tiltColumns,
} from '../../resources/js/ledger-grid.js';

const close = (actual, expected) => {
    assert.equal(actual.length, expected.length);
    actual.forEach((value, index) => assert.ok(Math.abs(value - expected[index]) < 1e-9, `${value} != ${expected[index]}`));
};
const column = (name, x, y = 0.2, h = 0.6, w = 0.1) => ({ name, x, y, w, h, kind: 'column' });

test('moving the whole table moves its row lines with it', () => {
    const from = { top: 0.2, bottom: 0.8 };
    close(remapRuledYs([0.2, 0.4, 0.6, 0.8], from, { top: 0.25, bottom: 0.85 }), [0.25, 0.45, 0.65, 0.85]);
});

test('stretching the table stretches its rows, as the Align step does', () => {
    close(remapRuledYs([0.2, 0.5, 0.8], { top: 0.2, bottom: 0.8 }, { top: 0.2, bottom: 0.92 }), [0.2, 0.56, 0.92]);
});

test('the band is the median top and bottom, so one stray column does not move it', () => {
    const band = columnBand([column('A', 0.1), column('B', 0.2), column('C', 0.3, 0.5, 0.2)]);
    assert.deepEqual(band, { top: 0.2, bottom: 0.8 });
});

test('a dragged row line stops short of its neighbours', () => {
    const ys = [0.2, 0.3, 0.4];
    assert.equal(clampRuledY(ys, 1, 0.1), 0.2 + MIN_ROW_GAP);
    assert.equal(clampRuledY(ys, 1, 0.9), 0.4 - MIN_ROW_GAP);
    assert.equal(clampRuledY(ys, 1, 0.33), 0.33);
    assert.equal(clampRuledY(ys, 0, -1), 0);
    assert.equal(clampRuledY(ys, 2, 2), 1);
});

test('all lines move together and stop at the page edge', () => {
    close(shiftRuledYs([0.2, 0.9], 0.05), [0.25, 0.95]);
    close(shiftRuledYs([0.2, 0.9], 0.5), [0.3, 1]);
    close(shiftRuledYs([0.1, 0.5], -0.5), [0, 0.4]);
});

test('a line is inserted half way below the chosen line, or one row below the last', () => {
    assert.deepEqual(insertRuledY([0.2, 0.4, 0.5], 0), { ys: [0.2, 0.30000000000000004, 0.4, 0.5], index: 1 });
    const appended = insertRuledY([0.2, 0.3, 0.4]);
    assert.equal(appended.index, 3);
    assert.ok(Math.abs(appended.ys[3] - 0.5) < 1e-9);
    assert.deepEqual(insertRuledY([]), { ys: [0.2, 0.25], index: 1 });
});

test('no line is inserted where there is no room', () => {
    assert.equal(insertRuledY([0.2, 0.2 + MIN_ROW_GAP], 0), null);
    assert.equal(insertRuledY([0.9, 0.99]), null);
    assert.equal(insertRuledY([0.8, 0.9, 0.97]), null, 'under half a row left above the page edge');
});

test('a last row cut off by the page edge is added on that edge', () => {
    // Rows 0.05 tall; the scan ends 0.035 under the last line.
    assert.deepEqual(insertRuledY([0.865, 0.915, 0.965]), { ys: [0.865, 0.915, 0.965, 1], index: 3 });
    assert.deepEqual(insertRuledY([0.865, 0.915, 0.965], 2).ys.at(-1), 1, 'with the last line selected');
});

test('rows are spaced evenly between the first and the last line', () => {
    close(distributeRuledYs([0.2, 0.25, 0.5, 0.8], 4), [0.2, 0.35, 0.5, 0.65, 0.8]);
    assert.equal(distributeRuledYs([0.2, 0.21], 100), null);
    assert.equal(distributeRuledYs([0.2], 3), null);
    assert.equal(distributeRuledYs([0.2, 0.8], 0), null);
});

test('a row line snaps to the nearest printed rule within reach', () => {
    assert.equal(nearestLine(0.401, [0.3, 0.4, 0.405], 0.01), 0.4);
    assert.equal(nearestLine(0.45, [0.3, 0.4], 0.01), null);
});

test('columns sitting on the first and last line follow those lines', () => {
    const boxes = [column('A', 0.1), { name: 'Title', x: 0.1, y: 0.05, w: 0.3, h: 0.05 }, column('B', 0.3, 0.3, 0.4)];
    const moved = followFirstAndLastLine(boxes, [0.2, 0.5, 0.8], [0.25, 0.5, 0.9]);

    assert.ok(Math.abs(moved[0].y - 0.25) < 1e-9);
    assert.ok(Math.abs(moved[0].y + moved[0].h - 0.9) < 1e-9);
    assert.equal(moved[1], boxes[1]);
    // A column that was not on the lines is left where the admin put it.
    assert.equal(moved[2], boxes[2]);
});

test('in the Align step a column within half a row of the first or last line follows it', () => {
    // The user aligns columns by hand, so their edges sit near the lines, not exactly on them.
    const boxes = [column('A', 0.1, 0.203, 0.594), column('B', 0.2, 0.2, 0.6)];
    const next = followFirstAndLastLine(boxes, [0.2, 0.5, 0.8], [0.2, 0.5, 0.8, 0.95], 0.15 / 2);

    // Both now sit on the first and the new last line.
    close([next[0].y, next[0].y + next[0].h], [0.2, 0.95]);
    close([next[1].y, next[1].y + next[1].h], [0.2, 0.95]);
    // The builder's own (exact) reach leaves the hand-aligned column alone.
    assert.equal(followFirstAndLastLine(boxes, [0.2, 0.5, 0.8], [0.2, 0.5, 0.8, 0.95])[0], boxes[0]);
});

test('an added column splits the selected one, or goes at the right of the table', () => {
    const boxes = [{ name: 'Field', x: 0.05, y: 0.05, w: 0.2, h: 0.04 }, column('A', 0.1), column('B', 0.2)];
    const named = { name: 'Remarks', kind: 'column', columnIndex: 2 };

    const split = addColumn(boxes, named, 1);
    assert.equal(split.index, 3);
    close([split.boxes[1].x, split.boxes[1].w], [0.1, 0.05]);
    assert.deepEqual(split.boxes[3], { x: 0.15000000000000002, y: 0.2, w: 0.05, h: 0.6, ...named });

    const appended = addColumn(boxes, named);
    close([appended.boxes[3].x, appended.boxes[3].w, appended.boxes[3].y, appended.boxes[3].h], [0.3, 0.1, 0.2, 0.6]);
    assert.equal(appended.boxes[2], boxes[2], 'the other columns are left as they are');
});

test('with no room at the right, an added column takes half of the last column', () => {
    const boxes = [column('A', 0.5, 0.2, 0.6, 0.25), column('B', 0.75, 0.2, 0.6, 0.24)];
    const added = addColumn(boxes, { name: 'C', kind: 'column' });

    close([added.boxes[1].x, added.boxes[1].w, added.boxes[2].x, added.boxes[2].w], [0.75, 0.12, 0.87, 0.12]);
    assert.equal(addColumn([column('A', 0.5, 0.2, 0.6, 0.015)], { name: 'B', kind: 'column' }, 0), null, 'too narrow to split');
    assert.equal(addColumn([{ name: 'Field', x: 0.1, y: 0.1, w: 0.2, h: 0.1 }], { name: 'C' }), null, 'no table');
});

test('columns added one after another never lie on top of each other', () => {
    // A table reaching near the right edge, then many columns added (as the builder's "+" does).
    let boxes = [column('A', 0.05, 0.2, 0.6, 0.3), column('B', 0.35, 0.2, 0.6, 0.3), column('C', 0.65, 0.2, 0.6, 0.3)];
    for (let n = 0; n < 8; n += 1) {
        const added = addColumnWhereRoom(boxes, { name: `New ${n}`, kind: 'column' });
        assert.ok(added, `column ${n} found room`);
        boxes = added.boxes;
    }

    const columns = boxes.filter((box) => box.kind === 'column').sort((a, b) => a.x - b.x);
    assert.equal(columns.length, 11);
    columns.forEach((box) => assert.ok(box.x >= 0 && box.x + box.w <= 1 + 1e-9, `${box.name} is on the page`));
    columns.slice(1).forEach((box, index) => {
        const before = columns[index];
        assert.ok(box.x >= before.x + before.w - 1e-9, `${box.name} starts where ${before.name} ends, not on it`);
    });
    // The room is shared: no column is squeezed far thinner than the rest.
    const widths = columns.map((box) => box.w);
    assert.ok(Math.min(...widths) >= Math.max(...widths) / 2 - 1e-9, `widths stay even: ${widths.map((w) => w.toFixed(3))}`);
    // With room at the right, a column still goes there, the others untouched.
    const roomy = addColumnWhereRoom([column('A', 0.05, 0.2, 0.6, 0.1)], { name: 'B', kind: 'column' });
    close([roomy.boxes[1].x, roomy.boxes[1].w], [0.15, 0.1]);
});

test('with no columns left, the first one goes where it is told', () => {
    const field = { name: 'Field', x: 0.1, y: 0.05, w: 0.2, h: 0.04 };
    const added = addColumn([field], { name: 'Entry No.', kind: 'column' }, null, { x: 0.05, y: 0.2, w: 0.1, h: 0.6 });

    assert.equal(added.index, 1);
    assert.equal(added.boxes[0], field);
    assert.deepEqual(added.boxes[1], { x: 0.05, y: 0.2, w: 0.1, h: 0.6, name: 'Entry No.', kind: 'column' });
});

test('a field stretched down the table\'s rows becomes a column read by row', () => {
    // Two columns with a gap between them (the Age column the template lacks); rows every 0.1.
    const ys = [0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8];
    const boxes = [
        { ...column('Sex', 0.1), columnIndex: 0 },
        { ...column('Date', 0.3), columnIndex: 1 },
        { name: 'Age', x: 0.205, y: 0.22, w: 0.09, h: 0.5, personGroup: 1, personFieldOrder: 0 },
    ];

    const next = fieldsToColumns(boxes, ys);

    assert.deepEqual(next[2], { name: 'Age', x: 0.205, y: 0.2, w: 0.09, h: 0.6000000000000001, kind: 'column', columnIndex: 2 });
    assert.equal(next[0], boxes[0], 'the table\'s own columns are left as they are');
});

test('a field stays a field off the table, over a column, across one row, or when kept', () => {
    const ys = [0.2, 0.3, 0.4, 0.5];
    const table = [column('Sex', 0.1, 0.2, 0.3), column('Date', 0.3, 0.2, 0.3)];
    const field = (x, y, h) => ({ name: 'Box', x, y, w: 0.05, h });

    for (const box of [
        field(0.6, 0.2, 0.3),    // right of the table
        field(0.12, 0.2, 0.3),   // over the Sex column: it would take that column's cells
        { name: 'Box', x: 0.15, y: 0.2, w: 0.2, h: 0.3 }, // middle in the gap, but over both columns
        field(0.22, 0.31, 0.08), // one row only
        field(0.22, 0.05, 0.1),  // above the table
    ]) {
        const boxes = [...table, box];
        assert.equal(fieldsToColumns(boxes, ys), boxes, JSON.stringify(box));
    }
    // A little over a column's edge, as a box placed by hand often is, still fits the gap.
    const sloppy = [...table, { name: 'Box', x: 0.19, y: 0.2, w: 0.1, h: 0.3 }];
    assert.equal(fieldsToColumns(sloppy, ys)[2].kind, 'column', 'a sliver over the Sex column');

    const kept = [...table, { ...field(0.22, 0.2, 0.3), name: 'Remarks' }];
    assert.equal(fieldsToColumns(kept, ys, (box) => box.name === 'Remarks'), kept, 'the template\'s own field');
});

test('nothing moves when the first and last lines stay', () => {
    const boxes = [column('A', 0.1)];
    assert.equal(followFirstAndLastLine(boxes, [0.2, 0.5, 0.8], [0.2, 0.6, 0.8]), boxes);
});

test('a field over the middle of ledger cells is reported, one beside the table is not', () => {
    const boxes = [
        column('Name', 0.1, 0.2, 0.6, 0.2),
        { name: 'Diseases', x: 0.15, y: 0.3, w: 0.1, h: 0.2 },
        { name: 'Title', x: 0.1, y: 0.05, w: 0.4, h: 0.1 },
        // Only touches the table's border.
        { name: 'Footer', x: 0.1, y: 0.795, w: 0.2, h: 0.1 },
        { name: 'Margin', x: 0.5, y: 0.3, w: 0.1, h: 0.2 },
    ];
    assert.deepEqual(fieldsOverLedger(boxes, [0.2, 0.4, 0.6, 0.8]), [1]);
    assert.deepEqual(fieldsOverLedger(boxes, []), []);
});

test('row lines outside the columns stop the layout from saving', () => {
    const columns = [column('A', 0.1, 0.2, 0.3)];
    const problems = ledgerGridProblems(columns, [0.2, 0.3, 0.5, 0.7, 0.9]);
    assert.equal(problems.length, 1);
    assert.equal(problems[0].blocking, true);
});

test('a column that spans other rows than the rest is a warning', () => {
    const columns = [column('A', 0.1), column('B', 0.2), column('C', 0.3, 0.4, 0.4)];
    const problems = ledgerGridProblems(columns, [0.2, 0.4, 0.6, 0.8]);
    assert.deepEqual(problems, [{ message: 'Column “C” does not span the same rows as the other columns.', blocking: false }]);
});

test('a level table turned a few degrees (Detect on a tilted page) has no row warnings', () => {
    const level = [0.05, 0.25, 0.45, 0.65, 0.85].map((x, index) => column(String.fromCharCode(65 + index), x, 0.2, 0.6, 0.1));
    const aspect = 1700 / 1150;
    const turned = tiltColumns(level, 5, aspect);
    // The outer columns' tops are well apart on the page itself...
    assert.ok(Math.abs(turned[0].y - turned[4].y) > 0.05);
    // ...but the table is the same one, so no column is "off".
    assert.deepEqual(ledgerGridProblems(turned, [0.2, 0.4, 0.6, 0.8], { aspect }), []);
    // A column really moved down still is.
    const moved = turned.map((box, index) => (index === 2 ? { ...box, y: box.y + 0.15 } : box));
    assert.equal(ledgerGridProblems(moved, [0.2, 0.4, 0.6, 0.8], { aspect }).length, 1);
});

test('a consistent ledger has no problems, and a layout without columns is not a ledger', () => {
    assert.deepEqual(ledgerGridProblems([column('A', 0.1), column('B', 0.2)], [0.2, 0.4, 0.6, 0.8]), []);
    assert.deepEqual(ledgerGridProblems([], [0.2]), []);
    assert.equal(ledgerGridProblems([column('A', 0.1)], [0.2])[0].blocking, true);
});

test('the table is as tilted as its columns typically are', () => {
    assert.equal(gridTilt([]), 0);
    assert.equal(gridTilt([column('A', 0.1), column('B', 0.3)]), 0);
    assert.equal(gridTilt([{ ...column('A', 0.1), angle: -0.4 }, { ...column('B', 0.3), angle: -0.4 },
        { ...column('C', 0.5), angle: 3 }]), -0.4);
});

test('a row line is held at the middle of the page and runs at the tilt in pixels', () => {
    // A page twice as wide as it is high, tilted 1 degree clockwise: 0.25 of the
    // width right of the middle is half the page's height across, so the line
    // drops tan(1 degree) / 2 of the height there.
    const drop = Math.tan(Math.PI / 180) / 2;
    assert.ok(Math.abs(rowLineY(0.3, 0.5, 1, 2) - 0.3) < 1e-12);
    assert.ok(Math.abs(rowLineY(0.3, 0.75, 1, 2) - (0.3 + drop)) < 1e-12);
    assert.ok(Math.abs(rowLineY(0.3, 0.25, 1, 2) - (0.3 - drop)) < 1e-12);
    assert.equal(rowLineY(0.3, 0.9), 0.3);
});

test('turning the table turns every column and keeps them on the row lines', () => {
    const aspect = 1.4;
    const field = { name: 'Page No.', x: 0.8, y: 0.05, w: 0.1, h: 0.03 };
    const boxes = [field, column('A', 0.1), column('B', 0.45), column('C', 0.8)];

    const turned = tiltColumns(boxes, 0.5, aspect);

    assert.equal(turned[0], field);
    const rows = [0.2, 0.8];
    turned.slice(1).forEach((box) => {
        assert.equal(box.angle, 0.5);
        assert.equal(box.w, 0.1);
        assert.equal(box.h, 0.6);
        // Its top and bottom where the turned first and last row lines cross it.
        const centre = box.x + box.w / 2;
        assert.ok(Math.abs(box.y - rowLineY(rows[0], centre, 0.5, aspect)) < 1e-4, `${box.name} top`);
        assert.ok(Math.abs(box.y + box.h - rowLineY(rows[1], centre, 0.5, aspect)) < 1e-4, `${box.name} bottom`);
    });
    // Turned back, the table is where it was.
    tiltColumns(turned, -0.5, aspect).slice(1).forEach((box, index) => {
        assert.ok(Math.abs(box.x - boxes[index + 1].x) < 1e-9);
        assert.ok(Math.abs(box.y - boxes[index + 1].y) < 1e-9);
        assert.ok(Math.abs(box.angle) < 1e-9);
    });
    assert.equal(tiltColumns(boxes, 0, aspect), boxes);
    assert.equal(tiltColumns([field], 1, aspect)[0], field);
});

// ---------------------------------------------------- one row line's own tilt

const lines = [0.2, 0.3, 0.4, 0.5];
const span = { left: 0.04, right: 0.96, aspect: 1.4 };

test('a row line turns freely while it stays clear of its neighbours', () => {
    assert.equal(clampRowAngle(lines, [0, 0, 0, 0], 1, 0.4, span), 0.4);
    assert.equal(clampRowAngle(lines, [0, 0, 0, 0], 1, -0.7, span), -0.7);
});

test('a row line stops before it would touch the next one, at either end of the table', () => {
    // 0.1 of the page apart; across 0.46 of the width on a 1.4 page the line rises
    // 0.46 * 1.4 * tan(angle): about 8 degrees closes the gap (less the minimum gap).
    const furthest = clampRowAngle(lines, [0, 0, 0, 0], 1, 30, span);
    assert.ok(furthest > 7 && furthest < 8.5, `${furthest}`);
    assert.equal(clampRowAngle(lines, [0, 0, 0, 0], 1, -30, span), -furthest);
    // The nearest tenth inside the limit: never over it.
    const rise = Math.tan((furthest * Math.PI) / 180) * 1.4 * 0.46;
    assert.ok(rise <= 0.1 - MIN_ROW_GAP + 1e-9);
});

test('the neighbours own tilts count when a row line is limited', () => {
    // The line above leans towards this one on the right, so there is less room there.
    const tight = clampRowAngle(lines, [0, 3, 0, 0], 2, -30, span);
    const free = clampRowAngle(lines, [0, 0, 0, 0], 2, -30, span);
    assert.ok(Math.abs(tight) < Math.abs(free));
    // A line that already crosses stays as it is.
    assert.equal(clampRowAngle([0.2, 0.2005, 0.3], [0, 0, 0], 1, 2, span), 0);
});

test('the first and last lines stay on the page', () => {
    const top = clampRowAngle([0.01, 0.2, 0.4], [0, 0, 0], 0, 30, span);
    assert.ok(Math.abs(top) < 1);
    assert.ok(clampRowAngle([0.01, 0.2, 0.4], [0, 0, 0], 0, 0.1, span) === 0.1);
});

test('a line added between two others is turned like both', () => {
    assert.deepEqual(insertTilt([0.2, 0.6], 1), [0.2, 0.4, 0.6]);
    assert.deepEqual(insertTilt([0.2, 0.6], 2), [0.2, 0.6, 0.6]);
    assert.deepEqual(insertTilt([], 0), [0]);
});

test('lines respaced evenly spread the old tilts along the new count', () => {
    assert.deepEqual(respaceTilts([0, 1], 5), [0, 0.3, 0.5, 0.8, 1]);
    assert.deepEqual(respaceTilts([0.5], 3), [0.5, 0.5, 0.5]);
    assert.deepEqual(respaceTilts([], 2), [0, 0]);
});

test('a tilt list is made the length of the lines: cut short, or padded with the last', () => {
    assert.deepEqual(fitTilts([0.1, 0.2, 0.3], 2), [0.1, 0.2]);
    assert.deepEqual(fitTilts([0.1, 0.2], 4), [0.1, 0.2, 0.2, 0.2]);
    assert.deepEqual(fitTilts(null, 3), [0, 0, 0]);
});

test('detecting a page moves a copied page 1 layout onto the columns that page printed', () => {
    // Page 2 started as a copy of page 1's six columns; it prints five, elsewhere.
    const copied = [0.02, 0.10, 0.24, 0.53, 0.61, 0.79].map((x, i) => column(`Column ${i + 1}`, x, 0.1, 0.5, 0.1));
    const printed = [0.02, 0.27, 0.51, 0.66, 0.86].map((x) => ({ x, w: 0.2 }));

    const { columns, created } = fitColumnsToPrinted(copied, printed, 0.24, 0.96);

    assert.equal(columns.length, 5);
    assert.deepEqual(columns.map((c) => c.x), [0.02, 0.27, 0.51, 0.66, 0.86]);
    assert.ok(columns.every((c) => c.y === 0.24 && Math.abs(c.h - 0.72) < 1e-9 && c.w === 0.2));
    assert.ok(created.length <= 5);
});

test('the same number of columns pair by order and keep their names and settings', () => {
    const existing = [
        { ...column('Name', 0.4), personGroup: 1 },
        { ...column('Entry', 0.05), personFieldOrder: 0 },
    ];

    const { columns, created } = fitColumnsToPrinted(existing, [{ x: 0.06, w: 0.1 }, { x: 0.3, w: 0.3 }], 0.2, 0.8);

    assert.deepEqual(columns.map((c) => [c.name, c.x, c.w]), [['Entry', 0.06, 0.1], ['Name', 0.3, 0.3]]);
    assert.equal(columns[1].personGroup, 1);
    assert.equal(columns[0].personFieldOrder, 0);
    assert.equal(created.length, 0);
});

test('a printed column nobody covers is new, and an unmatched existing column is dropped', () => {
    const existing = [column('A', 0.1), column('B', 0.4), column('C', 0.7)];

    const { columns, created } = fitColumnsToPrinted(existing, [{ x: 0.1, w: 0.1 }, { x: 0.4, w: 0.1 }, { x: 0.55, w: 0.05 }, { x: 0.72, w: 0.1 }].slice(0, 2), 0.2, 0.8);
    assert.deepEqual(columns.map((c) => c.name), ['A', 'B']);
    assert.equal(created.length, 0);

    const grown = fitColumnsToPrinted([column('A', 0.1)], [{ x: 0.1, w: 0.1 }, { x: 0.5, w: 0.1 }], 0.2, 0.8);
    assert.deepEqual(grown.created, [1]);
    assert.equal(grown.columns[0].name, 'A');
    assert.equal(grown.columns[1].kind, 'column');
});

test('fields drawn over a missed first row become a row of the table', () => {
    const ys = [0.3, 0.35, 0.4, 0.45];
    const col = (x, w) => ({ kind: 'column', name: 'c', x, y: 0.3, w, h: 0.15 });
    const field = (name, x, w, y) => ({ name, x, y, w, h: 0.05 });
    const boxes = [col(0.1, 0.3), col(0.45, 0.3), field('a', 0.1, 0.3, 0.25), field('b', 0.45, 0.3, 0.25), field('far', 0.1, 0.3, 0.8)];

    const result = mergeFieldsIntoTable(boxes, ys, [2, 3, 4]);
    assert.equal(result.merged, 2);
    assert.deepEqual(result.ys.map((y) => Math.round(y * 100) / 100), [0.25, 0.3, 0.35, 0.4, 0.45]);
    // Both fields go; the far one was not next to the grid and stays.
    assert.deepEqual(result.boxes.map((box) => box.name), ['c', 'c', 'far']);
    assert.ok(result.boxes.filter((box) => box.kind === 'column').every((c) => Math.abs(c.y - 0.25) < 1e-9 && Math.abs(c.h - 0.2) < 1e-9));

    // Under the last line works too; a field off the columns does not merge.
    const under = mergeFieldsIntoTable([col(0.1, 0.3), field('z', 0.1, 0.3, 0.45)], ys, [1]);
    assert.equal(under.ys.at(-1), 0.5);
    assert.equal(mergeFieldsIntoTable([col(0.1, 0.3), field('off', 0.6, 0.2, 0.32)], ys, [1]), null);
});

test('after a missed row is added, the other fields on it merge into their columns', () => {
    const ys = [0.25, 0.3, 0.35];
    const col = (x) => ({ kind: 'column', name: 'c', x, y: 0.25, w: 0.2, h: 0.1 });
    const boxes = [col(0.1), col(0.4), { name: 'b', x: 0.4, y: 0.25, w: 0.2, h: 0.05 }];

    const result = mergeFieldsIntoTable(boxes, ys, [2]);
    assert.equal(result.merged, 1);
    assert.equal(result.added, 0);
    assert.deepEqual(result.ys, ys);
    assert.deepEqual(result.boxes.map((box) => box.kind), ['column', 'column']);
});

test('columns sit on the first and last row lines where a tilted line crosses them', () => {
    const column = (x) => ({ kind: 'column', name: `c${x}`, x, y: 0.2, w: 0.1, h: 0.6 });
    const boxes = [column(0.1), column(0.8), { kind: 'field', name: 'date', x: 0.1, y: 0.05, w: 0.2, h: 0.05 }];
    const ys = [0.21, 0.5, 0.79];
    // The first line slopes down 1° to the right (on a square page), the others are level.
    const fitted = columnsOnLines(boxes, ys, [1, 0, 0], 1);
    const slope = Math.tan(Math.PI / 180);
    assert.ok(Math.abs(fitted[0].y - (0.21 + slope * (0.15 - 0.5))) < 1e-4);
    assert.ok(Math.abs(fitted[1].y - (0.21 + slope * (0.85 - 0.5))) < 1e-4);
    assert.ok(fitted[1].y > fitted[0].y);
    assert.ok(Math.abs(fitted[0].y + fitted[0].h - 0.79) < 1e-4);
    // A field is not a column: left alone.
    assert.equal(fitted[2], boxes[2]);
    // On the lines already: nothing changes.
    assert.equal(columnsOnLines(fitted, ys, [1, 0, 0], 1), fitted);
    // A column set well apart from the lines (more than a row away) stays where it is.
    const apart = [{ ...column(0.1), y: 0.02, h: 0.9 }];
    const rows = [0.3, 0.35, 0.4, 0.45, 0.5];
    assert.equal(columnsOnLines(apart, rows, rows.map(() => 0), 1), apart);
});

test('a bent line is followed too: the column top is the middle of its bent ends', () => {
    const boxes = [{ kind: 'column', name: 'a', x: 0.2, y: 0.2, w: 0.2, h: 0.6 }];
    const fitted = columnsOnLines(boxes, [0.2, 0.8], [0, 0], 1, [[[0.01, 0.03]], null]);
    assert.ok(Math.abs(fitted[0].y - 0.22) < 1e-4);
    assert.ok(Math.abs(fitted[0].y + fitted[0].h - 0.8) < 1e-4);
});

test('Align lines every column up on the first and last row lines, however far off it was', () => {
    const boxes = [
        { kind: 'column', name: 'A', x: 0.1, y: 0.05, w: 0.2, h: 0.9 },
        { kind: 'column', name: 'B', x: 0.3, y: 0.2, w: 0.2, h: 0.5 },
    ];
    const ys = [0.3, 0.35, 0.4, 0.45, 0.5];
    // Further off than a row: left alone unless asked.
    assert.equal(columnsOnLines(boxes, ys), boxes);
    const tidy = columnsOnLines(boxes, ys, [], 1, null, { always: true });
    tidy.forEach((box) => {
        assert.ok(Math.abs(box.y - 0.3) < 1e-6);
        assert.ok(Math.abs(box.y + box.h - 0.5) < 1e-6);
    });
});

test('overlapping neighbour columns meet half way; a sliver of a gap closes; a real gap stays', () => {
    const boxes = [
        { kind: 'column', name: 'A', x: 0.10, y: 0.1, w: 0.22, h: 0.8 },
        { kind: 'column', name: 'B', x: 0.30, y: 0.1, w: 0.20, h: 0.8 },
        { kind: 'column', name: 'C', x: 0.503, y: 0.1, w: 0.1, h: 0.8 },
        { kind: 'column', name: 'D', x: 0.70, y: 0.1, w: 0.1, h: 0.8 },
        { kind: 'field', name: 'Year', x: 0.2, y: 0.0, w: 0.5, h: 0.05 },
    ];
    const next = columnsSideBySide(boxes);
    const [a, b, c, d, field] = next;
    // A and B overlapped by 0.02: they share the middle, 0.31.
    assert.ok(Math.abs(a.x + a.w - 0.31) < 1e-6);
    assert.ok(Math.abs(b.x - 0.31) < 1e-6);
    assert.ok(Math.abs(b.x + b.w - 0.5) < 1e-6 || Math.abs(b.x + b.w - c.x) < 1e-6);
    // B and C: a 0.003 sliver closed.
    assert.ok(Math.abs(b.x + b.w - c.x) < 1e-6);
    // C and D: a real gap (a column left out) stays.
    assert.ok(Math.abs(d.x - 0.70) < 1e-6);
    assert.equal(field, boxes[4]);
    // Already side by side: nothing moves.
    assert.equal(columnsSideBySide(next), next);
});
