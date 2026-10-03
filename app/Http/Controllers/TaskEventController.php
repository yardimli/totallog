<?php

namespace App\Http\Controllers;

use App\Models\DailyLog;
use App\Models\GoalEntry;
use App\Models\LogBlock;
use App\Models\TaskDefinition;
use App\Models\TaskEvent;
use App\Services\GoalProgressService;
use App\Services\NominatimReverseGeocoder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskEventController extends Controller
{
    public function __construct(private NominatimReverseGeocoder $geocoder, private GoalProgressService $goalProgress) {}

    public function store(Request $request, DailyLog $dailyLog, TaskDefinition $task)
    {
        $startedAt = hrtime(true);
        abort_unless($dailyLog->user_id === $request->user()->id && $task->user_id === $request->user()->id, 403);
        abort_unless($task->is_active && $task->occursOn($dailyLog->log_date), 422, 'This event is not scheduled for this day.');
        $data = $request->validate([
            'value' => [empty($task->options) ? 'nullable' : 'required', 'nullable', 'string', 'max:100'],
            'scheduled_time' => ['nullable', \App\Support\DayTime::RULE],
        ]);
        if (! empty($task->options) && ! in_array($data['value'] ?? null, $task->options, true)) {
            abort(422, 'Choose a valid value.');
        }
        $scheduledTime = $data['scheduled_time'] ?? null;
        if ($scheduledTime !== null && ! in_array($scheduledTime, $task->scheduled_times ?? [], true)) {
            abort(422, 'Choose a valid event time slot.');
        }

        $eventCounts = $dailyLog->taskEvents()
            ->where('task_definition_id', $task->id)
            ->selectRaw('COUNT(*) as total_count');
        if ($scheduledTime !== null) {
            $eventCounts->selectRaw('SUM(CASE WHEN scheduled_time = ? THEN 1 ELSE 0 END) as selected_slot_count', [$scheduledTime]);
        }
        $eventCounts = $eventCounts->first();
        $count = (int) $eventCounts->total_count + 1;
        $slotCount = $scheduledTime === null ? null : (int) $eventCounts->selected_slot_count + 1;
        abort_if($scheduledTime !== null && $slotCount > $task->daily_default_count, 422, 'This event time slot has reached its daily count.');

        $now = now();
        $occurredAt = $dailyLog->log_date->copy()->setTime($now->hour, $now->minute, $now->second);
        $position = ($occurredAt->hour * 3600) + ($occurredAt->minute * 60) + $occurredAt->second;
        $event = DB::transaction(function () use ($dailyLog, $task, $data, $scheduledTime, $occurredAt, $position) {
            $block = $dailyLog->blocks()->create(['type' => 'event', 'emoji' => $task->emoji, 'icon_data' => $task->icon_data, 'position' => $position, 'occurred_at' => $occurredAt]);

            return TaskEvent::create(['daily_log_id' => $dailyLog->id, 'task_definition_id' => $task->id, 'log_block_id' => $block->id, 'task_name' => $task->name, 'selected_value' => $data['value'] ?? null, 'scheduled_time' => $scheduledTime, 'occurred_at' => $occurredAt]);
        });
        $this->goalProgress->syncForUser($request->user());

        return response()->json(['message' => "$task->name logged.", 'event' => $event, 'count' => $count, 'slot_count' => $slotCount, 'edit_url' => route('events.update', $event), 'location_url' => route('events.location', $event), 'hide_url' => route('blocks.visibility', $event->log_block_id), 'delete_url' => route('blocks.destroy', $event->log_block_id), 'block_id' => $event->log_block_id, 'emoji' => $task->emoji, 'icon_data' => $task->icon_data, 'time' => $event->occurred_at->format('H:i')], 201)
            ->header('Server-Timing', sprintf('event-store;dur=%.1f', (hrtime(true) - $startedAt) / 1_000_000));
    }

    public function edit(Request $request, TaskEvent $event)
    {
        $this->authorizeEvent($request, $event);
        $event->load('block.attachments');

        return view('events.edit', compact('event'));
    }

    public function update(Request $request, TaskEvent $event)
    {
        $this->authorizeEvent($request, $event);
        $goalEntry = $this->manualGoalEntry($request, $event);
        $data = $request->validate([
            'notes' => 'nullable|string|max:100000',
            'emoji' => 'nullable|string|max:32',
            'occurred_at' => ['sometimes', 'required', \App\Support\DayTime::RULE],
        ]);
        $updates = ['content' => $data['notes'] ?? null];
        if (array_key_exists('emoji', $data)) {
            $updates['emoji'] = $data['emoji'] ?: LogBlock::defaultEmojiForType('event');
        }
        if (isset($data['occurred_at'])) {
            $occurredAt = $event->dailyLog->log_date->copy()->setTimeFromTimeString($data['occurred_at']);
            $event->update(['occurred_at' => $occurredAt]);
            $updates['occurred_at'] = $occurredAt;
        }
        $event->block->update($updates);
        if ($goalEntry) {
            $goalEntry->update([
                'occurred_at' => $updates['occurred_at'] ?? $goalEntry->occurred_at,
                'note' => $updates['content'],
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Event updated.', 'event' => $event->fresh(), 'updated_time' => $request->user()->formatTime($event->block->fresh()->updated_at)]);
        }

        return redirect()->route('logs.show', $event->dailyLog->log_date->toDateString())->with('status', 'Event updated.');
    }

    public function updateLocation(Request $request, TaskEvent $event)
    {
        $this->authorizeEvent($request, $event);
        $data = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0|max:100000',
        ]);
        $place = $this->geocoder->reverse((float) $data['latitude'], (float) $data['longitude']);
        $event->update([
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'location_accuracy' => $data['accuracy'] ?? null,
            'city' => $place['city'] ?? null,
            'suburb' => $place['suburb'] ?? null,
        ]);

        return response()->json([
            'message' => 'Event location saved.',
            'location' => ['latitude' => $event->latitude, 'longitude' => $event->longitude, 'city' => $event->city, 'suburb' => $event->suburb],
        ]);
    }

    private function authorizeEvent(Request $request, TaskEvent $event): void
    {
        abort_unless($event->dailyLog()->where('user_id', $request->user()->id)->exists(), 403);
    }

    private function manualGoalEntry(Request $request, TaskEvent $event): ?GoalEntry
    {
        $entryId = data_get($event->block->metadata, 'goal_entry_id');
        if (! $entryId) {
            return null;
        }

        return GoalEntry::query()->whereKey($entryId)
            ->whereHas('goal', fn ($query) => $query->where('user_id', $request->user()->id))
            ->first();
    }
}
