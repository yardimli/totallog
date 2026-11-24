export type Row = Record<string, any>;
export type Operation = {
    id: string;
    kind: string;
    target_id: string | number | null;
    edited_at: string;
    data: Row;
};
export type Queued = { operation: Operation; status: string; receipt?: Row };
export type LoggingState = {
    account: { id: string; server: string; user_id: number } | null;
    snapshot?: Row;
    operations?: Queued[];
    aliases?: Record<string,number>;
    last_sync?: number;
    clock_offset?: number;
    error?: string;
};
export const syncInterval = (foreground: boolean) =>
    foreground ? 300_000 : 3_600_000;
export function localDate(date = new Date()): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}
export function localTime(date = new Date()): string {
    return date.toTimeString().slice(0, 5);
}
export function occursOn(task: Row, date: string): boolean {
    if (task.is_active === false) return false;
    const start = task.repeat_start_date?.slice(0, 10);
    if (start && date < start) return false;
    const day = new Date(`${date}T12:00:00`);
    if (task.recurrence_type === "weekly")
        return (task.recurrence_days || []).includes(day.getDay() || 7);
    if (task.recurrence_type === "monthly")
        return (task.recurrence_days || []).includes(day.getDate());
    return (
        !start ||
        Math.round(
            (Date.parse(`${date}T00:00:00Z`) -
                Date.parse(`${start}T00:00:00Z`)) /
                86400000,
        ) %
            Math.max(1, task.repeat_interval || 1) ===
            0
    );
}

/** A reproducible local view. Server records remain untouched beneath pending edits. */
export function project(state: LoggingState): Row {
    const view: Row = structuredClone(state.snapshot || {});
    for (const collection of [
        "tasks",
        "goals",
        "logs",
        "blocks",
        "sensors",
        "api_calls",
        "chat_proposals",
    ])
        view[collection] ||= [];
    view.user ||= {};
    const ids = new Map<string,number>([
        ...Object.entries(state.aliases || {}),
        ...
        (state.operations || [])
            .filter((q) => q.receipt?.entity_id)
            .map((q):[string,number] => [q.operation.id, q.receipt!.entity_id]),
    ]);
    for (const queued of state.operations || []) {
        if (!["pending", "applied"].includes(queued.status)) continue;
        const op = queued.operation,
            data = { ...op.data },
            [entity, action] = op.kind.split(".");
        const target = ids.get(String(op.target_id)) || op.target_id;
        const id = ids.get(op.id) || op.id;
        const find = (collection: string, key: unknown) =>
            view[collection].find((row: Row) => String(row.id) === String(key));
        const log = () => {
            let row = view.logs.find((r: Row) => r.log_date === data.log_date);
            if (!row) {
                row = { id: `local:${data.log_date}`, log_date: data.log_date };
                view.logs.push(row);
            }
            return row;
        };
        if (entity === "settings") {
            if (action === "logo") view.screensaver_logo = data.logo_data;
            else Object.assign(view.user, data);
            continue;
        }
        if (entity === "events") {
            if (action === "create") {
                const task = find(
                    "tasks",
                    ids.get(String(data.task_definition_id)) ||
                        data.task_definition_id,
                );
                view.blocks.push({
                    id: op.id,
                    daily_log_id: log().id,
                    type: "event",
                    emoji: task?.emoji || "✅",
                    _pending: true,
                    occurred_at: `${data.log_date}T${data.occurred_at || "00:00:00"}`,
                    task_event: {
                        id,
                        task_definition_id: task?.id,
                        task_name: task?.name || "Event",
                        selected_value: data.value,
                        scheduled_time: data.scheduled_time,
                    },
                });
            } else {
                const block = view.blocks.find(
                    (b: Row) => String(b.task_event?.id) === String(target),
                );
                if (block) {
                    block._pending = true;
                    Object.assign(block.task_event, data);
                    if (action === "update") {
                        block.content = data.notes;
                        block.emoji = data.emoji || block.emoji;
                        if (data.occurred_at)
                            block.occurred_at = `${view.logs.find((l:Row)=>String(l.id)===String(block.daily_log_id))?.log_date || String(block.occurred_at).slice(0,10)}T${data.occurred_at}`;
                    }
                }
            }
            continue;
        }
        const rows = view[entity];
        if (!Array.isArray(rows)) continue;
        const row = find(entity, target);
        if (action === "create") {
            const created: Row = { id, ...data, _pending: true };
            if (entity === "blocks")
                Object.assign(created, {
                    daily_log_id: log().id,
                    occurred_at: `${data.log_date}T${data.occurred_at || "00:00:00"}`,
                });
            if (entity === "tasks")
                Object.assign(created, {
                    options: split(data.options_text),
                    scheduled_times: split(data.scheduled_times_text),
                    recurrence_days:
                        data.recurrence_type === "weekly"
                            ? data.weekdays
                            : split(data.month_days_text).map(Number),
                    is_active: true,
                });
            if (entity === "goals")
                Object.assign(created, {
                    entries: [],
                    sources: data.task_definition_id
                        ? [
                              {
                                  type: "event",
                                  task_definition_id:
                                      ids.get(
                                          String(data.task_definition_id),
                                      ) || data.task_definition_id,
                              },
                          ]
                        : [],
                });
            rows.push(created);
        } else if (action === "delete") {
            view[entity] = rows.filter(
                (r: Row) => String(r.id) !== String(target),
            );
        } else if (row) {
            row._pending = true;
            if (action === "progress") {
                (row.entries ||= []).push({
                    id: op.id,
                    points: data.points,
                    note: data.note,
                    occurred_at: `${data.occurred_on}T12:00:00`,
                });
                data.log_date = data.occurred_on;
                view.blocks.push({
                    id: op.id,
                    daily_log_id: log().id,
                    type: "event",
                    emoji: row.emoji || "🎯",
                    content: data.note,
                    occurred_at: `${data.occurred_on}T12:00:00`,
                    _pending: true,
                    _goal_progress: true,
                    task_event: {
                        id: op.id,
                        task_name: row.name,
                        selected_value: `+${data.points} points`,
                    },
                });
            } else {
                Object.assign(row, data);
                if (entity === "tasks") {
                    if ("options_text" in data)
                        row.options = split(data.options_text);
                    if ("scheduled_times_text" in data)
                        row.scheduled_times = split(data.scheduled_times_text);
                    if ("recurrence_type" in data)
                        row.recurrence_days =
                            data.recurrence_type === "weekly"
                                ? data.weekdays
                                : split(data.month_days_text).map(Number);
                }
                if (entity === "blocks" && data.occurred_at)
                    row.occurred_at = `${view.logs.find((l: Row) => String(l.id) === String(row.daily_log_id))?.log_date}T${data.occurred_at}`;
                if (entity === "goals" && action === "update")
                    row.sources = data.task_definition_id
                        ? [
                              {
                                  type: "event",
                                  task_definition_id:
                                      ids.get(
                                          String(data.task_definition_id),
                                      ) || data.task_definition_id,
                              },
                          ]
                        : [];
            }
        }
    }
    view.tasks.sort((a: Row, b: Row) => (a.position || 0) - (b.position || 0));
    for (const goal of view.goals) {
        const taskIds = (goal.sources || [])
            .filter((s: Row) => s.type === "event")
            .map((s: Row) => String(s.task_definition_id));
        for (const block of view.blocks) {
            if (
                !taskIds.includes(
                    String(block.task_event?.task_definition_id),
                ) ||
                (!block._pending && !goal._pending)
            )
                continue;
            const key = `task-event:${block.task_event.id}`;
            if ((goal.entries || []).some((e: Row) => e.external_key === key))
                continue;
            (goal.entries ||= []).push({
                external_key: key,
                points: 1,
                occurred_at: block.occurred_at,
            });
        }
    }
    return view;
}
export const split = (value: unknown): string[] =>
    String(value || "")
        .split(/[,\n]+/)
        .map((s) => s.trim())
        .filter(Boolean);

export function goalPoints(goal: Row, date: string, weekStarts = 1): number {
    const end = new Date(`${date}T12:00:00`),
        start = new Date(end);
    if (goal.period === "weekly")
        start.setDate(
            start.getDate() - ((start.getDay() - weekStarts + 7) % 7),
        );
    if (goal.period === "monthly") start.setDate(1);
    if (goal.period === "weekly") end.setDate(start.getDate() + 6);
    if (goal.period === "monthly") {
        end.setMonth(end.getMonth() + 1, 0);
    }
    const from = [
        goal.period === "none" ? "0000" : localDate(start),
        goal.start_date || "0000",
    ]
        .sort()
        .at(-1)!;
    const to = [
        date,
        goal.end_date || "9999",
        goal.period === "none" ? "9999" : localDate(end),
    ].sort()[0];
    return (goal.entries || [])
        .filter((e: Row) => {
            const day = String(e.occurred_at).endsWith("Z")
                ? localDate(new Date(e.occurred_at))
                : String(e.occurred_at).slice(0, 10);
            return day >= from && day <= to;
        })
        .reduce((n: number, e: Row) => n + Number(e.points), 0);
}
