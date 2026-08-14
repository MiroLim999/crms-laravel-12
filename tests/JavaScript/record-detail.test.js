import assert from 'node:assert/strict';
import test from 'node:test';
import {
    clampRecordScanZoom,
    clampRecordSplit,
    RECORD_SPLIT_DEFAULT,
    recordComparisonFrames,
    recordScanPanPosition,
    recordScanZoomFromWheel,
    recordSplitFromKey,
    recordSplitFromPointer,
} from '../../resources/js/record-detail.js';

test('record split width stays inside usable panel limits', () => {
    assert.equal(clampRecordSplit(null), RECORD_SPLIT_DEFAULT);
    assert.equal(clampRecordSplit(10), 35);
    assert.equal(clampRecordSplit(58.4), 58);
    assert.equal(clampRecordSplit(95), 75);
});

test('original comparison slides horizontally or vertically into the workspace', () => {
    assert.deepEqual(recordComparisonFrames(true), [
        { opacity: 0, transform: 'translateX(-1.5rem)' },
        { opacity: 1, transform: 'translate(0, 0)' },
    ]);
    assert.deepEqual(recordComparisonFrames(false, true), [
        { opacity: 1, transform: 'translate(0, 0)' },
        { opacity: 0, transform: 'translateY(-1rem)' },
    ]);
});

test('record split follows the horizontal mouse position', () => {
    assert.equal(recordSplitFromPointer(600, 100, 1000), 50);
    assert.equal(recordSplitFromPointer(200, 100, 1000), 35);
    assert.equal(recordSplitFromPointer(1000, 100, 1000), 75);
});

test('record split supports precise and accelerated keyboard resizing', () => {
    assert.equal(recordSplitFromKey('ArrowLeft', 58), 56);
    assert.equal(recordSplitFromKey('ArrowRight', 58, true), 63);
    assert.equal(recordSplitFromKey('Home', 58), 35);
    assert.equal(recordSplitFromKey('End', 58), 75);
    assert.equal(recordSplitFromKey('Enter', 58), null);
});

test('Ctrl-wheel scan zoom follows wheel direction and stays within limits', () => {
    assert.equal(recordScanZoomFromWheel(1.6, -100), 1.7);
    assert.equal(recordScanZoomFromWheel(1.6, 100), 1.5);
    assert.equal(recordScanZoomFromWheel(3, -100), 3);
    assert.equal(recordScanZoomFromWheel(1, 100), 1);
    assert.equal(clampRecordScanZoom(Number.NaN), 1);
});

test('Ctrl-drag pans the scan opposite the pointer movement', () => {
    assert.deepEqual(recordScanPanPosition(300, 200, 40, -25), {
        left: 260,
        top: 225,
    });
    assert.deepEqual(recordScanPanPosition(10, 15, 50, 30), {
        left: 0,
        top: 0,
    });
});
