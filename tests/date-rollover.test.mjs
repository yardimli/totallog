import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const app = readFileSync(new URL('../resources/js/app.js', import.meta.url), 'utf8');
const source = app.slice(app.indexOf('const rolloverDateStorageKey'), app.indexOf('function scheduleDayReturnReminder'));

function browser(storage = new Map(), storageBlocked = false) {
    let clock = new Date(2026, 9, 25, 23, 59).getTime();
    let confirm;
    let otherModal = false;
    const listeners = new Map();
    const prompts = [];
    const destinations = [];
    const timers = new Map();
    let timerId = 0;
    const listen = (name, callback) => listeners.set(name, [...(listeners.get(name) || []), callback]);
    class Clock extends Date {
        constructor(...args) { super(...(args.length ? args : [clock])); }
        static now() { return clock; }
    }
    const document = {
        hidden: false,
        addEventListener: listen,
        querySelector: selector => selector === '[data-modal-backdrop]' && otherModal ? {} : null,
    };
    const context = vm.createContext({
        Date: Clock, document,
        sessionStorage: {
            getItem: key => { if (storageBlocked) throw new Error('blocked'); return storage.get(key) ?? null; },
            setItem: (key, value) => { if (storageBlocked) throw new Error('blocked'); storage.set(key, value); },
        },
        clearTimeout: id => timers.delete(id),
        window: {
            addEventListener: listen,
            setTimeout: callback => { timers.set(++timerId, callback); return timerId; },
            location: {assign: url => destinations.push(url)},
        },
        modal: options => { prompts.push(options); return new Promise(resolve => { confirm = resolve; }); },
    });
    return {
        document, prompts, destinations, timers,
        start: () => vm.runInContext(source + '\nstartDateRolloverWatch();', context),
        nextDay: () => { clock = new Date(2026, 9, 26, 0, 5).getTime(); },
        fire: name => (listeners.get(name) || []).forEach(callback => callback()),
        confirm: async () => { confirm(true); await new Promise(resolve => setImmediate(resolve)); },
        otherModal: value => { otherModal = value; },
    };
}

test('first interaction detects midnight even if the timer and lifecycle events never ran', async () => {
    const page = browser();
    page.start();
    assert.equal(page.prompts.length, 0);
    page.nextDay();
    page.fire('touchstart');
    page.fire('pointerdown');
    page.fire('keydown');
    assert.equal(page.prompts.length, 1);
    await page.confirm();
    assert.deepEqual(page.destinations, ['/log/today']);
});

test('discarded page remembers yesterday after reloading, then acknowledges the new date', async () => {
    const storage = new Map();
    browser(storage).start();
    const restored = browser(storage);
    restored.nextDay();
    restored.start();
    assert.equal(restored.prompts.length, 1);
    await restored.confirm();
    const today = browser(storage);
    today.nextDay();
    today.start();
    assert.equal(today.prompts.length, 0);
});

test('resume checks wait until visible and do not stack on another modal', () => {
    const page = browser();
    page.start();
    page.nextDay();
    page.document.hidden = true;
    page.fire('resume');
    assert.equal(page.prompts.length, 0);
    page.document.hidden = false;
    page.otherModal(true);
    page.fire('visibilitychange');
    assert.equal(page.prompts.length, 0);
    page.otherModal(false);
    page.fire('keydown');
    page.fire('focus');
    assert.equal(page.prompts.length, 1);
});

test('blocked session storage still permits interaction-based rollover checks', () => {
    const page = browser(new Map(), true);
    page.start();
    page.nextDay();
    page.fire('pointerdown');
    assert.equal(page.prompts.length, 1);
});
