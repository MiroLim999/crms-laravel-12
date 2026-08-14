import assert from 'node:assert/strict';
import test from 'node:test';

import {
    canVerifyValue,
    verificationGroupState,
} from '../../resources/js/verification-groups.js';

test('person verification state distinguishes none, partial, and all checked', () => {
    assert.deepEqual(verificationGroupState([false, false, false]), {
        checked: false,
        indeterminate: false,
        verified: 0,
        total: 3,
    });
    assert.deepEqual(verificationGroupState([true, false, true]), {
        checked: false,
        indeterminate: true,
        verified: 2,
        total: 3,
    });
    assert.deepEqual(verificationGroupState([true, true, true]), {
        checked: true,
        indeterminate: false,
        verified: 3,
        total: 3,
    });
});

test('bulk verification only accepts fields containing a value', () => {
    assert.equal(canVerifyValue('Juan D. Abad'), true);
    assert.equal(canVerifyValue('  M  '), true);
    assert.equal(canVerifyValue(''), false);
    assert.equal(canVerifyValue('   '), false);
    assert.equal(canVerifyValue(null), false);
});
