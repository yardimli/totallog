import {test} from 'node:test';
import assert from 'node:assert/strict';
import {clampTime, clockValue, windowStart} from '../resources/js/time-scroller.mjs';

test('every minute has a twelve-hour window contained in the day', () => {
    for (let minute = 0; minute < 1440; minute++) {
        const start = windowStart(minute);
        assert.ok(start >= 0 && start + 720 <= 1440);
        assert.ok(minute >= start && minute <= start + 720);
        if (minute >= 360 && minute <= 1080) assert.equal(minute - start, 360);
    }
});
test('day boundaries retain a full window and never save the following day', () => {
    assert.equal(windowStart(0), 0);
    assert.equal(windowStart(1439), 720);
    assert.equal(clockValue(clampTime(-5)), '00:00');
    assert.equal(clockValue(clampTime(1440)), '23:59');
    assert.equal(clockValue(1440), '24:00');
});
