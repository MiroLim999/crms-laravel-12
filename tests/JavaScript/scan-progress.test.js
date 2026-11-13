import assert from 'node:assert/strict';
import test from 'node:test';

import {
    pageSeconds,
    phaseProgress,
    scanTimings,
    STEADY_SHARE,
    timeLeftText,
} from '../../resources/js/scan-progress.js';

test('the ring crosses a phase at a steady pace, arriving as the phase usually ends', () => {
    // Finding lines: 18% to 72% of the ring, usually 16 s.
    const at = (seconds) => phaseProgress(18, 72, seconds, 16);

    assert.equal(at(0), 18);
    // Even steps: each second is worth the same.
    const perSecond = at(1) - at(0);
    for (const second of [3, 8, 15]) {
        assert.ok(Math.abs((at(second + 1) - at(second)) - perSecond) < 1e-9);
    }
    // After the usual time it has crossed most of the phase...
    assert.ok(Math.abs(at(16) - (18 + 54 * STEADY_SHARE)) < 1e-9);
    // ...and a phase that runs over still moves, without reaching the ceiling.
    assert.ok(at(20) > at(16));
    assert.ok(at(40) > at(20));
    assert.ok(at(60) < 72);
    assert.ok(at(6000) <= 72);
});

test('a phase the ring is already past holds it, and odd numbers do no harm', () => {
    assert.equal(phaseProgress(73, 73, 5, 2), 73);
    assert.equal(phaseProgress(80, 18, 5, 2), 80);
    assert.equal(phaseProgress(10, 20, -3, 4), 10);
    // No usual time known: still moves forward, never NaN.
    assert.ok(phaseProgress(10, 20, 1, 0) > 10);
    assert.ok(Number.isFinite(phaseProgress(10, 20, 1, undefined)));
});

test('the time left is said in plain words', () => {
    // 4 s into a 16 s phase, with 3 s of reading after it.
    assert.equal(timeLeftText(4, 16, 3), 'About 15 seconds left');
    assert.equal(timeLeftText(14, 16, 0), 'About 2 seconds left');
    // Under a second and a half is not worth a number.
    assert.equal(timeLeftText(15, 16, 0), 'Almost done');
    // Above 20 s it counts in fives.
    assert.equal(timeLeftText(0, 16, 17.4), 'About 35 seconds left');
    assert.equal(timeLeftText(0, 40, 22), 'About 1 minute left');
    assert.equal(timeLeftText(0, 100, 60), 'About 3 minutes left');
});

test('a phase well past its usual time gives no number', () => {
    // A little over: the phases after it are still counted.
    assert.equal(timeLeftText(17, 16, 3), 'About 3 seconds left');
    assert.equal(timeLeftText(17, 16, 0), 'Almost done');
    // Well over (more than a quarter, and more than 3 s).
    assert.equal(timeLeftText(21, 16, 3), 'Taking a little longer than usual');
    // A short phase gets 3 s of grace before it says so.
    assert.equal(timeLeftText(4, 1.5, 20), 'About 20 seconds left');
    assert.equal(timeLeftText(5, 1.5, 20), 'Taking a little longer than usual');
});

test('timings come from the server, with defaults for what is missing', () => {
    assert.deepEqual(scanTimings({ find: 16.4, read: 2.9, detect: 17 }),
        { wait: 1.5, reoutline: 4, find: 16.4, read: 2.9, detect: 17 });
    assert.deepEqual(scanTimings(null), { wait: 1.5, reoutline: 4, find: 20, read: 5, detect: 20 });
    const odd = scanTimings({ find: 'soon', read: -2, detect: 99999 });
    assert.equal(odd.find, 20);
    assert.equal(odd.read, 5);
    assert.equal(odd.detect, 600);
});

test('what a page still needs depends on what was already done to it', () => {
    const timings = scanTimings({ find: 16, read: 3, detect: 18 });
    assert.equal(pageSeconds('full', timings), 19);
    assert.equal(pageSeconds('read', timings), 3);
    assert.equal(pageSeconds('reoutline', timings), 7);
    assert.equal(pageSeconds('detect', timings), 18);
});
