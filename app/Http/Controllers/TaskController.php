<?php

namespace App\Http\Controllers;

use App\Models\TaskDefinition;
use App\Rules\CroppedIcon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        return view('tasks.index', ['tasks' => TaskDefinition::where('user_id', $request->user()->id)->orderBy('position')->orderBy('id')->get()]);
    }

    public function reorder(Request $request)
    {
        $data = $request->validate([
            'event_ids' => ['required', 'array', 'min:1'],
            'event_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ownedIds = $request->user()->taskDefinitions()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
        $requestedIds = collect($data['event_ids'])->map(fn ($id) => (int) $id);
        abort_unless($requestedIds->sort()->values()->all() === $ownedIds->all(), 422, 'The event order is incomplete.');

        DB::transaction(function () use ($requestedIds) {
            $requestedIds->values()->each(fn ($id, $position) => TaskDefinition::whereKey($id)->update(['position' => $position]));
        });

        return response()->json(['message' => 'Event order saved.']);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $task = $request->user()->taskDefinitions()->create($data);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Event created.', 'event' => $task, 'reload' => true], 201);
        }

        return redirect()->route('tasks.index')->with('status', 'Event created.');
    }

    public function update(Request $request, TaskDefinition $task)
    {
        abort_unless($task->user_id === $request->user()->id, 403);
        $task->update($this->validated($request));

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Event updated.', 'event' => $task->fresh(), 'reload' => true]);
        }

        return redirect()->route('tasks.index')->with('status', 'Event updated.');
    }

    public function destroy(Request $request, TaskDefinition $task)
    {
        abort_unless($task->user_id === $request->user()->id, 403);
        DB::transaction(function () use ($task) {
            $task->events()->with('block')->get()->each(function ($event) use ($task) {
                if ($event->block) {
                    $content = 'Event: '.$event->task_name;
                    if ($event->selected_value !== null) {
                        $content .= "\nValue: ".$event->selected_value;
                    }
                    if (filled($event->block->content)) {
                        $content .= "\n\n".$event->block->content;
                    }
                    $event->block->update([
                        'type' => 'text',
                        'content' => $content,
                        'occurred_at' => $event->occurred_at,
                        'metadata' => array_merge($event->block->metadata ?? [], [
                            'converted_from_event' => [
                                'definition_id' => $task->id,
                                'name' => $event->task_name,
                                'value' => $event->selected_value,
                                'deleted_at' => now()->toIso8601String(),
                            ],
                        ]),
                    ]);
                }
                $event->delete();
            });
            $task->delete();
        });

        return redirect()->route('tasks.index')->with('status', 'Event deleted. Its recorded entries were preserved as editable text.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'emoji' => 'nullable|string|max:32',
            'icon_data' => ['nullable', 'string', 'max:500000', new CroppedIcon],
            'remove_icon' => 'nullable|boolean',
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'options_text' => 'nullable|string|max:1000',
            'recurrence_type' => 'required|in:daily,weekly,monthly',
            'weekdays' => 'nullable|array',
            'weekdays.*' => 'integer|between:1,7',
            'month_days_text' => 'nullable|string|max:200',
            'scheduled_times_text' => 'nullable|string|max:300',
            'scheduled_times' => 'nullable|array|max:24',
            'scheduled_times.*' => 'date_format:H:i',
            'visible_after' => 'nullable|date_format:H:i',
            'daily_default_count' => 'nullable|integer|min:1|max:999',
        ]);
        $options = collect(preg_split('/[\r\n,]+/', $data['options_text'] ?? ''))->map(fn ($v) => trim($v))->filter()->unique()->values()->all();
        $recurrenceDays = match ($data['recurrence_type']) {
            'weekly' => collect($data['weekdays'] ?? [])->map(fn ($day) => (int) $day)->unique()->sort()->values()->all(),
            'monthly' => collect(preg_split('/[\s,]+/', $data['month_days_text'] ?? ''))->filter()->map(fn ($day) => (int) $day)->filter(fn ($day) => $day >= 1 && $day <= 31)->unique()->sort()->values()->all(),
            default => null,
        };
        $scheduledTimes = collect($data['scheduled_times'] ?? preg_split('/[\s,]+/', $data['scheduled_times_text'] ?? ''))
            ->map(fn ($time) => trim($time))->filter()->unique()->sort()->values();

        if (in_array($data['recurrence_type'], ['weekly', 'monthly'], true) && empty($recurrenceDays)) {
            throw ValidationException::withMessages(['recurrence_type' => 'Choose at least one day for this recurrence.']);
        }
        if ($scheduledTimes->contains(fn ($time) => ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time))) {
            throw ValidationException::withMessages(['scheduled_times_text' => 'Use 24-hour times such as 08:30 or 17:00.']);
        }
        $result = [
            'name' => $data['name'],
            'emoji' => filled($data['emoji'] ?? null) ? $data['emoji'] : TaskDefinition::DEFAULT_EMOJI,
            'color' => strtolower($data['color']),
            'is_sticky' => $request->boolean('is_sticky'),
            'daily_default_count' => $data['daily_default_count'] ?? 1,
            'recurrence_type' => $data['recurrence_type'],
            'recurrence_days' => $recurrenceDays ?: null,
            'scheduled_times' => $scheduledTimes->all() ?: null,
            'visible_after' => $request->boolean('is_sticky') ? ($data['visible_after'] ?? null) : null,
            'options' => $options ?: null,
            'is_active' => true,
        ];
        if (filled($data['icon_data'] ?? null)) {
            $result['icon_data'] = $data['icon_data'];
        } elseif ($request->boolean('remove_icon')) {
            $result['icon_data'] = null;
        }

        return $result;
    }
}
