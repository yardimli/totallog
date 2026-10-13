import assert from 'node:assert/strict';
import test from 'node:test';
import {trackedSeconds} from '../desktop/src/activity-tracking.ts';

const sample = (values = {}) => ({application: 'chrome', executable: 'chrome.exe', window_title: '',
  process_id: 123, idle_seconds: 0, captured_at_unix_ms: 10000, supported: true, ...values});
test('counts observed foreground time', () => assert.equal(trackedSeconds(sample(), sample({captured_at_unix_ms: 15000})), 5));
test('does not charge idle transitions or the return from idle', () => {
  assert.equal(trackedSeconds(sample(), sample({captured_at_unix_ms: 15000, idle_seconds: 180})), 0);
  assert.equal(trackedSeconds(sample({idle_seconds: 1000}), sample({captured_at_unix_ms: 15000})), 0);
});
test('does not charge sleep, missing foreground, unsupported capture, or backward clocks', () => {
  for (const values of [{captured_at_unix_ms: 36000000}, {process_id: 0}, {supported: false}, {captured_at_unix_ms: 1000}]) {
    assert.equal(trackedSeconds(sample(), sample(values)), 0);
  }
});
