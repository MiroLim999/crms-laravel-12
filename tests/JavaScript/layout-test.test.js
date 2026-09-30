import assert from 'node:assert/strict';
import test from 'node:test';

import { summariseTest, testFindings, testLineStatus } from '../../resources/js/layout-test.js';

const line = (column, row, flags = [], source = 'kraken') => ({ column, row, flags, source, polygon: [] });

test('each line is drawn by what became of it', () => {
    assert.equal(testLineStatus(line('Name', 2)), 'placed');
    assert.equal(testLineStatus(line('Name', null, ['no_row'])), 'no-row');
    assert.equal(testLineStatus(line('Name', 2, ['shared_cell'])), 'shared');
    assert.equal(testLineStatus(line('Diseases', 1, [], 'field')), 'field');
});

test('a test sums up lines, rows, and rows missing required cells', () => {
    const summary = summariseTest({
        lines: [
            line('Entry', 1), line('Name', 1), line('Sex', 1),
            line('Entry', 2), line('Name', 2),
            line('Name', null, ['no_row']),
            line('Title', 1, [], 'template'),
        ],
        fit: { fitted: true },
        ignored: 2,
    }, ['Entry', 'Name', 'Sex']);

    assert.equal(summary.lines, 7);
    assert.equal(summary.rows, 2);
    assert.equal(summary.noRow, 1);
    assert.equal(summary.fieldLines, 1);
    assert.deepEqual(summary.incompleteRows, [{ row: 2, missing: ['Sex'] }]);
    assert.equal(summary.fitted, true);
});

test('the findings put what needs attention first and stay quiet about a clean test', () => {
    assert.deepEqual(testFindings(summariseTest({ lines: [line('Name', 1)], fit: { fitted: true } }, ['Name'])), []);

    const findings = testFindings(summariseTest({
        lines: [line('Name', null, ['no_row']), line('Name', 3), line('Sex', 4)],
        fit: { fitted: false },
        deskew: 1.25,
    }, ['Name', 'Sex']));
    assert.equal(findings[0].text.startsWith('The markers could not be fitted'), true);
    assert.match(findings[1].text, /^1 line sits between two rows/);
    assert.match(findings[2].text, /row 3 \(Sex\); row 4 \(Name\)/);
    assert.match(findings.at(-1).text, /straightened by 1\.3°/);
});
