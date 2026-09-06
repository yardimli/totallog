import './bootstrap';

if (document.querySelector('[data-note-rich-editor]')) import('./notes');

const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
const demoReadOnly = document.body.dataset.demoReadonly === 'true';
const isScreenSaverRunning = () => document.body.dataset.screensaverRunning === 'true';
const cloneTemplate = id => document.getElementById(id)?.content.firstElementChild.cloneNode(true);
const setButtonBusy = (button, busy) => {
    if (!button) return;
    const label = button.querySelector('[data-button-label]');
    button.disabled = busy;
    button.querySelector('[data-button-spinner]')?.classList.toggle('hidden', !busy);
    if (label) {
        button.dataset.idleLabel ||= label.textContent;
        label.textContent = busy ? (button.dataset.busyLabel || 'Working…') : button.dataset.idleLabel;
    }
};
const toast = (message, error = false) => {
    const el = cloneTemplate('toast-template'); if (!el) return;
    if (error) el.className = 'fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-xl bg-rose-600 px-4 py-3 text-sm font-semibold text-white shadow-xl';
    el.textContent = message; document.body.append(el); setTimeout(() => el.remove(), 3500);
};

function modal({title, message = '', options = null, initial = null, confirmText = 'Continue', cancelText = 'Cancel'}) {
    return new Promise(resolve => {
        const backdrop = cloneTemplate('modal-template'); if (!backdrop) { resolve(null); return; }
        const heading = backdrop.querySelector('[data-modal-title]'); heading.textContent = title;
        const copy = backdrop.querySelector('[data-modal-message]'); copy.textContent = message; copy.classList.toggle('hidden', !message);
        let input = null;
        if (options) {
            input = backdrop.querySelector('[data-modal-select]'); input.classList.remove('hidden');
            options.forEach(value => { const option = cloneTemplate('select-option-template'); option.value = value; option.textContent = value; input.append(option); });
        } else if (initial !== null) { input = backdrop.querySelector('[data-modal-textarea]'); input.classList.remove('hidden'); input.value = initial; }
        const cancel = backdrop.querySelector('[data-modal-cancel]');
        if (cancelText === null) cancel.classList.add('hidden'); else cancel.textContent = cancelText;
        const confirm = backdrop.querySelector('[data-modal-confirm]'); confirm.textContent = confirmText;
        const close = value => { backdrop.remove(); resolve(value); };
        cancel.addEventListener('click', () => close(null)); confirm.addEventListener('click', () => close(input ? input.value : true));
        backdrop.addEventListener('click', event => { if (cancelText !== null && event.target === backdrop) close(null); });
        document.body.append(backdrop); (input || confirm).focus();
    });
}

async function ajax(url, options = {}) {
    const response = await fetch(url, {credentials: 'same-origin', ...options, headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json', ...(options.headers || {})}});
    if (isExpiredSessionResponse(response)) {
        showSessionExpired();
        throw new Error('Your session has expired. Sign in again to continue.');
    }
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || 'Something went wrong.');
    return body;
}

const backgroundSyncQueue = new Map();
const pendingEventCreates = new Map();

function renderBackgroundSyncStatus() {
    const label = document.querySelector('[data-sync-status]');
    if (!label) return;
    const entries = [...backgroundSyncQueue.values()];
    label.classList.toggle('hidden', entries.length === 0);
    if (!entries.length) return;
    if (entries.some(entry => entry.state === 'failed')) label.textContent = 'Changes not synced — retrying…';
    else if (entries.some(entry => entry.running)) label.textContent = 'Syncing changes…';
    else label.textContent = 'Changes waiting to sync…';
}

function scheduleSyncRun(key, entry, delay = 1000) {
    clearTimeout(entry.timer);
    entry.timer = window.setTimeout(() => runBackgroundSync(key, entry), delay);
    renderBackgroundSyncStatus();
}

async function runBackgroundSync(key, entry) {
    if (entry.running) { scheduleSyncRun(key, entry, 250); return; }
    const version = entry.version;
    const request = entry.request;
    const onSuccess = entry.onSuccess;
    entry.running = true;
    entry.state = 'syncing';
    entry.timer = null;
    renderBackgroundSyncStatus();
    try {
        const body = await request();
        if (entry.cancelled) return;
        onSuccess?.(body);
        entry.running = false;
        if (entry.version === version) backgroundSyncQueue.delete(key);
        else scheduleSyncRun(key, entry);
    } catch (error) {
        if (entry.cancelled) return;
        entry.running = false;
        entry.state = 'failed';
        entry.error = error;
        scheduleSyncRun(key, entry, 5000);
    }
    renderBackgroundSyncStatus();
}

function queueBackgroundSync(key, request, onSuccess = null) {
    const entry = backgroundSyncQueue.get(key) || {version:0, running:false, timer:null, state:'pending'};
    entry.version += 1;
    entry.request = request;
    entry.onSuccess = onSuccess;
    entry.state = 'pending';
    backgroundSyncQueue.set(key, entry);
    scheduleSyncRun(key, entry);
}

function cancelBackgroundSync(key) {
    const entry = backgroundSyncQueue.get(key);
    if (!entry) return;
    entry.cancelled = true;
    clearTimeout(entry.timer);
    backgroundSyncQueue.delete(key);
    renderBackgroundSyncStatus();
}

window.addEventListener('pagehide', () => {
    backgroundSyncQueue.forEach((entry, key) => {
        clearTimeout(entry.timer);
        runBackgroundSync(key, entry);
    });
});

const dayStateCache = new Map();
const dayStateRequests = new Map();
let activeDayState = null;
const DAY_RETURN_REMINDER_DELAY = 60 * 60 * 1000;
let dayReturnReminderTimer = null;
let dayReturnPromptOpen = false;
const loadedLocalDate = localDateKey();
let dateRolloverTimer = null;
let dateRolloverPromptOpen = false;
const supportsDayStateNavigation = () => Boolean(document.querySelector('#daily-log-page-container'));
const snapshotDayState = () => activeDayState ? structuredClone(activeDayState) : null;
const restoreDayState = state => { if (state) renderDayState(state, {scroll:{x:window.scrollX, y:window.scrollY}}); };

function localDateKey(date = new Date()) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function viewedCalendarDate() {
    return activeDayState?.date || document.querySelector('[data-calendar-focus-date]')?.dataset.calendarFocusDate || null;
}

function scheduleDateRolloverCheck() {
    clearTimeout(dateRolloverTimer);
    const nextMidnight = new Date();
    nextMidnight.setHours(24, 0, 0, 100);
    dateRolloverTimer = window.setTimeout(checkForDateRollover, Math.max(1000, nextMidnight.getTime() - Date.now()));
}

async function checkForDateRollover() {
    if (localDateKey() === loadedLocalDate) { scheduleDateRolloverCheck(); return; }
    if (dateRolloverPromptOpen) return;
    if (document.querySelector('[data-modal-backdrop]')) {
        dateRolloverTimer = window.setTimeout(checkForDateRollover, 1000);
        return;
    }
    dateRolloverPromptOpen = true;
    await modal({
        title: 'A new day has started',
        message: 'Total Log will refresh to the real date.',
        confirmText: 'Refresh now',
        cancelText: null,
    });
    window.location.assign(document.querySelector('meta[name="today-url"]')?.content || '/log/today');
}

function startDateRolloverWatch() {
    scheduleDateRolloverCheck();
    const checkWhenActive = () => { if (!document.hidden) checkForDateRollover(); };
    document.addEventListener('visibilitychange', checkWhenActive);
    window.addEventListener('focus', checkWhenActive);
    window.addEventListener('pageshow', checkWhenActive);
    document.addEventListener('screensaver:stopped', checkWhenActive);
}

function scheduleDayReturnReminder() {
    clearTimeout(dayReturnReminderTimer);
    dayReturnReminderTimer = null;
    if (!viewedCalendarDate()) return;
    dayReturnReminderTimer = window.setTimeout(showDayReturnReminder, DAY_RETURN_REMINDER_DELAY);
}

async function showDayReturnReminder() {
    dayReturnReminderTimer = null;
    if (dateRolloverPromptOpen || localDateKey() !== loadedLocalDate) { checkForDateRollover(); return; }
    const promptedDate = viewedCalendarDate();
    if (!promptedDate) return;
    if (promptedDate === localDateKey()) { scheduleDayReturnReminder(); return; }
    if (dayReturnPromptOpen) return;

    dayReturnPromptOpen = true;
    const goToToday = await modal({
        title: 'Return to today?',
        message: 'You have been viewing another date for an hour. Would you like to stay here or return to today?',
        confirmText: 'Go to today',
        cancelText: 'Stay on this day',
    });
    dayReturnPromptOpen = false;

    if (viewedCalendarDate() !== promptedDate) { scheduleDayReturnReminder(); return; }
    if (!goToToday) { scheduleDayReturnReminder(); return; }

    const todayUrl = activeDayState?.navigation?.today_url || document.querySelector('[data-calendar-today-url]')?.dataset.calendarTodayUrl;
    if (!todayUrl) return;
    if (supportsDayStateNavigation()) {
        navigateToDay(todayUrl).catch(error => { toast(error.message, true); scheduleDayReturnReminder(); });
    } else {
        window.location.href = todayUrl;
    }
}

function dayStateKey(url = window.location.href) {
    const parsed = new URL(url, window.location.href);
    return `${parsed.pathname}${parsed.search}`;
}

function captureCurrentDayState() {
    const source = document.querySelector('#day-log-state');
    if (!source) return null;
    const state = JSON.parse(source.textContent);
    dayStateCache.set(dayStateKey(state.url), state);
    activeDayState = state;
    return state;
}

function element(tag, className = '', text = null) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== null) node.textContent = text;
    return node;
}

function renderEntryIcon(iconData, emoji, className = 'h-7 w-7 shrink-0 rounded-lg object-cover') {
    if (iconData) {
        const image = element('img', className);
        image.src = iconData;
        image.alt = '';
        image.setAttribute('aria-hidden', 'true');
        return image;
    }
    const fallback = element('span', className.includes('mr-2') ? 'mr-2 text-lg' : 'text-lg', emoji || '📝');
    fallback.setAttribute('aria-hidden', 'true');
    return fallback;
}

function renderTaskButton(task, scheduledTime = null, {bubble = false} = {}) {
    const button = element('button', bubble
        ? 'inline-flex shrink-0 items-center rounded-full px-3 py-2 text-left text-sm font-semibold shadow-sm transition hover:brightness-110 disabled:cursor-wait disabled:opacity-50'
        : 'flex min-w-0 flex-1 items-center rounded-xl px-3 py-2.5 text-left text-sm font-semibold shadow-sm transition hover:brightness-110 disabled:cursor-wait disabled:opacity-50');
    button.style.backgroundColor = task.color;
    button.style.color = task.text_color;
    button.dataset.taskEvent = task.event_url;
    if (scheduledTime) button.dataset.scheduledTime = scheduledTime;
    if (bubble) button.dataset.stickyEventBubble = '';
    button.dataset.captureLocation = '';
    button.dataset.name = task.name;
    button.dataset.taskEmoji = task.emoji || '✅';
    button.dataset.taskIcon = task.icon_data || '';
    button.dataset.options = JSON.stringify(task.options || []);
    const color = element('span', 'mr-2 h-3 w-3 shrink-0 rounded-sm border border-current opacity-80'); color.style.backgroundColor = task.color;
    const emoji = renderEntryIcon(task.icon_data, task.emoji, 'mr-2 h-7 w-7 shrink-0 rounded-lg object-cover');
    const name = element('span', 'truncate', task.name);
    const count = element('span', 'ml-2 rounded-full bg-white/20 px-2', String(scheduledTime ? task.slot_count : task.count)); count.dataset.count = '';
    button.append(...(bubble ? [emoji, name, count] : [color, emoji, name, count]));
    return button;
}

function renderTimelineItem(item) {
    if (item.kind === 'gap') return null;
    if (item.kind === 'now') {
        const row = element('button', 'timeline-item flex w-full scroll-mt-24 items-center gap-3 py-1 text-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-4 dark:text-indigo-400 dark:focus:ring-offset-slate-950');
        row.type = 'button'; row.id = 'timeline-now'; row.dataset.currentTime = item.time; row.dataset.composerOpen = ''; row.setAttribute('aria-label', 'Add to the log now');
        row.append(element('span', 'h-px flex-1 bg-current opacity-40'), element('span', 'rounded-full bg-indigo-600 px-3 py-1 text-xs font-bold text-white', `Now · ${formatClock(item.time)}`), element('span', 'h-px flex-1 bg-current opacity-40'));
        return row;
    }
    if (item.kind === 'schedule') {
        const row = element('div', 'timeline-item flex min-w-0 cursor-pointer items-center gap-3 rounded-2xl border border-dashed border-slate-300 bg-white p-3 pl-0 shadow-sm dark:border-slate-700 dark:bg-slate-900');
        row.dataset.scheduledEvent = ''; row.dataset.timelineTime = item.time;
        row.append(element('time', 'w-20 shrink-0 text-center font-mono text-xs font-bold text-slate-500', formatClock(item.time)), renderTaskButton(item.task, item.time));
        return row;
    }

    const block = item.block;
    const row = element('div', `timeline-item flex min-w-0 cursor-pointer items-start gap-3 ${block.is_hidden ? 'opacity-60' : ''}`);
    row.dataset.recordedTime = item.time; row.dataset.timelineTime = item.time;
    if (block.type === 'sensor_browser') {
        row.dataset.timelineBrowsing = ''; row.dataset.browsingMode = 'duration'; row.dataset.browsingStart = formatClock(item.time); row.dataset.browsingDomains = JSON.stringify(block.browsing_domains || []); row.dataset.browsingTotal = String((block.browsing_domains || []).reduce((total, domain) => total + Number(domain.seconds || 0), 0));
    } else if (block.type === 'sensor_desktop') {
        row.dataset.timelineBrowsing = ''; row.dataset.browsingMode = 'applications'; row.dataset.browsingStart = formatClock(item.time); row.dataset.browsingDomains = JSON.stringify(block.desktop_applications || []); row.dataset.browsingTotal = String((block.desktop_applications || []).reduce((total, application) => total + Number(application.seconds || 0), 0));
    } else if (block.type === 'sensor_mobile_browser') {
        row.dataset.timelineBrowsing = ''; row.dataset.browsingMode = 'visits'; row.dataset.browsingStart = formatClock(item.time); row.dataset.browsingDomains = JSON.stringify(block.mobile_browsing_domains || []); row.dataset.browsingTotal = String((block.mobile_browsing_domains || []).reduce((total, domain) => total + Number(domain.visits || 0), 0));
    } else if (block.type === 'sensor_github' && (block.github_events || []).length) {
        row.dataset.timelineGithub = ''; row.dataset.githubProject = block.content || ''; row.dataset.githubStart = formatClock(item.time); row.dataset.githubEvents = JSON.stringify(block.github_events);
    } else if (block.type === 'sensor_google_calendar') {
        row.dataset.timelineGoogleCalendar = ''; row.dataset.googleCalendarEvent = JSON.stringify(block.calendar_event || {});
    } else {
        row.dataset.timelineEdit = ''; row.dataset.editKind = block.edit_kind; row.dataset.editEventName = block.event?.name || ''; row.dataset.editUrl = block.edit_url; row.dataset.editContent = block.content || ''; row.dataset.editEmoji = block.emoji || ''; row.dataset.editIcon = block.icon_data || ''; row.dataset.editUpdated = block.updated || ''; row.dataset.editLocation = JSON.stringify(block.event?.location || null); row.dataset.hideUrl = block.hide_url; row.dataset.deleteUrl = block.delete_url; row.dataset.isHidden = block.is_hidden ? 'true' : 'false';
    }
    if (block.is_hidden) row.dataset.hiddenPlannerItem = '';
    row.append(element('time', 'w-20 shrink-0 pt-4 text-center font-mono text-xs font-bold text-slate-500', formatClock(item.time)));
    const wrapper = element('div', 'timeline-entry-card min-w-0 flex-1');
    const article = element('article', `panel group ${block.is_hidden ? 'ring-2 ring-amber-400' : ''} ${block.optimistic ? 'ring-2 ring-indigo-300' : ''}`); article.id = `block-${block.id}`;
    const sensorBrowsing = ['sensor_browser', 'sensor_desktop', 'sensor_mobile_browser'].includes(block.type);
    const description = element('div', block.event || sensorBrowsing ? 'flex flex-wrap items-center gap-2 leading-relaxed' : 'block-text-description whitespace-pre-wrap leading-relaxed'); description.dataset.blockDescription = '';
    const emoji = block.icon_data
        ? renderEntryIcon(block.icon_data, block.emoji, 'h-8 w-8 shrink-0 rounded-lg object-cover')
        : element('span', block.event || sensorBrowsing ? 'text-xl' : 'mr-2 inline-block align-middle text-xl', block.emoji || '📝');
    emoji.dataset.blockEmoji = ''; emoji.setAttribute('aria-hidden', 'true');
    const labelText = block.type === 'sensor_mobile_browser' ? 'Mobile browsing' : (block.type === 'sensor_desktop' ? 'Desktop' : (block.type === 'sensor_browser' ? 'Browsing' : block.type.replaceAll('_', ' ')));
    const labelColor = block.type === 'sensor_mobile_browser' ? 'bg-violet-100 text-violet-800' : (block.type === 'sensor_desktop' ? 'bg-cyan-100 text-cyan-900' : (block.type === 'sensor_browser' ? 'bg-sky-100 text-sky-800' : (block.event ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600')));
    const label = element('span', `mr-2 inline-flex align-middle rounded-lg px-2 py-1 text-xs font-bold uppercase ${labelColor}`, labelText); label.dataset.blockTypeLabel = '';
    description.append(emoji, label);
    if (block.is_hidden) description.append(element('span', 'mr-2 inline-flex align-middle rounded-lg bg-amber-100 px-2 py-1 text-xs font-bold uppercase text-amber-800', 'Hidden'));
    if (block.event) description.append(element('span', 'text-lg font-bold', `${block.event.name}${block.event.value ? ` · ${block.event.value}` : ''}`));
    else description.append(document.createTextNode(block.content || ''));
    article.append(description);
    if (block.event && block.content) article.append(element('div', 'block-event-notes mt-2 whitespace-pre-wrap leading-relaxed', block.content));
    if (block.type === 'generated_image') (block.attachments || []).forEach(attachment => {
        const frame = element('div', 'block-attachment mt-3 overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-950');
        const image = element('img', 'h-[512px] max-h-[512px] w-full object-contain'); image.src = attachment.url; image.alt = 'AI-generated image'; image.loading = 'lazy';
        frame.append(image); article.append(frame);
    });
    wrapper.append(article); row.append(wrapper);
    return row;
}

function updateDayNavigation(state) {
    const date = document.querySelector('[data-navigation-date]');
    if (date) { date.textContent = state.title; date.setAttribute('datetime', state.date); }
    const links = document.querySelectorAll('[data-day-navigation] > a');
    if (links[0]) links[0].href = state.navigation.previous_url;
    if (links[1]) links[1].href = state.navigation.today_url;
    if (links[2]) links[2].href = state.navigation.next_url;
    const hiddenToggle = document.querySelector('[data-hidden-entries-toggle]');
    if (hiddenToggle) {
        hiddenToggle.href = state.show_hidden ? state.url.replace(/\?show_hidden=1$/, '') : `${state.url.split('?')[0]}?show_hidden=1`;
        const hiddenLabel = hiddenToggle.querySelector('[data-hidden-entries-label]');
        if (hiddenLabel) hiddenLabel.textContent = state.show_hidden ? 'Hide hidden entries' : 'Show hidden entries';
    }
    const menu = document.querySelector('#more-events-menu');
    if (menu) {
        const eventsMenu = menu.closest('[data-events-menu]');
        const nextMenu = menu.cloneNode(false);
        const menuContent = document.createDocumentFragment();
        state.tasks.forEach(task => menuContent.append(renderTaskButton(task)));
        nextMenu.append(menuContent);
        menu.replaceWith(nextMenu);
        eventsMenu?.classList.toggle('hidden', state.tasks.length === 0);
    }
}

function updateDayGoals(state) {
    const section = document.querySelector('#daily-log-goals');
    if (!section) return;
    const goals = state.goals || [];
    section.replaceChildren();
    section.classList.toggle('hidden', goals.length === 0);
    section.classList.toggle('flex', goals.length > 0);
    section.setAttribute('aria-label', `Goals for ${state.date}`);
    goals.forEach(goal => {
        const link = element('a', 'inline-flex min-w-48 shrink-0 items-center gap-2 rounded-full px-3 py-2 text-sm shadow-sm transition hover:-translate-y-0.5 hover:shadow-md');
        link.href = goal.url; link.style.backgroundColor = goal.color; link.style.color = goal.text_color; link.dataset.dayGoal = goal.id;
        link.draggable = false;
        const emoji = renderEntryIcon(goal.icon_data, goal.emoji, 'h-8 w-8 shrink-0 rounded-lg object-cover');
        const copy = element('span', 'min-w-0');
        copy.append(element('strong', 'block truncate', goal.name), element('span', 'block text-xs opacity-90', `${goal.points}/${goal.target} points · ${goal.latest || 'No activity'}`));
        link.append(emoji, copy); section.append(link);
    });
}

function updateDayStickyEvents(state) {
    const section = document.querySelector('#daily-log-sticky-events');
    if (!section) return;
    const stickyEvents = state.sticky_events || [];
    section.replaceChildren(...stickyEvents.map(task => renderTaskButton(task, null, {bubble:true})));
    section.classList.toggle('hidden', stickyEvents.length === 0);
    section.classList.toggle('flex', stickyEvents.length > 0);
    section.setAttribute('aria-label', `Sticky events for ${state.date}`);
}

function initializeHorizontalGoalDrag() {
    const section = document.querySelector('[data-horizontal-goal-drag]');
    if (!section) return;
    const threshold = 6;
    let pointerId = null;
    let startX = 0;
    let startY = 0;
    let startScroll = 0;
    let dragging = false;
    let suppressClick = false;

    section.addEventListener('pointerdown', event => {
        if (pointerId !== null || (event.pointerType === 'mouse' && event.button !== 0)) return;
        pointerId = event.pointerId;
        startX = event.clientX;
        startY = event.clientY;
        startScroll = section.scrollLeft;
        dragging = false;
    });
    section.addEventListener('pointermove', event => {
        if (event.pointerId !== pointerId) return;
        const deltaX = event.clientX - startX;
        const deltaY = event.clientY - startY;
        if (!dragging) {
            if (Math.abs(deltaX) < threshold) return;
            if (Math.abs(deltaY) > Math.abs(deltaX)) return;
            dragging = true;
            section.setPointerCapture?.(event.pointerId);
            section.classList.add('cursor-grabbing');
        }
        section.scrollLeft = startScroll - deltaX;
        if (event.cancelable) event.preventDefault();
    });
    const finishDrag = event => {
        if (event.pointerId !== pointerId) return;
        if (dragging) {
            suppressClick = true;
            window.setTimeout(() => { suppressClick = false; }, 0);
        }
        if (section.hasPointerCapture?.(event.pointerId)) section.releasePointerCapture(event.pointerId);
        section.classList.remove('cursor-grabbing');
        pointerId = null;
        dragging = false;
    };
    window.addEventListener('pointerup', finishDrag);
    window.addEventListener('pointercancel', finishDrag);
    section.addEventListener('click', event => {
        if (!suppressClick) return;
        event.preventDefault();
        event.stopPropagation();
        suppressClick = false;
    }, true);
    section.addEventListener('dragstart', event => event.preventDefault());
}

initializeHorizontalGoalDrag();

function updateDayControls(state) {
    const container = document.querySelector('#daily-log-page-container');
    if (state.next_sticky_visibility) container.dataset.nextStickyVisibility = state.next_sticky_visibility; else delete container.dataset.nextStickyVisibility;
    const timeline = document.querySelector('#timeline');
    const nextTimeline = timeline.cloneNode(false);
    const timelineContent = document.createDocumentFragment();
    state.timeline.forEach(item => {
        const rendered = renderTimelineItem(item);
        if (rendered) timelineContent.append(rendered);
    });
    nextTimeline.dataset.logDate = state.date;
    nextTimeline.append(timelineContent);
    timeline.replaceWith(nextTimeline);
    const composer = document.querySelector('[data-composer-note-form]');
    if (composer) { composer.action = state.log.create_block_url; composer.dataset.createAction = state.log.create_block_url; }
    const headingDate = document.querySelector('#log-composer-heading-copy > p'); if (headingDate) headingDate.textContent = state.title;
    const chat = document.querySelector('[data-smart-chat-form]'); if (chat) chat.action = state.log.chat_url;
    updateDayNavigation(state);
    updateDayStickyEvents(state);
    updateDayGoals(state);
}

function renderDayState(state, {scroll = null} = {}) {
    if (!document.querySelector('#daily-log-page-container')) return false;
    activeDayState = state;
    dayStateCache.set(dayStateKey(state.url), state);
    updateDayControls(state);
    scheduleStickyVisibilityRefresh();
    if (scroll) window.requestAnimationFrame(() => window.scrollTo(scroll.x, scroll.y));
    return true;
}

function mutateDayState(mutator) {
    const state = activeDayState || captureCurrentDayState();
    if (!state) return false;
    mutator(state);
    return renderDayState(state, {scroll:{x:window.scrollX, y:window.scrollY}});
}

let pageLoadingRequests = 0;
function beginPageLoading() {
    pageLoadingRequests += 1;
    const overlay = document.querySelector('[data-page-loading-overlay]');
    if (!overlay) return;
    overlay.classList.remove('hidden');
    overlay.classList.add('grid');
    document.body.setAttribute('aria-busy', 'true');
}

function finishPageLoading() {
    pageLoadingRequests = Math.max(0, pageLoadingRequests - 1);
    if (pageLoadingRequests > 0) return;
    const overlay = document.querySelector('[data-page-loading-overlay]');
    overlay?.classList.add('hidden');
    overlay?.classList.remove('grid');
    document.body.removeAttribute('aria-busy');
}

async function fetchDayState(url, {fresh = false} = {}) {
    const key = dayStateKey(url);
    if (!fresh && dayStateCache.has(key)) return dayStateCache.get(key);
    if (dayStateRequests.has(key)) return dayStateRequests.get(key);
    const request = fetch(url, {
            credentials: 'same-origin',
            cache: fresh ? 'no-store' : 'default',
            headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Day-State': 'json'},
        })
        .then(async response => {
            if (isExpiredSessionResponse(response)) { showSessionExpired(); throw new Error('Your session has expired.'); }
            if (!response.ok) throw new Error('The day could not be loaded.');
            const state = await response.json();
            dayStateCache.set(key, state);
            return state;
        })
        .finally(() => dayStateRequests.delete(key));
    dayStateRequests.set(key, request);
    return request;
}

async function navigateToDay(url, {history = true, fresh = false} = {}) {
    const key = dayStateKey(url);
    const cached = !fresh ? dayStateCache.get(key) : null;
    if (cached) {
        if (history) window.history.pushState({dayState:true}, '', cached.url || url);
        renderDayState(cached);
        window.scrollTo(0, 0);
        scheduleDayReturnReminder();
        return true;
    }
    beginPageLoading();
    try {
        const state = await fetchDayState(url, {fresh:true});
        if (history) window.history.pushState({dayState:true}, '', state.url || url);
        renderDayState(state);
        window.scrollTo(0, 0);
        scheduleDayReturnReminder();
        return true;
    } finally {
        finishPageLoading();
    }
}

async function refreshDayView({loading = true} = {}) {
    if (isScreenSaverRunning()) return false;
    if (!document.querySelector('#daily-log-page-container')) return false;
    if (loading) beginPageLoading();
    try {
        const state = await fetchDayState(window.location.href, {fresh:true});
        return renderDayState(state, {scroll:{x:window.scrollX, y:window.scrollY}});
    } finally {
        if (loading) finishPageLoading();
    }
}

function startTodayActivityRefresh() {
    const interval = 60 * 1000;
    let lastRefresh = Date.now();
    let running = false;

    const refresh = async () => {
        if (running || document.hidden || isScreenSaverRunning() || !activeDayState?.is_today || backgroundSyncQueue.size > 0) return;
        if (document.querySelector('[data-overlay][data-open="true"], [data-modal-backdrop]')) return;
        running = true;
        lastRefresh = Date.now();
        try { await refreshDayView({loading:false}); } catch (_) { /* Retry on the next interval. */ }
        finally { running = false; }
    };

    window.setInterval(refresh, interval);
    const refreshWhenDue = () => {
        if (!document.hidden && Date.now() - lastRefresh >= interval) refresh();
    };
    document.addEventListener('visibilitychange', refreshWhenDue);
    window.addEventListener('focus', refreshWhenDue);
    document.addEventListener('screensaver:stopped', refreshWhenDue);
}

const reloadScrollKey = `totallog.reload-scroll:${window.location.pathname}${window.location.search}`;
function reloadAtCurrentScroll() {
    try { sessionStorage.setItem(reloadScrollKey, JSON.stringify({x:window.scrollX, y:window.scrollY})); } catch (_) {}
    window.location.reload();
}

function restoreReloadScrollPosition() {
    let saved = null;
    try { saved = sessionStorage.getItem(reloadScrollKey); sessionStorage.removeItem(reloadScrollKey); } catch (_) {}
    if (!saved) return;
    try {
        const position = JSON.parse(saved);
        window.requestAnimationFrame(() => window.scrollTo(Number(position.x) || 0, Number(position.y) || 0));
    } catch (_) {}
}

restoreReloadScrollPosition();

async function refreshDayViewOrReload() {
    if (isScreenSaverRunning()) return;
    if (!await refreshDayView()) reloadAtCurrentScroll();
}

function isExpiredSessionResponse(response) {
    const redirectedToLogin = response.redirected && new URL(response.url, window.location.href).pathname.includes('/login');
    return response.status === 401 || response.status === 419 || redirectedToLogin;
}

function showSessionExpired() {
    const overlay = document.querySelector('[data-session-expired-overlay]');
    if (!overlay || overlay.dataset.open === 'true') return;
    overlay.dataset.open = 'true';
    overlay.classList.remove('hidden');
    overlay.classList.add('grid');
    document.body.classList.add('overflow-hidden');
    overlay.querySelector('[data-session-login]')?.focus();
}

function startSessionKeepAlive() {
    const url = document.body.dataset.sessionKeepaliveUrl;
    if (!url) return;

    const interval = 5 * 60 * 1000;
    let lastPing = Date.now();
    let timer;

    const ping = async () => {
        if (!navigator.onLine || isScreenSaverRunning()) return;
        lastPing = Date.now();

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
            });

            if (isExpiredSessionResponse(response)) {
                clearInterval(timer);
                showSessionExpired();
            }
        } catch {
            // A temporary network failure should not interrupt the page.
        }
    };

    timer = window.setInterval(ping, interval);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && Date.now() - lastPing >= interval) ping();
    });
    document.addEventListener('screensaver:stopped', () => {
        if (Date.now() - lastPing >= interval) ping();
    });
}

function screenSaverSettings() {
    const selected = document.querySelector('input[name="screensaver_style"]:checked')?.value;
    const logoInput = document.querySelector('[data-screensaver-logo]');
    return {
        style: selected || document.body.dataset.screensaverStyle || 'flying-toasters',
        speed: Number(document.querySelector('[data-screensaver-speed]')?.value || document.body.dataset.screensaverSpeed || 1),
        message: document.querySelector('[data-screensaver-message]')?.value || document.body.dataset.screensaverMessage || 'OUT TO LUNCH',
        logo: logoInput?.dataset.previewUrl || document.body.dataset.screensaverLogo,
    };
}

function screenSaverUrl(style) {
    return style === 'starry-night'
        ? document.body.dataset.screensaverStarryNight
        : `${document.body.dataset.screensaverBase}/${style}.html`;
}

function configureScreenSaverFrame(frame, settings = screenSaverSettings()) {
    const apply = () => {
        const frameDocument = frame.contentDocument;
        if (!frameDocument) return;
        frameDocument.documentElement.style.setProperty('--screensaver-speed', String(settings.speed));
        frameDocument.querySelectorAll('.message').forEach(message => { message.textContent = settings.message; });
        if (settings.style === 'logo' && settings.logo) {
            frameDocument.querySelectorAll('body img').forEach(image => { image.src = settings.logo; });
        }
        frame.contentWindow?.postMessage({type:'totallog-screensaver-settings', speed:settings.speed}, window.location.origin);
    };
    if (frame.contentDocument?.readyState === 'complete') apply();
    else frame.addEventListener('load', apply, {once:true});
}

function initScreenSaver() {
    const overlay = document.querySelector('[data-screensaver-overlay]');
    const frame = overlay?.querySelector('[data-screensaver-frame]');
    const spotlight = overlay?.querySelector('[data-screensaver-spotlight]');
    if (!overlay || !frame) return;

    let idleTimer = null;
    let ignoreActivityUntil = 0;
    let previousHtmlOverflow = '';
    let previousBodyOverflow = '';
    let previousFocus = null;
    const enabled = () => document.body.dataset.screensaverEnabled === 'true';
    const waitMilliseconds = () => Math.max(1, Number(document.body.dataset.screensaverWait || 10)) * 60 * 1000;

    const schedule = () => {
        window.clearTimeout(idleTimer);
        if (!enabled() || document.hidden || isScreenSaverRunning()) return;
        idleTimer = window.setTimeout(() => start(), waitMilliseconds());
    };

    const stop = () => {
        if (!isScreenSaverRunning()) return;
        document.body.dataset.screensaverRunning = 'false';
        overlay.classList.add('hidden');
        overlay.classList.remove('block');
        frame.removeAttribute('src');
        frame.classList.remove('hidden');
        spotlight?.classList.add('hidden');
        overlay.style.backgroundColor = '';
        document.documentElement.style.overflow = previousHtmlOverflow;
        document.body.style.overflow = previousBodyOverflow;
        previousFocus?.focus?.({preventScroll:true});
        previousFocus = null;
        document.dispatchEvent(new CustomEvent('screensaver:stopped'));
        schedule();
    };

    const start = () => {
        const settings = screenSaverSettings();
        ignoreActivityUntil = Date.now() + 350;
        window.clearTimeout(idleTimer);
        previousHtmlOverflow = document.documentElement.style.overflow;
        previousBodyOverflow = document.body.style.overflow;
        previousFocus = document.activeElement;
        document.body.dataset.screensaverRunning = 'true';
        overlay.classList.remove('hidden');
        overlay.classList.add('block');
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        if (settings.style === 'spotlight') {
            frame.classList.add('hidden');
            frame.removeAttribute('src');
            spotlight?.classList.remove('hidden');
            spotlight?.querySelector('.screensaver-spotlight-lens')?.style.setProperty('animation-duration', `${12 / settings.speed}s`);
            overlay.style.backgroundColor = 'transparent';
        } else {
            spotlight?.classList.add('hidden');
            frame.classList.remove('hidden');
            overlay.style.backgroundColor = '';
            frame.src = screenSaverUrl(settings.style);
            configureScreenSaverFrame(frame, settings);
        }
        overlay.focus({preventScroll:true});
        setMobileNavigation(false);
    };

    const bindFrameExitEvents = () => {
        const frameDocument = frame.contentDocument;
        if (!frameDocument) return;
        frameDocument.documentElement.style.setProperty('overflow', 'hidden', 'important');
        frameDocument.body?.style.setProperty('overflow', 'hidden', 'important');
        ['pointermove', 'pointerdown', 'keydown', 'touchstart', 'wheel'].forEach(eventName => {
            frameDocument.addEventListener(eventName, activity, {passive:true});
        });
        frame.contentWindow?.focus();
    };

    const activity = () => {
        if (isScreenSaverRunning()) {
            if (Date.now() >= ignoreActivityUntil) stop();
            return;
        }
        schedule();
    };

    ['pointerdown', 'pointermove', 'keydown', 'touchstart', 'wheel', 'scroll'].forEach(eventName => {
        document.addEventListener(eventName, activity, {passive:true});
    });
    document.addEventListener('visibilitychange', () => document.hidden ? window.clearTimeout(idleTimer) : schedule());
    frame.addEventListener('load', bindFrameExitEvents);
    overlay.querySelector('[data-screensaver-close]')?.addEventListener('click', stop);
    document.querySelectorAll('[data-screensaver-start]').forEach(button => button.addEventListener('click', start));

    document.querySelector('[data-screensaver-toggle]')?.addEventListener('click', async event => {
        const button = event.currentTarget;
        button.disabled = true;
        try {
            const result = await ajax(button.dataset.toggleUrl, {method:'PATCH'});
            document.body.dataset.screensaverEnabled = result.enabled ? 'true' : 'false';
            button.setAttribute('aria-pressed', result.enabled ? 'true' : 'false');
            button.querySelector('[data-screensaver-toggle-label]').textContent = result.enabled ? 'Disable screensaver' : 'Enable screensaver';
            if (!result.enabled) stop();
            else schedule();
            toast(result.message);
        } catch (error) {
            toast(error.message, true);
        } finally {
            button.disabled = false;
            setMobileNavigation(false);
        }
    });

    const preview = document.querySelector('[data-screensaver-preview]');
    const spotlightPreview = document.querySelector('[data-screensaver-spotlight-preview]');
    const messageSetting = document.querySelector('[data-screensaver-message-setting]');
    const logoSetting = document.querySelector('[data-screensaver-logo-setting]');
    const previewTitle = document.querySelector('[data-screensaver-preview-title]');
    const updatePreview = () => {
        const settings = screenSaverSettings();
        const selectedOption = document.querySelector('[data-screensaver-option]:checked');
        if (previewTitle) previewTitle.textContent = selectedOption?.dataset.screensaverLabel || 'Preview';
        messageSetting?.classList.toggle('hidden', !['messages', 'messages2'].includes(settings.style));
        logoSetting?.classList.toggle('hidden', settings.style !== 'logo');
        document.querySelectorAll('[data-screensaver-option]').forEach(option => option.closest('label')?.classList.toggle('screensaver-list-option-selected', option.checked));

        if (!preview) return;
        const isSpotlight = settings.style === 'spotlight';
        preview.classList.toggle('hidden', isSpotlight);
        spotlightPreview?.classList.toggle('hidden', !isSpotlight);
        spotlightPreview?.querySelector('.screensaver-spotlight-lens')?.style.setProperty('animation-duration', `${12 / settings.speed}s`);
        if (isSpotlight) {
            preview.removeAttribute('src');
            preview.dataset.screensaverName = '';
            return;
        }

        if (preview.dataset.screensaverName !== settings.style) {
            preview.dataset.screensaverName = settings.style;
            preview.src = screenSaverUrl(settings.style);
        } else {
            configureScreenSaverFrame(preview, settings);
        }
    };
    preview?.addEventListener('load', updatePreview);
    document.querySelectorAll('[data-screensaver-option]').forEach(option => option.addEventListener('change', updatePreview));
    document.querySelector('[data-screensaver-speed]')?.addEventListener('change', updatePreview);
    document.querySelector('[data-screensaver-message]')?.addEventListener('input', updatePreview);
    document.querySelector('[data-screensaver-logo]')?.addEventListener('change', event => {
        const input = event.currentTarget;
        if (input.dataset.previewUrl) URL.revokeObjectURL(input.dataset.previewUrl);
        input.dataset.previewUrl = input.files[0] ? URL.createObjectURL(input.files[0]) : '';
        updatePreview();
    });
    updatePreview();
    schedule();
}

startSessionKeepAlive();
initScreenSaver();
captureCurrentDayState();
scheduleDayReturnReminder();
startDateRolloverWatch();
startTodayActivityRefresh();

document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
    const url = new URL(link.href, window.location.href);
    if (!supportsDayStateNavigation() || url.origin !== window.location.origin || !/^\/logs\/\d{4}-\d{2}-\d{2}$/.test(url.pathname)) return;
    event.preventDefault();
    navigateToDay(url.href).catch(error => toast(error.message, true));
});

document.addEventListener('pointerenter', event => {
    if (!supportsDayStateNavigation()) return;
    const link = event.target.closest?.('a[href]');
    if (!link) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin === window.location.origin && /^\/logs\/\d{4}-\d{2}-\d{2}$/.test(url.pathname)) fetchDayState(url.href).catch(() => {});
}, true);

window.addEventListener('popstate', () => {
    if (/^\/logs\/\d{4}-\d{2}-\d{2}$/.test(window.location.pathname)) navigateToDay(window.location.href, {history:false}).catch(() => window.location.reload());
});

document.querySelectorAll('[data-auto-dismiss]').forEach(element => window.setTimeout(() => element.remove(), 2000));

const accountDeleteDialog = document.querySelector('[data-account-delete-dialog]');
if (accountDeleteDialog?.dataset.open === 'true') accountDeleteDialog.showModal();
document.querySelector('[data-account-delete-open]')?.addEventListener('click', () => accountDeleteDialog?.showModal());
document.querySelector('[data-account-delete-close]')?.addEventListener('click', () => accountDeleteDialog?.close());

function setMobileNavigation(open) {
    const toggle = document.querySelector('[data-mobile-nav-toggle]');
    const menu = document.querySelector('[data-mobile-nav-menu]');
    if (!toggle || !menu) return;
    menu.classList.toggle('hidden', !open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
}

const colorThemes = ['light', 'paper', 'blue', 'red', 'dark'];
function applyTheme(theme) {
    const selected = colorThemes.includes(theme) ? theme : 'light';
    document.documentElement.dataset.theme = selected;
    document.documentElement.classList.toggle('dark', selected === 'dark' || selected === 'red');
    localStorage.setItem('totallog.theme', selected);
    document.querySelectorAll('[data-theme-icon]').forEach(icon => icon.classList.toggle('hidden', icon.dataset.themeIcon !== selected));
    document.querySelectorAll('[data-theme-option]').forEach(option => {
        const active = option.dataset.themeOption === selected;
        option.setAttribute('aria-checked', active ? 'true' : 'false');
        option.querySelector('[data-theme-check]')?.classList.toggle('opacity-0', !active);
    });
}

applyTheme(document.documentElement.dataset.theme || 'light');

function openOverlay(name) {
    const root = document.querySelector(`[data-overlay="${name}"]`); if (!root) return;
    document.querySelectorAll('[data-overlay][data-open="true"]').forEach(item => closeOverlay(item, true));
    root.classList.remove('hidden', 'pointer-events-none');
    if (name !== 'composer') root.classList.add('grid');
    root.dataset.open = 'true'; document.body.classList.add('overflow-hidden');
    requestAnimationFrame(() => {
        root.querySelector('[data-overlay-backdrop]')?.classList.replace('opacity-0', 'opacity-100');
        const panel = root.querySelector('[data-overlay-panel]');
        panel?.classList.remove('translate-x-full', 'translate-y-5', 'opacity-0');
        root.querySelector('[data-overlay-close]:not([data-overlay-backdrop])')?.focus();
    });
}

function closeOverlay(root, immediate = false) {
    if (!root) return;
    root.querySelector('[data-overlay-backdrop]')?.classList.replace('opacity-100', 'opacity-0');
    const panel = root.querySelector('[data-overlay-panel]');
    if (root.dataset.overlay === 'composer' || root.dataset.overlaySide === 'right') panel?.classList.add('translate-x-full');
    else panel?.classList.add('translate-y-5', 'opacity-0');
    root.dataset.open = 'false'; root.classList.add('pointer-events-none'); document.body.classList.remove('overflow-hidden');
    const finish = () => { if (immediate || root.dataset.open !== 'true') { root.classList.add('hidden'); root.classList.remove('grid'); } };
    if (immediate) finish(); else setTimeout(finish, 300);
}

function renderSearchResultDetails(details, card) {
    if (!details) return card;

    const content = element('div', 'space-y-4');
    if (details.kind === 'browsing') {
        const visitsMode = details.mode === 'visits';
        const applicationsMode = details.mode === 'applications';
        const source = applicationsMode ? 'Desktop sensor' : (visitsMode ? 'Synced Chrome history' : 'Chrome sensor');
        const itemName = applicationsMode ? 'applications' : 'domains';
        const total = Number(details.total || 0);
        const summary = visitsMode ? `${total} ${total === 1 ? 'visit' : 'visits'}` : browsingDuration(total);
        const introduction = element('div', 'rounded-2xl bg-white p-4 dark:bg-slate-900');
        introduction.append(
            element('p', 'text-xs font-bold uppercase tracking-wider text-sky-600', source),
            element('p', 'mt-1 font-semibold', `${details.items.length} ${itemName} · ${summary}`),
        );
        content.append(introduction);
        details.items.forEach(item => {
            const row = cloneTemplate('browsing-domain-row-template');
            row.querySelector('[data-browsing-domain-name]').textContent = item.domain;
            const count = Number(item.visits || 0);
            row.querySelector('[data-browsing-domain-time]').textContent = visitsMode ? `${count} ${count === 1 ? 'visit' : 'visits'}` : browsingDuration(item.seconds);
            content.append(row);
        });
        content.append(element('p', 'text-xs leading-relaxed text-slate-500', applicationsMode
            ? 'Only application names, process names, and time totals are stored. Window titles and executable paths stay on your computer.'
            : visitsMode
                ? 'Only domains, visit timestamps, and counts are stored. Individual page paths, titles, and query strings are not added to your log.'
                : 'Only site domains and time totals are stored. Individual page paths, titles, and query strings are not added to your log.'));
        return content;
    }

    if (details.kind === 'github') {
        const count = details.events.length;
        content.append(element('p', 'rounded-2xl bg-white p-4 text-sm font-semibold dark:bg-slate-900', `${count} ${count === 1 ? 'commit' : 'commits'}`));
        details.events.forEach(event => {
            const row = cloneTemplate('github-event-row-template');
            row.querySelector('[data-github-event-time]').textContent = event.time || '';
            row.querySelector('[data-github-event-sha]').textContent = String(event.sha || '').slice(0, 7);
            row.querySelector('[data-github-event-message]').textContent = event.message || `Commit ${String(event.sha || '').slice(0, 7)}`;
            const link = row.querySelector('[data-github-event-link]');
            if (event.url) link.href = event.url; else link.classList.add('hidden');
            content.append(row);
        });
        return content;
    }

    if (details.kind === 'calendar') {
        if (details.location) {
            const location = element('div', 'rounded-2xl bg-white p-4 dark:bg-slate-900');
            location.append(element('p', 'text-xs font-bold uppercase tracking-wider text-slate-500', 'Location'), element('p', 'mt-1 whitespace-pre-wrap', details.location));
            content.append(location);
        }
        if (details.description) {
            const description = element('div', 'rounded-2xl bg-white p-4 dark:bg-slate-900');
            description.append(element('p', 'text-xs font-bold uppercase tracking-wider text-slate-500', 'Description'), element('p', 'mt-1 whitespace-pre-wrap', details.description));
            content.append(description);
        }
        if (details.url) {
            const link = element('a', 'btn w-full justify-center', 'Open in Google Calendar');
            link.href = details.url; link.target = '_blank'; link.rel = 'noopener noreferrer';
            content.append(link);
        }
        return content;
    }

    return card;
}

function openSearchResult(result) {
    const overlay = document.querySelector('[data-overlay="search-result"]');
    const card = result.querySelector('article')?.cloneNode(true);
    if (!overlay || !card) return;

    card.removeAttribute('id');
    let details = null;
    try { details = JSON.parse(result.querySelector('[data-search-result-details]')?.textContent || 'null'); } catch {}
    overlay.querySelector('[data-search-result-title]').textContent = details?.title || details?.project || result.dataset.resultTitle || 'Log entry';
    overlay.querySelector('[data-search-result-date]').textContent = result.dataset.resultDate || '';
    overlay.querySelector('[data-search-result-time]').textContent = result.dataset.resultTime || '';
    overlay.querySelector('[data-search-result-content]').replaceChildren(renderSearchResultDetails(details, card));
    overlay.querySelector('[data-search-result-date-link]').href = result.dataset.resultDateUrl;
    openOverlay('search-result');
}

function initializeLogSearch() {
    const form = document.querySelector('#log-search-form');
    const input = document.querySelector('#log-search-input');
    const results = document.querySelector('#log-search-results');
    if (!form || !input || !results) return;

    let debounceTimer = null;
    let activeRequest = null;

    const updateUrl = keyword => {
        const url = new URL(form.action, window.location.href);
        if (keyword) url.searchParams.set('q', keyword);
        window.history.replaceState({}, '', url);
    };

    const showPrompt = keyword => {
        const root = element('div', 'panel py-12 text-center');
        root.id = keyword ? 'log-search-short-query' : 'log-search-prompt';
        root.append(
            element('span', 'text-4xl', keyword ? '⌨️' : '🔍'),
            element('h2', 'mt-3 text-lg font-bold', keyword ? 'Type at least two letters' : 'Find anything you recorded'),
            element('p', 'mt-1 text-sm text-slate-500', keyword ? 'Results will appear automatically.' : 'Searches entry text, events, GitHub details, calendar metadata, browsing domains, apps, and attachment names.'),
        );
        root.firstElementChild.setAttribute('aria-hidden', 'true');
        results.replaceChildren(root);
        results.setAttribute('aria-busy', 'false');
        updateUrl(keyword);
    };

    const search = async () => {
        const keyword = input.value.trim();
        if (keyword.length < 2) { showPrompt(keyword); return; }

        activeRequest?.abort();
        activeRequest = new AbortController();
        const url = new URL(form.action, window.location.href);
        url.searchParams.set('q', keyword);
        results.setAttribute('aria-busy', 'true');

        try {
            const body = await ajax(url, {signal: activeRequest.signal});
            if (input.value.trim() !== keyword) return;
            results.innerHTML = body.html;
            window.history.replaceState({}, '', body.url);
        } catch (error) {
            if (error.name !== 'AbortError') toast(error.message, true);
        } finally {
            if (input.value.trim() === keyword) results.setAttribute('aria-busy', 'false');
        }
    };

    results.addEventListener('click', event => {
        const result = event.target.closest('[data-search-result-open]');
        if (!result || event.target.closest('a, button, input, textarea, select')) return;
        openSearchResult(result);
    });
    results.addEventListener('keydown', event => {
        const result = event.target.closest('[data-search-result-open]');
        if (!result || event.target !== result || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        openSearchResult(result);
    });
    input.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        activeRequest?.abort();
        const keyword = input.value.trim();
        if (keyword.length < 2) { showPrompt(keyword); return; }
        debounceTimer = window.setTimeout(search, 500);
    });
    form.addEventListener('submit', event => {
        event.preventDefault();
        clearTimeout(debounceTimer);
        search();
    });
}

initializeLogSearch();

const accountTimeFormat = document.body.dataset.timeFormat || '24';

function formatClock(value) {
    const [hour, minute] = (value || '00:00').split(':').map(Number);
    if (accountTimeFormat === '24') return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
    return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`;
}

function openTimePicker(root) {
    const input = root.querySelector('[data-time-picker-input]');
    const anchor = root.querySelector('[data-time-picker-open]');
    const originalValue = input.value || '12:00';
    const [initialHour, initialMinute] = (input.value || '12:00').split(':').map(Number);
    let hour = initialHour, minute = Math.round(initialMinute / 5) * 5;
    if (minute === 60) { minute = 0; hour = (hour + 1) % 24; }
    let period = hour >= 12 ? 'PM' : 'AM';
    let displayHour = hour % 12 || 12;
    const backdrop = cloneTemplate('time-picker-dialog-template'); if (!backdrop) return;
    const panel = backdrop.querySelector('[data-time-dialog-panel]');
    const labels = backdrop.querySelector('[data-time-column-grid]');
    const wheels = backdrop.querySelector('[data-time-wheel-grid]');
    const periodLabel = backdrop.querySelector('[data-time-period-column]');
    const hour24 = backdrop.querySelector('[data-time-wheel-hour-24]');
    const hour12 = backdrop.querySelector('[data-time-wheel-hour-12]');
    const periodWheel = backdrop.querySelector('[data-time-wheel-period]');
    const periodColumn = backdrop.querySelector('[data-time-wheel-period-column]');
    const usesTwelveHours = accountTimeFormat === '12';
    labels.classList.toggle('grid-cols-2', !usesTwelveHours); labels.classList.toggle('grid-cols-3', usesTwelveHours);
    wheels.classList.toggle('grid-cols-2', !usesTwelveHours); wheels.classList.toggle('grid-cols-3', usesTwelveHours);
    periodLabel.classList.toggle('hidden', !usesTwelveHours); periodColumn.classList.toggle('hidden', !usesTwelveHours);
    hour24.classList.toggle('hidden', usesTwelveHours); hour12.classList.toggle('hidden', !usesTwelveHours);
    const wheelControls = [];
    let dismissed = false, initializing = true;
    const updateInput = value => {
        anchor.textContent = formatClock(value);
        if (input.value === value) return;
        input.value = value;
        input.dispatchEvent(new Event('input', {bubbles:true}));
        input.dispatchEvent(new Event('change', {bubbles:true}));
    };
    const makeWheel = (list, numeric, selected, choose, kind) => {
        const buttons = Array.from(list.querySelectorAll('[data-value]'));
        const values = buttons.map(button => numeric ? Number(button.dataset.value) : button.dataset.value);
        const stepButtons = Array.from(list.closest('[data-time-wheel-column]').querySelectorAll('[data-time-wheel-step]'));
        let scrollTarget = null;
        let programmedScrollTimer;
        const scrollToIndex = (index, smooth = true) => {
            scrollTarget = index;
            list.scrollTo({top:index * 48, behavior:smooth ? 'smooth' : 'auto'});
            clearTimeout(programmedScrollTimer);
            programmedScrollTimer = setTimeout(() => {
                if (scrollTarget !== index) return;
                scrollTarget = null;
                list.scrollTop = index * 48;
            }, smooth ? 400 : 0);
        };
        const selectIndex = (index, smooth = true) => {
            const next = Math.max(0, Math.min(values.length - 1, index));
            choose(values[next]);
            scrollToIndex(next, smooth);
            render(true);
        };
        buttons.forEach((button, index) => button.addEventListener('click', () => {
            selectIndex(index);
            if (kind === 'minute') dismiss();
        }));
        stepButtons.forEach(button => button.addEventListener('click', () => selectIndex(values.indexOf(selected()) + Number(button.dataset.timeWheelStep))));
        let scrollTimer;
        let accumulatedWheel = 0;
        let wheelFrame = null;
        let lastWheelEventAt = 0;
        let lastWheelStepAt = 0;
        let notchedGesture = false;
        list.addEventListener('wheel', event => {
            event.preventDefault();
            const now = performance.now();
            if (!event.deltaY) return;
            if (now - lastWheelEventAt > 180) {
                accumulatedWheel = 0;
                notchedGesture = event.deltaMode === WheelEvent.DOM_DELTA_LINE || Math.abs(event.deltaY) >= 100;
            }
            lastWheelEventAt = now;
            const modeScale = event.deltaMode === WheelEvent.DOM_DELTA_LINE ? 16 : (event.deltaMode === WheelEvent.DOM_DELTA_PAGE ? list.clientHeight : 1);
            const adaptedDelta = notchedGesture ? Math.sign(event.deltaY) * 48 : event.deltaY * modeScale * 0.35;
            accumulatedWheel += adaptedDelta;
            if (wheelFrame !== null) return;
            wheelFrame = requestAnimationFrame(frameTime => {
                wheelFrame = null;
                const threshold = notchedGesture ? 1 : 48;
                const cooldown = notchedGesture ? 40 : 140;
                if (Math.abs(accumulatedWheel) < threshold) return;
                if (frameTime - lastWheelStepAt < cooldown) { accumulatedWheel = 0; return; }
                const direction = Math.sign(accumulatedWheel);
                accumulatedWheel = 0;
                lastWheelStepAt = frameTime;
                selectIndex(values.indexOf(selected()) + direction);
            });
        }, {passive:false});
        list.addEventListener('scroll', () => {
            if (scrollTarget !== null) {
                clearTimeout(programmedScrollTimer);
                programmedScrollTimer = setTimeout(() => {
                    const target = scrollTarget;
                    scrollTarget = null;
                    list.scrollTop = target * 48;
                }, 100);
                return;
            }
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(() => {
                if (dismissed) return;
                const index = Math.max(0, Math.min(values.length - 1, Math.round(list.scrollTop / 48)));
                selectIndex(index);
            }, 80);
        });
        const control = {list, values, buttons, selected, update() { const current = selected(), index = values.indexOf(current); buttons.forEach(button => { const active = String(button.dataset.value) === String(current); button.classList.toggle('text-white', active); button.classList.toggle('text-slate-800', !active); button.classList.toggle('dark:text-slate-200', !active); button.setAttribute('aria-selected', active ? 'true' : 'false'); }); stepButtons.forEach(button => { const disabled = Number(button.dataset.timeWheelStep) < 0 ? index <= 0 : index >= values.length - 1; button.disabled = disabled; button.classList.toggle('opacity-30', disabled); }); }, center() { list.scrollTop = values.indexOf(selected()) * 48; }};
        wheelControls.push(control); return control;
    };
    makeWheel(usesTwelveHours ? hour12 : hour24, true, () => usesTwelveHours ? displayHour : hour, value => { if (usesTwelveHours) { displayHour = value; hour = (value % 12) + (period === 'PM' ? 12 : 0); } else hour = value; }, 'hour');
    makeWheel(backdrop.querySelector('[data-time-wheel-minute]'), true, () => minute, value => { minute = value; }, 'minute');
    if (usesTwelveHours) makeWheel(periodWheel, false, () => period, value => { period = value; hour = (displayHour % 12) + (period === 'PM' ? 12 : 0); }, 'period');
    let naturalDialogHeight;
    const positionPanel = () => {
        const margin = 12, gap = 8, anchorRect = anchor.getBoundingClientRect();
        const container = root.closest('[data-overlay-panel], .panel'), containerRect = container?.getBoundingClientRect();
        const width = Math.min(448, containerRect?.width || 448, window.innerWidth);
        panel.style.width = `${width}px`;
        const idealLeft = anchorRect.left + (anchorRect.width / 2) - (width / 2);
        panel.style.left = `${Math.max(0, Math.min(idealLeft, window.innerWidth - width))}px`;
        naturalDialogHeight ??= panel.scrollHeight;
        const naturalHeight = Math.min(naturalDialogHeight, window.innerHeight * 0.8);
        const roomBelow = window.innerHeight - anchorRect.bottom - gap;
        const opensBelow = roomBelow >= naturalHeight;
        const height = naturalHeight;
        const idealTop = opensBelow ? anchorRect.bottom + gap : anchorRect.top - height - gap;
        panel.style.top = `${Math.max(margin, Math.min(idealTop, window.innerHeight - height - margin))}px`;
        panel.dataset.placement = opensBelow ? 'below' : 'above';
    };
    const dismiss = () => { dismissed = true; window.removeEventListener('resize', positionPanel); window.removeEventListener('scroll', positionPanel, true); backdrop.remove(); };
    backdrop.querySelector('[data-time-dialog-cancel]').addEventListener('click', () => { updateInput(originalValue); dismiss(); });
    backdrop.addEventListener('click', event => { if (event.target === backdrop) dismiss(); });
    const render = (commit = false) => {
        const value = `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
        backdrop.querySelector('[data-time-preview]').textContent = formatClock(value);
        wheelControls.forEach(control => control.update());
        if (commit) updateInput(value);
    };
    document.body.append(backdrop); render(); positionPanel(); window.addEventListener('resize', positionPanel); window.addEventListener('scroll', positionPanel, true); requestAnimationFrame(() => { wheelControls.forEach(control => control.center()); positionPanel(); setTimeout(() => { initializing = false; }, 120); });
}

function initTimePicker(root) {
    const input = root.querySelector('[data-time-picker-input]'), button = root.querySelector('[data-time-picker-open]'); if (!input || !button) return;
    const update = () => { button.textContent = formatClock(input.value || '12:00'); }; button.addEventListener('click', () => openTimePicker(root)); input.addEventListener('change', update); update();
}

document.querySelectorAll('[data-time-picker]').forEach(initTimePicker);

function setEmojiPickerValue(picker, value, dispatch = false) {
    if (!picker) return;
    const input = picker.querySelector('[data-emoji-input]');
    const preview = picker.querySelector('[data-emoji-preview]');
    if (!input || !value) return;
    input.value = value;
    if (preview) preview.textContent = value;
    if (dispatch) {
        input.dispatchEvent(new Event('input', {bubbles:true}));
        input.dispatchEvent(new Event('change', {bubbles:true}));
    }
}

const emojiApiRequests = new Map();
function loadEmojiPage(baseUrl, params = {}) {
    const url = new URL(baseUrl, window.location.origin);
    Object.entries(params).forEach(([key, value]) => { if (value) url.searchParams.set(key, value); });
    const key = url.toString();
    if (!emojiApiRequests.has(key)) {
        emojiApiRequests.set(key, fetch(key, {headers:{Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'}}).then(response => {
            if (!response.ok) throw new Error('The emoji library could not be loaded.');
            return response.json();
        }).catch(error => { emojiApiRequests.delete(key); throw error; }));
    }
    return emojiApiRequests.get(key);
}

function initEmojiPicker(picker) {
    if (picker.dataset.emojiInitialized === 'true') return;
    picker.dataset.emojiInitialized = 'true';
    const toggle = picker.querySelector('[data-emoji-toggle]');
    const menu = picker.querySelector('[data-emoji-menu]');
    const search = picker.querySelector('[data-emoji-search]');
    const categorySelect = picker.querySelector('[data-emoji-categories]');
    const grid = picker.querySelector('[data-emoji-grid]');
    const optionTemplate = picker.querySelector('[data-emoji-option-template]');
    const loading = picker.querySelector('[data-emoji-loading]');
    const loadingMessage = picker.querySelector('[data-emoji-loading-message]');
    const loadingSpinner = picker.querySelector('[data-emoji-loading-spinner]');
    const empty = picker.querySelector('[data-emoji-empty]');
    const host = picker.closest('.panel');
    let activeCategory = '', hydrated = false, searchTimer, requestRevision = 0;
    const close = () => { menu?.classList.add('hidden'); menu?.classList.remove('flex'); host?.classList.remove('emoji-picker-host-active'); toggle?.setAttribute('aria-expanded', 'false'); };
    const setLoading = busy => {
        loading?.classList.toggle('hidden', !busy);
        loading?.classList.toggle('grid', busy);
        grid?.classList.toggle('opacity-40', busy);
        grid?.classList.toggle('pointer-events-none', busy);
        if (busy) {
            if (loadingMessage) loadingMessage.textContent = 'Loading emojis…';
            loadingSpinner?.classList.remove('hidden');
        }
        if (busy) empty?.classList.add('hidden');
    };
    const markCategory = slug => {
        activeCategory = slug || '';
        if (categorySelect && categorySelect.value !== activeCategory) categorySelect.value = activeCategory;
    };
    const renderOptions = (emojis, revision) => {
        const nodes = (emojis || []).map(emoji => {
            const option = optionTemplate.content.firstElementChild.cloneNode(true);
            option.dataset.emojiValue = emoji.emoji;
            option.dataset.emojiName = emoji.name || emoji.slug || emoji.emoji;
            option.textContent = emoji.emoji;
            option.setAttribute('aria-label', emoji.name || emoji.slug || emoji.emoji);
            option.title = emoji.name || emoji.slug || emoji.emoji;
            option.classList.remove('hidden'); option.classList.add('flex');
            option.addEventListener('click', () => { setEmojiPickerValue(picker, option.dataset.emojiValue, true); close(); });
            return option;
        });
        window.requestAnimationFrame(() => {
            if (revision !== requestRevision) return;
            grid.replaceChildren(...nodes);
            setLoading(false);
            empty?.classList.toggle('hidden', nodes.length > 0);
        });
    };
    const requestEmojis = async params => {
        const revision = ++requestRevision;
        setLoading(true);
        try {
            const body = await loadEmojiPage(picker.dataset.emojiUrl, params);
            if (revision !== requestRevision) return;
            if (!hydrated) {
                const categoryOptions = (body.categories || []).map(group => {
                    const option = document.createElement('option');
                    option.value = group.slug;
                    option.textContent = group.name;
                    return option;
                });
                categorySelect?.replaceChildren(...categoryOptions);
                hydrated = true;
            }
            const selected = body.group || activeCategory || categorySelect?.options[0]?.value || '';
            if (selected && !params.q) markCategory(selected);
            renderOptions(body.emojis, revision);
        } catch (error) {
            if (revision !== requestRevision) return;
            if (loading) {
                loading.classList.remove('hidden'); loading.classList.add('grid');
                loadingSpinner?.classList.add('hidden');
                if (loadingMessage) loadingMessage.textContent = error.message;
            }
        }
    };
    toggle?.addEventListener('click', async () => {
        const opens = menu?.classList.contains('hidden');
        document.querySelectorAll('[data-emoji-menu]:not(.hidden)').forEach(other => { if (other !== menu) { other.classList.add('hidden'); other.classList.remove('flex'); const otherPicker = other.closest('[data-emoji-picker]'); otherPicker?.closest('.panel')?.classList.remove('emoji-picker-host-active'); otherPicker?.querySelector('[data-emoji-toggle]')?.setAttribute('aria-expanded', 'false'); } });
        if (!opens) { close(); return; }
        menu?.classList.remove('hidden'); menu?.classList.add('flex'); host?.classList.add('emoji-picker-host-active');
        toggle.setAttribute('aria-expanded', opens ? 'true' : 'false');
        if (opens) { if (!hydrated) await requestEmojis({}); setTimeout(() => search?.focus(), 0); }
    });
    search?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const query = search.value.trim();
        searchTimer = window.setTimeout(() => requestEmojis(query ? {q:query} : {group:activeCategory}), 300);
    });
    categorySelect?.addEventListener('change', async () => {
        clearTimeout(searchTimer);
        if (search) search.value = '';
        markCategory(categorySelect.value);
        await requestEmojis({group:activeCategory});
    });
}

document.querySelectorAll('[data-emoji-picker]').forEach(initEmojiPicker);

function setIconUploadValue(root, iconData = '') {
    if (!root) return;
    const preview = root.querySelector('[data-icon-preview]');
    const empty = root.querySelector('[data-icon-empty]');
    const remove = root.querySelector('[data-icon-remove]');
    const data = root.querySelector('[data-icon-data]');
    const removeInput = root.querySelector('[data-icon-remove-input]');
    root.dataset.currentIcon = iconData || '';
    if (data) data.value = '';
    if (removeInput) removeInput.value = '0';
    if (preview) {
        preview.src = iconData || '';
        preview.classList.toggle('hidden', !iconData);
    }
    empty?.classList.toggle('hidden', Boolean(iconData));
    remove?.classList.toggle('hidden', !iconData);
}

function initializeIconCropper() {
    const dialog = document.querySelector('[data-icon-crop-dialog]');
    if (!dialog) return;
    const stage = dialog.querySelector('[data-icon-crop-stage]');
    const image = dialog.querySelector('[data-icon-crop-image]');
    const zoomInput = dialog.querySelector('[data-icon-zoom]');
    const targetSize = 128;
    const stageSize = 288;
    const targetOffset = (stageSize - targetSize) / 2;
    let activeUpload = null;
    let sourceWidth = 0;
    let sourceHeight = 0;
    let minimumScale = 1;
    let zoom = 1;
    let panX = 0;
    let panY = 0;
    let pointer = null;

    const render = () => {
        if (!sourceWidth || !sourceHeight) return;
        const scale = minimumScale * zoom;
        const width = sourceWidth * scale;
        const height = sourceHeight * scale;
        const maxPanX = Math.max(0, (width - targetSize) / 2);
        const maxPanY = Math.max(0, (height - targetSize) / 2);
        panX = Math.max(-maxPanX, Math.min(maxPanX, panX));
        panY = Math.max(-maxPanY, Math.min(maxPanY, panY));
        image.style.width = `${width}px`;
        image.style.height = `${height}px`;
        image.style.left = `${(stageSize - width) / 2 + panX}px`;
        image.style.top = `${(stageSize - height) / 2 + panY}px`;
    };

    const setZoom = value => {
        zoom = Math.max(1, Math.min(4, Number(value) || 1));
        zoomInput.value = String(zoom);
        render();
    };

    const openCropper = (upload, file) => {
        if (!file?.type?.startsWith('image/')) return;
        const reader = new FileReader();
        reader.addEventListener('load', () => {
            image.onload = () => {
                activeUpload = upload;
                sourceWidth = image.naturalWidth;
                sourceHeight = image.naturalHeight;
                minimumScale = Math.max(targetSize / sourceWidth, targetSize / sourceHeight);
                panX = 0;
                panY = 0;
                setZoom(1);
                dialog.showModal();
            };
            image.src = String(reader.result);
        });
        reader.readAsDataURL(file);
    };

    document.querySelectorAll('[data-icon-upload]').forEach(upload => {
        const file = upload.querySelector('[data-icon-file]');
        upload.querySelector('[data-icon-choose]')?.addEventListener('click', () => file?.click());
        file?.addEventListener('change', () => {
            openCropper(upload, file.files?.[0]);
            file.value = '';
        });
        upload.querySelector('[data-icon-remove]')?.addEventListener('click', () => {
            setIconUploadValue(upload, '');
            upload.querySelector('[data-icon-remove-input]').value = '1';
        });
        setIconUploadValue(upload, '');
    });

    stage?.addEventListener('pointerdown', event => {
        pointer = {id:event.pointerId, x:event.clientX, y:event.clientY, panX, panY};
        stage.setPointerCapture(event.pointerId);
    });
    stage?.addEventListener('pointermove', event => {
        if (!pointer || pointer.id !== event.pointerId) return;
        panX = pointer.panX + event.clientX - pointer.x;
        panY = pointer.panY + event.clientY - pointer.y;
        render();
    });
    const finishPointer = event => {
        if (pointer?.id === event.pointerId) pointer = null;
    };
    stage?.addEventListener('pointerup', finishPointer);
    stage?.addEventListener('pointercancel', finishPointer);
    stage?.addEventListener('wheel', event => {
        event.preventDefault();
        setZoom(zoom + (event.deltaY < 0 ? 0.1 : -0.1));
    }, {passive:false});
    zoomInput?.addEventListener('input', () => setZoom(zoomInput.value));
    dialog.querySelector('[data-icon-zoom-out]')?.addEventListener('click', () => setZoom(zoom - 0.25));
    dialog.querySelector('[data-icon-zoom-in]')?.addEventListener('click', () => setZoom(zoom + 0.25));
    dialog.querySelector('[data-icon-crop-cancel]')?.addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-icon-crop-apply]')?.addEventListener('click', () => {
        if (!activeUpload || !sourceWidth || !sourceHeight) return;
        const scale = minimumScale * zoom;
        const displayedWidth = sourceWidth * scale;
        const displayedHeight = sourceHeight * scale;
        const imageLeft = (stageSize - displayedWidth) / 2 + panX;
        const imageTop = (stageSize - displayedHeight) / 2 + panY;
        const canvas = document.createElement('canvas');
        canvas.width = targetSize;
        canvas.height = targetSize;
        canvas.getContext('2d').drawImage(
            image,
            (targetOffset - imageLeft) / scale,
            (targetOffset - imageTop) / scale,
            targetSize / scale,
            targetSize / scale,
            0,
            0,
            targetSize,
            targetSize,
        );
        const iconData = canvas.toDataURL('image/png');
        setIconUploadValue(activeUpload, iconData);
        activeUpload.querySelector('[data-icon-data]').value = iconData;
        dialog.close();
    });
}

initializeIconCropper();

function syncEventVisibilityControls(form) {
    const sticky = form.querySelector('[data-event-sticky-toggle]')?.checked === true;
    const field = form.querySelector('[data-sticky-visibility-field]');
    const toggle = form.querySelector('[data-visible-after-toggle]');
    const picker = form.querySelector('[data-visible-after-picker]');
    const input = picker?.querySelector('[data-time-picker-input]');
    field?.classList.toggle('hidden', !sticky);
    if (toggle) toggle.disabled = !sticky;
    const enabled = sticky && toggle?.checked === true;
    picker?.classList.toggle('hidden', !enabled);
    if (input) input.disabled = !enabled;
}

function configureEventDefinition(data = null) {
    const root = document.querySelector('[data-overlay="event-definition"]');
    const form = root?.querySelector('[data-event-definition-form]');
    if (!root || !form) return;
    const editing = Boolean(data?.update_url);
    form.reset();
    form.action = editing ? data.update_url : form.dataset.createAction;
    let method = form.querySelector('input[name="_method"]');
    if (editing) {
        if (!method) { method = cloneTemplate('ajax-method-template'); form.append(method); }
        method.value = 'PATCH';
    } else method?.remove();
    form.querySelector('[name="name"]').value = data?.name || '';
    setEmojiPickerValue(form.querySelector('[data-emoji-picker]'), data?.emoji || '✅');
    setIconUploadValue(form.querySelector('[data-icon-upload]'), data?.icon_data || '');
    const color = form.querySelector('[name="color"]');
    color.value = data?.color || '#4f46e5';
    const colorPreview = document.getElementById(color.dataset.colorInput);
    if (colorPreview) { colorPreview.style.backgroundColor = color.value; colorPreview.title = color.value; }
    const recurrence = form.querySelector('[name="recurrence_type"]');
    recurrence.value = data?.recurrence_type || 'daily';
    const days = (data?.recurrence_days || []).map(Number);
    form.querySelectorAll('[name="weekdays[]"]').forEach(input => { input.checked = days.includes(Number(input.value)); });
    form.querySelector('[name="month_days_text"]').value = recurrence.value === 'monthly' ? days.join(', ') : '';
    form.querySelector('[name="options_text"]').value = data?.options_text || '';
    form.querySelector('[name="daily_default_count"]').value = data?.daily_default_count || 1;
    form.querySelector('[name="is_sticky"]').checked = Boolean(data?.is_sticky);
    const visibleAfter = form.querySelector('[name="visible_after"]');
    const visibleAfterToggle = form.querySelector('[data-visible-after-toggle]');
    visibleAfter.value = data?.visible_after || '18:00';
    visibleAfterToggle.checked = Boolean(data?.visible_after);
    visibleAfter.dispatchEvent(new Event('change', {bubbles:true}));
    syncEventVisibilityControls(form);
    form.querySelector('[data-time-slots]')?.setTimeSlotValues(data?.scheduled_times || []);
    recurrence.dispatchEvent(new Event('change', {bubbles:true}));
    root.querySelector('[data-event-definition-title]').textContent = editing ? `Edit ${data.name}` : 'Add event';
    root.querySelector('[data-event-definition-submit]').textContent = editing ? 'Save changes' : 'Create event';
    const deleteSection = root.querySelector('[data-event-definition-delete-section]');
    deleteSection?.classList.toggle('hidden', !editing);
    const deleteForm = root.querySelector('[data-event-definition-delete-form]');
    if (deleteForm) deleteForm.action = data?.delete_url || '';
    openOverlay('event-definition');
    setTimeout(() => form.querySelector('[name="name"]')?.focus(), 320);
}

function configureGoal(data = null) {
    const root = document.querySelector('[data-overlay="goal-definition"]');
    const form = root?.querySelector('[data-goal-form]');
    if (!root || !form) return;
    const editing = Boolean(data?.update_url);
    form.reset();
    form.action = editing ? data.update_url : form.dataset.createAction;
    let method = form.querySelector('input[name="_method"]');
    if (editing) {
        if (!method) { method = cloneTemplate('ajax-method-template'); form.append(method); }
        method.value = 'PATCH';
    } else method?.remove();
    form.querySelector('[name="name"]').value = data?.name || '';
    setEmojiPickerValue(form.querySelector('[data-emoji-picker]'), data?.emoji || '🎯');
    setIconUploadValue(form.querySelector('[data-icon-upload]'), data?.icon_data || '');
    const color = form.querySelector('[name="color"]');
    color.value = data?.color || '#4f46e5';
    const preview = document.getElementById(color.dataset.colorInput);
    if (preview) preview.style.backgroundColor = color.value;
    form.querySelector('[name="target_points"]').value = data?.target_points || 5;
    form.querySelector('[name="period"]').value = data?.period || 'weekly';
    form.querySelector('[name="start_date"]').value = data?.start_date || '';
    form.querySelector('[name="end_date"]').value = data?.end_date || '';
    form.querySelector('[name="task_definition_id"]').value = data?.task_definition_id || '';
    form.querySelector('[data-goal-project-picker]')?.setProjects(data?.github_projects || []);
    form.querySelector('[name="manual_enabled"]').checked = Boolean(data?.manual_enabled);
    root.querySelector('[data-goal-title]').textContent = editing ? `Edit ${data.name}` : 'Add goal';
    root.querySelector('[data-goal-submit]').textContent = editing ? 'Save changes' : 'Create goal';
    root.querySelector('[data-goal-delete-section]')?.classList.toggle('hidden', !editing);
    const deleteForm = root.querySelector('[data-goal-delete-form]');
    if (deleteForm) deleteForm.action = data?.delete_url || '';
    openOverlay('goal-definition');
    setTimeout(() => form.querySelector('[name="name"]')?.focus(), 320);
}

function initGoalProjectPicker(root) {
    const filter = root.querySelector('[data-goal-project-filter]');
    const list = root.querySelector('[data-goal-project-list]');
    const selectedRoot = root.querySelector('[data-goal-project-selected]');
    const inputs = root.querySelector('[data-goal-project-inputs]');
    let selected = [];
    const render = () => {
        selectedRoot.replaceChildren(); inputs.replaceChildren();
        selected.forEach(name => {
            const bubble = element('span', 'inline-flex items-center gap-1 rounded-full bg-indigo-100 py-1 pl-3 pr-1 text-sm font-semibold text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200');
            const remove = element('button', 'grid h-6 w-6 place-items-center rounded-full hover:bg-indigo-200 dark:hover:bg-indigo-800', '×');
            remove.type = 'button'; remove.dataset.goalProjectRemove = name; remove.setAttribute('aria-label', `Remove ${name}`);
            bubble.append(document.createTextNode(name), remove); selectedRoot.append(bubble);
            const input = element('input'); input.type = 'hidden'; input.name = 'github_projects[]'; input.value = name; inputs.append(input);
        });
        root.querySelectorAll('[data-goal-project-add]').forEach(button => { button.disabled = selected.some(name => name.toLowerCase() === button.dataset.goalProjectAdd.toLowerCase()); });
    };
    root.setProjects = values => { selected = [...new Set((values || []).map(value => String(value).split('/').pop()).filter(Boolean))]; if (filter) filter.value = ''; root.querySelectorAll('[data-goal-project-add]').forEach(button => button.classList.remove('hidden')); render(); };
    list?.addEventListener('click', event => { const button = event.target.closest('[data-goal-project-add]'); if (!button || button.disabled) return; selected.push(button.dataset.goalProjectAdd); render(); });
    selectedRoot?.addEventListener('click', event => { const button = event.target.closest('[data-goal-project-remove]'); if (!button) return; selected = selected.filter(name => name !== button.dataset.goalProjectRemove); render(); });
    filter?.addEventListener('input', () => { const query = filter.value.trim().toLowerCase(); root.querySelectorAll('[data-goal-project-add]').forEach(button => button.classList.toggle('hidden', !button.dataset.goalProjectAdd.toLowerCase().includes(query))); });
    root.setProjects([]);
}

document.querySelectorAll('[data-goal-project-picker]').forEach(initGoalProjectPicker);

document.addEventListener('click', event => {
    document.querySelectorAll('[data-emoji-picker]').forEach(picker => {
        if (!picker.contains(event.target)) {
            const menu = picker.querySelector('[data-emoji-menu]');
            menu?.classList.add('hidden');
            menu?.classList.remove('flex');
            picker.closest('.panel')?.classList.remove('emoji-picker-host-active');
            picker.querySelector('[data-emoji-toggle]')?.setAttribute('aria-expanded', 'false');
        }
    });
});

document.addEventListener('change', event => {
    const source = event.target.closest('[data-shared-time-source]'); if (!source) return;
    document.querySelectorAll(`[data-shared-time-field="${source.dataset.sharedTimeSource}"]`).forEach(field => { field.value = source.value; });
});

function initTimeSlots(editor) {
    if (editor.dataset.timeSlotsInitialized === 'true') return;
    editor.dataset.timeSlotsInitialized = 'true';
    const list = editor.querySelector('[data-time-slot-list]'), name = editor.dataset.name || 'scheduled_times[]';
    const addSlot = (value, open = false) => {
        const row = cloneTemplate('time-slot-row-template'); if (!row) return;
        const picker = row.querySelector('[data-time-picker]'), choose = row.querySelector('[data-time-picker-open]'), input = row.querySelector('[data-time-picker-input]');
        input.name = name; input.value = value;
        row.querySelector('[data-time-slot-remove]').addEventListener('click', () => row.remove());
        list.append(row); initTimePicker(picker); if (open) choose.click();
    };
    editor.setTimeSlotValues = values => { list.replaceChildren(); values.forEach(value => addSlot(value)); };
    let values = []; try { values = JSON.parse(editor.dataset.values || '[]'); } catch (_) {}
    editor.setTimeSlotValues(values);
    editor.querySelector('[data-time-slot-add]')?.addEventListener('click', () => { const date = new Date(), five = Math.ceil(date.getMinutes() / 5) * 5, hour = (date.getHours() + Math.floor(five / 60)) % 24; addSlot(`${String(hour).padStart(2,'0')}:${String(five % 60).padStart(2,'0')}`, true); });
}

document.querySelectorAll('[data-time-slots]').forEach(initTimeSlots);
document.querySelectorAll('[data-event-definition-form]').forEach(form => {
    form.querySelector('[data-event-sticky-toggle]')?.addEventListener('change', () => syncEventVisibilityControls(form));
    form.querySelector('[data-visible-after-toggle]')?.addEventListener('change', () => syncEventVisibilityControls(form));
    syncEventVisibilityControls(form);
});

function syncComposerTime() {
    const time = document.querySelector('[data-composer-time]')?.value || '';
    document.querySelectorAll('[data-composer-time-field]').forEach(field => { field.value = time; });
}

const eventAutosaveTimers = new WeakMap();

function isEventAutosaveForm(form) {
    return form?.matches('[data-event-autosave-form]');
}

function scheduleEventAutosave(form, delay = 650) {
    if (!isEventAutosaveForm(form)) return;
    clearTimeout(eventAutosaveTimers.get(form));
    eventAutosaveTimers.set(form, setTimeout(() => {
        const status = form.querySelector('[data-autosave-status]');
        const notes = form.querySelector('textarea[name="notes"]')?.value ?? '';
        const occurredAt = form.querySelector('[name="occurred_at"]')?.value;
        const emoji = form.querySelector('[name="emoji"]')?.value;
        if (status) { status.classList.remove('hidden', 'text-emerald-600', 'dark:text-emerald-400', 'text-rose-600', 'dark:text-rose-400'); status.classList.add('text-indigo-600', 'dark:text-indigo-400'); status.textContent = 'Queued for sync.'; }
        updateLocalTimelineBlock(form.action, {content:notes, emoji, time:occurredAt});
        const action = form.action;
        queueBackgroundSync(`event-edit:${action}`, () => ajax(action, {method:'PATCH', keepalive:true, headers:{'Content-Type':'application/json'}, body:JSON.stringify({notes, emoji, occurred_at:occurredAt})}), body => {
            if (status) { status.classList.remove('text-indigo-600', 'dark:text-indigo-400'); status.classList.add('text-emerald-600', 'dark:text-emerald-400'); status.textContent = 'Saved automatically.'; }
            const updatedLabel = form.closest('[data-overlay="composer"]')?.querySelector('[data-composer-updated]');
            if (updatedLabel && body.updated_time) { updatedLabel.textContent = `Updated ${body.updated_time}`; updatedLabel.classList.remove('hidden'); }
        });
    }, delay));
}

function renderComposerLocation(root, kind, location) {
    const panel = root?.querySelector('[data-composer-location]');
    const hasLocation = kind === 'event' && location?.latitude != null && location?.longitude != null;
    panel?.classList.toggle('hidden', !hasLocation);
    if (!hasLocation) return;
    const place = [...new Set([location.suburb, location.city].filter(Boolean))].join(', ');
    const display = panel.querySelector('[data-composer-location-display]');
    display.textContent = place || `${Number(location.latitude).toFixed(5)}, ${Number(location.longitude).toFixed(5)}`;
    display.classList.toggle('font-mono', !place);
    display.classList.toggle('text-xs', !place);
    panel.querySelector('[data-composer-location-attribution]')?.classList.toggle('hidden', !place);
}

function renderComposerEventImage(root, kind, iconData) {
    const panel = root?.querySelector('[data-composer-event-image]');
    const image = panel?.querySelector('[data-composer-event-image-display]');
    const showImage = kind === 'event' && Boolean(iconData);
    panel?.classList.toggle('hidden', !showImage);
    if (!image) return;
    image.src = showImage ? iconData : '';
    image.alt = showImage ? 'Event image' : '';
}

function configureComposer({time, mode = 'create', kind = 'block', eventName = '', action = '', content = '', emoji = '📝', iconData = '', updated = '', hideUrl = '', deleteUrl = '', isHidden = false, location = null, pendingEventId = '', isNew = false} = {}) {
    const root = document.querySelector('[data-overlay="composer"]');
    const timeInput = root?.querySelector('[data-composer-time]');
    const form = root?.querySelector('[data-composer-note-form]');
    const textarea = root?.querySelector('[data-composer-content]');
    const updatedLabel = root?.querySelector('[data-composer-updated]');
    if (!root || !timeInput || !form || !textarea) return;
    clearTimeout(eventAutosaveTimers.get(form));
    form.dataset.eventAutosave = 'false';
    form.dataset.composerMode = mode;
    if (pendingEventId) form.dataset.pendingEventId = pendingEventId; else delete form.dataset.pendingEventId;
    form.dataset.newEntry = isNew ? 'true' : 'false';
    timeInput.value = time || new Date().toTimeString().slice(0, 5);
    timeInput.dispatchEvent(new Event('change', {bubbles:true}));
    form.action = mode === 'edit' ? action : form.dataset.createAction;
    let method = form.querySelector('input[name="_method"]');
    if (mode === 'edit') {
        if (!method) { method = cloneTemplate('ajax-method-template'); form.append(method); }
        method.value = 'PATCH';
    } else method?.remove();
    textarea.name = kind === 'event' ? 'notes' : 'content';
    textarea.value = content || '';
    textarea.required = mode === 'create';
    setEmojiPickerValue(form.querySelector('[data-emoji-picker]'), emoji || (kind === 'event' ? '✅' : '📝'));
    root.querySelector('[data-composer-title]').textContent = mode === 'edit' ? 'Edit log entry' : 'Add to this log';
    if (updatedLabel) { updatedLabel.textContent = updated ? `Updated ${updated}` : ''; updatedLabel.classList.toggle('hidden', mode !== 'edit' || !updated); }
    const eventSource = root.querySelector('[data-composer-event-source]');
    const showEventSource = kind === 'event' && Boolean(eventName);
    eventSource?.classList.toggle('hidden', !showEventSource);
    if (showEventSource) eventSource.querySelector('[data-composer-event-name]').textContent = eventName;
    renderComposerEventImage(root, kind, iconData);
    renderComposerLocation(root, kind, location);
    const noteHeading = root.querySelector('[data-note-heading]');
    noteHeading.textContent = kind === 'event' ? 'Event notes' : (mode === 'edit' ? 'Edit note' : 'Write a note');
    noteHeading.classList.toggle('hidden', mode === 'edit');
    const submit = root.querySelector('[data-composer-submit]');
    submit.textContent = 'Add to log';
    submit.classList.toggle('hidden', mode === 'edit');
    const autosaveStatus = form.querySelector('[data-autosave-status]');
    if (autosaveStatus) { autosaveStatus.classList.toggle('hidden', mode !== 'edit'); autosaveStatus.textContent = mode === 'edit' ? 'Changes save when you close this panel.' : ''; }
    root.querySelector('[data-composer-cancel]')?.classList.toggle('hidden', mode !== 'edit');
    root.querySelector('[data-composer-time-now]')?.classList.toggle('hidden', mode !== 'edit');
    const entryActions = root.querySelector('[data-composer-entry-actions]');
    const visibility = root.querySelector('[data-composer-visibility]');
    const deleteButton = root.querySelector('[data-composer-delete]');
    const showVisibility = mode === 'edit' && !isNew && Boolean(hideUrl);
    const showDelete = mode === 'edit' && Boolean(deleteUrl);
    const showActions = showVisibility || showDelete;
    entryActions?.classList.toggle('hidden', !showActions); entryActions?.classList.toggle('grid', showActions);
    if (visibility) { visibility.classList.toggle('hidden', !showVisibility); visibility.textContent = isHidden ? 'Restore' : 'Hide'; visibility.dataset.plannerVisibility = hideUrl; visibility.dataset.method = 'PATCH'; visibility.dataset.payload = JSON.stringify({hidden:!isHidden}); }
    if (deleteButton) { deleteButton.classList.toggle('hidden', !showDelete); deleteButton.dataset.delete = deleteUrl; }
    syncComposerTime();
    form.dataset.originalContent = textarea.value;
    form.dataset.originalTime = timeInput.value;
    form.dataset.originalEmoji = form.querySelector('[name="emoji"]')?.value || '';
    openOverlay('composer');
    setTimeout(() => textarea.focus(), 320);
}

function browsingDuration(seconds) {
    const total = Math.max(0, Number(seconds) || 0);
    if (total < 60) return 'Under 1 min';
    const minutes = Math.floor(total / 60);
    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function openBrowsingDetails(item) {
    const root = document.querySelector('[data-overlay="browsing"]');
    if (!root) return;
    const visitsMode = item.dataset.browsingMode === 'visits';
    const applicationsMode = item.dataset.browsingMode === 'applications';
    const total = Number(item.dataset.browsingTotal || 0);
    root.querySelector('[data-browsing-detail-source]').textContent = applicationsMode ? 'Desktop sensor' : (visitsMode ? 'Synced Chrome history' : 'Chrome sensor');
    root.querySelector('[data-browsing-detail-title]').textContent = applicationsMode ? 'Applications' : (visitsMode ? 'Mobile browsing' : 'Browsing');
    root.querySelector('[data-browsing-detail-start]').textContent = `${visitsMode ? 'First visit' : 'Started'} ${item.dataset.browsingStart}`;
    root.querySelector('[data-browsing-detail-total]').textContent = visitsMode ? `${total} ${total === 1 ? 'visit' : 'visits'}` : browsingDuration(total);
    root.querySelector('[data-browsing-detail-privacy]').textContent = applicationsMode
        ? 'Only application names, process names, and time totals are stored. Window titles and executable paths stay on your computer.'
        : visitsMode
        ? 'Only domains, visit timestamps, and counts are stored. Individual page paths, titles, and query strings are not added to your log.'
        : 'Only site domains and time totals are stored. Individual page paths, titles, and query strings are not added to your log.';
    const rows = JSON.parse(item.dataset.browsingDomains || '[]').map(domain => {
        const row = cloneTemplate('browsing-domain-row-template');
        row.querySelector('[data-browsing-domain-name]').textContent = domain.domain;
        const count = Number(domain.visits || 0);
        row.querySelector('[data-browsing-domain-time]').textContent = visitsMode ? `${count} ${count === 1 ? 'visit' : 'visits'}` : browsingDuration(domain.seconds);
        return row;
    });
    root.querySelector('[data-browsing-domain-list]').replaceChildren(...rows);
    openOverlay('browsing');
}

function openGithubDetails(item) {
    const root = document.querySelector('[data-overlay="github"]');
    if (!root) return;
    const events = JSON.parse(item.dataset.githubEvents || '[]');
    root.querySelector('[data-github-detail-project]').textContent = item.dataset.githubProject || 'GitHub activity';
    root.querySelector('[data-github-detail-start]').textContent = `Started ${item.dataset.githubStart}`;
    root.querySelector('[data-github-detail-count]').textContent = `${events.length} ${events.length === 1 ? 'commit' : 'commits'}`;
    const rows = events.map(event => {
        const row = cloneTemplate('github-event-row-template');
        row.querySelector('[data-github-event-time]').textContent = event.time;
        row.querySelector('[data-github-event-sha]').textContent = String(event.sha || '').slice(0, 7);
        row.querySelector('[data-github-event-message]').textContent = event.message || `Commit ${String(event.sha || '').slice(0, 7)}`;
        const link = row.querySelector('[data-github-event-link]');
        if (event.url) link.href = event.url; else link.classList.add('hidden');
        return row;
    });
    root.querySelector('[data-github-event-list]').replaceChildren(...rows);
    openOverlay('github');
}

function openGoogleCalendarDetails(item) {
    const root = document.querySelector('[data-overlay="google-calendar"]');
    if (!root) return;
    const event = JSON.parse(item.dataset.googleCalendarEvent || '{}');
    root.querySelector('[data-google-calendar-title]').textContent = event.title || 'Calendar event';
    root.querySelector('[data-google-calendar-start]').textContent = event.start || '';
    root.querySelector('[data-google-calendar-end]').textContent = event.end || '';
    root.querySelector('[data-google-calendar-end-wrap]').classList.toggle('hidden', !event.end);
    const location = root.querySelector('[data-google-calendar-location]');
    location.textContent = event.location || '';
    location.parentElement.classList.toggle('hidden', !event.location);
    const description = root.querySelector('[data-google-calendar-description]');
    description.textContent = event.description || '';
    description.parentElement.classList.toggle('hidden', !event.description);
    const link = root.querySelector('[data-google-calendar-link]');
    link.classList.toggle('hidden', !event.url);
    if (event.url) link.href = event.url;
    openOverlay('google-calendar');
}

function openImagePreview(trigger) {
    let root = document.querySelector('[data-overlay="image-preview"]');
    if (!root) {
        root = cloneTemplate('image-preview-overlay-template');
        document.body.append(root);
    }

    const url = trigger.dataset.imageUrl;
    const name = trigger.dataset.imageName || 'image';
    const image = root.querySelector('[data-image-preview-image]');
    const download = root.querySelector('[data-image-preview-download]');
    image.src = url;
    image.alt = name;
    download.href = url;
    download.download = name;
    openOverlay('image-preview');
}

function findBlockItem(state, url, field = 'edit_url') {
    return state.timeline.find(item => item.kind === 'block' && item.block?.[field] === url);
}

function findPendingBlockItem(state, pendingEventId) {
    return state.timeline.find(item => item.kind === 'block' && item.block?.client_id === pendingEventId);
}

function addOptimisticTimelineBlock(state, {id, time, emoji, iconData = '', content, kind = 'text', editUrl = '', hideUrl = '', deleteUrl = '', eventName = '', selectedValue = ''}) {
    const item = {
        kind:'block', time,
        block:{
            id, client_id:id, type:kind === 'event' ? 'event' : 'text', emoji, icon_data:iconData, content, is_hidden:false, updated:'syncing', optimistic:true,
            edit_kind:kind === 'event' ? 'event' : 'block', edit_url:editUrl, hide_url:hideUrl, delete_url:deleteUrl,
            event:kind === 'event' ? {name:eventName, value:selectedValue || null, location:null} : null,
            attachments:[], browsing_domains:[], mobile_browsing_domains:[], github_events:[], calendar_event:null,
        },
    };
    const followingIndex = state.timeline.findIndex(candidate => ['block', 'schedule', 'now'].includes(candidate.kind) && (candidate.time || '24:00') > time);
    state.timeline.splice(followingIndex < 0 ? state.timeline.length : followingIndex, 0, item);
}

function reorderTimeline(state) {
    const previousGaps = state.timeline.filter(item => item.kind === 'gap');
    const timedItems = state.timeline
        .map((item, index) => ({item, index}))
        .filter(({item}) => item.kind !== 'gap')
        .sort((left, right) => (left.item.time || '24:00').localeCompare(right.item.time || '24:00') || left.index - right.index)
        .map(({item}) => item);
    const currentTime = timedItems.find(item => item.kind === 'now')?.time;
    const fallbackGapState = previousGaps[0]?.state || 'future';
    const gapState = end => state.is_today && currentTime ? (end <= currentTime ? 'past' : 'future') : fallbackGapState;
    const timeline = [];
    let cursor = '00:00';
    timedItems.forEach(item => {
        const time = item.time || cursor;
        if (time > cursor) timeline.push({kind:'gap', from:cursor, to:time, state:gapState(time)});
        timeline.push(item);
        if (time > cursor) cursor = time;
    });
    if (cursor < '24:00') timeline.push({kind:'gap', from:cursor, to:'24:00', state:gapState('24:00')});
    state.timeline = timeline;
}

function updateLocalTimelineBlock(url, {content, emoji, time}, pendingEventId = '') {
    return mutateDayState(state => {
        const item = pendingEventId ? findPendingBlockItem(state, pendingEventId) : findBlockItem(state, url);
        if (!item) return;
        if (content !== undefined) item.block.content = content;
        if (emoji) item.block.emoji = emoji;
        if (time && time !== item.time) {
            item.time = time;
            reorderTimeline(state);
        }
    });
}

function reconcileOptimisticBlock(id, body) {
    const item = activeDayState?.timeline.find(candidate => candidate.kind === 'block' && candidate.block?.id === id);
    if (!item) return;
    item.block.id = body.block?.id || body.block_id || item.block.id;
    item.block.edit_url = body.edit_url || item.block.edit_url;
    item.block.hide_url = body.hide_url || item.block.hide_url;
    item.block.delete_url = body.delete_url || item.block.delete_url;
    item.block.icon_data = body.icon_data || item.block.icon_data;
    item.block.updated = body.updated_time || '';
    item.block.optimistic = false;
    const row = document.querySelector(`#block-${CSS.escape(String(id))}`)?.closest('.timeline-item');
    if (row) {
        row.querySelector('article').id = `block-${item.block.id}`;
        row.querySelector('article').classList.remove('ring-2', 'ring-indigo-300');
        row.dataset.editUrl = item.block.edit_url; row.dataset.hideUrl = item.block.hide_url; row.dataset.deleteUrl = item.block.delete_url; row.dataset.editUpdated = item.block.updated;
    }
}

function saveComposerDraft(root, {close = true} = {}) {
    const form = root?.querySelector('[data-composer-note-form]');
    if (!form || form.dataset.composerMode !== 'edit') {
        if (close) closeOverlay(root);
        return true;
    }
    const status = form.querySelector('[data-autosave-status]');
    const textarea = form.querySelector('[data-composer-content]');
    const occurredAt = form.querySelector('[name="occurred_at"]')?.value;
    const emoji = form.querySelector('[name="emoji"]')?.value || '';
    if (textarea.value === form.dataset.originalContent && occurredAt === form.dataset.originalTime && emoji === form.dataset.originalEmoji) {
        if (close) closeOverlay(root);
        return true;
    }
    const payload = {[textarea.name]: textarea.value, emoji, occurred_at: occurredAt};
    const action = form.action;
    const pendingEventId = form.dataset.pendingEventId || '';
    const state = activeDayState;
    if (close) closeOverlay(root);
    updateLocalTimelineBlock(action, {content:textarea.value, emoji, time:occurredAt}, pendingEventId);
    form.dataset.originalContent = textarea.value;
    form.dataset.originalTime = occurredAt;
    form.dataset.originalEmoji = emoji;
    if (status) { status.classList.remove('hidden', 'text-emerald-600', 'dark:text-emerald-400'); status.classList.add('text-indigo-600', 'dark:text-indigo-400'); status.textContent = 'Queued for sync.'; }
    const syncKey = pendingEventId ? `pending-event-edit:${pendingEventId}` : `log-edit:${action}`;
    const request = pendingEventId
        ? async () => {
            const created = await pendingEventCreates.get(pendingEventId);
            return ajax(created.edit_url, {method:'PATCH', keepalive:true, headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
        }
        : () => ajax(action, {method:'PATCH', keepalive:true, headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
    queueBackgroundSync(syncKey, request, body => {
        const item = state ? (pendingEventId ? findPendingBlockItem(state, pendingEventId) : findBlockItem(state, action)) : null;
        if (item && body.updated_time) item.block.updated = body.updated_time;
        if (activeDayState === state) {
            const row = [...document.querySelectorAll('[data-edit-url]')].find(candidate => candidate.dataset.editUrl === action);
            if (row && body.updated_time) row.dataset.editUpdated = body.updated_time;
        }
        if (pendingEventId) pendingEventCreates.delete(pendingEventId);
    });
    return true;
}

document.addEventListener('submit', async e => {
    const form = e.target.closest('form[data-ajax]'); if (!form) return;
    if (isEventAutosaveForm(form)) { e.preventDefault(); scheduleEventAutosave(form, 0); return; }
    e.preventDefault();
    if (form.matches('[data-composer-note-form]') && form.dataset.composerMode !== 'edit') {
        const requestBody = new FormData(form);
        const action = form.action;
        const previousState = snapshotDayState();
        const optimisticId = `local-${crypto.randomUUID?.() || Date.now()}`;
        const draft = {
            id:optimisticId,
            time:String(requestBody.get('occurred_at') || '12:00'),
            emoji:String(requestBody.get('emoji') || '📝'),
            content:String(requestBody.get('content') || ''),
            editUrl:action,
        };
        mutateDayState(state => addOptimisticTimelineBlock(state, draft));
        closeOverlay(document.querySelector('[data-overlay="composer"]'));
        try {
            const body = await ajax(action, {method:'POST', body:requestBody});
            reconcileOptimisticBlock(optimisticId, body);
            toast(body.message || 'Saved.');
        } catch (error) {
            restoreDayState(previousState);
            toast(error.message, true);
        }
        return;
    }
    const button = form.querySelector('[type=submit]'); setButtonBusy(button, true);
    try {
        const composer = form.closest('[data-overlay="composer"]');
        const body = await ajax(form.action, {method: form.method || 'POST', body: new FormData(form)});
        toast(body.message || 'Saved.');
        if (await refreshDayView()) {
            const overlay = form.closest('[data-overlay]');
            if (overlay) closeOverlay(overlay);
        } else if (body.reload || form.matches('[data-composer-note-form]')) reloadAtCurrentScroll();
        else form.reset();
    }
    catch (error) { toast(error.message, true); } finally { setButtonBusy(button, false); }
});

document.querySelectorAll('[data-sensor-enable]').forEach(toggle => toggle.addEventListener('change', () => toggle.form?.requestSubmit()));

document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-confirm-sensor-unlink]');
    if (!form || form.dataset.confirmed === 'true') return;
    event.preventDefault();
    const confirmed = await modal({title:'Unlink GitHub?', message:'The encrypted token and sensor settings will be removed. Existing GitHub log entries will remain.', confirmText:'Unlink'});
    if (confirmed) { form.dataset.confirmed = 'true'; form.requestSubmit(); }
});

document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-confirm-browser-unlink]');
    if (!form || form.dataset.confirmed === 'true') return;
    event.preventDefault();
    const confirmed = await modal({title:'Unlink Chrome extension?', message:'The extension key will stop working. Existing browsing entries and domain totals will remain.', confirmText:'Unlink'});
    if (confirmed) { form.dataset.confirmed = 'true'; form.requestSubmit(); }
});

document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-confirm-demo-delete]');
    if (!form || form.dataset.confirmed === 'true') return;
    event.preventDefault();
    const confirmed = await modal({title:'Reset demo data?', message:'Rebuild the shared read-only demo around today. Existing demo records will be replaced; shared image assets remain available.', confirmText:'Reset demo data'});
    if (confirmed) { form.dataset.confirmed = 'true'; form.requestSubmit(); }
});

document.addEventListener('submit', event => {
    const form = event.target.closest('[data-event-autosave-form]'); if (!form) return;
    event.preventDefault(); scheduleEventAutosave(form, 0);
});

document.addEventListener('input', event => {
    const form = event.target.closest('[data-event-autosave-form]');
    if (form && event.target.matches('textarea[name="notes"]')) scheduleEventAutosave(form);
});

document.addEventListener('change', event => {
    const form = event.target.closest('[data-event-autosave-form]');
    if (form && event.target.matches('[name="occurred_at"], [name="emoji"]')) scheduleEventAutosave(form, 0);
});

document.addEventListener('submit', async e => {
    const form = e.target.closest('form[data-confirm-event-delete]');
    if (!form || form.dataset.confirmed === 'true') return;
    e.preventDefault();
    const confirmed = await modal({
        title:'Delete this event?',
        message:'The event button and setup will be deleted. Its recorded entries, notes, timestamps, and media will remain as editable text entries.',
        confirmText:'Delete event',
    });
    if (confirmed) { form.dataset.confirmed = 'true'; form.requestSubmit(); }
});

document.addEventListener('submit', async event => {
    const form = event.target.closest('form[data-confirm-delete]');
    if (!form || form.dataset.confirmed === 'true') return;
    event.preventDefault();
    const confirmed = await modal({
        title:form.dataset.confirmTitle || 'Delete this item?',
        message:form.dataset.confirmMessage || 'This cannot be undone.',
        confirmText:form.dataset.confirmText || 'Delete',
    });
    if (confirmed) { form.dataset.confirmed = 'true'; form.requestSubmit(); }
});

document.addEventListener('submit', async e => {
    const form = e.target.closest('form[data-smart-chat-form]'); if (!form) return;
    e.preventDefault();
    const button = form.querySelector('[type=submit]'), result = form.parentElement.querySelector('[data-chat-result]');
    setButtonBusy(button, true);
    const showResult = name => { result.classList.remove('hidden'); result.querySelectorAll('[data-chat-view]').forEach(view => view.classList.toggle('hidden', view.dataset.chatView !== name)); };
    showResult('status');
    try {
        const body = await ajax(form.action, {method:'POST', body:new FormData(form)});
        if (body.kind === 'answer') {
            result.querySelector('[data-chat-answer]').textContent = body.answer; showResult('answer'); form.querySelector('textarea[name=message]').value = '';
        } else if (body.kind === 'action') {
            const summary = result.querySelector('[data-chat-summary]'); summary.textContent = body.summary;
            const cancel = result.querySelector('[data-chat-cancel]'), confirm = result.querySelector('[data-chat-confirm]');
            cancel.onclick = () => result.classList.add('hidden');
            confirm.onclick = async () => {
                confirm.disabled = true; confirm.textContent = 'Applying...';
                try { const confirmed = await ajax(body.confirm_url, {method:'POST'}); toast(confirmed.message || 'Actions completed.'); if (confirmed.reload) { await refreshDayViewOrReload(); closeOverlay(form.closest('[data-overlay]')); } }
                catch (error) { toast(error.message, true); confirm.disabled = false; confirm.textContent = 'Confirm & run'; }
            };
            confirm.disabled = false; confirm.textContent = 'Confirm & run'; showResult('action');
        }
        toast(body.message || 'Chat response ready.');
    } catch (error) {
        result.querySelector('[data-chat-error]').textContent = error.message; showResult('error'); toast(error.message, true);
    } finally { setButtonBusy(button, false); }
});

function captureBrowserLocation() {
    if (!window.isSecureContext || !navigator.geolocation) return Promise.resolve(null);
    return new Promise(resolve => navigator.geolocation.getCurrentPosition(
        position => resolve({latitude:position.coords.latitude, longitude:position.coords.longitude, accuracy:position.coords.accuracy}),
        () => resolve(null),
        {enableHighAccuracy:true, timeout:10000, maximumAge:60000},
    ));
}

document.addEventListener('click', async e => {
    document.querySelectorAll('[data-events-menu][open]').forEach(menu => {
        if (!menu.contains(e.target)) menu.removeAttribute('open');
    });
    document.querySelectorAll('[data-theme-menu][open]').forEach(menu => {
        if (!menu.contains(e.target)) menu.removeAttribute('open');
    });
    const mobileToggle = e.target.closest('[data-mobile-nav-toggle]');
    if (mobileToggle) setMobileNavigation(mobileToggle.getAttribute('aria-expanded') !== 'true');
    else if (!e.target.closest('[data-mobile-nav-menu]')) setMobileNavigation(false);
    const overlayTrigger = e.target.closest('[data-panel-open]');
    const composerTrigger = e.target.closest('[data-composer-open]');
    const eventDefinitionCreate = e.target.closest('[data-event-definition-create]');
    const eventDefinitionOpen = e.target.closest('[data-event-definition-open]');
    const goalCreate = e.target.closest('[data-goal-create]');
    const goalOpen = e.target.closest('[data-goal-open]');
    const imagePreview = e.target.closest('[data-image-preview-open]');
    const overlayClose = e.target.closest('[data-overlay-close]');
    const composerCancel = e.target.closest('[data-composer-cancel]');
    if (overlayTrigger) { setMobileNavigation(false); openOverlay(overlayTrigger.dataset.panelOpen); }
    if (composerTrigger) {
        if (demoReadOnly) toast('The demo is read-only. Create an account to save your own entries.');
        else configureComposer({time: composerTrigger.dataset.currentTime || composerTrigger.dataset.defaultTime});
    }
    if (eventDefinitionCreate && !demoReadOnly) configureEventDefinition();
    if (eventDefinitionOpen) {
        const source = document.getElementById(eventDefinitionOpen.dataset.eventDefinitionOpen);
        if (source) configureEventDefinition(JSON.parse(source.textContent));
    }
    if (goalCreate) configureGoal();
    if (goalOpen) {
        const source = document.getElementById(goalOpen.dataset.goalOpen);
        if (source) configureGoal(JSON.parse(source.textContent));
    }
    if (imagePreview) openImagePreview(imagePreview);
    if (composerCancel) closeOverlay(composerCancel.closest('[data-overlay="composer"]'));
    if (overlayClose) {
        const overlay = overlayClose.closest('[data-overlay]');
        if (overlay?.dataset.overlay === 'composer') saveComposerDraft(overlay);
        else closeOverlay(overlay);
    }
    const themeOption = e.target.closest('[data-theme-option]');
    if (themeOption) {
        applyTheme(themeOption.dataset.themeOption);
        themeOption.closest('[data-theme-menu]')?.removeAttribute('open');
    }
    const timelineItem = e.target.closest('.timeline-item');
    const nestedAction = e.target.closest('button, a, input, textarea, select, form, audio, video');
    if (timelineItem && !composerTrigger && !e.target.closest('[data-task-event]') && (!nestedAction || nestedAction === timelineItem)) {
        if (timelineItem.matches('[data-timeline-github]')) openGithubDetails(timelineItem);
        else if (timelineItem.matches('[data-timeline-browsing]')) openBrowsingDetails(timelineItem);
        else if (timelineItem.matches('[data-timeline-google-calendar]')) openGoogleCalendarDetails(timelineItem);
        else if (demoReadOnly) toast('The demo is read-only. Create an account to save your own entries.');
        else if (timelineItem.matches('[data-timeline-edit]')) configureComposer({time: timelineItem.dataset.timelineTime, mode:'edit', kind:timelineItem.dataset.editKind, eventName:timelineItem.dataset.editEventName, action:timelineItem.dataset.editUrl, content:timelineItem.dataset.editContent, emoji:timelineItem.dataset.editEmoji, iconData:timelineItem.dataset.editIcon, updated:timelineItem.dataset.editUpdated, hideUrl:timelineItem.dataset.hideUrl, deleteUrl:timelineItem.dataset.deleteUrl, isHidden:timelineItem.dataset.isHidden === 'true', location:JSON.parse(timelineItem.dataset.editLocation || 'null')});
        else configureComposer({time:timelineItem.dataset.timelineTime || timelineItem.dataset.currentTime});
    }
    const emptyLogSpace = e.target === document.querySelector('#timeline')
        || e.target === document.querySelector('#daily-log-page-container')
        || (e.target === document.querySelector('#page-content') && supportsDayStateNavigation());
    if (emptyLogSpace && !timelineItem && !composerTrigger && !demoReadOnly) configureComposer();
    const visibility = e.target.closest('[data-planner-visibility]');
    if (visibility) {
        visibility.disabled = true;
        const overlay = visibility.closest('[data-overlay]');
        if (overlay && !saveComposerDraft(overlay, {close:false})) return;
        const payload = JSON.parse(visibility.dataset.payload || '{}');
        const visibilityUrl = visibility.dataset.plannerVisibility;
        const method = visibility.dataset.method || 'PATCH';
        mutateDayState(state => {
            const item = findBlockItem(state, visibilityUrl, 'hide_url');
            if (!item) return;
            if (!state.show_hidden && payload.hidden) state.timeline = state.timeline.filter(candidate => candidate !== item);
            else item.block.is_hidden = Boolean(payload.hidden);
        });
        if (overlay) closeOverlay(overlay);
        queueBackgroundSync(`visibility:${visibilityUrl}`, () => ajax(visibilityUrl, {method, keepalive:true, headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)}), body => toast(body.message));
    }
    const del = e.target.closest('[data-delete]');
    if (del && await modal({title:'Delete this item?', message:'This cannot be undone.', confirmText:'Delete'})) {
        const previousState = snapshotDayState();
        const deleteUrl = del.dataset.delete;
        let editUrl = null;
        mutateDayState(state => {
            editUrl = findBlockItem(state, deleteUrl, 'delete_url')?.block.edit_url || null;
            state.timeline = state.timeline.filter(item => item.kind !== 'block' || item.block.delete_url !== deleteUrl);
        });
        if (editUrl) { cancelBackgroundSync(`log-edit:${editUrl}`); cancelBackgroundSync(`event-edit:${editUrl}`); }
        closeOverlay(document.querySelector('[data-overlay="composer"]'));
        try {
            const body = await ajax(deleteUrl, {method:'DELETE'});
            toast(body.message);
        } catch(error) {
            restoreDayState(previousState);
            toast(error.message, true);
        }
    }
    const edit = e.target.closest('[data-edit-block]');
    if (edit) {
        const content = await modal({title:'Edit log entry', message:edit.dataset.updated ? `Updated ${edit.dataset.updated}` : '', initial:edit.dataset.content || '', confirmText:'Save'});
        if (content !== null) {
            const previousState = snapshotDayState();
            updateLocalTimelineBlock(edit.dataset.editBlock, {content});
            try { const body = await ajax(edit.dataset.editBlock,{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({content})}); toast(body.message || 'Saved.'); }
            catch(error) { restoreDayState(previousState); toast(error.message,true); }
        }
    }
    const task = e.target.closest('[data-task-event]');
    if (task) {
        if (demoReadOnly) { toast('The demo is read-only. Create an account to track your own events.'); return; }
        task.closest('[data-events-menu]')?.removeAttribute('open');
        let value = null; const options = JSON.parse(task.dataset.options || '[]');
        if (options.length) { value = await modal({title:task.dataset.name, message:'Choose a value before this event is tracked.', options, confirmText:'Track event'}); if (value === null) return; }
        const locationPromise = task.hasAttribute('data-capture-location') ? captureBrowserLocation() : Promise.resolve(null);
        const relatedButtons = [...document.querySelectorAll('[data-task-event]')].filter(button => button.dataset.taskEvent === task.dataset.taskEvent);
        const originalCounts = new Map();
        relatedButtons.forEach(button => {
            const count = button.querySelector('[data-count]');
            const buttonSlot = button.dataset.scheduledTime || '';
            const clickedSlot = task.dataset.scheduledTime || '';
            if (!count || (buttonSlot && buttonSlot !== clickedSlot)) return;
            originalCounts.set(count, count.textContent);
            count.textContent = String((Number.parseInt(count.textContent, 10) || 0) + 1);
        });
        const previousState = snapshotDayState();
        const eventUrl = task.dataset.taskEvent;
        const scheduledTime = task.dataset.scheduledTime || null;
        const taskName = task.dataset.name;
        const taskEmoji = task.dataset.taskEmoji || task.querySelector('[aria-hidden="true"]')?.textContent?.trim() || '✅';
        const taskIcon = task.dataset.taskIcon || '';
        const optimisticId = `local-event-${crypto.randomUUID?.() || Date.now()}`;
        const now = new Date();
        const optimisticTime = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
        mutateDayState(state => {
            state.tasks.filter(item => item.event_url === eventUrl).forEach(item => { item.count += 1; });
            state.sticky_events = (state.sticky_events || []).map(item => {
                if (item.event_url === eventUrl) item.count += 1;
                return item;
            }).filter(item => Number(item.count || 0) < Number(item.daily_default_count || 1));
            state.timeline.filter(item => item.kind === 'schedule' && item.task.event_url === eventUrl).forEach(item => {
                item.task.count += 1;
                if (!scheduledTime || item.time === scheduledTime) item.task.slot_count = Number(item.task.slot_count || 0) + 1;
            });
            addOptimisticTimelineBlock(state, {
                id:optimisticId,
                time:optimisticTime,
                emoji:taskEmoji,
                iconData:taskIcon,
                content:'',
                kind:'event',
                editUrl:eventUrl,
                eventName:taskName,
                selectedValue:value || '',
            });
            state.timeline = state.timeline.filter(item => {
                if (item.kind !== 'schedule' || item.task.event_url !== eventUrl) return true;
                const limit = Number(item.task.daily_default_count || 1);
                if (scheduledTime) return item.time !== scheduledTime || Number(item.task.slot_count || 0) < limit;
                return !item.is_unscheduled || Number(item.task.count || 0) < limit;
            });
            reorderTimeline(state);
        });
        task.disabled = true;
        const eventCreatePromise = ajax(eventUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({value, scheduled_time:scheduledTime})});
        pendingEventCreates.set(optimisticId, eventCreatePromise);
        configureComposer({
            time:optimisticTime,
            mode:'edit',
            kind:'event',
            eventName:taskName,
            action:eventUrl,
            content:'',
            emoji:taskEmoji,
            iconData:taskIcon,
            pendingEventId:optimisticId,
            isNew:true,
        });
        let eventCreated = false;
        try {
            const body = await eventCreatePromise;
            eventCreated = true;
            reconcileOptimisticBlock(optimisticId, {...body, block:{id:body.block_id}});
            activeDayState.tasks.filter(item => item.event_url === eventUrl).forEach(item => { item.count = body.count; });
            toast(body.message);
            const composerRoot = document.querySelector('[data-overlay="composer"]');
            const composerForm = composerRoot?.querySelector('[data-composer-note-form]');
            if (composerForm?.dataset.pendingEventId === optimisticId) {
                composerForm.action = body.edit_url;
                delete composerForm.dataset.pendingEventId;
                const actions = composerRoot.querySelector('[data-composer-entry-actions]');
                actions?.classList.remove('hidden'); actions?.classList.add('grid');
                const visibility = composerRoot.querySelector('[data-composer-visibility]');
                visibility?.classList.add('hidden');
                const deleteButton = composerRoot.querySelector('[data-composer-delete]');
                if (deleteButton) { deleteButton.classList.remove('hidden'); deleteButton.dataset.delete = body.delete_url; }
            }
            if (!backgroundSyncQueue.has(`pending-event-edit:${optimisticId}`)) pendingEventCreates.delete(optimisticId);
            const capturedLocation = await locationPromise;
            if (capturedLocation && body.location_url) {
                try {
                    const locationBody = await ajax(body.location_url, {method:'PATCH', headers:{'Content-Type':'application/json'}, body:JSON.stringify(capturedLocation)});
                    const item = activeDayState ? findPendingBlockItem(activeDayState, optimisticId) : null;
                    if (item?.block.event) item.block.event.location = locationBody.location;
                    if (composerForm?.action === body.edit_url) renderComposerLocation(composerRoot, 'event', locationBody.location);
                } catch (_) { toast('The event was logged, but its location could not be saved.', true); }
            }
        }
        catch(error) {
            if (!eventCreated) {
                cancelBackgroundSync(`pending-event-edit:${optimisticId}`);
                pendingEventCreates.delete(optimisticId);
                restoreDayState(previousState);
                const composer = document.querySelector('[data-overlay="composer"]');
                if (composer?.querySelector('[data-composer-note-form]')?.dataset.pendingEventId === optimisticId) closeOverlay(composer);
            }
            toast(error.message,true);
        } finally { task.disabled = false; }
    }
});

function initComposerTimeInput(root = document) {
    const composerTimeInput = root.querySelector('[data-composer-time]');
    composerTimeInput?.addEventListener('input', syncComposerTime);
    composerTimeInput?.addEventListener('change', syncComposerTime);
    root.querySelector('[data-composer-time-now]')?.addEventListener('click', () => {
        if (!composerTimeInput) return;
        const now = new Date();
        composerTimeInput.value = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
        composerTimeInput.dispatchEvent(new Event('input', {bubbles:true}));
        composerTimeInput.dispatchEvent(new Event('change', {bubbles:true}));
    });
}

initComposerTimeInput();

document.addEventListener('keydown', async event => {
    if (event.key === 'Escape') {
        document.querySelectorAll('[data-events-menu][open]').forEach(menu => menu.removeAttribute('open'));
        for (const root of document.querySelectorAll('[data-overlay][data-open="true"]')) {
            if (root.dataset.overlay === 'composer') saveComposerDraft(root);
            else closeOverlay(root);
        }
        setMobileNavigation(false);
    }
});

const calendarPage = document.querySelector('[data-calendar-view-current]');
if (calendarPage) {
    const storageKey = 'totallog.calendarView';
    const validViews = ['week', 'month'];
    const params = new URLSearchParams(location.search);
    const requestedView = params.get('view');
    const savedView = localStorage.getItem(storageKey);

    if (validViews.includes(requestedView)) {
        localStorage.setItem(storageKey, requestedView);
    } else if (validViews.includes(savedView) && savedView !== calendarPage.dataset.calendarViewCurrent) {
        params.set('view', savedView);
        location.replace(`${location.pathname}?${params}`);
    } else if (savedView && !validViews.includes(savedView)) {
        localStorage.removeItem(storageKey);
    }

    document.querySelectorAll('[data-calendar-view]').forEach(link => {
        link.addEventListener('click', () => {
            if (validViews.includes(link.dataset.calendarView)) localStorage.setItem(storageKey, link.dataset.calendarView);
        });
    });
}

document.querySelectorAll('[data-color-input]').forEach(input => {
    const preview = document.getElementById(input.dataset.colorInput);
    const update = () => { if (preview) { preview.style.backgroundColor = input.value; preview.title = input.value; } };
    input.addEventListener('input', update); update();
});

document.querySelectorAll('[data-recurrence-form]').forEach(form => {
    const select = form.querySelector('[data-recurrence-select]');
    const weekly = form.querySelector('[data-recurrence-weekly]');
    const monthly = form.querySelector('[data-recurrence-monthly]');
    const update = () => {
        weekly?.classList.toggle('hidden', select.value !== 'weekly');
        monthly?.classList.toggle('hidden', select.value !== 'monthly');
    };
    select?.addEventListener('change', update);
    if (select) update();
});

const modelLoadOutcomes = new Map();
const modelLoadRequests = new Map();

function requestModelList(url) {
    if (modelLoadOutcomes.has(url)) return Promise.resolve(modelLoadOutcomes.get(url));
    if (modelLoadRequests.has(url)) return modelLoadRequests.get(url);

    const request = ajax(url)
        .then(body => ({models: body.data || [], error: null}))
        .catch(error => ({models: null, error}))
        .then(outcome => {
            modelLoadOutcomes.set(url, outcome);
            return outcome;
        })
        .finally(() => modelLoadRequests.delete(url));
    modelLoadRequests.set(url, request);

    return request;
}

async function loadModels(select) {
    if (select.dataset.modelsInitialized === 'true') return;
    select.dataset.modelsInitialized = 'true';
    const kind = select.dataset.modelSelect, key = 'totallog.models.chat', choiceKey = 'totallog.model.chat', accountChoice = select.dataset.selected || '';
    const render = models => {
        const choice = kind === 'chat-default' ? accountChoice : (localStorage.getItem(choiceKey) || accountChoice);
        const options = models.map(model => { const option = cloneTemplate('select-option-template'); option.textContent = model.name || model.id; option.value = model.id; return option; });
        if (!options.length) { const option = cloneTemplate('select-option-template'); option.textContent = 'No compatible models available'; option.value = ''; options.push(option); }
        select.replaceChildren(...options);
        if (choice && [...select.options].some(option => option.value === choice)) select.value = choice;
        select.dispatchEvent(new CustomEvent('modelsloaded', {bubbles: true}));
    };
    const cached = JSON.parse(localStorage.getItem(key) || '[]'); if (cached.length) render(cached);
    const outcome = await requestModelList(select.dataset.modelsUrl);
    if (outcome.models) {
        localStorage.setItem(key, JSON.stringify(outcome.models));
        render(outcome.models);
    } else if (!cached.length) {
        const option = cloneTemplate('select-option-template'); option.textContent = accountChoice || 'Add an API key in Settings'; option.value = accountChoice; select.replaceChildren(option); select.dispatchEvent(new CustomEvent('modelsloaded', {bubbles: true}));
    }
    if (kind !== 'chat-default') select.addEventListener('change', () => localStorage.setItem(choiceKey, select.value));
}
document.querySelectorAll('[data-model-select]').forEach(loadModels);

const requestedPanel = new URLSearchParams(location.search).get('panel');
if (requestedPanel === 'chat' && document.querySelector('[data-overlay="chat"]')) {
    openOverlay(requestedPanel);
}


function initializeRefreshedMain(root) {
    root.querySelectorAll('[data-time-picker]').forEach(initTimePicker);
    root.querySelectorAll('[data-emoji-picker]').forEach(initEmojiPicker);
    root.querySelectorAll('[data-model-select]').forEach(loadModels);
    initComposerTimeInput(root);
    scheduleStickyVisibilityRefresh(root);
}

let stickyVisibilityTimer;
function scheduleStickyVisibilityRefresh(root = document) {
    clearTimeout(stickyVisibilityTimer);
    const container = root.querySelector?.('[data-next-sticky-visibility]');
    const value = container?.dataset.nextStickyVisibility;
    if (!value) return;
    const [hour, minute] = value.split(':').map(Number);
    const target = new Date();
    target.setHours(hour, minute, 0, 0);
    const delay = target.getTime() - Date.now();
    if (delay <= 0) return;
    stickyVisibilityTimer = window.setTimeout(() => refreshDayViewOrReload(), delay + 500);
}

scheduleStickyVisibilityRefresh();
