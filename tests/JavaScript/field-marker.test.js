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

import {
    FieldMarker,
    markerAngleMetadata,
    markerPivot,
    normaliseAngle,
    turnPoint,
} from '../../resources/js/field-marker.js';

test('marker angles are tenths of a degree in (-180, 180], and upright markers carry none', () => {
    assert.equal(normaliseAngle(12.345), 12.3);
    assert.equal(normaliseAngle(-190), 170);
    assert.equal(normaliseAngle(180), 180);
    assert.equal(normaliseAngle(-180), 180);
    assert.equal(normaliseAngle('not a number'), 0);
    assert.deepEqual(markerAngleMetadata({ angle: 0.04 }), {});
    assert.deepEqual(markerAngleMetadata({ angle: -2.5 }), { angle: -2.5 });
});

test('every marker, a ledger column too, turns about its own centre', () => {
    for (const kind of [undefined, 'column']) {
        const pivot = markerPivot({ x: 0.1, y: 0.2, w: 0.4, h: 0.2, kind }, 1000, 500);
        assert.ok(Math.abs(pivot.x - 300) < 1e-9 && Math.abs(pivot.y - 150) < 1e-9);
    }
    // Clockwise on screen: right turns to down.
    const down = turnPoint(10, 0, 90);
    assert.ok(Math.abs(down.x) < 1e-9 && Math.abs(down.y - 10) < 1e-9);
});

test('each marker turns on its own: a turned column leaves its neighbours as they were', () => {
    const columnA = { name: 'A', x: 0.1, y: 0.2, w: 0.2, h: 0.6, kind: 'column', angle: 1 };
    const columnB = { name: 'B', x: 0.3, y: 0.2, w: 0.2, h: 0.6, kind: 'column', angle: 1 };
    const field = { name: 'No.', x: 0.6, y: 0.05, w: 0.2, h: 0.05, angle: -3 };
    const fake = { boxes: [columnA, columnB, field] };

    FieldMarker.prototype._turnBoxes.call(fake, [{ box: columnA, angle: 1 }, { box: field, angle: -3 }], 1.5);

    assert.deepEqual([columnA.angle, columnB.angle, field.angle], [2.5, 1, -1.5]);
});

test('resizing a turned field grows it along its own sides and keeps its turned top-left corner', () => {
    const width = 1000;
    const height = 600;
    const box = { name: 'Remarks', x: 0.3, y: 0.3, w: 0.2, h: 0.1, angle: 30 };
    const corner = (b) => {
        const centre = { x: (b.x + b.w / 2) * width, y: (b.y + b.h / 2) * height };
        const offset = turnPoint(-b.w * width / 2, -b.h * height / 2, b.angle);
        return { x: centre.x + offset.x, y: centre.y + offset.y };
    };
    const before = corner(box);

    // Drag the corner handle 50 px along the field's own width.
    const along = turnPoint(50, 0, 30);
    FieldMarker.prototype._dragTurned.call({}, [{ box, ...box }], 'resize', along.x, along.y, width, height);

    assert.ok(Math.abs(box.w * width - 250) < 1e-6);
    assert.ok(Math.abs(box.h * height - 60) < 1e-6);
    const after = corner(box);
    assert.ok(Math.hypot(after.x - before.x, after.y - before.y) < 1e-6);
});

test('a turned marker moves the way the pointer moves on screen', () => {
    const column = { name: 'A', x: 0.2, y: 0.2, w: 0.2, h: 0.5, kind: 'column', angle: 90 };
    FieldMarker.prototype._dragTurned.call({}, [{ box: column, ...column }], 'move', 0, 100, 1000, 500);

    assert.ok(Math.abs(column.x - 0.2) < 1e-9);
    assert.ok(Math.abs(column.y - 0.4) < 1e-9);
});
