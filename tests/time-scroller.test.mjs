import {test} from 'node:test';
import assert from 'node:assert/strict';
import {clampTime, clockValue, snapTime, stripOffset} from '../resources/js/time-scroller.mjs';

test('all five-minute selections stay under the fixed center cursor', () => {
    for (let minute = 0; minute <= 1440; minute += 5) {
        // Strip is twice the viewport width. Its selected point is always at 50% of viewport.
        assert.ok(Math.abs((stripOffset(minute) / 100 + minute / 1440) * 2 - 0.5) < 1e-10);
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
    assert.equal(stripOffset(0), 25);
    assert.equal(stripOffset(720), -25);
    assert.equal(stripOffset(1440), -75);
    assert.equal(clockValue(0), '00:00');
    assert.equal(clockValue(1440), '24:00');
});
