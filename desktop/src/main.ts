import { invoke } from '@tauri-apps/api/core';
import { listen } from '@tauri-apps/api/event';
import { openUrl } from '@tauri-apps/plugin-opener';
import './styles.css';
import { trackedSeconds, type ActivitySnapshot } from './activity-tracking';

type KindleBook = {
  title: string;
  author: string | null;
  asin: string | null;
};

type KindleStatusReport = { status: string; message: string | null };
type BrowserExtensionReport = { status: 'connected' | 'receiving' | 'error'; message: string; received_at_unix_ms: number };
type UrlMode = 'hosted' | 'localhost' | 'custom';

type ActivitySlice = {
  application: string;
  process_name: string;
  started_at_ms: number;
  ended_at_ms: number;
  duration_seconds: number;
};

type PendingBatch = {
  app_url: string;
  pairing_key: string;
  client_id: string;
  activities: ReturnType<typeof aggregatePending>;
};

const platformBadge = document.querySelector<HTMLElement>('#platform-badge')!;
const list = document.querySelector<HTMLOListElement>('#activity-list')!;
const appUrl = document.querySelector<HTMLInputElement>('#app-url')!;
const pairingKey = document.querySelector<HTMLInputElement>('#pairing-key')!;
const connectionStatus = document.querySelector<HTMLElement>('#connection-status')!;
const connectionPanel = document.querySelector<HTMLDialogElement>('#connection-panel')!;
const connectionToggle = document.querySelector<HTMLButtonElement>('#connection-toggle')!;
const serverHealth = document.querySelector<HTMLElement>('#server-health')!;
const serverHealthLabel = document.querySelector<HTMLElement>('#server-health-label')!;
const extensionHealth = document.querySelector<HTMLElement>('#extension-health')!;
const extensionHealthLabel = document.querySelector<HTMLElement>('#extension-health-label')!;
const extensionHealthDetail = document.querySelector<HTMLElement>('#extension-health-detail')!;
const kindleStatus = document.querySelector<HTMLElement>('#kindle-status')!;
const clientId = localStorage.getItem('totalLogDesktop.clientId') || crypto.randomUUID().replaceAll('-', '');
const HISTORY_KEY = 'totalLogDesktop.activityHistory';
const PENDING_KEY = 'totalLogDesktop.pendingActivity';
const OUTBOX_KEY = 'totalLogDesktop.activityOutbox';
const LAST_BATCH_KEY = 'totalLogDesktop.lastActivityBatchAt';
const FIVE_MINUTES_MS = 5 * 60 * 1000;
const HOSTED_URL = 'https://captainslog.playground.computer/';
const LOCALHOST_URL = 'http://127.0.0.1:8016/';
let activityHistory = readSlices(HISTORY_KEY);
const savedOutbox = readOutbox();
let pendingActivity: ActivitySlice[] = savedOutbox.pending;
let pendingBatch: PendingBatch | null = savedOutbox.batch;
let previousSnapshot: ActivitySnapshot | null = null;
let activityUploadRunning = false;
let lastBatchAt = Number(localStorage.getItem(LAST_BATCH_KEY)) || Date.now();
let kindleUploadRunning = false;
let kindleResponseTimer: number | null = null;
let serverCheckRunning = false;
let pairingCheckTimer: number | null = null;
let lastExtensionSeenAt = 0;
const extensionDetectionStartedAt = Date.now();

const randomKey = () => `${crypto.randomUUID().replaceAll('-', '')}${crypto.randomUUID().replaceAll('-', '')}`;
function setConnectionHealth(state: 'working' | 'error' | 'waiting' | 'disconnected', detail?: string) {
  const labels = {working: 'Connection working', error: 'Connection not working', waiting: 'Checking connection', disconnected: 'Not connected'};
  serverHealth.dataset.state = state;
  serverHealthLabel.textContent = labels[state];
  localStorage.setItem('totalLogDesktop.connectionHealth', state);
  if (detail) connectionStatus.textContent = detail;
}

function setConnectionPanel(open: boolean) {
  if (open && !connectionPanel.open) connectionPanel.showModal();
  if (!open && connectionPanel.open) connectionPanel.close();
  connectionToggle.setAttribute('aria-expanded', String(open));
}

async function checkServerConnection(showError = true): Promise<boolean> {
  if (serverCheckRunning || localStorage.getItem('totalLogDesktop.syncEnabled') !== 'true') return false;
  serverCheckRunning = true;
  try {
    await configureBrowserBridge();
    await invoke('check_server_connection', {payload: {
      app_url: normalizedBaseUrl().toString(),
      pairing_key: pairingKey.value,
    }});
    setConnectionHealth('working', `Connection verified ${new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})}. Desktop, browser, and Kindle data can be sent.`);
    return true;
  } catch (error) {
    if (showError) setConnectionHealth('error', error instanceof Error ? error.message : String(error));
    return false;
  } finally {
    serverCheckRunning = false;
  }
}

function waitForPairing(attempt = 0) {
  if (pairingCheckTimer !== null) window.clearTimeout(pairingCheckTimer);
  pairingCheckTimer = window.setTimeout(async () => {
    pairingCheckTimer = null;
    if (await checkServerConnection(false)) {
      setConnectionPanel(false);
      return;
    }
    if (attempt < 39) {
      waitForPairing(attempt + 1);
    } else {
      setConnectionHealth('error', 'Pairing was not verified after two minutes. Confirm the website accepted the pairing, then try again.');
    }
  }, attempt === 0 ? 1500 : 3000);
}

function renderExtensionHealth(report?: BrowserExtensionReport) {
  if (report) {
    lastExtensionSeenAt = Number(report.received_at_unix_ms) || Date.now();
    extensionHealth.dataset.state = report.status;
    extensionHealthLabel.textContent = report.status === 'receiving' ? 'Receiving browser data'
      : report.status === 'error' ? 'Browser forwarding needs attention' : 'Browser extension connected';
    extensionHealthDetail.textContent = `${report.message} Last contact ${new Date(lastExtensionSeenAt).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'})}.`;
    return;
  }
  const reference = lastExtensionSeenAt || extensionDetectionStartedAt;
  if (Date.now() - reference > 90_000) {
    extensionHealth.dataset.state = 'missing';
    extensionHealthLabel.textContent = 'Browser extension not detected';
    extensionHealthDetail.textContent = 'The extension may not be installed, Chrome may be closed, or the extension may be disabled. Open Chrome and use “Check desktop app” in the extension settings.';
  }
}

function selectedUrlMode(): UrlMode {
  return (document.querySelector<HTMLInputElement>('input[name="url-mode"]:checked')?.value || 'hosted') as UrlMode;
}

function inferUrlMode(value: string): UrlMode {
  if (value === HOSTED_URL) return 'hosted';
  if (value === LOCALHOST_URL) return 'localhost';
  return 'custom';
}

function applyUrlMode(mode: UrlMode, changedByUser = false) {
  const previous = appUrl.value;
  const previousMode = (localStorage.getItem('totalLogDesktop.urlMode') || inferUrlMode(previous)) as UrlMode;
  if (previousMode === 'custom' && previous && previous !== 'https://') {
    localStorage.setItem('totalLogDesktop.customAppUrl', previous);
  }
  const customUrl = localStorage.getItem('totalLogDesktop.customAppUrl') || (inferUrlMode(previous) === 'custom' ? previous : 'https://');
  document.querySelector<HTMLInputElement>(`input[name="url-mode"][value="${mode}"]`)!.checked = true;
  localStorage.setItem('totalLogDesktop.urlMode', mode);
  appUrl.readOnly = mode !== 'custom';
  appUrl.value = mode === 'hosted' ? HOSTED_URL : mode === 'localhost' ? LOCALHOST_URL : customUrl;
  if (mode !== 'custom' || appUrl.value !== 'https://') localStorage.setItem('totalLogDesktop.appUrl', appUrl.value);
  if (changedByUser && previous !== appUrl.value) {
    localStorage.setItem('totalLogDesktop.syncEnabled', 'false');
    setConnectionHealth('disconnected', 'Server changed. Pair this desktop app with the selected server before syncing.');
  }
}

function readSlices(key: string): ActivitySlice[] {
  try {
    const value = JSON.parse(localStorage.getItem(key) || '[]');
    return Array.isArray(value) ? value.filter(item => item && Number.isFinite(item.started_at_ms) && Number.isFinite(item.duration_seconds)) : [];
  } catch (_error) {
    return [];
  }
}

function readOutbox(): {pending: ActivitySlice[]; batch: PendingBatch | null} {
  const saved = localStorage.getItem(OUTBOX_KEY);
  // Fail visibly on damaged outbox data instead of silently dropping queued time.
  return saved ? JSON.parse(saved) : {pending: readSlices(PENDING_KEY), batch: null};
}

function hourKey(timestamp: number): string {
  const date = new Date(timestamp);
  return `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}-${date.getHours()}`;
}

function appendSlice(target: ActivitySlice[], slice: ActivitySlice) {
  const last = target.at(-1);
  if (last && last.application === slice.application && last.process_name === slice.process_name
    && hourKey(last.started_at_ms) === hourKey(slice.started_at_ms) && slice.started_at_ms - last.ended_at_ms <= 10_000) {
    last.ended_at_ms = slice.ended_at_ms;
    last.duration_seconds += slice.duration_seconds;
  } else {
    target.push({...slice});
  }
}

function saveActivity() {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  activityHistory = activityHistory.filter(item => item.ended_at_ms >= today.getTime());
  const oldestPending = Date.now() - (7 * 24 * 60 * 60 * 1000);
  pendingActivity = pendingActivity.filter(item => item.ended_at_ms >= oldestPending);
  localStorage.setItem(HISTORY_KEY, JSON.stringify(activityHistory));
  // A single write keeps the immutable upload and new observations crash-consistent.
  localStorage.setItem(OUTBOX_KEY, JSON.stringify({pending: pendingActivity, batch: pendingBatch}));
  localStorage.removeItem(PENDING_KEY);
}

function collectActivity(snapshot: ActivitySnapshot) {
  const elapsed = trackedSeconds(previousSnapshot, snapshot);
  if (previousSnapshot && elapsed > 0) {
    const slice: ActivitySlice = {
      application: previousSnapshot.application,
      process_name: previousSnapshot.executable.split(/[\\/]/).at(-1) || previousSnapshot.application,
      started_at_ms: previousSnapshot.captured_at_unix_ms,
      ended_at_ms: snapshot.captured_at_unix_ms,
      duration_seconds: elapsed,
    };
    appendSlice(activityHistory, slice);
    appendSlice(pendingActivity, slice);
    saveActivity();
  }
  previousSnapshot = snapshot;
  renderActivityList();
  if (Date.now() - lastBatchAt >= FIVE_MINUTES_MS) uploadActivityBatch();
}

function overlappingSeconds(slice: ActivitySlice, start: number, end: number): number {
  const overlap = Math.max(0, Math.min(slice.ended_at_ms, end) - Math.max(slice.started_at_ms, start));
  const span = Math.max(1, slice.ended_at_ms - slice.started_at_ms);
  return slice.duration_seconds * overlap / span;
}

function minutesLabel(value: number): string {
  if (value < 1) return '—';
  const minutes = value / 60;
  return `${minutes < 10 ? minutes.toFixed(1) : Math.round(minutes)}m`;
}

function renderActivityList() {
  const now = Date.now();
  const today = new Date(now);
  today.setHours(0, 0, 0, 0);
  const totals = new Map<string, {application: string; process: string; five: number; hour: number; today: number}>();
  for (const slice of activityHistory) {
    const key = `${slice.application}\n${slice.process_name}`;
    const row = totals.get(key) || {application: slice.application, process: slice.process_name, five: 0, hour: 0, today: 0};
    row.five += overlappingSeconds(slice, now - FIVE_MINUTES_MS, now);
    row.hour += overlappingSeconds(slice, now - 60 * 60 * 1000, now);
    row.today += overlappingSeconds(slice, today.getTime(), now);
    totals.set(key, row);
  }
  const rows = [...totals.values()].filter(row => row.today >= 1).sort((left, right) => right.today - left.today);
  if (!rows.length) {
    list.innerHTML = '<li class="empty">No activity collected yet.</li>';
    return;
  }
  list.replaceChildren(...rows.map(item => {
    const row = document.createElement('li');
    row.innerHTML = '<div class="app-identity"><strong></strong><small></small></div><span></span><span></span><span></span>';
    row.querySelector('strong')!.textContent = item.application;
    row.querySelector('small')!.textContent = item.process;
    const values = row.querySelectorAll<HTMLElement>(':scope > span');
    values[0].textContent = minutesLabel(item.five);
    values[1].textContent = minutesLabel(item.hour);
    values[2].textContent = minutesLabel(item.today);
    return row;
  }));
}

function aggregatePending(slices: ActivitySlice[]) {
  const totals = new Map<string, ActivitySlice>();
  for (const slice of slices) {
    const key = `${slice.application}\n${slice.process_name}\n${hourKey(slice.started_at_ms)}`;
    const current = totals.get(key);
    if (current) {
      current.started_at_ms = Math.min(current.started_at_ms, slice.started_at_ms);
      current.ended_at_ms = Math.max(current.ended_at_ms, slice.ended_at_ms);
      current.duration_seconds += slice.duration_seconds;
    } else {
      totals.set(key, {...slice});
    }
  }
  return [...totals.values()].map(item => ({
    application: item.application,
    process_name: item.process_name,
    started_at: new Date(item.started_at_ms).toISOString(),
    ended_at: new Date(item.ended_at_ms).toISOString(),
    duration_seconds: Math.min(3600, Math.round(item.duration_seconds)),
  }));
}

async function uploadActivityBatch() {
  if (activityUploadRunning || localStorage.getItem('totalLogDesktop.syncEnabled') !== 'true') return;
  lastBatchAt = Date.now();
  localStorage.setItem(LAST_BATCH_KEY, String(lastBatchAt));
  if (!pendingBatch && !pendingActivity.length) return;
  activityUploadRunning = true;
  try {
    if (!pendingBatch) {
      pendingBatch = {
        app_url: normalizedBaseUrl().toString(), pairing_key: pairingKey.value, client_id: clientId,
        activities: aggregatePending(pendingActivity),
      };
      pendingActivity = [];
    }
    // Persist before sending. Identical payloads let the server recognize existing rows.
    saveActivity();
    if (pendingBatch.app_url !== normalizedBaseUrl().toString() || pendingBatch.pairing_key !== pairingKey.value) {
      throw new Error('A desktop upload is pending for the previous pairing. Restore that server and pairing to finish syncing.');
    }
    const {activities} = pendingBatch;
    await invoke('send_activity_batch', {payload: pendingBatch});
    pendingBatch = null;
    saveActivity();
    const totalSeconds = activities.reduce((sum, item) => sum + item.duration_seconds, 0);
    setConnectionHealth('working', `Connected · sent ${minutesLabel(totalSeconds)} at ${new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})}`);
  } catch (error) {
    setConnectionHealth('error', error instanceof Error ? error.message : String(error));
  } finally {
    activityUploadRunning = false;
  }
}

function normalizedBaseUrl(): URL {
  const url = new URL(appUrl.value.trim());
  if (!['http:', 'https:'].includes(url.protocol)) throw new Error('Use an http:// or https:// URL.');
  url.hash = '';
  url.search = '';
  if (!url.pathname.endsWith('/')) url.pathname += '/';
  return url;
}

function saveSettings() {
  localStorage.setItem('totalLogDesktop.appUrl', normalizedBaseUrl().toString());
  localStorage.setItem('totalLogDesktop.pairingKey', pairingKey.value);
  configureBrowserBridge();
}

async function configureBrowserBridge() {
  try {
    await invoke('configure_browser_bridge', {payload: {
      app_url: normalizedBaseUrl().toString(),
      pairing_key: pairingKey.value,
    }});
  } catch (error) {
    console.warn('Could not configure the local browser bridge.', error);
  }
}

async function uploadKindleBook(book: KindleBook) {
  if (kindleResponseTimer !== null) window.clearTimeout(kindleResponseTimer);
  kindleResponseTimer = null;
  if (kindleUploadRunning) return;
  if (localStorage.getItem('totalLogDesktop.syncEnabled') !== 'true') {
    kindleStatus.textContent = 'Pair the desktop app before logging a Kindle book.';
    return;
  }
  kindleUploadRunning = true;
  kindleStatus.textContent = `Logging ${book.title}…`;
  try {
    await invoke('send_kindle_book', {payload: {
      app_url: normalizedBaseUrl().toString(), pairing_key: pairingKey.value, client_id: clientId,
      book, observed_at: new Date().toISOString(),
    }});
    localStorage.setItem('totalLogDesktop.kindleLastTitle', book.title);
    localStorage.setItem('totalLogDesktop.kindleLastSyncAt', new Date().toISOString());
    kindleStatus.textContent = `${book.title} · logged ${new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})}`;
  } catch (error) {
    kindleStatus.textContent = error instanceof Error ? error.message : String(error);
  } finally {
    kindleUploadRunning = false;
  }
}

async function syncKindle() {
  try {
    kindleStatus.textContent = 'Opening your Kindle library for a manual book log…';
    await invoke('sync_kindle');
    if (kindleResponseTimer !== null) window.clearTimeout(kindleResponseTimer);
    kindleResponseTimer = window.setTimeout(() => {
      kindleResponseTimer = null;
      kindleStatus.textContent = 'The manual Kindle capture did not answer. Confirm the library is signed in and shows at least one book, then try again.';
    }, 25_000);
  } catch (error) {
    kindleStatus.textContent = error instanceof Error ? error.message : String(error);
  }
}

function render(snapshot: ActivitySnapshot) {
  platformBadge.textContent = snapshot.supported ? (snapshot.idle_seconds >= 180 ? 'Away' : 'Tracking') : 'Windows only';
  platformBadge.classList.toggle('away', snapshot.idle_seconds >= 180);

  collectActivity(snapshot);
}

const savedAppUrl = localStorage.getItem('totalLogDesktop.appUrl') || HOSTED_URL;
appUrl.value = savedAppUrl;
const savedUrlMode = localStorage.getItem('totalLogDesktop.urlMode');
applyUrlMode(['hosted', 'localhost', 'custom'].includes(savedUrlMode || '') ? savedUrlMode as UrlMode : inferUrlMode(savedAppUrl));
pairingKey.value = localStorage.getItem('totalLogDesktop.pairingKey') || randomKey();
localStorage.setItem('totalLogDesktop.clientId', clientId);
saveSettings();
const savedConnectionHealth = localStorage.getItem('totalLogDesktop.connectionHealth');
setConnectionHealth(localStorage.getItem('totalLogDesktop.syncEnabled') === 'true'
  ? (['working', 'error', 'waiting'].includes(savedConnectionHealth || '') ? savedConnectionHealth as 'working' | 'error' | 'waiting' : 'waiting')
  : 'disconnected');

const previousKindleTitle = localStorage.getItem('totalLogDesktop.kindleLastTitle');
const previousKindleSync = localStorage.getItem('totalLogDesktop.kindleLastSyncAt');
if (previousKindleTitle && previousKindleSync) {
  kindleStatus.textContent = `${previousKindleTitle} · last logged ${new Date(previousKindleSync).toLocaleString()}`;
}

document.querySelector('#reveal-key')?.addEventListener('click', event => {
  pairingKey.type = pairingKey.type === 'password' ? 'text' : 'password';
  (event.currentTarget as HTMLButtonElement).textContent = pairingKey.type === 'password' ? 'Show' : 'Hide';
});

document.querySelector('#new-key-button')?.addEventListener('click', () => {
  pairingKey.value = randomKey();
  localStorage.setItem('totalLogDesktop.syncEnabled', 'false');
  saveSettings();
  setConnectionHealth('disconnected', 'A new local key was generated. Pair it before syncing.');
});

document.querySelector('#pair-button')?.addEventListener('click', async () => {
  try {
    saveSettings();
    if (!/^[A-Za-z0-9_-]{32,128}$/.test(pairingKey.value)) throw new Error('The pairing key must be 32–128 URL-safe characters.');
    const url = new URL(`sensors/desktop/pair/${encodeURIComponent(pairingKey.value)}`, normalizedBaseUrl());
    await openUrl(url.toString());
    localStorage.setItem('totalLogDesktop.syncEnabled', 'true');
    lastBatchAt = Date.now();
    localStorage.setItem(LAST_BATCH_KEY, String(lastBatchAt));
    setConnectionHealth('waiting', 'Finish signing in and approve pairing in your browser. This app will verify it automatically.');
    waitForPairing();
  } catch (error) {
    setConnectionHealth('error', error instanceof Error ? error.message : String(error));
  }
});

appUrl.addEventListener('change', () => {
  try {
    saveSettings();
    if (selectedUrlMode() === 'custom') localStorage.setItem('totalLogDesktop.customAppUrl', normalizedBaseUrl().toString());
    localStorage.setItem('totalLogDesktop.syncEnabled', 'false');
    setConnectionHealth('disconnected', 'Custom server saved. Pair this desktop app before syncing.');
  } catch (error) {
    setConnectionHealth('error', error instanceof Error ? error.message : String(error));
  }
});
pairingKey.addEventListener('change', saveSettings);

connectionToggle.addEventListener('click', () => setConnectionPanel(!connectionPanel.open));
document.querySelector('#connection-close')?.addEventListener('click', () => setConnectionPanel(false));
connectionPanel.addEventListener('close', () => connectionToggle.setAttribute('aria-expanded', 'false'));
document.querySelectorAll<HTMLInputElement>('input[name="url-mode"]').forEach(radio => radio.addEventListener('change', () => {
  if (radio.checked) applyUrlMode(radio.value as UrlMode, true);
}));

document.querySelector('#kindle-connect')?.addEventListener('click', async () => {
  try {
    kindleStatus.textContent = 'Sign in to Amazon in the Kindle window. Total Log never receives your password or cookies.';
    await invoke('open_kindle');
  } catch (error) {
    kindleStatus.textContent = error instanceof Error ? error.message : String(error);
  }
});

document.querySelector('#kindle-sync')?.addEventListener('click', async () => {
  await syncKindle();
});

async function bootstrap() {
  await configureBrowserBridge();
  await Promise.all([
    listen<ActivitySnapshot>('activity-update', event => render(event.payload)),
    listen<KindleBook>('kindle-book', event => uploadKindleBook(event.payload)),
    listen<BrowserExtensionReport>('browser-extension-status', event => renderExtensionHealth(event.payload)),
    listen<KindleStatusReport>('kindle-status', event => {
      if (event.payload.status !== 'syncing') {
        if (kindleResponseTimer !== null) window.clearTimeout(kindleResponseTimer);
        kindleResponseTimer = null;
      }
      const labels: Record<string, string> = {
        syncing: 'Waiting for the first book in your Kindle library…',
        expired: 'Kindle sign-in expired. Open Kindle and sign in again.',
      };
      kindleStatus.textContent = event.payload.message || labels[event.payload.status] || event.payload.status;
    }),
  ]);
  render(await invoke<ActivitySnapshot>('current_activity'));
  if (localStorage.getItem('totalLogDesktop.syncEnabled') === 'true') await checkServerConnection();
}

window.setInterval(() => checkServerConnection(), 60 * 1000);
window.setInterval(() => {
  renderActivityList();
  renderExtensionHealth();
  if (Date.now() - lastBatchAt >= FIVE_MINUTES_MS) uploadActivityBatch();
}, 10_000);

bootstrap().catch(error => {
  platformBadge.textContent = 'Error';
  setConnectionHealth('error', error instanceof Error ? error.message : String(error));
});
