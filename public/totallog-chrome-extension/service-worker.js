const DESKTOP_BRIDGE_URL = 'http://127.0.0.1:32145/';
const HEARTBEAT_ALARM = 'totallog-browsing-heartbeat';
const MOBILE_HISTORY_ALARM = 'totallog-mobile-history';
const DEBUG_MESSAGE_LIMIT = 1000;
let debugWriteQueue = Promise.resolve();
let browsingRequestRunning = false;

function debugLog(level, event, details = {}) {
  const entry = {at: new Date().toISOString(), level, event, details};
  const detailText = JSON.stringify(details);
  console[level === 'error' ? 'warn' : 'log'](`[Total Log] ${event}${detailText === '{}' ? '' : ` ${detailText}`}`);
  debugWriteQueue = debugWriteQueue.then(async () => {
    const stored = await chrome.storage.local.get(['debugMessages']);
    const messages = Array.isArray(stored.debugMessages) ? stored.debugMessages : [];
    messages.push(entry);
    await chrome.storage.local.set({debugMessages: messages.slice(-DEBUG_MESSAGE_LIMIT)});
  }).catch(error => {
    console.warn(`[Total Log] Could not store debug message: ${error.message || String(error)}`);
  });
  return debugWriteQueue;
}

async function settings() {
  const keys = ['clientId', 'connectionStatus', 'lastSentAt', 'lastDomain', 'lastLogDate', 'lastError', 'lastDesktopCheckAt', 'lastMobileHistorySyncAt', 'lastMobileHistoryImportCount', 'lastMobileHistoryRejectedCount', 'mobileHistoryLastError'];
  const saved = await chrome.storage.local.get(keys);
  const updates = {};
  if (!saved.clientId) updates.clientId = crypto.randomUUID().replaceAll('-', '');
  if (Object.keys(updates).length) await chrome.storage.local.set(updates);
  return {...saved, ...updates};
}

async function setConnectionStatus(status, error = null) {
  const connected = status === 'connected';
  await chrome.storage.local.set({
    connectionStatus: status,
    lastError: error,
    lastDesktopCheckAt: new Date().toISOString(),
  });
  await chrome.action.setBadgeBackgroundColor({color: connected ? '#10b981' : '#ef4444'});
  await chrome.action.setBadgeText({text: connected ? '' : '!'});
  await chrome.action.setTitle({title: connected
    ? 'Total Log · Desktop app connected'
    : `Total Log · ${error || 'Desktop app is not running'}`});
}

async function checkDesktopApp() {
  try {
    const response = await fetch(new URL('health', DESKTOP_BRIDGE_URL), {
      cache: 'no-store',
      headers: {'X-TotalLog-Extension': '1'},
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(body.message || `Desktop bridge returned ${response.status}.`);
    }
    if (body.service !== 'total-log-desktop' || body.protocol !== 1) {
      throw new Error('A service is listening locally, but it is not a compatible Total Log Desktop bridge.');
    }
    if (!body.configured) throw new Error('Desktop app is running but is not paired with Total Log.');
    await setConnectionStatus('connected');
    return true;
  } catch (error) {
    const rawMessage = error.message || String(error);
    const unreachable = error instanceof TypeError || /failed to fetch|networkerror|could not connect/i.test(rawMessage);
    const message = unreachable
      ? 'Desktop app is not running. Open Total Log Desktop to resume browser tracking.'
      : rawMessage;
    await setConnectionStatus('desktop-missing', message);
    return false;
  }
}

async function activeWebTab() {
  if (await chrome.idle.queryState(180) !== 'active') return null;
  const window = await chrome.windows.getLastFocused();
  if (!window.focused || window.state === 'minimized') return null;
  const [tab] = await chrome.tabs.query({active: true, lastFocusedWindow: true});
  if (!tab?.url || tab.discarded || tab.windowId !== window.id) return null;
  const url = new URL(tab.url);
  return ['http:', 'https:'].includes(url.protocol) ? tab : null;
}

async function sendActiveBrowsing() {
  if (browsingRequestRunning) return;
  browsingRequestRunning = true;
  try {
    const config = await settings();
    if (!await checkDesktopApp()) return;
    const tab = await activeWebTab();
    if (!tab) return;
    const browsingUrl = new URL(tab.url);
    if (browsingUrl.origin === new URL(DESKTOP_BRIDGE_URL).origin) return;
    const response = await fetch(new URL('v1/browser/activity', DESKTOP_BRIDGE_URL), {
      method: 'POST',
      headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-TotalLog-Extension': '1'},
      body: JSON.stringify({url: `${browsingUrl.protocol}//${browsingUrl.hostname}`, observed_at: new Date().toISOString(), client_id: config.clientId})
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || `Total Log returned ${response.status}.`);
    if (body.skipped) return;
    await chrome.storage.local.set({lastSentAt: new Date().toISOString(), lastDomain: body.domain || browsingUrl.hostname, lastLogDate: body.log_date || null});
    await setConnectionStatus('connected');
  } catch (error) {
    await setConnectionStatus('error', error.message || String(error));
  } finally {
    browsingRequestRunning = false;
  }
}

async function sha256(value) {
  const bytes = new TextEncoder().encode(value);
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, '0')).join('');
}

function isTrackableHostname(hostname) {
  const host = String(hostname || '').replace(/^\[|\]$/g, '');
  if (!host || host.includes(':')) return false;
  if (/^\d{1,3}(?:\.\d{1,3}){3}$/.test(host)) return false;
  return true;
}

async function remoteHistoryVisits(startTime, endTime, appOrigin) {
  const pageSize = 10000;
  const seenUrls = new Set();
  const visits = [];
  const counts = {pages: 0, visits: 0, local: 0, remote: 0, unknown: 0};
  let searchEndTime = endTime;
  while (searchEndTime >= startTime) {
    const pages = await chrome.history.search({text: '', startTime, endTime: searchEndTime, maxResults: pageSize});
    await debugLog('info', 'history-page-read', {startTime: new Date(startTime).toISOString(), endTime: new Date(searchEndTime).toISOString(), pages: pages.length});
    if (!pages.length) break;
    const unseenPages = pages.filter(page => page.url && !seenUrls.has(page.url));
    unseenPages.forEach(page => seenUrls.add(page.url));
    counts.pages += unseenPages.length;
    for (let offset = 0; offset < unseenPages.length; offset += 25) {
      const group = unseenPages.slice(offset, offset + 25);
      const details = await Promise.all(group.map(async page => {
        let url;
        try {
          url = new URL(page.url);
        } catch (_error) {
          return [];
        }
        if (!['http:', 'https:'].includes(url.protocol) || url.origin === appOrigin || !isTrackableHostname(url.hostname)) return [];
        const pageVisits = await chrome.history.getVisits({url: page.url});
        counts.visits += pageVisits.length;
        counts.local += pageVisits.filter(visit => visit.isLocal === true).length;
        counts.remote += pageVisits.filter(visit => visit.isLocal === false).length;
        counts.unknown += pageVisits.filter(visit => typeof visit.isLocal !== 'boolean').length;
        return Promise.all(pageVisits
          .filter(visit => visit.isLocal === false && visit.visitTime >= startTime && visit.visitTime <= endTime)
          .map(async visit => ({
            url: `${url.protocol}//${url.hostname}`,
            visited_at: new Date(visit.visitTime).toISOString(),
            visit_key: await sha256(`${page.url}\n${visit.visitTime}\n${visit.visitId}`)
          })));
      }));
      visits.push(...details.flat());
    }
    if (pages.length < pageSize || !unseenPages.length) break;
    const oldestVisitTime = Math.min(...pages.map(page => page.lastVisitTime).filter(Number.isFinite));
    if (!Number.isFinite(oldestVisitTime) || oldestVisitTime >= searchEndTime) break;
    searchEndTime = oldestVisitTime - 1;
  }
  await debugLog('info', 'history-scan-complete', {...counts, remoteVisitsSelected: visits.length});
  return visits.sort((left, right) => right.visited_at.localeCompare(left.visited_at));
}

async function syncMobileHistory(fullScan = false) {
  const running = await chrome.storage.session.get(['mobileHistorySyncRunning']);
  if (running.mobileHistorySyncRunning) {
    await debugLog('info', 'mobile-sync-skipped', {reason: 'another scan is already running', fullScan});
    return;
  }
  await chrome.storage.session.set({mobileHistorySyncRunning: true});
  await debugLog('info', 'mobile-sync-started', {fullScan});
  try {
    const config = await settings();
    if (!await checkDesktopApp()) throw new Error('Desktop app is not running. Open Total Log Desktop before syncing mobile history.');
    const endTime = Date.now();
    const initialStart = endTime - (90 * 24 * 60 * 60 * 1000);
    const startTime = fullScan
      ? 0
      : (config.lastMobileHistorySyncAt
        ? Math.max(initialStart, Date.parse(config.lastMobileHistorySyncAt) - (24 * 60 * 60 * 1000))
        : initialStart);
    await debugLog('info', 'mobile-sync-window', {fullScan, start: new Date(startTime).toISOString(), end: new Date(endTime).toISOString()});
    const visits = await remoteHistoryVisits(startTime, endTime, new URL(DESKTOP_BRIDGE_URL).origin);
    let imported = 0;
    let rejected = 0;
    const batches = visits.length ? Array.from({length: Math.ceil(visits.length / 500)}, (_value, index) => visits.slice(index * 500, (index + 1) * 500)) : [[]];
    for (const [index, batch] of batches.entries()) {
      await debugLog('info', 'mobile-api-request', {batch: index + 1, batches: batches.length, visits: batch.length});
      const response = await fetch(new URL('v1/browser/mobile-history', DESKTOP_BRIDGE_URL), {
        method: 'POST',
        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-TotalLog-Extension': '1'},
        body: JSON.stringify({visits: batch})
      });
      const body = await response.json().catch(() => ({}));
      await debugLog(response.ok ? 'info' : 'error', 'mobile-api-response', {batch: index + 1, status: response.status, ok: response.ok, imported: body.imported, duplicates: body.duplicates, rejected: body.rejected, message: body.message});
      if (!response.ok) throw new Error(body.message || `Mobile history import returned ${response.status}.`);
      imported += Number(body.imported || 0);
      rejected += Number(body.rejected || 0);
    }
    await chrome.storage.local.set({
      lastMobileHistorySyncAt: new Date(endTime).toISOString(),
      lastMobileHistoryImportCount: imported,
      lastMobileHistoryRejectedCount: rejected,
      mobileHistoryLastError: null,
      connectionStatus: 'connected',
      lastError: null,
      lastDesktopCheckAt: new Date().toISOString()
    });
    await debugLog('info', 'mobile-sync-complete', {fullScan, visitsFound: visits.length, imported, rejected});
  } catch (error) {
    await chrome.storage.local.set({mobileHistoryLastError: error.message || String(error)});
    await debugLog('error', 'mobile-sync-failed', {fullScan, message: error.message || String(error), stack: error.stack || null});
  } finally {
    await chrome.storage.session.remove(['mobileHistorySyncRunning']);
  }
}

async function installAlarms() {
  await chrome.alarms.create(HEARTBEAT_ALARM, {periodInMinutes: 1});
  await chrome.alarms.create(MOBILE_HISTORY_ALARM, {periodInMinutes: 5});
}

chrome.runtime.onInstalled.addListener(async details => {
  await settings();
  await installAlarms();
  if (details.reason === 'install') await chrome.runtime.openOptionsPage();
});
chrome.runtime.onStartup.addListener(async () => {
  await settings();
  await installAlarms();
  await sendActiveBrowsing();
  await syncMobileHistory();
});
chrome.alarms.onAlarm.addListener(alarm => {
  if (alarm.name === HEARTBEAT_ALARM) sendActiveBrowsing();
  if (alarm.name === MOBILE_HISTORY_ALARM) syncMobileHistory();
});
chrome.tabs.onActivated.addListener(() => sendActiveBrowsing());
chrome.tabs.onUpdated.addListener((_tabId, changeInfo, tab) => {
  if (tab.active && (changeInfo.url || changeInfo.status === 'complete')) sendActiveBrowsing();
});
chrome.windows.onFocusChanged.addListener(windowId => {
  if (windowId !== chrome.windows.WINDOW_ID_NONE) sendActiveBrowsing();
});
chrome.idle.onStateChanged.addListener(state => {
  if (state === 'active') sendActiveBrowsing();
});
chrome.action.onClicked.addListener(() => chrome.runtime.openOptionsPage());
chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  debugLog('info', 'runtime-message', {type: message?.type || null, sender: _sender?.url || _sender?.id || 'unknown'});
  let operation;
  if (message?.type === 'check-desktop') operation = checkDesktopApp();
  else if (message?.type === 'send-now') operation = sendActiveBrowsing();
  else if (message?.type === 'mobile-history-sync-now') operation = syncMobileHistory();
  else if (message?.type === 'mobile-history-sync-past') operation = syncMobileHistory(true);
  else return false;
  operation.then(result => sendResponse(result || {ok: true})).catch(error => sendResponse({ok: false, error: error.message}));
  return true;
});

settings().then(async () => {
  await installAlarms();
  await checkDesktopApp();
});
