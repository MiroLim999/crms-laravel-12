import assert from 'node:assert/strict';
import test from 'node:test';

import {
    addColumn,
    clampRuledY,
    columnBand,
    distributeRuledYs,
    fieldsOverLedger,
    fieldsToColumns,
    followFirstAndLastLine,
    gridSummary,
    insertRuledY,
    ledgerGridProblems,
    MIN_ROW_GAP,
    nearestLine,
    remapRuledYs,
    shiftRuledYs,
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
    // Staff align columns by hand, so their edges sit near the lines, not exactly on them.
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

test('the grid summary names the template\'s rows only when the page differs', () => {
    assert.equal(gridSummary(11, [0.1, 0.2, 0.3], 2), '11 columns · 2 rows');
    assert.equal(gridSummary(1, [0.1, 0.2, 0.3, 0.4, 0.5], 2), '1 column · 4 rows (template: 2)');
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

test('a consistent ledger has no problems, and a layout without columns is not a ledger', () => {
    assert.deepEqual(ledgerGridProblems([column('A', 0.1), column('B', 0.2)], [0.2, 0.4, 0.6, 0.8]), []);
    assert.deepEqual(ledgerGridProblems([], [0.2]), []);
    assert.equal(ledgerGridProblems([column('A', 0.1)], [0.2])[0].blocking, true);
});
