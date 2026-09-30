import assert from 'node:assert/strict';
import test from 'node:test';

import {
    clampRuledY,
    columnBand,
    distributeRuledYs,
    fieldsOverLedger,
    followFirstAndLastLine,
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
