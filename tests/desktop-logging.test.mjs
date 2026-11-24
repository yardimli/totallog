import assert from "node:assert/strict";
import test from "node:test";
import {
    project,
    occursOn,
    goalPoints,
    syncInterval,
} from "../desktop/src/logging-model.ts";

const op = (kind, data, target_id = null, id = crypto.randomUUID()) => ({
    status: "pending",
    operation: { id, kind, data, target_id, edited_at: "2026-11-23T01:00:00Z" },
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
