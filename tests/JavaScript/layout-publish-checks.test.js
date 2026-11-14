import assert from 'node:assert/strict';
import test from 'node:test';

import { guessedRowCount, isDefaultName, namesOnBothPages, publishProblems, rowMismatch } from '../../resources/js/layout-publish-checks.js';
import { columnsAtPageTilt, gridTilt, rowsWithinFrame } from '../../resources/js/ledger-grid.js';

const ready = [
    { name: 'Entry No.', role: 'entry' },
    { name: "Child's Name", role: 'name' },
];

test('a layout with a sample and real names can be published', () => {
    assert.deepEqual(publishProblems({ hasSample: true, pages: [ready] }), []);
});

test('a layout without a sample is not ready', () => {
    const problems = publishProblems({ hasSample: false, pages: [ready] });
    assert.equal(problems.length, 1);
    assert.match(problems[0], /sample page/);
});

test('default column names are listed, the first four and how many more', () => {
    const columns = [...ready, ...Array.from({ length: 6 }, (_, i) => ({ name: `Column ${i + 3}` }))];
    const [problem] = publishProblems({ hasSample: true, pages: [columns] });
    assert.match(problem, /Column 3, Column 4, Column 5, Column 6 and 2 more/);
});

test('page 2 names are checked too', () => {
    const problems = publishProblems({ hasSample: true, pages: [ready, [{ name: 'Field 2' }]] });
    assert.equal(problems.length, 1);
    assert.match(problems[0], /Field 2/);
});

test('only names the builder made up count as default', () => {
    assert.ok(isDefaultName('Column 8'));
    assert.ok(isDefaultName(' field 12 '));
    assert.ok(isDefaultName('Column8'));
    assert.ok(!isDefaultName('Column 8 notes'));
    assert.ok(!isDefaultName("Child's Name"));
    assert.ok(!isDefaultName(''));
});

test('guessed rows are counted over every page', () => {
    const key = (y) => y.toFixed(5);
    const pages = [
        { ruledYs: [0.1, 0.2, 0.3], estimated: [key(0.3)] },
        { ruledYs: [0.1, 0.2], estimated: [key(0.1), key(0.9)] },
        { ruledYs: [0.5, 0.6] },
    ];
    assert.equal(guessedRowCount(pages, key), 2);
});

test('row lines below the table frame are dropped, one up to half a row below stays', () => {
    const ys = [0.2, 0.3, 0.4, 0.5, 0.6, 0.7];
    // The frame ends at 0.5: 0.6 and 0.7 are a signature line and a margin.
    assert.deepEqual(rowsWithinFrame(ys, 0.5), [0.2, 0.3, 0.4, 0.5]);
    assert.deepEqual(rowsWithinFrame(ys, 0.55), [0.2, 0.3, 0.4, 0.5, 0.6]);
    assert.deepEqual(rowsWithinFrame(ys, null), ys);
    assert.deepEqual(rowsWithinFrame(ys, 0.05), [0.2, 0.3]);
});

test('detected columns are set upright, then turned by the page tilt', () => {
    const boxes = [
        { name: 'Page', x: 0.8, y: 0.02, w: 0.1, h: 0.04 },
        { name: 'A', kind: 'column', x: 0.1, y: 0.2, w: 0.3, h: 0.6, angle: 2 },
        { name: 'B', kind: 'column', x: 0.4, y: 0.2, w: 0.3, h: 0.6, angle: 2 },
    ];
    const turned = columnsAtPageTilt(boxes, -0.4, 1.4);
    assert.equal(gridTilt(turned.filter((box) => box.kind === 'column')), -0.4);
    assert.deepEqual(turned[0], boxes[0]);

    // Under a tenth of a degree is no tilt: upright.
    const upright = columnsAtPageTilt(boxes, 0.04, 1.4);
    assert.deepEqual(upright.filter((box) => box.kind === 'column').map((box) => box.angle), [0, 0]);
    assert.equal(upright[1].x, 0.1);
});

test('two pages: a name on both pages is pointed out, once, in page 1 order', () => {
    const pageOne = [{ name: 'Entry No.', role: 'entry' }, { name: "Child's Name", role: 'name' }, { name: 'Remarks' }];
    const pageTwo = [{ name: 'remarks ' }, { name: 'Entry no.' }, { name: "Father's Name" }];
    assert.deepEqual(namesOnBothPages(pageOne, pageTwo), ['Entry No.', 'Remarks']);
    const [problem] = publishProblems({ hasSample: true, pages: [pageOne, pageTwo] });
    assert.match(problem, /own column names \(on both pages: Entry No\., Remarks\)/);
});

test('two pages: different row counts stop the publish; equal or tableless pages do not', () => {
    const pageOne = [{ name: "Child's Name", role: 'name' }];
    const pageTwo = [{ name: "Father's Name" }];
    assert.deepEqual(publishProblems({ hasSample: true, pages: [pageOne, pageTwo], rows: [20, 20] }), []);
    assert.deepEqual(publishProblems({ hasSample: true, pages: [pageOne, pageTwo], rows: [20, null] }), []);
    const [problem] = publishProblems({ hasSample: true, pages: [pageOne, pageTwo], rows: [20, 19] });
    assert.match(problem, /Page 1 has 20 rows and page 2 has 19/);
    assert.equal(rowMismatch([1, 2]), 'Page 1 has 1 row and page 2 has 2. Row 1 of both pages is one person, so both pages need the same number of rows.');
    assert.equal(rowMismatch(undefined), null);
});
