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
        transfer_stats:{session:{sent:1024,received:2048},all_time:{sent:1048576,received:2097152}},
        last_sync: Date.now(),
        clock_offset: 0,
        operations: [],
        snapshot: {
            user: {
                id: 1,
                name: "Alex",
                email: "alex@example.com",
                time_format: "12",
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
                    metadata: { location: { latitude: 24.910616, longitude: 121.499695 } },
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
    fixture.snapshot.blocks.push(...Array.from({length:110},(_,i)=>({id:1000+i,daily_log_id:1,type:'text',content:`Example entry ${i}`,occurred_at:`${date}T14:00:00`})));
    window.__fixture = fixture;
    window.__syncCalls = 0;
    window.__locationCalls = 0;
    Object.defineProperty(navigator,'geolocation',{value:{getCurrentPosition(success,failure){
        window.__locationCalls++;
        if(window.__denyLocation) failure({code:1}); else success({coords:{latitude:24.91,longitude:121.49,accuracy:12}});
    }}});
    let next = 0;
    const callbacks = new Map();
    window.__TAURI_INTERNALS__ = {
        transformCallback: callback => {const id=++next;callbacks.set(id,callback);return id;},
        unregisterCallback: () => {},
        invoke: async (command, args) => {
            if(command === 'logging_online' && args.path === 'location/reverse') return args.data.latitude===24.91 ? {suburb:'Xindian',city:'New Taipei'} : {};
            if(command==='plugin:event|listen' && args.event==='logging-updated') {window.__emitLoggingUpdated=()=>callbacks.get(args.handler)?.({event:'logging-updated',payload:null});return 1;}
            if(command === 'system_date_formats') return {locale:'en-US',date:'yyyy-MM-dd',time:'HH:mm',am:'AM',pm:'PM'};
            if(command === 'logging_reset_transfer_stats') {fixture.transfer_stats={session:{sent:0,received:0},all_time:{sent:0,received:0}};return structuredClone(fixture.transfer_stats);}
            if (command === "logging_state") return structuredClone(fixture);
            if (command === "logging_enqueue") {
                fixture.operations.push({
                    operation: args.operation,
                    status: "pending",
                });
                return null; // Local durability acknowledgement, never a full cache.
            }
            if (command === "logging_sync") {
                window.__lastSyncScope = args.scope;
                window.__syncCalls++;
                await new Promise(resolve=>setTimeout(resolve,200));
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
    assert.doesNotMatch(await page.locator('.sync-banner').textContent(), /\b(AM|PM)\b/);
    const dayScope = await page.evaluate(() => window.__lastSyncScope);
    assert.equal(dayScope.from, dayScope.to);
    await page.locator('.timeline-row').first().waitFor();
    assert.equal(await page.locator('.event-grid').count(), 2);
    assert.equal(await page.locator('.logging-sidebar .sync-banner').count(), 1);
    await page.locator('.logging-main').evaluate(el=>{el.scrollTop=900;});
    const syncScroll=await page.locator('.logging-main').evaluate(el=>el.scrollTop);
    await page.getByRole('button',{name:'Sync now',exact:true}).click();
    await page.waitForTimeout(350);
    assert.equal(await page.locator('.logging-main').evaluate(el=>el.scrollTop),syncScroll);
    await page.evaluate(()=>window.__emitLoggingUpdated());
    await page.waitForTimeout(100);
    assert.equal(await page.locator('.logging-main').evaluate(el=>el.scrollTop),syncScroll);
    await page.locator('.timeline-row').first().click();
    await page.getByRole('dialog').getByLabel('Entry text').waitFor();
    assert.equal(await page.locator('dialog .entry-content, dialog .location-controls, dialog .emoji-picker').count(), 0);
    assert.equal(await page.locator('dialog .entry-coordinates').textContent(), '24.910616, 121.499695');
    assert.match(await page.locator('dialog .entry-date-time').textContent(), /\d{4}-\d{2}-\d{2} · 08:30/);
    const footerRows = await page.locator('dialog .dialog-footer button').evaluateAll(els => els.map(el => Math.round(el.getBoundingClientRect().top)));
    assert.equal(new Set(footerRows).size, 1);
    assert.equal(await page.locator('dialog .dialog-body').evaluate(el => el.scrollHeight <= el.clientHeight), true);
    await page.screenshot({path:'desktop/dist/compact-edit-preview.png'});

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
    assert.equal(await page.locator('.day-goals .goal-pill').count(),1);
    assert.ok(await page.locator('.day-goals .goal-count').textContent());
    await page.locator('.day-goals .goal-pill').first().click();
    await page.locator('.goal-detail-page').waitFor();
    await page.getByRole('button',{name:'Daily log',exact:true}).click();
    assert.equal(await page.locator('.sticky-events [data-id="3"]').count(), 1);
    assert.equal(await page.locator('.daily-events [data-id="3"]').count(), 0);
    assert.equal(await page.locator('.daily-events [data-id="1"]').count(), 0, 'Existing logged event is absent from quick buttons');
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
    assert.equal(await page.getByLabel('Latitude',{exact:true}).count(),0);
    await page.locator('dialog [data-location-status]').filter({hasText:'Xindian, New Taipei'}).waitFor();
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
    await page.locator('.timeline-row.entry-accent').waitFor();
    const addedRow=page.locator('.timeline-row.entry-accent');
    const visibleBox=await addedRow.boundingBox();
    assert.ok(visibleBox.y>=0 && visibleBox.y+visibleBox.height<=820,'Added entry scrolls into view');
    const savedScroll=await page.locator('.logging-main').evaluate(el=>el.scrollTop);
    await page.waitForTimeout(400);
    assert.equal(await page.locator('.logging-main').evaluate(el=>el.scrollTop),savedScroll,'Post-save sync preserves scroll');
    await page.locator('.timeline-row.entry-accent').waitFor({state:'detached',timeout:4000});
    await page.locator('.timeline-row').filter({hasText:'Offline entry from smoke test'}).click();
    await page.getByLabel('Entry text').fill('Edited entry from smoke test');
    await page.getByRole('dialog').getByRole('button',{name:'Save',exact:true}).click();
    await page.locator('.timeline-row.entry-accent').filter({hasText:'Edited entry from smoke test'}).waitFor();
    assert.deepEqual(await page.evaluate(() => window.__fixture.operations[0].operation.data.location), {latitude: 24.91, longitude: 121.49, location_accuracy:12});
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
    await page.getByLabel('Repeats',{exact:true}).selectOption('weekly');
    assert.equal(await page.locator('[data-recurrence="weekly"]').isVisible(),true);
    assert.equal(await page.locator('[data-recurrence="daily"]').isVisible(),false);
    assert.equal(await page.locator('[data-recurrence="monthly"]').isVisible(),false);
    await page.locator('[data-weekday="2"]').check();
    await page.getByLabel('Repeats',{exact:true}).selectOption('monthly');
    assert.equal(await page.locator('[data-recurrence="monthly"]').isVisible(),true);
    assert.equal(await page.locator('[data-recurrence="weekly"]').isVisible(),false);
    await page.getByLabel('Repeats',{exact:true}).selectOption('daily');
    await page.getByLabel('Repeat every (days)',{exact:true}).fill('2');
    assert.equal(await page.getByLabel('First occurrence',{exact:true}).getAttribute('required'),'');
    await page.getByLabel('Repeat every (days)',{exact:true}).fill('1');
    await page.getByRole('button',{name:'+ Add time slot',exact:true}).click();
    await page.getByLabel('Scheduled time',{exact:true}).fill('11:30');
    assert.equal(await page.locator('[name="scheduled_times_text"]').inputValue(),'11:30');
    await page.getByRole('button',{name:'Remove time slot',exact:true}).click();
    await page.getByLabel('Sticky event',{exact:true}).check();
    await page.getByLabel('Become visible at a set time',{exact:true}).check();
    assert.equal(await page.getByLabel('Visible after',{exact:true}).isVisible(),true);
    await page.getByLabel('Sticky event',{exact:true}).uncheck();
    assert.equal(await page.getByLabel('Visible after',{exact:true}).isVisible(),false);
    assert.equal(await page.locator('[data-event-image] [name="__icon_file"]').count(),1);
    await page.locator('dialog[open] .dialog-body').evaluate(el=>{el.scrollTop=0;});
    await page.screenshot({path:'desktop/dist/event-setup-preview.png'});

    await page
        .getByRole("dialog")
        .getByRole("button", { name: "Save", exact: true })
        .click();
    await page.getByRole("heading", { name: /Stretch/ }).waitFor();
    await page.getByRole("button", { name: "Daily log", exact: true }).click();
    await page.getByRole("button", { name: /Stretch/ }).first().click();
    await page.getByRole("dialog").waitFor();
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
    assert.equal(await page.locator('.event-strip [data-action="log-event"]').filter({hasText:'Stretch'}).count(),0,'Logged events disappear from quick buttons');
    const createdEventId=await page.evaluate(()=>window.__fixture.operations.at(-1).operation.id);
    await page.getByLabel('Entry text').fill('Updated immediately created event');
    await page.getByRole('dialog').getByRole('button',{name:'Save',exact:true}).click();
    assert.equal(await page.evaluate(()=>window.__fixture.operations.at(-1).operation.kind),'events.update');
    assert.equal(await page.evaluate(()=>window.__fixture.operations.at(-1).operation.target_id),createdEventId);
    assert.equal(await page.locator('.timeline-row').filter({hasText:'Updated immediately created event'}).count(),1);
    await page.evaluate(()=>{window.__fixture.snapshot.tasks.push({id:20,name:'Scheduled walk',emoji:'X',is_active:true,is_sticky:true,daily_default_count:1,scheduled_times:['11:00','18:00']});window.__emitLoggingUpdated();});
    await page.locator('.scheduled-button[data-id="20"][data-slot="11:00"]').waitFor();
    assert.equal(await page.locator('.event-strip [data-id="20"]').count(),0,'Scheduled events never duplicate into quick buttons');
    const clickTime=await page.evaluate(()=>{const d=new Date();return `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;});
    await page.locator('.scheduled-button[data-id="20"][data-slot="11:00"]').click();
    await page.getByRole('dialog').waitFor();
    assert.equal(await page.evaluate(()=>window.__fixture.operations.at(-1).operation.data.occurred_at),clickTime);
    assert.equal(await page.evaluate(()=>window.__fixture.operations.at(-1).operation.data.scheduled_time),'11:00');
    assert.equal(await page.locator('.scheduled-button[data-id="20"][data-slot="11:00"]').count(),0);
    assert.equal(await page.locator('.scheduled-button[data-id="20"][data-slot="18:00"]').count(),1);
    await page.getByRole('dialog').getByRole('button',{name:'Close',exact:true}).click();
    await page.evaluate(()=>{window.__fixture.snapshot.tasks.push({id:21,name:'Read genre',emoji:'X',is_active:true,daily_default_count:1,options:['Fiction','Science']});window.__emitLoggingUpdated();});
    await page.locator('.event-strip [data-id="21"]').click();
    await page.getByRole('dialog').waitFor();
    assert.equal(await page.locator('dialog[open] textarea, dialog[open] [name="occurred_at"]').count(),0,'First step only asks for the option');
    const optionEvent=await page.evaluate(()=>window.__fixture.operations.at(-1).operation);
    assert.equal(optionEvent.kind,'events.create');
    assert.equal(optionEvent.data.value,'Fiction');
    await page.getByLabel('Option',{exact:true}).selectOption('Science');
    await page.waitForFunction(()=>window.__fixture.operations.at(-1).operation.data.value==='Science');
    assert.equal(await page.evaluate(()=>window.__fixture.operations.at(-1).operation.target_id),optionEvent.id);
    await page.getByRole('dialog').getByRole('button',{name:'Done',exact:true}).click();
    await page.getByRole('dialog').getByLabel('Entry text').waitFor();
    assert.equal(await page.getByRole('dialog').getByLabel('Value',{exact:true}).inputValue(),'Science');
    await page.getByRole('dialog').getByLabel('Entry text').fill('Selected genre note');
    await page.getByRole('dialog').getByRole('button',{name:'Save',exact:true}).click();
    assert.equal(await page.evaluate(()=>window.__fixture.operations.at(-1).operation.target_id),optionEvent.id);
    await page.locator('.timeline-row').filter({hasText:'Selected genre note'}).click();
    assert.equal(await page.getByRole('dialog').getByLabel('Value',{exact:true}).inputValue(),'Science');
    await page.getByRole('dialog').getByRole('button',{name:'Close',exact:true}).click();
    await page.getByRole('button',{name:'Add defined event',exact:true}).click();
    await page.locator('.all-events-options').waitFor({state:'visible'});
    assert.equal(await page.locator('.all-events-options [data-id="1"]').count(),1,'Completed events remain available in the dropdown');
    assert.equal(await page.locator('.all-events-options [data-id="20"]').count(),1,'Scheduled events remain available in the dropdown');
    await page.screenshot({path:'desktop/dist/all-events-preview.png'});
    await page.locator('.all-events-options [data-id="1"]').click();
    await page.getByRole('dialog').waitFor();
    assert.equal(await page.evaluate(()=>window.__fixture.operations.at(-1).operation.data.manual_entry),true);
    assert.equal(await page.evaluate(()=>window.__fixture.operations.at(-1).operation.data.scheduled_time),null);
    await page.getByRole('dialog').getByRole('button',{name:'Close',exact:true}).click();
    await page.getByRole('button',{name:'Events',exact:true}).click();
    const orderBefore=await page.locator('[data-event-row]').evaluateAll(rows=>rows.map(row=>row.dataset.eventRow));
    const movedId=orderBefore.at(-1);
    await page.locator(`[data-event-drag="${movedId}"]`).dragTo(page.locator('[data-event-row]').first(),{targetPosition:{x:30,y:3}});
    await page.waitForFunction(id=>document.querySelector('[data-event-row]')?.dataset.eventRow===id,movedId);
    const reordered=await page.locator('[data-event-row]').evaluateAll(rows=>rows.map(row=>row.dataset.eventRow));
    assert.deepEqual(reordered,[movedId,...orderBefore.slice(0,-1)]);
    await page.getByRole('button',{name:'Daily log',exact:true}).click();
    await page.getByRole('button',{name:'Add defined event',exact:true}).click();
    assert.deepEqual(await page.locator('.all-events-options [data-id]').evaluateAll(rows=>rows.map(row=>row.dataset.id)),reordered,'Dropdown uses saved drag order');
    await page.getByRole('button',{name:'Add defined event',exact:true}).click();
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
            assert.equal(await page.locator('.week-dots i').count(),100);
            assert.equal(await page.locator('.goal-strip .goal-pill').count(),1);
            await page.screenshot({path:'desktop/dist/week-goals-preview.png'});
            await page.locator('.goal-pill').first().click();
            await page.getByRole('heading',{name:'Activity in this period'}).waitFor();
            assert.equal(await page.locator('.goal-history-row').count(),12);
            await page.getByRole('button',{name:'Previous period',exact:false}).click();
            await page.getByRole('button',{name:'Current',exact:true}).click();
            await page.screenshot({path:'desktop/dist/goal-details-preview.png'});
            await page.getByRole('button',{name:'Goal setup',exact:true}).click();
            await page.getByRole('dialog').getByLabel('Filter projects').fill('Total');
            await page.getByRole('button',{name:'Add repository Total Log',exact:true}).click();
            assert.equal(await page.locator('dialog [name="github_projects_text"]').inputValue(),'Total Log');
            await page.getByRole('button',{name:'Remove repository Total Log',exact:true}).click();
            assert.equal(await page.locator('dialog [name="github_projects_text"]').inputValue(),'');
            await page.getByRole('button',{name:'Add repository Total Log',exact:true}).click();
            assert.equal(await page.locator('.dialog-header .event-setup-description').textContent(),'Set the target and choose what earns points.');
            assert.equal(await page.locator('.goal-appearance-row [name="color"]').count(),1);
            assert.equal(await page.locator('.goal-appearance-row [name="__icon_file"]').count(),1);
            await page.locator('dialog[open] .dialog-body').evaluate(el=>{el.scrollTop=0;});
            await page.screenshot({path:'desktop/dist/goal-setup-preview.png'});
            await page.getByRole('button',{name:'Save changes',exact:true}).click();
            await page.getByRole('dialog').waitFor({state:'hidden'});
            assert.equal(await page.evaluate(()=>window.__fixture.operations.at(-1).operation.data.github_projects_text),'Total Log');
            await page.getByRole('button',{name:'View calendar',exact:true}).click();
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
        if(name === 'Goals') {
            await page.screenshot({path:'desktop/dist/goal-list-preview.png'});
            await page.locator('.goal-list-link').first().click();
            await page.getByRole('heading',{name:'Activity in this period'}).waitFor();
            await page.getByRole('button',{name:'Goals',exact:true}).click();
            await page.getByRole('button',{name:'Setup',exact:true}).first().click();
            await page.getByRole('dialog').getByLabel('Goal name').waitFor();
            await page.getByRole('dialog').getByRole('button',{name:'Cancel',exact:true}).click();
        }
        if (name === 'Sensors') assert.equal(await page.getByRole('button', {name:'Open desktop tracker',exact:true}).count(), 0);
        if (name === 'Settings') {
            assert.equal(await page.locator('.workspace-card').filter({has:page.getByRole('heading',{name:'Your account'})}).getByRole('button',{name:'Sign in again'}).count(),1);
            assert.equal(await page.locator('.preferences-row > label').count(),3);
        }
    }
    await page.getByRole('button', {name:'Kindle', exact:true}).click();
    assert.equal(await page.locator('#kindle-host #kindle-connect').isVisible(), true);
    await page.getByRole('button', {name:'Desktop tracker', exact:true}).click();
    assert.equal(await page.locator('#tracker-host .kindle-panel').count(), 0);
    await page.getByRole('button', {name:'Kindle', exact:true}).click();
    assert.equal(await page.locator('#kindle-host #kindle-sync').isVisible(), true);
    const brand = await page.locator('.sidebar-brand-row > button').evaluateAll(els => els.map(el => el.getBoundingClientRect().top));
    assert.ok(Math.abs(brand[0] - brand[1]) < 8);
    await page.getByRole("button", { name: "Settings", exact: true }).click();
    assert.match(await page.locator('[data-transfer-period="session"]').textContent(),/1 KiB.*2 KiB/);
    assert.match(await page.locator('[data-transfer-period="all_time"]').textContent(),/1 MiB.*2 MiB/);
    const pendingBeforeReset=await page.evaluate(()=>window.__fixture.operations.length);
    await page.getByRole('button',{name:'Reset counters',exact:true}).click();
    await page.locator('[data-transfer-period="all_time"]').filter({hasText:'0 B'}).waitFor();
    assert.equal(await page.locator('.sync-transfer-stats td').allTextContents().then(values=>values.every(v=>v==='0 B')),true);
    assert.equal(await page.evaluate(()=>window.__fixture.operations.length),pendingBeforeReset);
    assert.equal(await page.getByLabel('Save location with log entries').isChecked(),true);
    await page.getByLabel('Save location with log entries').uncheck();
    await page.getByRole('button',{name:'Save preferences',exact:true}).click();
    const captureCount=await page.evaluate(()=>window.__locationCalls);
    await page.getByRole('button',{name:'Daily log',exact:true}).click();
    await page.getByRole('button',{name:'Add to your day',exact:true}).click();
    assert.equal(await page.locator('dialog [name="__location"]').count(),0);
    assert.equal(await page.evaluate(()=>window.__locationCalls),captureCount);
    await page.getByRole('dialog').getByRole('button',{name:'Cancel',exact:true}).click();
    await page.getByRole('button',{name:'Settings',exact:true}).click();
    assert.equal(await page.getByLabel('Save location with log entries').isChecked(),false);
    await page.getByLabel('Save location with log entries').check();
    await page.getByRole('button',{name:'Save preferences',exact:true}).click();
    await page.evaluate(()=>{window.__denyLocation=true;});
    await page.getByRole('button',{name:'Daily log',exact:true}).click();
    await page.getByRole('button',{name:'Add to your day',exact:true}).click();
    assert.equal(await page.locator('dialog [data-location-status]').isVisible(),false);
    await page.getByLabel('Entry text').fill('Entry without location permission');
    await page.getByRole('button',{name:'Save entry',exact:true}).click();
    await page.getByRole('dialog').waitFor({state:'hidden'});
    assert.equal(await page.evaluate(()=>window.__fixture.operations.at(-1).operation.data.location),undefined);
    await page.getByRole('button',{name:'Settings',exact:true}).click();
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
    assert.equal(await page.locator('.logging-sidebar > nav').evaluate(el=>el.scrollHeight<=el.clientHeight),true);
    assert.equal(await page.locator('.sidebar-sync .nav-icon').count(),1);
    await page.setViewportSize({width:760,height:400});
    const fixedSidebar = () => page.locator('.sidebar-brand-row, .sidebar-footer').evaluateAll(els=>els.map(el=>({top:el.getBoundingClientRect().top,bottom:el.getBoundingClientRect().bottom})));
    const beforeScroll=await fixedSidebar();
    await page.locator('.logging-sidebar > nav').evaluate(el=>{el.scrollTop=el.scrollHeight;});
    assert.deepEqual(await fixedSidebar(),beforeScroll);
    assert.ok(beforeScroll[1].bottom<=400);
    await page.setViewportSize({width:760,height:650});
    await page.getByRole('button', {name:'Collapse sidebar'}).click();
    assert.equal(await page.locator('.sidebar-brand-row .brand-mark').isVisible(),false);
    assert.equal(await page.locator('.sidebar-sync').isVisible(),true);
    assert.equal(await page.locator('.logging-sidebar .sync-banner').isVisible(), false);
    assert.equal(await page.locator('.logging-sidebar .sidebar-account strong').isVisible(), false);
    await page.getByRole('button', {name:'Daily log', exact:true}).click();
    assert.equal(await page.locator('.logging-sidebar').evaluate(el => el.getBoundingClientRect().width),52);
    await page.getByRole('button', {name:'Expand sidebar'}).click();
    await page.getByRole('button', {name:'Desktop tracker', exact:true}).click();
    await page.getByRole("button", { name: "Open log", exact: true }).click();
    await page.getByRole('button', {name:'Settings', exact:true}).click();
    for (const [theme, background] of [['light','rgb(241, 245, 249)'],['paper','rgb(233, 231, 201)'],['blue','rgb(207, 227, 244)'],['red','rgb(33, 15, 20)'],['dark','rgb(2, 6, 23)']]) {
        await page.getByLabel('Theme', {exact:true}).selectOption(theme);
        assert.equal(await page.locator('#logging-app').evaluate(el=>getComputedStyle(el).backgroundColor),background);
        assert.equal(await page.evaluate(()=>localStorage.getItem('totallog.theme')),theme);
    }
    await page.getByLabel('Theme', {exact:true}).selectOption('paper');
    await page.screenshot({path:'desktop/dist/theme-paper-preview.png'});
    await page.reload();
    await page.locator('#logging-app .logging-sidebar').waitFor();
    assert.equal(await page.locator('html').getAttribute('data-theme'),'paper');
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
