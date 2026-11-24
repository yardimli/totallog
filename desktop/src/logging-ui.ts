import { invoke } from "@tauri-apps/api/core";
import { enhanceEntryControls } from './entry-controls';
import { listen } from "@tauri-apps/api/event";
import { openUrl } from "@tauri-apps/plugin-opener";
import {
    project,
    occursOn,
    localDate,
    localTime,
    goalPoints,
    calendarHeat,
    calendarRange,
    type Row,
    type LoggingState,
} from "./logging-model";
import "./logging.css";
import {
    updateScreensaver,
    startScreensaver,
    screensaverStyles,
} from "./screensaver";

const esc = (value: unknown) =>
    String(value ?? "").replace(
        /[&<>"']/g,
        (c) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;",
            })[c]!,
    );
const root = document.querySelector<HTMLElement>("#logging-app")!;
let state: LoggingState = { account: null };
let view: Row = project(state);
let page = "day";
let calendarMode = "week";
let sidebarCollapsed = localStorage.getItem("totalLogDesktop.sidebarCollapsed") === "true";
const navIcons: Record<string, string> = { day: "☷", calendar: "▦", tasks: "✓", goals: "◎", search: "⌕", chat: "◌", sensors: "◉", settings: "⚙", usage: "▥", tracker: "▣" };
const navLabel = (key: string, label: string) => `<span class="nav-icon" aria-hidden="true">${navIcons[key]}</span><span class="nav-label">${label}</span>`;

let date = localDate();
let search = "";
let showHidden = false;
let busy = false;
let again = false;
let againManual = true;
let message = "";
let models: Row[] = [];
const pages: Record<string, string> = {
    day: "Daily log",
    calendar: "Calendar",
    tasks: "Events",
    goals: "Goals",
    search: "Search",
    chat: "AI assistant",
    sensors: "Sensors",
    settings: "Settings",
    usage: "API usage",
};
const button = (
    label: string,
    action: string,
    id: unknown = "",
    cls = "secondary",
) =>
    `<button type="button" class="${cls}" data-action="${action}" data-id="${esc(id)}">${label}</button>`;
function status(text: string) {
    message = text;
    const el = document.querySelector("#logging-message");
    if (el) el.textContent = text;
}
function error(e: unknown) {
    status(String(e instanceof Error ? e.message : e));
}
async function reload() {
    const drafts = Array.from(
        root.querySelectorAll<HTMLFormElement>("form[id]"),
    ).map((form) => ({ id: form.id, values: formData(form) }));
    const active = document.activeElement as
        HTMLInputElement | HTMLTextAreaElement | null;
    const focusName = active?.name,
        focusForm = active?.form?.id;
    state = await invoke<LoggingState>("logging_state");
    view = project(state);
    render();
    for (const draft of drafts) {
        // Account/security forms never persist credentials across a render.
        if (draft.id === "login-form") continue;
        const form = root.querySelector<HTMLFormElement>(`#${draft.id}`);
        for (const input of form?.querySelectorAll<
            HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
        >("[name]") || []) {
            if (input.name in draft.values) {
                if (
                    input instanceof HTMLInputElement &&
                    input.type === "checkbox"
                )
                    input.checked = Boolean(draft.values[input.name]);
                else input.value = String(draft.values[input.name] ?? "");
            }
        }
    }
    if (focusForm && focusName)
        root.querySelector<HTMLFormElement>(`#${focusForm}`)
            ?.querySelector<HTMLElement>(`[name="${focusName}"]`)
            ?.focus();
}

async function sync(manual = false) {
    if (!state.account) return;
    if (busy) {
        again = true;
        againManual = againManual && manual;
        return;
    }
    busy = true;
    status("Syncing…");
    try {
        const scope = manual && page === "day" ? {from: date, to: date}
            : manual && page === "calendar" ? calendarRange(date, calendarMode, Number(view.user.week_starts_on ?? 1)) : null;
        await invoke("logging_sync", {scope});
        message = "";
    } catch (e) {
        message = String(e);
    } finally {
        busy = false;
        await reload();
        if (again) {
            again = false;
            const manualRetry = againManual;
            againManual = true;
            void sync(manualRetry);
        }
    }
}

async function save(kind: string, data: Row, target: unknown = null) {
    if (!state.account) throw new Error("Sign in before logging.");
    const operation = {
        id: crypto.randomUUID(),
        kind,
        data,
        target_id: target,
        edited_at: new Date(
            Date.now() + (state.clock_offset || 0),
        ).toISOString(),
    };
    state = await invoke<LoggingState>("logging_enqueue", {
        account: state.account.id,
        operation,
    });
    window.dispatchEvent(new Event("logging-user-sync"));
    view = project(state);
    message = "Saved.";
    render();
    // User edits bypass the periodic timer. The native outbox survives an unavailable server.
    void sync();
}
async function online(path: string, data: Row | null = {}) {
    if (
        (state.operations || []).some(
            (q) => q.status === "pending" || q.status === "applied",
        )
    ) {
        await sync();
        if (
            (state.operations || []).some(
                (q) => q.status === "pending" || q.status === "applied",
            )
        )
            throw new Error(
                "Connect and sync your local edits before this online action.",
            );
    }
    const result = await invoke<Row>("logging_online", { path, data });
    await sync();
    return result;
}
function dayBlocks(day = date) {
    const ids = new Set(
        view.logs
            .filter((l: Row) => l.log_date === day)
            .map((l: Row) => String(l.id)),
    );
    return view.blocks
        .filter((b: Row) => ids.has(String(b.daily_log_id)))
        .sort((a: Row, b: Row) =>
            new Date(a.occurred_at || a.created_at).getTime() - new Date(b.occurred_at || b.created_at).getTime(),
        );
}
function blockDate(block: Row) {
    return (
        view.logs.find((l: Row) => String(l.id) === String(block.daily_log_id))
            ?.log_date || date
    );
}
function clock(value: unknown) {
    const raw = String(value || "");
    if (!raw) return "";
    const time = raw.includes("T")
        ? raw.endsWith("Z")
            ? new Date(raw).toTimeString().slice(0, 5)
            : raw.slice(11, 16)
        : raw.slice(11, 16);
    if (view.user.time_format !== "12") return time;
    const [h, m] = time.split(":").map(Number);
    return `${h % 12 || 12}:${String(m).padStart(2, "0")} ${h < 12 ? "AM" : "PM"}`;
}
function renderBlock(block: Row, withDate = false, expanded = false) {
    const event = block.task_event;
    const title =
        event?.task_name ||
        (
            {
                text: "Log entry",
                chat_user: "You",
                chat_assistant: "Assistant",
                sensor_desktop: "Desktop activity",
                sensor_browser: "Browsing",
                sensor_github: "GitHub",
                sensor_kindle: "Kindle",
            } as Row
        )[block.type] ||
        String(block.type).replaceAll("_", " ");
    if (!expanded) return button(
        `<span class="entry-time">${withDate ? esc(blockDate(block)) + " " : ""}${esc(clock(block.occurred_at || block.created_at))}</span><span class="row-title">${esc(block.emoji || "•")} ${esc(title)}</span><span class="row-preview">${esc([event?.selected_value, block.content].filter(Boolean).join(" · "))}</span>${block._pending ? '<small class="pending-label">Local</small>' : ""}${block.is_hidden ? "<small>Hidden</small>" : ""}`,
        "edit-block", block.id, `timeline-row ${block.is_hidden ? "is-hidden" : ""}`);
    const details = [
        ...(block.desktop_activities || []),
        ...(block.browsing_activities || []),
        ...(block.mobile_browsing_visits || []),
        ...(block.kindle_reading_progress || []),
    ];
    return `<article class="log-entry ${block.is_hidden ? "is-hidden" : ""}"><div class="entry-time">${withDate ? button(esc(blockDate(block)), "date", blockDate(block), "quiet") : ""}${esc(clock(block.occurred_at || block.created_at))}</div><div class="entry-body"><div class="entry-heading"><span>${esc(block.emoji || "•")} ${esc(title)}</span>${block._pending ? '<small class="pending-label">Saved locally</small>' : ""}${block.is_hidden ? "<small>Hidden</small>" : ""}</div>${event?.selected_value ? `<p class="entry-value">${esc(event.selected_value)}</p>` : ""}${block.content ? `<div class="entry-content">${esc(block.content)}</div>` : ""}${event?.city ? `<small>${esc([event.suburb, event.city].filter(Boolean).join(", "))}</small>` : ""}${details.length ? `<details><summary>${details.length} activity details</summary>${details.map((d: Row) => `<p>${esc(d.application || d.domain || d.title || d.book_title || d.url || "Activity")} ${d.duration_seconds ? `· ${Math.round(d.duration_seconds / 60)} min` : ""}</p>`).join("")}</details>` : ""}${block.metadata?.commits ? `<details><summary>Commits</summary>${block.metadata.commits.map((c: Row) => `<p>${esc(c.project)} · ${esc(c.message)}</p>`).join("")}</details>` : ""}${block.google_calendar_event ? `<p>${esc(block.google_calendar_event.summary || block.google_calendar_event.title)}</p>` : ""}${(block.attachments || []).map((a: Row) => button(`Open ${esc(a.original_name || "attachment")}`, "attachment", a.id, "quiet")).join("")}<div class="entry-actions">${button(block.is_hidden ? "Show" : "Hide", "hide-block", block.id, "quiet")}${button("Delete", "delete-block", block.id, "quiet danger")}</div></div></article>`;
}
function renderDay() {
    const blocks = dayBlocks();
    const tasks = view.tasks.filter((t: Row) => occursOn(t, date) && !(t.is_sticky && t.visible_after && date===localDate() && localTime()<t.visible_after));
    const eventStrip = (sticky: boolean) => `<section class="event-strip ${sticky ? "sticky-events" : "daily-events"}" aria-label="${sticky ? "Repeatable events" : "Daily events"}"><div class="event-grid">${
      tasks.filter((t: Row) => Boolean(t.is_sticky) === sticky)
          .map((t: Row) => {
              const count = blocks.filter(
                  (b: Row) =>
                      String(b.task_event?.task_definition_id) === String(t.id),
              ).length;
              return button(
                  `<span>${/^data:image\/png;base64,/.test(t.icon_data || "") ? `<img class="entry-icon" alt="" src="${esc(t.icon_data)}">` : esc(t.emoji || "✅")} ${esc(t.name)}</span><small>${count}${t.is_sticky ? "" : `/${(t.daily_default_count || 1) * Math.max(1, t.scheduled_times?.length || 0)}`}</small>`,
                  "log-event",
                  t.id,
                  "event-button",
              );
          })
          .join("")
  }</div></section>`;
    return `<div class="page-toolbar"><div><p class="eyebrow">Your day, recorded</p><h2>${esc(new Date(`${date}T12:00:00`).toLocaleDateString(undefined, { weekday: "long", month: "long", day: "numeric" }))}</h2></div><div class="actions">${button("←", "previous-day")}<input aria-label="Log date" id="log-date" type="date" value="${date}">${button("→", "next-day")}${button("Today", "today")}<button type="button" data-action="add-entry" aria-label="Add to your day" title="Add to your day" class="add-entry-button">+</button></div></div>
${eventStrip(true)}

  <section class="workspace-card"><div class="section-heading"><h3>Timeline <small>${blocks.length} entries</small></h3>${button(showHidden ? "Hide hidden entries" : "Show hidden entries", "show-hidden", "", "quiet")}</div>${
      blocks
          .filter((b: Row) => showHidden || !b.is_hidden)
          .map((b: Row) => renderBlock(b))
          .join("") ||
      '<div class="empty-state">Your timeline is ready. Log an event or add an entry above.</div>'
  }</section>${eventStrip(false)}`;
}
function renderCalendar(mini = false) {
    const counts: Record<string, number> = {};
    const logDates = new Map(view.logs.map((log: Row) => [String(log.id), String(log.log_date)]));
    for (const block of view.blocks) {
        const day = logDates.get(String(block.daily_log_id)) as string | undefined;
        if (day && !block.is_hidden) counts[day] = (counts[day] || 0) + 1;
    }
    const today = localDate();

    const mode = mini ? "month" : calendarMode;
    const week = Number(view.user.week_starts_on ?? 1);
    const range = calendarRange(date, mode, week);
    const cells = (month: string, startDate: string, length: number) => {
        const monthHeat = calendarHeat(counts, month, today);
        const start = new Date(`${startDate}T12:00:00`);
        return Array.from({length}, (_, i) => {
            const day = new Date(start); day.setDate(start.getDate() + i);
            const key = localDate(day), count = counts[key] || 0;
            return button(`<span>${day.getDate()}</span><small>${count ? `${count} ${count === 1 ? "entry" : "entries"}` : ""}</small>`, "date", key,
                `calendar-day ${key === today ? "today" : ""} ${key.slice(0,7) !== month ? "outside" : ""} ${monthHeat[key] !== undefined ? `heat-${monthHeat[key]}` : ""}`);
        }).join("");
    };
    const weekdays = Array.from({length: 7}, (_, i) => `<strong>${["Sun","Mon","Tue","Wed","Thu","Fri","Sat"][(i + week) % 7]}</strong>`).join("");
    const monthGrid = (month: string) => {
        const start = new Date(`${month}-01T12:00:00`);
        start.setDate(1 - (start.getDay() - week + 7) % 7);
        return `<div class="calendar-grid">${weekdays}${cells(month, localDate(start), 42)}</div>`;
    };
    const title = mode === "year" ? date.slice(0,4) : mode === "week" ? `${range.from} – ${range.to}` : new Date(`${date}T12:00:00`).toLocaleDateString(undefined, {month:"long", year:"numeric"});
    const modes = mini ? "" : `<div class="calendar-modes" role="group" aria-label="Calendar view">${[["week","▤"],["month","▦"],["year","▦▦"]].map(([key, glyph]) => `<button type="button" data-action="calendar-mode" data-id="${key}" aria-label="${key[0].toUpperCase()+key.slice(1)} view" title="${key[0].toUpperCase()+key.slice(1)} view" aria-pressed="${mode===key}" class="${mode===key ? "selected" : "secondary"}"><span aria-hidden="true">${glyph}</span></button>`).join("")}</div>`;
    let body = mode === "week" ? `<div class="calendar-grid week-grid">${weekdays}${cells(date.slice(0,7), range.from, 7)}</div>` : monthGrid(date.slice(0,7));
    if (mode === "year") body = `<div class="year-calendar">${Array.from({length:12}, (_, i) => {
        const month = `${date.slice(0,4)}-${String(i+1).padStart(2,"0")}`;
        return `<section class="year-month"><h3>${button(new Date(`${month}-01T12:00:00`).toLocaleDateString(undefined,{month:"long"}), "calendar-month", `${month}-01`, "quiet")}</h3>${monthGrid(month)}</section>`;
    }).join("")}</div>`;
    return `<div class="page-toolbar"><h2>${title}</h2><div class="actions">${modes}${button("←", mini ? "previous-month" : "calendar-previous")}${button("Today","today")}${button("→", mini ? "next-month" : "calendar-next")}</div></div>${body}`;

}
function renderTasks() {
    return `<div class="page-toolbar"><div><h2>Events</h2><p class="muted">Build routines, set schedules, and log each occurrence.</p></div>${button("New event", "new-task", "", "")}</div><div class="card-grid">${view.tasks.map((t: Row) => `<article class="workspace-card"><h3>${esc(t.emoji || "✅")} ${esc(t.name)}</h3><p>${esc(t.recurrence_type || "daily")} ${t.repeat_interval > 1 ? `· every ${t.repeat_interval} days` : ""} · ${t.daily_default_count || 1} per slot</p><p class="muted">${esc((t.scheduled_times || []).join(", ") || "Any time")}${t.options?.length ? ` · ${esc(t.options.join(", "))}` : ""}</p><div class="actions">${button("Edit", "edit-task", t.id)}${button("Move up", "task-up", t.id, "quiet")}${button("Delete", "delete-task", t.id, "quiet danger")}</div></article>`).join("") || '<div class="empty-state">Create your first event.</div>'}</div>`;
}
function renderGoals() {
    return `<div class="page-toolbar"><div><h2>Goals</h2><p class="muted">Progress for ${esc(date)}</p></div>${button("New goal", "new-goal", "", "")}</div><div class="card-grid">${
        view.goals
            .map((g: Row) => {
                const points = goalPoints(
                    g,
                    date,
                    Number(view.user.week_starts_on ?? 1),
                );
                return `<article class="workspace-card"><h3>${esc(g.emoji || "🎯")} ${esc(g.name)}</h3><p class="goal-number">${points} <small>/ ${g.target_points} points · ${esc(g.period)}</small></p><progress max="${g.target_points}" value="${Math.min(points, g.target_points)}"></progress><div class="actions">${g.manual_enabled ? button("Add progress", "goal-progress", g.id, "") : ""}${button("Edit", "edit-goal", g.id)}${button("Delete", "delete-goal", g.id, "quiet danger")}</div><details><summary>Progress history</summary>${
                    (g.entries || [])
                        .slice()
                        .reverse()
                        .map(
                            (e: Row) =>
                                `<p>${esc(String(e.occurred_at).slice(0, 10))} · +${e.points} ${esc(e.note || "")}</p>`,
                        )
                        .join("") || "<p>No progress yet.</p>"
                }</details></article>`;
            })
            .join("") ||
        '<div class="empty-state">Add a goal to track what matters.</div>'
    }</div>`;
}
function renderSearch() {
    const term = search.toLowerCase();
    const matches = term
        ? view.blocks.filter((b: Row) =>
              JSON.stringify([
                  b.content,
                  b.task_event,
                  b.metadata,
                  b.desktop_activities,
                  b.browsing_activities,
              ])
                  .toLowerCase()
                  .includes(term),
          )
        : [];
    return `<h2>Search your log</h2><form id="search-form" class="actions"><input name="query" type="search" value="${esc(search)}" placeholder="Search events, entries, and activity…" aria-label="Search logs"><button>Search</button></form><p class="muted">Search works offline across downloaded history. ${matches.length} results.</p><section class="workspace-card">${matches
        .slice(0, 200)
        .map((b: Row) => renderBlock(b, true))
        .join(
            "",
        )}${matches.length > 200 ? "<p>Showing the first 200 results. Narrow your search.</p>" : ""}</section>`;
}
function renderChat() {
    return `<h2>AI assistant</h2><p class="muted">Online · Works with your log for ${date}. Proposed changes require your confirmation.</p><form id="chat-form" class="workspace-card"><label>Model<input name="model" list="chat-models" value="${esc(view.user.default_chat_model || "")}" required placeholder="Choose or enter a model ID"></label><datalist id="chat-models">${models.map((m) => `<option value="${esc(m.id)}">${esc(m.name)}</option>`).join("")}</datalist>${button("Load models", "load-models", "", "quiet")}<label>Message<textarea name="message" rows="4" maxlength="30000" required></textarea></label><button>Send message</button></form>${view.chat_proposals
        .filter((p: Row) => new Date(p.expires_at).getTime() > Date.now())
        .map(
            (p: Row) =>
                `<article class="workspace-card"><h3>Review proposed changes</h3><p class="entry-content">${esc(p.summary)}</p>${button("Confirm these changes", "confirm-proposal", p.id, "")}</article>`,
        )
        .join("")}<section class="workspace-card">${dayBlocks()
        .filter((b: Row) => b.type.startsWith("chat_"))
        .map((b: Row) => renderBlock(b))
        .join("")}</section>`;
}
function renderSensors() {
    return `<header class="page-header"><h2>Sensors</h2><p class="muted">Automatic desktop tracking continues in the tray. Account login and tracker pairing are separate.</p><div class="actions">${button("Refresh today’s sensors", "refresh-day")}${button("Link GitHub", "link-github")}${button("Connect Google Calendar", "google-connect")}</div></header><div class="card-grid">${view.sensors.map((s: Row) => `<article class="workspace-card"><h3>${esc(s.type)}</h3><p>${esc(s.username || "")} · ${s.enabled ? "Enabled" : "Paused"}</p><p class="muted">${esc(s.last_error || s.last_checked_at || "Not checked yet")}</p><div class="actions">${button(s.enabled ? "Pause" : "Enable", "toggle-sensor", s.id)}${button("Remove", "delete-sensor", s.id, "quiet danger")}</div></article>`).join("")}</div>`;
}
function renderSettings() {
    const u = view.user;
    return `<header class="page-header"><h2>Settings</h2></header><div class="card-grid"><form id="settings-form" class="workspace-card"><h3>Logging preferences</h3><div class="preferences-row">${select(
        "time_format",
        "Time format",
        [
            ["24", "24 hour"],
            ["12", "12 hour"],
        ],
        u.time_format || "24",
    )}${select(
        "week_starts_on",
        "Week starts on",
        [
            [1, "Monday"],
            [0, "Sunday"],
        ],
        u.week_starts_on ?? 1,
    )}${field("default_chat_model", "Default AI model", u.default_chat_model)}</div><button>Save preferences</button></form><section class="workspace-card"><h3>Your account</h3><p>${esc(u.name)}<br>${esc(u.email)}</p><p class="muted">${esc(state.account?.server)}</p><div class="actions">${button("Edit profile", "profile")}${button("Change password", "password")}${button("OpenRouter key", "api-key")}${button("Sign in again", "reauth", "", "quiet")}${button("Sign out", "logout", "", "quiet")}</div>${u.is_admin ? button("View users", "admin-users", "", "quiet") : ""}</section></div><section class="workspace-card"><h3>Sync</h3><p>Changes save to SQLite immediately. Foreground refresh: every 5 minutes. Background refresh: every hour. Your edits and manual actions sync immediately.</p><p>Last successful sync: ${state.last_sync ? new Date(state.last_sync).toLocaleString() : "Not yet synced"}</p>${button("Sync now", "sync", "", "")}</section>`;
}
function renderUsage() {
    return `<header class="page-header"><h2>API usage</h2></header><div class="workspace-card table-scroll"><table><thead><tr><th>Time</th><th>Operation</th><th>Model</th><th>Tokens</th><th>Cost</th><th>Status</th></tr></thead><tbody>${view.api_calls
        .slice()
        .reverse()
        .map(
            (a: Row) =>
                `<tr><td>${esc(a.created_at)}</td><td>${esc(a.operation)}</td><td>${esc(a.model)}</td><td>${a.total_tokens || 0}</td><td>${esc(a.cost)}</td><td>${esc(a.error || a.status_code)}</td></tr>`,
        )
        .join("")}</tbody></table></div>`;
}

function render() {
    updateScreensaver({ ...view.user, logo: view.screensaver_logo });
    const tracker = document.querySelector<HTMLElement>("#tracker-app")!;
    tracker.hidden = page !== "tracker";
    document.body.append(tracker);
    root.hidden = false;
    if (!state.account && page !== "tracker") {
        root.innerHTML = `<div class="login-shell"><div class="brand-mark">TL</div><p class="eyebrow">Total Log Desktop</p><h1>Your day, wherever you are.</h1><p class="muted">Log events, build routines, and track goals—even offline after your first sync.</p><form id="login-form" class="workspace-card">${field("server", "Server", localStorage.getItem("totalLogDesktop.appUrl") || "https://total-log.com/", "url", true)}${field("email", "Email", "", "email", true)}${field("password", "Password", "", "password", true)}<button>Sign in</button></form><p id="logging-message" role="status">${esc(message)}</p><div class="actions">${button("Create account", "register", "", "quiet")}${button("Forgot password", "forgot-password", "", "quiet")}${button("Open tracker", "tracker", "", "quiet")}</div></div>`;
        bindForms();
        return;
    }
    const issues = (state.operations || []).filter((q) =>
        ["conflict", "rejected"].includes(q.status),
    );
    const pending = (state.operations || []).filter((q) =>
        ["pending", "applied"].includes(q.status),
    ).length;
    root.innerHTML = `<aside class="logging-sidebar ${sidebarCollapsed ? "collapsed" : ""}"><button type="button" class="sidebar-toggle quiet" data-action="toggle-sidebar" aria-label="${sidebarCollapsed ? "Expand" : "Collapse"} sidebar" aria-expanded="${!sidebarCollapsed}" title="${sidebarCollapsed ? "Expand" : "Collapse"} sidebar">☰</button><div class="logging-brand"><div class="brand-mark">TL</div><strong>Total Log</strong></div><nav aria-label="Main navigation">${Object.entries(
        pages,
    )
        .map(([key, label]) =>
            button(
                navLabel(key, label),
                "page",
                key,
                `nav-button ${page === key ? "selected" : ""}`,
            ),
        )
        .join(
            "",
        )}${button(navLabel("tracker", "Desktop tracker"), "tracker", "", `nav-button ${page === "tracker" ? "selected" : ""}`)}</nav><div class="sync-banner"><span>${busy ? "Syncing…" : state.error ? "Offline / sync needs attention" : pending ? "Changes saved locally" : "Up to date"}${state.last_sync ? ` · ${new Date(state.last_sync).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}` : ""}</span><span id="logging-message" role="status">${esc(message || state.error || "")}</span></div><div class="sidebar-account"><strong>${esc(view.user.name || "Your account")}</strong>${pending ? `<small>${pending} edits waiting to sync</small>` : ""}${button(`<span aria-hidden="true">↻</span><span class="nav-label">${busy ? "Syncing…" : "Sync now"}</span>`, "sync", "", "quiet sidebar-sync")}</div></aside><main class="logging-main">${issues.length ? `<section class="sync-issues"><h3>${issues.length} edits need attention</h3>${issues.map((q) => `<details><summary>${esc(q.operation.kind)} · ${esc(q.receipt?.message || q.status)}</summary><pre>${esc(JSON.stringify(q.operation.data, null, 2))}</pre><div class="actions">${q.status === "rejected" ? button("Retry unchanged", "retry", q.operation.id) : ""}${button("Copy saved input", "copy-operation", q.operation.id)}${button("Keep server version / discard this edit", "discard", q.operation.id, "quiet danger")}</div></details>`).join("")}</section>` : ""}<div id="page-content">${page === "day" ? `<div class="day-layout"><div class="day-log">${renderDay()}</div><aside class="day-calendar" aria-label="Calendar">${renderCalendar(true)}</aside></div>` : page === "tracker" ? '<div id="tracker-host"></div>' : ({ calendar: () => renderCalendar(), tasks: renderTasks, goals: renderGoals, search: renderSearch, chat: renderChat, sensors: renderSensors, settings: renderSettings, usage: renderUsage }[page] || renderDay)()}</div></main>`;
    if (page === "tracker") root.querySelector("#tracker-host")!.append(tracker);
    root.querySelectorAll<HTMLElement>(".nav-button").forEach(el => {
        const label = el.querySelector(".nav-label")?.textContent || "";
        el.setAttribute("aria-label", label); el.title = label;
    });
    root.querySelector(".sidebar-sync")?.setAttribute("aria-label", busy ? "Syncing…" : "Sync now");
    bindForms();
}
function field(
    name: string,
    label: string,
    value: unknown = "",
    type = "text",
    required = false,
) {
    return `<label>${label}<input name="${name}" type="${type}" value="${esc(value)}" ${required ? "required" : ""}></label>`;
}
function select(
    name: string,
    label: string,
    options: unknown[][],
    value: unknown,
) {
    return `<label>${label}<select name="${name}">${options.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(value) ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></label>`;
}
function textarea(
    name: string,
    label: string,
    value: unknown = "",
    required = false,
) {
    return `<label>${label}<textarea name="${name}" rows="3" ${required ? "required" : ""}>${esc(value)}</textarea></label>`;
}
function check(name: string, label: string, value: unknown) {
    return `<label class="checkbox-label"><input type="checkbox" name="${name}" ${value ? "checked" : ""}>${label}</label>`;
}
function formData(form: HTMLFormElement): Row {
    const data: Row = Object.fromEntries(new FormData(form));
    form.querySelectorAll<HTMLInputElement>("input[type=checkbox]").forEach(
        (input) => {
            data[input.name] = input.checked;
        },
    );
    form.querySelectorAll<HTMLInputElement>("input[type=number]").forEach(
        (input) => {
            if (!input.name) return;
            data[input.name] = input.value ? Number(input.value) : null;
        },
    );
    if ('__location' in data) {
        if (data.__location) data.location = JSON.parse(data.__location);
        delete data.__location;
    }
    return data;
}
function submit(
    form: HTMLFormElement | null,
    handler: (data: Row) => Promise<void>,
) {
    form?.addEventListener("submit", async (event) => {
        event.preventDefault();
        const btn = form.querySelector<HTMLButtonElement>(
            "button[type=submit],button:not([type])",
        )!;
        if (btn.disabled) return;
        btn.disabled = true;
        try {
            await handler(formData(form));
        } catch (e) {
            error(e);
        } finally {
            btn.disabled = false;
        }
    });
}
function bindForms() {
    root.querySelectorAll<HTMLElement>('[data-action="retry"]').forEach((el) =>
        el.insertAdjacentHTML(
            "afterend",
            button("Correct saved input", "correct-operation", el.dataset.id),
        ),
    );
    if (state.account && page === "settings") {
        root.querySelector("#page-content")?.insertAdjacentHTML(
            "beforeend",
            `<section class="workspace-card"><h3>Screensaver</h3><div class="actions">${button("Configure", "screensaver")}${button("Choose logo", "screensaver-logo")}${button("Start now", "screensaver-start")}</div></section>`,
        );
    }
    for (const [actionName, rows] of [
        ["edit-task", view.tasks],
        ["log-event", view.tasks],
        ["edit-goal", view.goals],
        ["edit-block", view.blocks],
    ] as [string, Row[]][]) {
        root.querySelectorAll<HTMLElement>(
            `[data-action="${actionName}"]`,
        ).forEach((button) => {
            const row = rows.find((r) => String(r.id) === button.dataset.id);
            if (actionName === "log-event") {
                const legacy: Row = {indigo: '#4f46e5', emerald: '#059669', amber: '#d97706', rose: '#e11d48', sky: '#0284c7'};
                const color = /^#[0-9a-f]{6}$/i.test(row?.color || '') ? row!.color : legacy[row?.color] || '#4f46e5';
                button.style.backgroundColor = color;
                const rgb = [1, 3, 5].map(i => parseInt(color.slice(i, i + 2), 16));
                button.style.color = rgb[0] * .299 + rgb[1] * .587 + rgb[2] * .114 > 160 ? '#101827' : '#fff';
            }
            if (!/^data:image\/png;base64,/.test(row?.icon_data || "")) return;
            const title =
                actionName === "log-event"
                    ? button.querySelector("span")
                    : button
                          .closest("article")
                          ?.querySelector(".entry-heading>span,h3");
            if (title && !title.querySelector("img")) {
                const img = document.createElement("img");
                img.className = "entry-icon";
                img.alt = "";
                img.src = row!.icon_data;
                title.prepend(img);
            }
        });
    }
    submit(root.querySelector("#login-form"), async (d) => {
        status("Signing in…");
        state = await invoke("logging_login", d);
        await sync();
    });

    submit(root.querySelector("#search-form"), async (d) => {
        search = d.query;
        render();
    });
    submit(root.querySelector("#settings-form"), async (d) => {
        await save("settings.update", {
            ...d,
            week_starts_on: Number(d.week_starts_on),
        });
    });
    submit(root.querySelector("#chat-form"), async (d) => {
        status("Waiting for the assistant…");
        const result = await online(`chat/${date}`, d);
        status(result.answer || result.summary || result.message);
    });
    root.querySelector<HTMLInputElement>("#log-date")?.addEventListener(
        "change",
        (event) => {
            date = (event.target as HTMLInputElement).value || localDate();
            render();
        },
    );
}
function dialog(
    title: string,
    fields: string,
    onSave: (data: Row) => Promise<void>,
    label = "Save",
) {
    const element = document.createElement("dialog");
    element.className = "logging-dialog";
    element.innerHTML = `<form><header class="section-heading dialog-header"><h2>${esc(title)}</h2><button type="button" data-close class="quiet">Close</button></header><div class="dialog-body"><div class="form-fields">${fields}</div></div><footer class="dialog-footer"><p class="dialog-error" role="alert"></p><button type="button" data-cancel class="secondary">Cancel</button><button type="submit">${label}</button></footer></form>`;
    document.body.append(element);
    element.showModal();
    enhanceEntryControls(element);
    const entryActions = element.querySelector('.entry-actions');
    if (entryActions) element.querySelector('.dialog-footer')!.prepend(entryActions);
    element.querySelector('[data-cancel]')!.addEventListener('click', () => element.close());
    element
        .querySelector("[data-close]")!
        .addEventListener("click", () => element.close());
    element.addEventListener("close", () => element.remove());
    element.addEventListener("click", async (event) => {
        const target = (event.target as HTMLElement).closest<HTMLElement>("[data-action]");
        if (!target) return;
        try {
            await action(target.dataset.action!, target.dataset.id || "");
            if (["hide-block", "delete-block"].includes(target.dataset.action!)) element.close();
        } catch (e) {
            element.querySelector(".dialog-error")!.textContent = String(e);
        }
    });

    const form = element.querySelector("form")!;
    if (["New event", "Edit event", "New goal", "Edit goal"].includes(title))
        form.querySelector(".form-fields")!.insertAdjacentHTML(
            "beforeend",
            iconPicker(),
        );
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const btn = form.querySelector<HTMLButtonElement>("[type=submit]")!;
        btn.disabled = true;
        try {
            const data = formData(form);
            const file = data.__icon_file;
            delete data.__icon_file;
            if (file instanceof File && file.size) {
                if (file.size > 4 * 1024 * 1024)
                    throw new Error("Choose an image smaller than 4 MB.");
                const image = await createImageBitmap(file);
                const canvas = document.createElement("canvas");
                canvas.width = canvas.height = 128;
                const side = Math.min(image.width, image.height);
                canvas
                    .getContext("2d")!
                    .drawImage(
                        image,
                        (image.width - side) / 2,
                        (image.height - side) / 2,
                        side,
                        side,
                        0,
                        0,
                        128,
                        128,
                    );
                image.close();
                data.icon_data = canvas.toDataURL("image/png");
            }
            await onSave(data);
            element.close();
        } catch (e) {
            element.querySelector(".dialog-error")!.textContent = String(e);
        } finally {
            btn.disabled = false;
        }
    });
}
function iconPicker() {
    return (
        '<label>Custom icon (center cropped to a square)<input type="file" name="__icon_file" accept="image/png,image/jpeg,image/webp"></label>' +
        check("remove_icon", "Remove custom icon", false)
    );
}
function editTask(task?: Row) {
    const t = task || {};
    dialog(
        task ? "Edit event" : "New event",
        field("name", "Event name", t.name, "text", true) +
            field("emoji", "Emoji", t.emoji || "✅") +
            field("color", "Color", t.color || "#4f46e5", "color") +
            textarea(
                "options_text",
                "Options (comma separated)",
                (t.options || []).join(", "),
            ) +
            select(
                "recurrence_type",
                "Repeat",
                [
                    ["daily", "Daily"],
                    ["weekly", "Weekly"],
                    ["monthly", "Monthly"],
                ],
                t.recurrence_type || "daily",
            ) +
            field(
                "repeat_interval",
                "Every N days",
                t.repeat_interval || 1,
                "number",
            ) +
            field(
                "repeat_start_date",
                "Start date",
                t.repeat_start_date?.slice(0, 10),
                "date",
            ) +
            field(
                "weekdays_text",
                "Weekdays (1 = Monday … 7 = Sunday)",
                t.recurrence_type === "weekly"
                    ? (t.recurrence_days || []).join(", ")
                    : "1, 2, 3, 4, 5, 6, 7",
            ) +
            field(
                "month_days_text",
                "Days of month (comma separated)",
                t.recurrence_type === "monthly"
                    ? (t.recurrence_days || []).join(", ")
                    : "1",
            ) +
            field(
                "scheduled_times_text",
                "Scheduled slots (e.g. 08:00, 18:00)",
                (t.scheduled_times || []).join(", "),
            ) +
            field(
                "daily_default_count",
                "Target per slot",
                t.daily_default_count || 1,
                "number",
            ) +
            check("is_sticky", "Keep in day planner", t.is_sticky) +
            field(
                "visible_after",
                "Show in planner after",
                t.visible_after,
                "time",
            ),
        async (d) => {
            d.weekdays = String(d.weekdays_text)
                .split(/[,\s]+/)
                .filter(Boolean)
                .map(Number);
            delete d.weekdays_text;
            if (!d.repeat_start_date) d.repeat_start_date = null;
            if (!d.visible_after) d.visible_after = null;
            if (
                d.repeat_interval < 1 ||
                d.repeat_interval > 999 ||
                d.daily_default_count < 1 ||
                d.daily_default_count > 999
            )
                throw new Error("Counts must be between 1 and 999.");
            await save(task ? "tasks.update" : "tasks.create", d, task?.id);
        },
    );
}
function editGoal(goal?: Row) {
    const g = goal || {};
    dialog(
        goal ? "Edit goal" : "New goal",
        field("name", "Goal name", g.name, "text", true) +
            field("emoji", "Emoji", g.emoji || "🎯") +
            field("color", "Color", g.color || "#059669", "color") +
            field(
                "target_points",
                "Target points",
                g.target_points || 10,
                "number",
                true,
            ) +
            select(
                "period",
                "Period",
                [
                    ["daily", "Daily"],
                    ["weekly", "Weekly"],
                    ["monthly", "Monthly"],
                    ["none", "All time"],
                ],
                g.period || "weekly",
            ) +
            field("start_date", "Start date", g.start_date, "date") +
            field("end_date", "End date", g.end_date, "date") +
            check(
                "manual_enabled",
                "Allow manual progress",
                g.manual_enabled ?? true,
            ) +
            select(
                "task_definition_id",
                "Count an event",
                [
                    ["", "No event"],
                    ...view.tasks.map((t: Row) => [t.id, t.name]),
                ],
                g.sources?.find((s: Row) => s.type === "event")
                    ?.task_definition_id || "",
            ) +
            textarea(
                "github_projects_text",
                "GitHub projects (one per line)",
                (g.sources || [])
                    .filter((s: Row) => s.type === "github")
                    .map((s: Row) => s.github_project)
                    .join("\n"),
            ),
        async (d) => {
            for (const k of ["start_date", "end_date", "task_definition_id"])
                if (!d[k]) d[k] = null;
            if (d.target_points < 1 || d.target_points > 1000000)
                throw new Error("Target must be between 1 and 1,000,000.");
            await save(goal ? "goals.update" : "goals.create", d, goal?.id);
        },
    );
}
function locationFields(location?: Row) {
    const value = location?.latitude != null && location?.longitude != null ? {latitude: location.latitude, longitude: location.longitude, location_accuracy: location.location_accuracy} : null;
    return `<section class="location-controls"><h3>Location</h3><input type="hidden" name="__location" value="${value ? esc(JSON.stringify(value)) : ''}"><button type="button" data-location-capture class="secondary">Store current location</button><p data-location-status role="status">${value ? esc(`${value.latitude}, ${value.longitude}`) : 'No location stored'}</p><div class="location-coordinates"><input data-latitude aria-label="Latitude" placeholder="Latitude" type="number" step="any"><input data-longitude aria-label="Longitude" placeholder="Longitude" type="number" step="any"><button type="button" data-location-manual class="quiet">Use coordinates</button></div></section>`;
}
function logEvent(task: Row) {
    dialog(
        `Log ${task.name}`,
        field("occurred_at", "Time", localTime(), "time", true) +
            (task.options?.length
                ? select(
                      "value",
                      "Value",
                      task.options.map((v: string) => [v, v]),
                      task.options[0],
                  )
                : "") +
            (task.scheduled_times?.length
                ? select(
                      "scheduled_time",
                      "Scheduled slot",
                      task.scheduled_times.map((v: string) => [v, v]),
                      task.scheduled_times[0],
                  )
                : "") + textarea("notes", "Entry text") + field("emoji", "Emoji", task.emoji || "✅") + locationFields(),
        async (d) => {
            if(d.scheduled_time && dayBlocks().filter((b:Row)=>String(b.task_event?.task_definition_id)===String(task.id)&&b.task_event?.scheduled_time===d.scheduled_time).length>=Number(task.daily_default_count||1))throw new Error('This time slot has reached its daily count.');
            await save("events.create", {
                ...d,
                task_definition_id: task.id,
                log_date: date,
            });
        },
        "Log event",
    );
}
function showActivity(block: Row) {
    const git = block.type === 'sensor_github';
    const desktop = block.type === 'sensor_desktop';
    const commits: Row[] = block.metadata?.commits || [];
    const totals = new Map<string, number>();
    for (const item of desktop ? block.desktop_activities || [] : [...(block.browsing_activities || []), ...(block.mobile_browsing_visits || [])]) {
        const name = String(desktop ? item.application || item.process_name || 'Application' : item.domain || item.url || 'Website');
        totals.set(name, (totals.get(name) || 0) + Number(item.duration_seconds || 0));
    }
    const duration = (seconds: number) => seconds < 60 ? 'Under 1 min' : `${Math.round(seconds / 60)} min`;
    const total = [...totals.values()].reduce((a, b) => a + b, 0);
    const element = document.createElement('dialog');
    element.className = 'logging-dialog activity-dialog';
    element.innerHTML = `<header class="section-heading dialog-header"><div><small class="sensor-label">${git ? 'GITHUB SENSOR' : desktop ? 'DESKTOP SENSOR' : 'BROWSING SENSOR'}</small><h2>${esc(git ? block.metadata?.project || commits[0]?.project || commits[0]?.repository || 'Git commits' : desktop ? 'Applications' : 'Browsing')}</h2><p class="muted">Started ${esc(clock(block.occurred_at || block.created_at))} · ${git ? `${commits.length} commit${commits.length === 1 ? '' : 's'}` : duration(total)}</p></div><button type="button" data-close>Close</button></header><div class="dialog-body"><div class="activity-list">${git ? commits.map((c, i) => `<article class="activity-item commit-item"><div class="section-heading"><span>${esc(clock(c.occurred_at || block.occurred_at))}</span><code>${esc(String(c.sha || '').slice(0, 7))}</code></div><p>${esc(c.message || '')}</p>${/^https?:\/\//i.test(c.url || c.html_url || '') ? `<button type="button" class="quiet" data-commit="${i}">Open commit</button>` : ''}</article>`).join('') : [...totals].sort((a,b) => b[1]-a[1]).map(([name, seconds]) => `<article class="activity-item"><strong>${esc(name)}</strong><span class="duration-badge">${duration(seconds)}</span></article>`).join('') || '<p>No activity data recorded.</p>'}</div>${git ? '' : `<p class="activity-footnote">${desktop ? 'Only application names, process names, and time totals are stored. Window titles and executable paths stay on your computer.' : 'Domains and time totals are shown above.'}</p>`}</div><footer class="dialog-footer"><button type="button" data-done>Done</button></footer>`;
    element.querySelector('[data-close]')!.addEventListener('click', () => element.close());
    element.querySelector('[data-done]')?.addEventListener('click', () => element.close());
    element.querySelectorAll<HTMLElement>('[data-commit]').forEach(button => button.addEventListener('click', () => {
        const c = commits[Number(button.dataset.commit)];
        void openUrl(c.url || c.html_url).catch(error);
    }));
    element.addEventListener('close', () => element.remove());
    document.body.append(element);
    element.showModal();
}
async function action(action: string, id: string) {
    const block = view.blocks.find((r: Row) => String(r.id) === id),
        task = view.tasks.find((r: Row) => String(r.id) === id),
        goal = view.goals.find((r: Row) => String(r.id) === id),
        sensor = view.sensors.find((r: Row) => String(r.id) === id);
    if (action === "edit-block" && block?._goal_progress)
        throw new Error(
            "Sync this saved goal progress before editing its timeline entry.",
        );
    switch (action) {
        case "toggle-sidebar":
            sidebarCollapsed = !sidebarCollapsed;
            localStorage.setItem("totalLogDesktop.sidebarCollapsed", String(sidebarCollapsed));
            render(); break;
        case "calendar-mode":
            calendarMode = id; render(); break;
        case "calendar-month":
            date = id; calendarMode = "month"; render(); break;
        case "calendar-previous":
        case "calendar-next": {
            const d = new Date(`${date}T12:00:00`), direction = action === "calendar-next" ? 1 : -1;
            if (calendarMode === "week") d.setDate(d.getDate() + direction * 7);
            else if (calendarMode === "year") { d.setMonth(0, 1); d.setFullYear(d.getFullYear() + direction); }
            else { d.setDate(1); d.setMonth(d.getMonth() + direction); }
            date = localDate(d); render(); break;
        }

        case "add-entry": {
            const entryDate = date;
            dialog("Add to your day",
                textarea("content", "Entry text", "", true) +
                field("occurred_at", "Time", localTime(), "time", true) +
                field("emoji", "Emoji", "📝") + locationFields(),
                async (data) => {
                    if (String(data.content || "").length > 100000) throw new Error("Entry text must be at most 100,000 characters.");
                    await save("blocks.create", { ...data, type: "text", log_date: entryDate });
                }, "Save entry");
            break;
        }

        case "page":
            page = id;
            render();
            break;
        case "tracker":
            page = "tracker";
            render();
            break;
        case "date":
            date = id;
            page = "day";
            render();
            break;
        case "today":
            date = localDate();
            render();
            break;
        case "previous-day":
        case "next-day": {
            const d = new Date(`${date}T12:00:00`);
            d.setDate(d.getDate() + (action === "next-day" ? 1 : -1));
            date = localDate(d);
            render();
            break;
        }
        case "previous-month":
        case "next-month": {
            const d = new Date(`${date.slice(0, 7)}-01T12:00:00`);
            d.setMonth(d.getMonth() + (action === "next-month" ? 1 : -1));
            date = localDate(d);
            render();
            break;
        }
        case "sync":
            await sync(true);
            break;
        case "show-hidden":
            showHidden = !showHidden;
            render();
            break;
        case "new-task":
            editTask();
            break;
        case "edit-task":
            editTask(task);
            break;
        case "log-event":
            logEvent(task);
            break;
        case "task-up": {
            const i = view.tasks.indexOf(task);
            if (i > 0) {
                const previous = view.tasks[i - 1];
                await save(
                    "tasks.position",
                    { position: previous.position || 0 },
                    task.id,
                );
                await save(
                    "tasks.position",
                    { position: task.position || i },
                    previous.id,
                );
            }
            break;
        }
        case "delete-task":
            if (
                confirm(
                    `Delete ${task.name}? Recorded entries will be preserved as text.`,
                )
            )
                await save("tasks.delete", {}, task.id);
            break;
        case "new-goal":
            editGoal();
            break;
        case "edit-goal":
            editGoal(goal);
            break;
        case "delete-goal":
            if (
                confirm(`Delete ${goal.name}? Original logs will be preserved.`)
            )
                await save("goals.delete", {}, goal.id);
            break;
        case "goal-progress":
            dialog(
                `Progress · ${goal.name}`,
                field("points", "Points", 1, "number", true) +
                    field("occurred_on", "Date", date, "date", true) +
                    textarea("note", "Entry text"),
                async (d) => {
                    if (d.points < 1 || d.points > 1000000)
                        throw new Error(
                            "Points must be between 1 and 1,000,000.",
                        );
                    await save("goals.progress", d, goal.id);
                },
                "Add progress",
            );
            break;
        case "hide-block":
        case "delete-block":
            if (block._pending && block.type === "event")
                throw new Error(
                    "This event is saved locally. Sync it before hiding or deleting it.",
                );
            if (action === "hide-block")
                await save(
                    "blocks.visibility",
                    { is_hidden: !block.is_hidden },
                    block.id,
                );
            else if (confirm("Delete this log entry?"))
                await save("blocks.delete", {}, block.id);
            break;
        case "edit-block":
            if (["sensor_desktop", "sensor_browser", "sensor_mobile_browser", "sensor_github"].includes(block.type)) {
                showActivity(block);
                break;
            }
            dialog(
                "Entry details & edit",
                renderBlock(block, true, true) + textarea(
                    block.task_event ? "notes" : "content",
                    "Entry text",
                    block.content,
                ) +
                    field("emoji", "Emoji", block.emoji) +
                    field(
                        "occurred_at",
                        "Time",
                        block.occurred_at
                            ? String(block.occurred_at).endsWith("Z")
                                ? localTime(new Date(block.occurred_at))
                                : String(block.occurred_at).slice(11, 16)
                            : localTime(),
                        "time",
                        true,
                    ) + locationFields(block.task_event || block.metadata?.location),
                async (d) => {
                    await save(
                        block.task_event ? "events.update" : "blocks.update",
                        d,
                        block.task_event?.id || block.id,
                    );
                },
            );
            break;
        case "refresh-day":
            status("Refreshing sensors…");
            await online(`refresh-day/${date}`);
            break;
        case "toggle-sensor":
            await save(
                "sensors.update",
                { enabled: !sensor.enabled },
                sensor.id,
            );
            break;
        case "delete-sensor":
            if (confirm(`Remove ${sensor.type} sensor?`))
                await save("sensors.delete", {}, sensor.id);
            break;
        case "link-github":
            dialog(
                "Link GitHub",
                field("github_username", "GitHub username", "", "text", true) +
                    field(
                        "github_token",
                        "GitHub personal access token",
                        "",
                        "password",
                        true,
                    ),
                async (d) => {
                    await online("sensors/github", d);
                },
                "Link",
            );
            break;
        case "google-connect":
            await openUrl(
                new URL(
                    "settings/sensors/google-calendar/connect",
                    state.account!.server,
                ).toString(),
            );
            break;
        case "load-models":
            models = (await online("models", null)).data || [];
            render();
            break;
        case "confirm-proposal":
            if (confirm("Apply the proposed changes shown above?"))
                await online(`chat-actions/${id}/confirm`);
            break;
        case "attachment": {
            status("Opening attachment…");
            const url = await invoke<string>("logging_attachment", {
                id: Number(id),
            });
            dialog(
                "Attachment",
                `<img src="${esc(url)}" alt="Log attachment" style="max-width:100%">`,
                async () => {},
                "Close",
            );
            status("");
            break;
        }
        case "logout":
            await invoke("logging_logout");
            message = "";
            await reload();
            break;
        case "reauth":
            dialog(
                "Sign in again",
                field("server", "Server", state.account?.server, "url", true) +
                    field("email", "Email", view.user.email, "email", true) +
                    field("password", "Password", "", "password", true),
                async (d) => {
                    state = await invoke("logging_login", d);
                    await sync();
                },
                "Sign in",
            );
            break;
        case "screensaver":
            dialog(
                "Screensaver",
                check(
                    "screensaver_enabled",
                    "Enable while this app is idle",
                    view.user.screensaver_enabled,
                ) +
                    select(
                        "screensaver_style",
                        "Style",
                        screensaverStyles.map((s) => [
                            s,
                            s.replaceAll("-", " "),
                        ]),
                        view.user.screensaver_style || "flying-toasters",
                    ) +
                    select(
                        "screensaver_wait_minutes",
                        "Idle minutes",
                        [1, 2, 5, 10, 15, 30, 60].map((n) => [n, n]),
                        view.user.screensaver_wait_minutes || 10,
                    ) +
                    select(
                        "screensaver_speed",
                        "Speed",
                        [0.5, 0.75, 1, 1.25, 1.5, 2].map((n) => [n, `${n}×`]),
                        view.user.screensaver_speed || 1,
                    ) +
                    field(
                        "screensaver_message",
                        "Message",
                        view.user.screensaver_message || "OUT TO LUNCH",
                    ),
                async (d) => {
                    d.screensaver_wait_minutes = Number(
                        d.screensaver_wait_minutes,
                    );
                    d.screensaver_speed = Number(d.screensaver_speed);
                    await save("settings.screensaver", d);
                },
            );
            break;
        case "screensaver-start":
            startScreensaver();
            break;
        case "screensaver-logo":
            dialog("Screensaver logo", iconPicker(), async (d) => {
                if (!d.icon_data && !d.remove_icon)
                    throw new Error("Choose an image or select remove.");
                await save("settings.logo", {
                    logo_data: d.remove_icon ? null : d.icon_data,
                });
            });
            break;
        case "profile":
            dialog(
                "Edit profile",
                field("name", "Name", view.user.name, "text", true) +
                    field("email", "Email", view.user.email, "email", true),
                async (d) => {
                    await online("profile", d);
                },
            );
            break;
        case "password":
            dialog(
                "Change password",
                field(
                    "current_password",
                    "Current password",
                    "",
                    "password",
                    true,
                ) +
                    field("password", "New password", "", "password", true) +
                    field(
                        "password_confirmation",
                        "Confirm password",
                        "",
                        "password",
                        true,
                    ),
                async (d) => {
                    await online("password", d);
                },
            );
            break;
        case "api-key":
            dialog(
                "OpenRouter API key",
                field("openrouter_api_key", "API key", "", "password") +
                    check("remove_api_key", "Remove saved key", false),
                async (d) => {
                    await online("settings", {
                        ...d,
                        time_format: view.user.time_format || "24",
                        week_starts_on: view.user.week_starts_on ?? 1,
                        default_chat_model: view.user.default_chat_model,
                    });
                },
            );
            break;
        case "admin-users": {
            const result = await online("admin/users", null);
            dialog(
                "Users",
                `<pre>${esc(JSON.stringify(result, null, 2))}</pre>`,
                async () => {},
                "Done",
            );
            break;
        }
        case "retry":
            state = await invoke("logging_resolve", { id, retry: true });
            view = project(state);
            render();
            void sync();
            break;
        case "correct-operation": {
            const queued = state.operations!.find(
                (q) => q.operation.id === id,
            )!;
            const original = queued.operation.data;
            const fields = Object.entries(original)
                .map(([key, value]) =>
                    typeof value === "boolean"
                        ? check(key, key.replaceAll("_", " "), value)
                        : field(
                              key,
                              key.replaceAll("_", " "),
                              Array.isArray(value) ? value.join(", ") : value,
                              typeof value === "number" ? "number" : "text",
                          ),
                )
                .join("");
            dialog(
                "Correct saved input",
                fields,
                async (data) => {
                    for (const [key, value] of Object.entries(original)) {
                        if (Array.isArray(value))
                            data[key] = String(data[key])
                                .split(",")
                                .map((s) =>
                                    typeof value[0] === "number"
                                        ? Number(s.trim())
                                        : s.trim(),
                                );
                        if (data[key] === "" && value === null)
                            data[key] = null;
                    }
                    state = await invoke("logging_correct", {
                        id,
                        data,
                        editedAt: new Date(
                            Date.now() + (state.clock_offset || 0),
                        ).toISOString(),
                    });
                    view = project(state);
                    render();
                    void sync();
                },
                "Save and retry",
            );
            break;
        }
        case "discard":
            if (
                confirm(
                    "Discard this saved edit? The server version will be kept.",
                )
            ) {
                state = await invoke("logging_resolve", { id, retry: false });
                view = project(state);
                render();
            }
            break;
        case "copy-operation":
            await navigator.clipboard.writeText(
                JSON.stringify(
                    state.operations?.find((q) => q.operation.id === id)
                        ?.operation.data,
                    null,
                    2,
                ),
            );
            status("Saved input copied.");
            break;
        case "register":
        case "forgot-password":
            await openUrl(
                new URL(
                    action,
                    localStorage.getItem("totalLogDesktop.appUrl") ||
                        "https://total-log.com/",
                ).toString(),
            );
            break;
    }
}
root.addEventListener("click", (event) => {
    const el = (event.target as Element).closest<HTMLElement>("[data-action]");
    if (el) void action(el.dataset.action!, el.dataset.id || "").catch(error);
});
document.querySelector("#open-logging")?.addEventListener("click", () => {
    page = "day";
    render();
});
// Native timers keep working while the webview is hidden/minimized. Events do not destroy open editors.
void listen("logging-updated", () => {
    if (
        document.querySelector(".logging-dialog") ||
        root.querySelector("textarea:focus,input:focus")
    )
        return;
    void reload().catch(error);
});
window.addEventListener("online", () => void sync());
void reload()
    .then(() => {
        if (state.account && !state.last_sync) void sync();
    })
    .catch(error);
