<?php

namespace App\Http\Controllers;

use App\Models\DailyLog;
use App\Models\Goal;
use App\Models\TaskEvent;
use App\Services\GoalProgressService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoalEntryController extends Controller
{
    public function __construct(private GoalProgressService $progress) {}

    public function store(Request $request, Goal $goal)
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        abort_unless($goal->manual_enabled, 422, 'Manual progress is not enabled for this goal.');
        $data = $request->validate(['points' => 'required|integer|min:1|max:1000000', 'note' => 'nullable|string|max:2000', 'occurred_on' => 'required|date']);
        $occurredAt = Carbon::parse($data['occurred_on'])->setTimeFrom(now());
        abort_unless($goal->isAvailableOn($occurredAt), 422, 'This date is outside the goal.');
        DB::transaction(function () use ($request, $goal, $data, $occurredAt) {
            $entry = $goal->entries()->create(['occurred_at' => $occurredAt, 'points' => $data['points'], 'note' => $data['note'] ?? null]);
            $log = $request->user()->dailyLogs()->whereDate('log_date', $occurredAt->toDateString())->first() ?? $request->user()->dailyLogs()->create(['log_date' => $occurredAt->toDateString()]);
            $block = $log->blocks()->create([
                'type' => 'event',
                'emoji' => $goal->emoji,
                'icon_data' => $goal->icon_data,
                'content' => $data['note'] ?? null,
                'metadata' => ['goal_id' => $goal->id, 'goal_entry_id' => $entry->id],
                'position' => ($occurredAt->hour * 3600) + ($occurredAt->minute * 60) + $occurredAt->second,
                'occurred_at' => $occurredAt,
            ]);
            TaskEvent::create([
                'daily_log_id' => $log->id,
                'task_definition_id' => null,
                'log_block_id' => $block->id,
                'task_name' => $goal->name,
                'selected_value' => '+'.$data['points'].' '.str('point')->plural($data['points']),
                'occurred_at' => $occurredAt,
            ]);
        });
        $this->progress->sync($goal);

        return redirect()->route('goals.show', ['goal' => $goal, 'date' => $data['occurred_on']])->with('status', 'Goal progress added.');
    }
}
