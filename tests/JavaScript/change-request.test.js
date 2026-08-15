import assert from 'node:assert/strict';
import test from 'node:test';
import {
    changeRequestValueChanged,
    countChangedProposals,
    normaliseChangeRequestValue,
} from '../../resources/js/change-request.js';

test('change request values use the same trim and blank rules as the server', () => {
    assert.equal(normaliseChangeRequestValue('  Maria Santos  '), 'Maria Santos');
    assert.equal(normaliseChangeRequestValue('   '), null);
    assert.equal(normaliseChangeRequestValue(null), null);
    assert.equal(changeRequestValueChanged('Maria Santos', '  Maria Santos '), false);
    assert.equal(changeRequestValueChanged('', 'Corrected'), true);
});

test('changed proposal count ignores unchanged and whitespace-only differences', () => {
    assert.equal(countChangedProposals([
        { current: 'One', proposed: 'One' },
        { current: 'Two', proposed: ' Two ' },
        { current: null, proposed: '' },
        { current: 'Four', proposed: 'Corrected' },
    ]), 1);
});
