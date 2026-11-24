// Run with: npm exec --yes --package=@playwright/test -- node tests/desktop-ui.smoke.mjs
// Uses mocked native IPC, never a real account. Start desktop Vite on port 1420 first.
import { existsSync } from "node:fs";
import { resolve, delimiter } from "node:path";
import { pathToFileURL } from "node:url";
import assert from "node:assert/strict";
const bin = process.env.PATH.split(delimiter).find((p) =>
    existsSync(resolve(p, "..", "playwright", "index.mjs")),
);
if (!bin) throw new Error("Run through npm exec --package=@playwright/test");
const { chromium } = await import(
    pathToFileURL(resolve(bin, "..", "playwright", "index.mjs"))
);
const browser = await chromium.launch({ channel: "msedge", headless: true });
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
const errors = [];
page.on("pageerror", (e) => errors.push(String(e)));
await page.addInitScript(() => {
    const today = new Date();
    const date = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}`;
    const fixture = {
        account: {
            id: "fixture",
            server: "https://total-log.com/",
            user_id: 1,
        },
        last_sync: Date.now(),
        clock_offset: 0,
        operations: [],
        snapshot: {
            user: {
                id: 1,
                name: "Alex",
                email: "alex@example.com",
                time_format: "24",
                week_starts_on: 1,
            },
            tasks: [
                {
                    id: 1,
                    name: "Morning walk",
                    emoji: "🚶",
                    recurrence_type: "daily",
                    is_active: true,
                    daily_default_count: 1,
                    position: 1,
                },
                {
                    id: 2,
                    name: "Read",
                    emoji: "📖",
                    recurrence_type: "daily",
                    is_active: true,
                    daily_default_count: 1,
                    position: 2,
                },
                {
                    id: 3,
                    name: "Water",
                    is_sticky: true,
                    emoji: "💧",
                    recurrence_type: "daily",
                    is_active: true,
                    daily_default_count: 8,
                    position: 3,
                },
            ],
            logs: [{ id: 1, log_date: date }],
            blocks: [
                { id: 101, daily_log_id: 1, type: 'sensor_desktop', occurred_at: `${date}T10:00:00`, desktop_activities: [{ application: 'Editor', duration_seconds: 120 }, { application: 'Editor', duration_seconds: 60 }] },
                { id: 102, daily_log_id: 1, type: 'sensor_browser', occurred_at: `${date}T10:01:00`, browsing_activities: [{ domain: 'example.com', duration_seconds: 120 }] },
                { id: 103, daily_log_id: 1, type: 'sensor_github', occurred_at: `${date}T10:02:00`, metadata: { commits: [{ project: 'Total Log', message: 'Update desktop', sha: 'abcdef123', url: 'https://github.com/example/project/commit/abcdef123', occurred_at: `${date}T10:02:00` }] } },
                {
                    id: 1,
                    daily_log_id: 1,
                    type: "text",
                    emoji: "📝",
                    content:
                        "A quiet start to the day. Finished planning the week and made time for a walk.",
                    occurred_at: `${date}T08:30:00`,
                },
                {
                    id: 2,
                    daily_log_id: 1,
                    type: "event",
                    emoji: "🚶",
                    task_event: {
                        id: 1,
                        task_name: "Morning walk",
                        task_definition_id: 1,
                    },
                    occurred_at: `${date}T09:00:00`,
                },
            ],
            goals: [
                {
                    id: 1,
                    name: "Read a little every day",
                    emoji: "📖",
                    target_points: 7,
                    period: "weekly",
                    manual_enabled: true,
                    entries: [{ points: 2, occurred_at: `${date}T09:00:00` }],
                },
            ],
            sensors: [],
            api_calls: [],
            chat_proposals: [],
        },
    };
    window.__fixture = fixture;
    window.__syncCalls = 0;
    let next = 0;
    window.__TAURI_INTERNALS__ = {
        transformCallback: () => ++next,
        unregisterCallback: () => {},
        invoke: async (command, args) => {
            if (command === "logging_state") return structuredClone(fixture);
            if (command === "logging_enqueue") {
                fixture.operations.push({
                    operation: args.operation,
                    status: "pending",
                });
                return structuredClone(fixture);
            }
            if (command === "logging_sync") {
                window.__lastSyncScope = args.scope;
                window.__syncCalls++;
                throw new Error("Test offline connection");
            }
            if (command === "current_activity")
                return {
                    application: "Editor",
                    executable: "editor.exe",
                    window_title: "",
                    process_id: 123,
                    idle_seconds: 0,
                    captured_at_unix_ms: Date.now(),
                    supported: true,
                };
            return null;
        },
    };
});
try {
    await page.goto("http://localhost:1420");
    await page.getByRole('button', {name:'Sync now', exact:true}).click();
    await page.locator('#logging-message').filter({hasText:'Test offline connection'}).waitFor();
    const dayScope = await page.evaluate(() => window.__lastSyncScope);
    assert.equal(dayScope.from, dayScope.to);
    await page.locator('.timeline-row').first().waitFor();
    assert.equal(await page.locator('.event-grid').count(), 2);
    assert.equal(await page.locator('.logging-sidebar .sync-banner').count(), 1);
    await page.locator('.timeline-row').first().click();
    await page.getByRole('dialog').getByLabel('Entry text').waitFor();
    assert.equal(await page.getByRole('dialog').getByRole('button', { name: 'Hide', exact: true }).count(), 1);
    assert.equal(await page.getByRole('dialog').getByRole('button', { name: 'Delete', exact: true }).count(), 1);
    await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click();
    for (const id of [101, 102, 103]) {
        await page.locator(`.timeline-row[data-id="${id}"]`).click();
        assert.equal(await page.locator('dialog[open] input, dialog[open] textarea, dialog[open] details, dialog[open] [type="submit"]').count(), 0);
        assert.ok(await page.locator('dialog[open] .activity-item').count() > 0);
        await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click();
    }
    await page.getByRole("heading", { name: /Timeline/ }).waitFor();
    await page.screenshot({
        path: "desktop/dist/desktop-preview.png",
        fullPage: true,
    });
    assert.equal(await page.locator('#entry-form').count(), 0);
    assert.equal(await page.locator('.sticky-events [data-id="3"]').count(), 1);
    assert.equal(await page.locator('.daily-events [data-id="3"]').count(), 0);
    assert.equal(await page.locator('.daily-events [data-id="1"]').count(), 1);
    await page.getByRole('button', {name: 'Add to your day', exact: true}).click();
    await page.getByRole('slider', {name: 'Time', exact: true}).focus();
    await page.keyboard.press('Home');
    await page.keyboard.press('ArrowRight');
    assert.equal(await page.locator('dialog[open] [name="occurred_at"]').inputValue(), '00:05');
    await page.getByRole('button', {name: 'Now', exact: true}).click();
    await page.getByRole('button', {name: /Choose emoji/}).click();
    await page.getByRole('searchbox', {name: 'Search emojis'}).fill('smiling');
    assert.ok(await page.locator('dialog[open] .emoji-grid button').count() > 0);
    await page.locator('dialog[open] .emoji-grid button').first().click();
    await page.getByLabel('Latitude', {exact: true}).fill('24.91');
    await page.getByLabel('Longitude', {exact: true}).fill('121.49');
    await page.getByRole('button', {name: 'Use coordinates'}).click();
    await page.locator('dialog[open] .dialog-body').evaluate(el => { el.scrollTop = 0; });
    const artwork = await page.locator('dialog[open] .time-scroller-artwork').evaluate(el => getComputedStyle(el).backgroundImage.slice(5, -2));
    assert.equal((await page.request.get(artwork)).status(), 200, 'Time-strip artwork must load');
    await page.screenshot({path: 'desktop/dist/entry-dialog-preview.png'});
    await page
        .getByLabel("Entry text")
        .fill("Offline entry from smoke test");
    await page.getByRole("button", { name: "Save entry", exact: true }).click();
    await page
        .getByText("Offline entry from smoke test", { exact: true })
        .waitFor();
    assert.ok(
        (await page.evaluate(() => window.__syncCalls)) > 0,
        "Saving must trigger immediate sync",
    );
    assert.deepEqual(await page.evaluate(() => window.__fixture.operations[0].operation.data.location), {latitude: 24.91, longitude: 121.49});
    assert.equal(
        await page.evaluate(
            () =>
                window.__fixture.operations[0].operation.data.occurred_at
                    .length,
        ),
        5,
        "Wall-clock time must use HH:MM",
    );
    await page.getByRole("button", { name: "Events", exact: true }).click();
    await page.getByRole("button", { name: "New event", exact: true }).click();
    await page.getByLabel("Event name", { exact: true }).fill("Stretch");
    await page
        .getByRole("dialog")
        .getByRole("button", { name: "Save", exact: true })
        .click();
    await page.getByRole("heading", { name: /Stretch/ }).waitFor();
    await page.getByRole("button", { name: "Daily log", exact: true }).click();
    await page.getByRole("button", { name: /Stretch/ }).first().click();
    await page
        .getByRole("dialog")
        .getByRole("button", { name: "Log event", exact: true })
        .click();
    assert.equal(
        await page.evaluate(
            () => window.__fixture.operations.at(-1).operation.kind,
        ),
        "events.create",
    );
    const eventRef = await page.evaluate(
        () =>
            window.__fixture.operations.at(-1).operation.data
                .task_definition_id,
    );
    assert.equal(
        eventRef,
        await page.evaluate(
            () =>
                window.__fixture.operations.find(
                    (q) => q.operation.kind === "tasks.create",
                ).operation.id,
        ),
    );
    for (const name of [
        "Calendar",
        "Goals",
        "Search",
        "AI assistant",
        "Sensors",
        "Settings",
        "API usage",
    ]) {
        await page.getByRole("button", { name, exact: true }).click();
        assert.equal(await page.locator("#page-content").count(), 1);
        if (name === 'Calendar') {
            assert.equal(await page.getByRole('button', {name:'Week view'}).getAttribute('aria-pressed'), 'true');
            assert.equal(await page.locator('#page-content .calendar-day').count(), 7);
            await page.getByRole('button', {name:'Month view'}).click();
            assert.equal(await page.locator('#page-content .calendar-day').count(), 42);
            await page.getByRole('button', {name:'Sync now', exact:true}).click();
            await page.locator('#logging-message').filter({hasText:'Test offline connection'}).waitFor();
            const monthScope = await page.evaluate(() => window.__lastSyncScope);
            assert.equal(monthScope.from.slice(-2), '01');
            assert.equal(monthScope.from.slice(0,7), monthScope.to.slice(0,7));
            await page.getByRole('button', {name:'Year view'}).click();
            assert.equal(await page.locator('.year-month').count(), 12);
        }
        if (['Goals','Sensors','Settings','API usage'].includes(name)) {
            assert.equal(await page.locator('#page-content > .page-header, #page-content > .page-toolbar').evaluate(el => getComputedStyle(el).position), 'sticky');
        }
        if (name === 'Sensors') assert.equal(await page.getByRole('button', {name:'Open desktop tracker',exact:true}).count(), 0);
        if (name === 'Settings') {
            assert.equal(await page.locator('.workspace-card').filter({has:page.getByRole('heading',{name:'Your account'})}).getByRole('button',{name:'Sign in again'}).count(),1);
            assert.equal(await page.locator('.preferences-row > label').count(),3);
        }
    }
    await page.getByRole("button", { name: "Settings", exact: true }).click();
    await page.getByRole("button", { name: "Configure", exact: true }).click();
    await page
        .locator('dialog select[name="screensaver_style"]')
        .selectOption("messages");
    await page
        .getByRole("dialog")
        .getByRole("button", { name: "Save", exact: true })
        .click();
    await page.getByRole("button", { name: "Start now", exact: true }).click();
    await page.locator(".desktop-screensaver iframe").waitFor();
    await page.waitForTimeout(600);
    await page.keyboard.press("Escape");
    await page.getByRole("button", { name: "Daily log", exact: true }).click();
    await page.setViewportSize({ width: 760, height: 650 });
    assert.equal(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
        true,
        "No horizontal overflow at minimum window width",
    );
    await page
        .getByRole("button", { name: "Desktop tracker", exact: true })
        .click();
    assert.equal(await page.locator('.logging-sidebar').isVisible(), true);
    assert.equal(await page.locator('#tracker-host #tracker-app').isVisible(), true);
    await page.getByRole('button', {name:'Collapse sidebar'}).click();
    assert.equal(await page.locator('.logging-sidebar .sync-banner').isVisible(), false);
    assert.equal(await page.locator('.logging-sidebar .sidebar-account strong').isVisible(), false);
    await page.getByRole('button', {name:'Daily log', exact:true}).click();
    assert.equal(await page.locator('.logging-sidebar').evaluate(el => el.getBoundingClientRect().width),58);
    await page.getByRole('button', {name:'Expand sidebar'}).click();
    await page.getByRole('button', {name:'Desktop tracker', exact:true}).click();
    await page.getByRole("button", { name: "Open log", exact: true }).click();
    assert.deepEqual(errors, []);
    console.log(
        "Desktop UI smoke checks passed: offline logging, immediate sync, dependent events, navigation, screensaver, tracker, minimum width.",
    );
} catch (error) {
    await page.screenshot({
        path: "desktop/dist/desktop-ui-error.png",
        fullPage: true,
    });
    throw error;
} finally {
    await browser.close();
}
