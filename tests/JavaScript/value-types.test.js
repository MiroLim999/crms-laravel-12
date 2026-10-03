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
