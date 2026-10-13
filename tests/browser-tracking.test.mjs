import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../public/totallog-chrome-extension/service-worker.js', import.meta.url), 'utf8');
function worker({idle = 'active', focused = true, state = 'normal', discarded = false, skipped = false} = {}) {
  const sent = [];
  const saved = {clientId: 'test'};
  const event = () => ({addListener() {}});
  const storage = {get: async () => saved, set: async values => Object.assign(saved, values), remove: async () => {}};
  const context = vm.createContext({URL, Date, TextEncoder, crypto, console: {log() {}, warn() {}}, chrome: {
    storage: {local: storage, session: storage},
    action: {setBadgeBackgroundColor: async () => {}, setBadgeText: async () => {}, setTitle: async () => {}, onClicked: event()},
    idle: {queryState: async () => idle, onStateChanged: event()},
    windows: {getLastFocused: async () => ({id: 1, focused, state}), onFocusChanged: event()},
    tabs: {query: async () => [{url: 'https://drive.google.com/private', windowId: 1, discarded}], onActivated: event(), onUpdated: event()},
    alarms: {create: async () => {}, onAlarm: event()},
    runtime: {onInstalled: event(), onStartup: event(), onMessage: event()},
  }, fetch: async (url, options) => {
    if (url.pathname === '/health') return {ok: true, json: async () => ({service: 'total-log-desktop', protocol: 1, configured: true})};
    sent.push(JSON.parse(options.body));
    return {ok: true, json: async () => skipped ? {skipped: true} : {domain: 'drive.google.com'}};
  }});
  vm.runInContext(source, context);
  return {sent, saved, send: () => vm.runInContext('sendActiveBrowsing()', context)};
}

for (const options of [{idle: 'idle'}, {idle: 'locked'}, {focused: false}, {state: 'minimized'}, {discarded: true}]) {
  test(`does not report inactive browser: ${JSON.stringify(options)}`, async () => {
    const w = worker(options); await w.send(); assert.equal(w.sent.length, 0);
  });
}
test('reports only the domain of a focused active browser and coalesces concurrent events', async () => {
  const w = worker(); await Promise.all([w.send(), w.send(), w.send()]);
  assert.equal(w.sent.length, 1); assert.equal(w.sent[0].url, 'https://drive.google.com');
  assert.equal(w.saved.lastDomain, 'drive.google.com');
});
test('a bridge idle skip does not become a successful browsing observation', async () => {
  const w = worker({skipped: true}); await w.send(); assert.equal(w.saved.lastSentAt, undefined);
});
