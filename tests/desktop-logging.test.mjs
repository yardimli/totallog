import assert from "node:assert/strict";
import test from "node:test";
import {
    project,
    occursOn,
    scheduledSlots,
    quickEventAvailable,
    goalPoints,
    syncInterval,
    calendarHeat,
    calendarRange,
} from "../desktop/src/logging-model.ts";

const op = (kind, data, target_id = null, id = crypto.randomUUID()) => ({
    status: "pending",
    operation: { id, kind, data, target_id, edited_at: "2026-11-23T01:00:00Z" },
});

test("calendar heat uses only nonzero, nonfuture days in the displayed month", () => {
    assert.deepEqual(calendarHeat({ '2026-11-01': 10, '2026-11-02': 30, '2026-11-03': 50,
        '2026-11-04': 0, '2026-11-25': 100, '2026-10-31': 1000 }, '2026-11', '2026-11-24'),
        { '2026-11-01': 0, '2026-11-02': 2, '2026-11-03': 4 });
    assert.deepEqual(calendarHeat({'2026-11-01': 7, '2026-11-02': 7}, '2026-11', '2026-11-24'),
        {'2026-11-01': 2, '2026-11-02': 2});
    assert.deepEqual(calendarHeat({'2026-11-01': 0}, '2026-11', '2026-11-24'), {});
});
test('calendar sync ranges handle weeks crossing months and leap years', () => {
    assert.deepEqual(calendarRange('2026-11-01', 'week', 1), {from:'2026-10-26', to:'2026-11-01'});
    assert.deepEqual(calendarRange('2024-02-12', 'month'), {from:'2024-02-01', to:'2024-02-29'});
    assert.deepEqual(calendarRange('2026-11-24', 'year'), {from:'2026-01-01', to:'2026-12-31'});
});
test("offline entry persists in the projected day and edits do not alter server snapshot", () => {
    const create = op("blocks.create", {
        log_date: "2026-11-23",
        type: "text",
        content: "First",
        occurred_at: "09:30:00",
    });
    const state = {
        account: null,
        snapshot: { blocks: [] },
        operations: [
            create,
            op(
                "blocks.update",
                { content: "Edited", occurred_at: "10:00:00" },
                create.operation.id,
            ),
        ],
    };
    const view = project(state);
    assert.equal(view.blocks[0].content, "Edited");
    assert.equal(view.blocks[0].occurred_at, "2026-11-23T10:00:00");
    assert.equal(view.logs[0].log_date, "2026-11-23");
    assert.deepEqual(state.snapshot.blocks, []);
});
test("offline event definitions can immediately be used to log events", () => {
    const task = op("tasks.create", {
        name: "Water",
        options_text: "Glass, Bottle",
        recurrence_type: "daily",
    });
    const view = project({
        account: null,
        operations: [
            task,
            op("events.create", {
                task_definition_id: task.operation.id,
                log_date: "2026-11-23",
                value: "Glass",
                occurred_at: "12:30:00",
            }),
        ],
    });
    assert.deepEqual(view.tasks[0].options, ["Glass", "Bottle"]);
    assert.equal(view.blocks[0].task_event.task_name, "Water");
    assert.equal(view.blocks[0].task_event.selected_value, "Glass");
});
test("conflicts preserve the server view and pending deletions hide records", () => {
    const conflict = {
        ...op("blocks.update", { content: "Losing edit" }, 1),
        status: "conflict",
    };
    const state = {
        account: null,
        snapshot: { blocks: [{ id: 1, content: "Server" }] },
        operations: [conflict],
    };
    assert.equal(project(state).blocks[0].content, "Server");
    state.operations.push(op("blocks.delete", {}, 1));
    assert.deepEqual(project(state).blocks, []);
});
test("applied receipts remain visible until the atomic pull completes", () => {
    const pending = op("blocks.create", {
        log_date: "2026-11-23",
        content: "Saved",
    });
    pending.status = "applied";
    pending.receipt = { entity_id: 123 };
    assert.equal(
        project({ account: null, operations: [pending] }).blocks[0].id,
        123,
    );
});
test("recurrence handles start boundaries, weekly slots and month days", () => {
    assert.equal(
        occursOn(
            { repeat_start_date: "2026-11-23", repeat_interval: 2 },
            "2026-11-22",
        ),
        false,
    );
    assert.equal(
        occursOn(
            { repeat_start_date: "2026-11-23", repeat_interval: 2 },
            "2026-11-25",
        ),
        true,
    );
    assert.equal(
        occursOn(
            { recurrence_type: "weekly", recurrence_days: [1] },
            "2026-11-23",
        ),
        true,
    );
    assert.equal(
        occursOn(
            { recurrence_type: "monthly", recurrence_days: [23] },
            "2026-11-24",
        ),
        false,
    );
});
test("goal progress respects the selected calendar period", () => {
    const goal = {
        period: "weekly",
        entries: [
            { occurred_at: "2026-11-22T12:00:00", points: 7 },
            { occurred_at: "2026-11-23T12:00:00", points: 3 },
        ],
    };
    assert.equal(goalPoints(goal, "2026-11-23", 1), 3);
});
test("foreground and background polling intervals are five minutes and one hour", () => {
    assert.equal(syncInterval(true), 5 * 60 * 1000);
    assert.equal(syncInterval(false), 60 * 60 * 1000);
});

test('an editor opened before sync can still update its original UUID offline',()=>{
  const id=crypto.randomUUID();
  const view=project({account:null,aliases:{[id]:123},snapshot:{logs:[{id:1,log_date:'2026-11-23'}],blocks:[{id:123,daily_log_id:1,content:'Server'}]},operations:[op('blocks.update',{content:'Still local'},id)]});
  assert.equal(view.blocks[0].content,'Still local');
});

test('offline goal progress appears in the timeline and event progress updates linked goals',()=>{
  const view=project({account:null,snapshot:{tasks:[{id:1,name:'Water'}],goals:[{id:2,name:'Hydrate',period:'daily',manual_enabled:true,sources:[{type:'event',task_definition_id:1}],entries:[]}]},operations:[op('events.create',{log_date:'2026-11-23',task_definition_id:1,occurred_at:'08:30'}),op('goals.progress',{occurred_on:'2026-11-23',points:3,note:'Extra'},2)]});
  assert.equal(view.blocks.length,2);
  assert.equal(goalPoints(view.goals[0],'2026-11-23'),4);
});

test('editing an early morning event keeps its daily-log date',()=>{
  const view=project({account:null,snapshot:{logs:[{id:1,log_date:'2026-11-23'}],blocks:[{id:1,daily_log_id:1,occurred_at:'2026-11-22T17:00:00Z',task_event:{id:2}}]},operations:[op('events.update',{notes:'Edit',occurred_at:'02:30'},2)]});
  assert.equal(view.blocks[0].occurred_at,'2026-11-23T02:30');
});

test('offline goal setup retains event and GitHub progress sources',()=>{
 const view=project({account:null,snapshot:{goals:[{id:4,sources:[{id:8,type:'github',github_project:'totallog'}],entries:[]}]},operations:[op('goals.update',{task_definition_id:3,github_projects_text:'totallog\nreader'},4)]});
 assert.equal(view.goals[0].sources.length,3);
 assert.equal(view.goals[0].sources.find(s=>s.github_project==='totallog').id,8);
 assert.equal(view.goals[0].sources[0].task_definition_id,3);
});

test('scheduled placeholders honor viewed-day recurrence, visibility and individual slot counts',()=>{
 const task={id:1,is_sticky:true,is_active:true,recurrence_type:'weekly',recurrence_days:[2],scheduled_times:['11:00','18:00'],visible_after:'10:00',daily_default_count:1};
 assert.equal(scheduledSlots([task],[],'2026-11-24','2026-11-24','09:00').length,0);
 assert.equal(scheduledSlots([task],[],'2026-11-24','2026-11-25','09:00').length,2);
 assert.equal(scheduledSlots([task],[],'2026-11-23','2026-11-25','09:00').length,0);
 const blocks=[{is_hidden:true,task_event:{task_definition_id:1,scheduled_time:'11:00'}}];
 assert.deepEqual(scheduledSlots([task],blocks,'2026-11-24','2026-11-25','09:00').map(s=>s.time),['18:00']);
});

test('quick buttons honor daily limits regardless of pinning and exclude scheduled events',()=>{
 assert.equal(quickEventAvailable({is_sticky:true,daily_default_count:1},1),false);
 assert.equal(quickEventAvailable({is_sticky:true,daily_default_count:99},10),true);
 assert.equal(quickEventAvailable({is_sticky:false,daily_default_count:3},1),true);
 assert.equal(quickEventAvailable({is_sticky:false,daily_default_count:3},3),false);
 assert.equal(quickEventAvailable({is_sticky:false,daily_default_count:3},4),false);
 assert.equal(quickEventAvailable({is_sticky:true,scheduled_times:['11:00']},0),false);
 assert.equal(quickEventAvailable({is_sticky:false,scheduled_times:['11:00']},0),false);
});
