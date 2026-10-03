import {test} from 'node:test';
import assert from 'node:assert/strict';
import {clampTime, clockValue, snapTime, stripOffset, minutesForPixels} from '../resources/js/time-scroller.mjs';

test('all five-minute selections stay under the fixed center cursor', () => {
    for (let minute = 0; minute <= 1440; minute += 5) {
        // Strip starts at the viewport center, independent of the viewport width.
        for (const viewportWidth of [240, 400, 625]) {
            const stripWidth = 64 * 1600 / 90;
            const selectedX = viewportWidth / 2 + (stripOffset(minute) / 100 + minute / 1440) * stripWidth;
            assert.ok(Math.abs(selectedX - viewportWidth / 2) < 1e-10);
        }
        assert.equal(snapTime(minute), minute);
    }
});
test('magnetic snapping rounds both directions and clamps overscroll', () => {
    assert.equal(snapTime(722.4), 720);
    assert.equal(snapTime(722.6), 725);
    assert.equal(snapTime(-90), 0);
    assert.equal(snapTime(1510), 1440);
    assert.equal(clampTime(723), 723); // Preserve existing precise timestamps on open.
});
test('endpoints expose half a viewport of empty space', () => {
    assert.equal(Math.abs(stripOffset(0)), 0);
    assert.equal(stripOffset(720), -50);
    assert.equal(stripOffset(1440), -100);
    assert.equal(clockValue(0), '00:00');
    assert.equal(clockValue(1440), '24:00');
});

test('drag distance follows the artwork width without stretching it to the viewport', () => {
    const width = 64 * 1600 / 90;
    assert.equal(minutesForPixels(width / 2, width), 720);
    assert.equal(minutesForPixels(-width / 24, width), -60);
    assert.equal(minutesForPixels(20, 0), 0);
});
