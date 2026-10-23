import assert from 'node:assert/strict';
import test from 'node:test';

import { LayoutHistory } from '../../resources/js/template-history.js';

const column = (x) => ({ name: `Column ${x}`, x, y: 0.2, w: 0.1, h: 0.6, kind: 'column' });
const layout = (boxes, ruledYs, groupingMode = 'auto') => ({ boxes, groupingMode, ruledYs });

test('undoing "Remove grid" brings back the columns together with their row lines', () => {
    const history = new LayoutHistory();
    const ledger = layout([column(0.1), column(0.2)], [0.2, 0.4, 0.6, 0.8]);

    history.record(ledger);
    // Remove grid: the columns and the row lines go in one change.
    history.record(layout([], []));

    assert.deepEqual(history.undo(), ledger);
    assert.equal(history.canUndo, false);
});

test('moving a row line alone is its own undo step', () => {
    const history = new LayoutHistory();
    const boxes = [column(0.1)];

    history.record(layout(boxes, [0.2, 0.4, 0.6]));
    assert.equal(history.record(layout(boxes, [0.2, 0.45, 0.6])), true);

    assert.deepEqual(history.undo().ruledYs, [0.2, 0.4, 0.6]);
});

test('undoing a grid detection restores the earlier row lines, not only the markers', () => {
    const history = new LayoutHistory();
    const before = layout([column(0.1)], [0.1, 0.2]);

    history.record(before);
    history.record(layout([column(0.1), column(0.3)], [0.25, 0.3, 0.35, 0.4]));

    assert.deepEqual(history.undo(), before);
});

test('recording the same layout again adds no step', () => {
    const history = new LayoutHistory();
    const state = layout([column(0.1)], [0.2, 0.4]);

    history.record(state);
    assert.equal(history.record(layout([column(0.1)], [0.2, 0.4])), false);
    assert.equal(history.canUndo, false);
});

test('a restored layout is synced without becoming a new step', () => {
    const history = new LayoutHistory();
    history.record(layout([], [0.2, 0.4]));
    history.record(layout([], [0.2, 0.5]));

    const restored = history.undo();
    // What the editor reports back after restoring may be normalised.
    history.sync({ ...restored, groupingMode: 'auto' });

    assert.equal(history.record({ ...restored, groupingMode: 'auto' }), false);
    assert.equal(history.canUndo, false);
});

test('a step is a copy that later edits cannot change', () => {
    const history = new LayoutHistory();
    const ruledYs = [0.2, 0.4];

    history.record(layout([], ruledYs));
    ruledYs[0] = 0.3;
    history.record(layout([], ruledYs));

    assert.deepEqual(history.undo().ruledYs, [0.2, 0.4]);
});

test('only the most recent steps are kept', () => {
    const history = new LayoutHistory({ limit: 3 });
    for (let step = 0; step <= 5; step += 1) history.record(layout([], [step / 10, 0.9]));

    const undone = [];
    let previous = history.undo();
    while (previous) {
        undone.push(previous.ruledYs[0]);
        previous = history.undo();
    }
    assert.deepEqual(undone, [0.4, 0.3, 0.2]);
});
