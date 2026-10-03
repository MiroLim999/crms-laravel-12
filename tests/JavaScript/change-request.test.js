import assert from 'node:assert/strict';
import test from 'node:test';
import {
    changeRequestValueChanged,
    countChangedProposals,
    normaliseChangeRequestValue,
    submitOnce,
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

test('only the first submit among the guarded forms is sent', () => {
    const approve = fakeForm();
    const reject = fakeForm();
    submitOnce([approve, reject]);

    assert.equal(approve.submit(), true);
    assert.equal(approve.button.disabled, true);
    assert.equal(reject.button.disabled, true);
    assert.equal(approve.submit(), false);
    assert.equal(reject.submit(), false);
});

function fakeForm() {
    const listeners = [];
    const button = { disabled: false };

    return {
        button,
        addEventListener: (type, listener) => listeners.push(listener),
        querySelectorAll: () => [button],
        // Runs the submit listeners and says whether the browser would send the form.
        submit() {
            let sent = true;
            listeners.forEach((listener) => listener({ preventDefault: () => { sent = false; } }));
            return sent;
        },
    };
}
