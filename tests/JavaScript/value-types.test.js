import assert from 'node:assert/strict';
import test from 'node:test';

import {
    looksLikeDate,
    looksLikeNumber,
    matchingChoice,
    valueProblem,
} from '../../resources/js/value-types.js';

test('dates as the registers write them are dates', () => {
    [
        'Jan. 03, 1978', 'Jan, 02, 2024', 'Jan.03, 1978', 'Jan. 04.1978', 'January 3 1978',
        '3 January 1978', 'Sept. 21, 1950', '21st March 1950', '01/03/1978', '1978-01-03', '3.1.78',
    ].forEach((value) => assert.equal(looksLikeDate(value), true, value));
});

test('names, places and half dates are not dates', () => {
    ['Maasin City', 'Juan Dela Cruz', 'Jan 1978', '13/13/1978', '1978', 'Legitimate', '12:30'].forEach((value) => {
        assert.equal(looksLikeDate(value), false, value);
    });
});

test('numbers, grouped or labelled, are numbers', () => {
    ['12', '1,024', 'No. 12', '#7', '2024-001'].forEach((value) => assert.equal(looksLikeNumber(value), true, value));
    ['twelve', 'M', '12a', ''].forEach((value) => assert.equal(looksLikeNumber(value), false, value));
});

test('a choice matches whole, ignoring case and a final period, or by its only prefix', () => {
    const options = ['Male', 'Female'];
    assert.equal(matchingChoice('male', options), 'Male');
    assert.equal(matchingChoice('F.', options), 'Female');
    assert.equal(matchingChoice('M', options), 'Male');
    assert.equal(matchingChoice('Legit', ['Legitimate', 'Illegitimate']), 'Legitimate');
    // "Ma" starts no second choice here, but "e" is not the start of any.
    assert.equal(matchingChoice('e', options), null);
    assert.equal(matchingChoice('Mal e', options), null);
});

test('only values that do not fit their field get a problem', () => {
    assert.equal(valueProblem('Juan', { type: 'text' }), null);
    assert.equal(valueProblem('Juan'), null);
    assert.equal(valueProblem('', { type: 'date' }), null);
    assert.equal(valueProblem('Maasin City', { type: 'date' }), 'This does not look like a date.');
    assert.equal(valueProblem('twelve', { type: 'number' }), 'This does not look like a number.');
    assert.equal(valueProblem('X', { type: 'choice', options: ['M', 'F'] }), 'Expected one of: M, F.');
    assert.equal(valueProblem('m', { type: 'choice', options: ['M', 'F'] }), null);
    assert.equal(valueProblem('anything', { type: 'choice', options: [] }), null);
});

test('register dates become YYYY-MM-DD; a half date is not a date', async () => {
    const { toIsoDate } = await import('../../resources/js/value-types.js');
    const today = new Date('2026-11-25');
    [
        ['5-14-79', '1979-05-14'], ['3-11-57', '1957-03-11'], ['12-11-1956', '1956-12-11'], ['9-21-1904', '1904-09-21'],
        ['Jan, 07,1978', '1978-01-07'], ['Jan. 03, 1978', '1978-01-03'], ['3 January 1978', '1978-01-03'],
        ['21st March 1950', '1950-03-21'], ['1978-01-03', '1978-01-03'], ['14-5-79', '1979-05-14'],
        ['Sept. 21, 1950', '1950-09-21'], ['12-5-24', '2024-12-05'],
    ].forEach(([value, iso]) => assert.equal(toIsoDate(value, today), iso, value));
    ['02/30/1990', '3-1957', '33-15', '3-45', 'Maasin', ''].forEach((value) => assert.equal(toIsoDate(value, today), null, value));
});

test('a register that writes the day first: one date that can only be day first decides the column', async () => {
    const { toIsoDate, dateOrderOf } = await import('../../resources/js/value-types.js');
    const today = new Date('2026-11-25');
    assert.equal(toIsoDate('3-11-57', today), '1957-03-11');
    assert.equal(toIsoDate('3-11-57', today, { dayFirst: true }), '1957-11-03');
    // A number that cannot be a month decides anyway.
    assert.equal(toIsoDate('3-25-57', today, { dayFirst: true }), '1957-03-25');
    assert.equal(dateOrderOf(['3-11-57', '25-3-57', '']), 'day');
    assert.equal(dateOrderOf(['3-11-57', '3-25-57']), 'month');
    assert.equal(dateOrderOf(['3-11-57', 'Jan. 3, 1957']), null);
});

test('a date misread by one stray digit is repaired from its column, and only when one answer fits', async () => {
    const { toIsoDate, usualMonthOf, repairedDate } = await import('../../resources/js/value-types.js');
    const today = new Date('2026-11-25');
    const column = ['2-11-57', '22-22-57', '22-16-57', '2-24-57', '21-18-57', '2-18-57', '27-19-57', '3-27-143', '33-37', '14-22-33'];
    const usual = usualMonthOf(column.map((value) => toIsoDate(value, today)));
    assert.deepEqual(usual, { year: 1957, month: 2 });
    const repaired = (value) => repairedDate(value, usual, { today });
    assert.equal(repaired('22-22-57'), '1957-02-22');
    assert.equal(repaired('22-16-57'), '1957-02-16');
    assert.equal(repaired('21-18-57'), '1957-02-18');
    assert.equal(repaired('27-19-57'), '1957-02-19');
    // Too garbled, or outside the column's month: left for the reviewer.
    ['3-27-143', '33-37', '14-22-33', 'Maasin'].forEach((value) => assert.equal(repaired(value), null, value));
    // A whole date is never "repaired"; without a usual month, nothing is.
    assert.equal(repaired('2-11-57'), null);
    assert.equal(repairedDate('22-22-57', null, { today }), null);
    assert.equal(usualMonthOf(['1957-02-11']), null);
});

test('a date whose dashes were missed is repaired from its column: 3457 and 36-57 in March 1957', async () => {
    const { repairedDate } = await import('../../resources/js/value-types.js');
    const today = new Date('2026-11-28');
    const usual = { year: 1957, month: 3 };
    assert.equal(repairedDate('3457', usual, { today }), '1957-03-04');
    assert.equal(repairedDate('36-57', usual, { today }), '1957-03-06');
    assert.equal(repairedDate('31157', usual, { today }), '1957-03-11');
    // Outside the column's month, or more than one way: left for the reviewer.
    assert.equal(repairedDate('4557', usual, { today }), null);
    assert.equal(repairedDate('10-19-', usual, { today }), null);
});
