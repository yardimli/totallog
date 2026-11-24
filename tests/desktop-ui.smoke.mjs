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
                    emoji: "💧",
                    recurrence_type: "daily",
                    is_active: true,
                    daily_default_count: 8,
                    position: 3,
                },
            ],
            logs: [{ id: 1, log_date: date }],
            blocks: [
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
    await page.getByRole("heading", { name: /Timeline/ }).waitFor();
    await page.screenshot({
        path: "desktop/dist/desktop-preview.png",
        fullPage: true,
    });
    await page.getByLabel("Add to your day").fill("Keep my unfinished draft");
    await page.getByRole("button", { name: "Sync now", exact: true }).click();
    await page.locator('#logging-message').filter({hasText:'Test offline connection'}).waitFor();
    assert.equal(
        await page.getByLabel("Add to your day").inputValue(),
        "Keep my unfinished draft",
    );
    await page
        .getByLabel("Add to your day")
        .fill("Offline entry from smoke test");
    await page.getByRole("button", { name: "Save entry", exact: true }).click();
    await page
        .getByText("Offline entry from smoke test", { exact: true })
        .waitFor();
    assert.ok(
        (await page.evaluate(() => window.__syncCalls)) > 0,
        "Saving must trigger immediate sync",
    );
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
    await page.getByRole("button", { name: /Stretch/ }).click();
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
