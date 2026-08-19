import assert from 'node:assert/strict';
import test from 'node:test';
import { fieldMarkerPanPosition } from '../../resources/js/field-marker.js';

test('Ctrl-drag pans the document viewport opposite the pointer movement', () => {
    assert.deepEqual(fieldMarkerPanPosition(400, 300, 50, -30), {
        left: 350,
        top: 330,
    });
    assert.deepEqual(fieldMarkerPanPosition(20, 10, 80, 50), {
        left: 0,
        top: 0,
    });
});
