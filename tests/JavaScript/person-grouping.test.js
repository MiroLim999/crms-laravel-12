import assert from 'node:assert/strict';
import test from 'node:test';

import {
    markerPersonMetadata,
    nonNegativeInteger,
    positiveInteger,
    templatePersonPayload,
} from '../../resources/js/person-grouping.js';

test('optional person numbers do not coerce missing values to zero', () => {
    for (const missing of [null, undefined, '', '   ']) {
        assert.equal(positiveInteger(missing), null);
        assert.equal(nonNegativeInteger(missing), null);
    }

    assert.equal(positiveInteger('1'), 1);
    assert.equal(positiveInteger(0), null);
    assert.equal(nonNegativeInteger(0), 0);
    assert.equal(nonNegativeInteger('0'), 0);
});

test('marker metadata keeps an ungrouped field completely ungrouped', () => {
    assert.deepEqual(markerPersonMetadata({
        personGroup: null,
        personFieldOrder: null,
    }), {});

    assert.deepEqual(templatePersonPayload({}, true), {
        person_group: null,
        person_field_order: null,
    });
});

test('eleven grouped fields and one detail field produce a valid custom payload', () => {
    const fields = Array.from({ length: 12 }, (_, index) => (
        index < 11
            ? { personGroup: 1, personFieldOrder: index }
            : { personGroup: null, personFieldOrder: null }
    ));

    const payload = fields.map((field) => (
        templatePersonPayload(markerPersonMetadata(field), true)
    ));

    payload.slice(0, 11).forEach((field, index) => {
        assert.deepEqual(field, {
            person_group: 1,
            person_field_order: index,
        });
    });
    assert.deepEqual(payload[11], {
        person_group: null,
        person_field_order: null,
    });
});

test('an order without a group is safely treated as ungrouped', () => {
    const orderOnlyMetadata = markerPersonMetadata({
        personGroup: null,
        personFieldOrder: 0,
    });

    assert.deepEqual(orderOnlyMetadata, {});
    assert.deepEqual(templatePersonPayload(orderOnlyMetadata, true), {
        person_group: null,
        person_field_order: null,
    });

    const groupWithoutOrder = markerPersonMetadata({
        personGroup: 1,
        personFieldOrder: null,
    });
    assert.deepEqual(groupWithoutOrder, { personGroup: 1 });
    assert.deepEqual(templatePersonPayload(groupWithoutOrder, true), {
        person_group: 1,
        person_field_order: null,
    });
});
