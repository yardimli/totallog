<?php

namespace App\Http\Controllers;

use App\Models\DailyLog;
use App\Models\GoalEntry;
use App\Models\LogBlock;
use App\Services\GoalProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LogBlockController extends Controller
{
    public function __construct(private GoalProgressService $goalProgress) {}

    public function store(Request $request, DailyLog $dailyLog)
    {
        abort_unless($dailyLog->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'type' => 'required|in:text',
            'content' => 'required|string|max:100000',
            'emoji' => 'nullable|string|max:32',
            'occurred_at' => ['nullable', \App\Support\DayTime::RULE],
        ]);
        $data['emoji'] = filled($data['emoji'] ?? null) ? $data['emoji'] : LogBlock::defaultEmojiForType($data['type']);
        $data['occurred_at'] = $dailyLog->log_date->copy()->setTimeFromTimeString($data['occurred_at'] ?? now()->format('H:i'));
        $block = $dailyLog->blocks()->create($data + ['position' => ($dailyLog->blocks()->max('position') ?? 0) + 1]);

        return response()->json([
            'message' => 'Entry added.',
            'block' => $block,
            'edit_url' => route('blocks.update', $block),
            'hide_url' => route('blocks.visibility', $block),
            'delete_url' => route('blocks.destroy', $block),
            'updated_time' => $request->user()->formatTime($block->updated_at),
        ], 201);
    }

    public function update(Request $request, LogBlock $block)
    {
        $this->authorizeBlock($request, $block);
        abort_if($block->type === 'event', 422, 'Edit event notes from the event editor.');
        $data = $request->validate([
            'content' => 'nullable|string|max:100000',
            'emoji' => 'nullable|string|max:32',
            'occurred_at' => ['sometimes', 'required', \App\Support\DayTime::RULE],
        ]);
        if (isset($data['occurred_at'])) {
            $data['occurred_at'] = $block->dailyLog->log_date->copy()->setTimeFromTimeString($data['occurred_at']);
        }
        if (array_key_exists('emoji', $data)) {
            $data['emoji'] = filled($data['emoji']) ? $data['emoji'] : LogBlock::defaultEmojiForType($block->type);
        }
        $block->update($data);

        $block = $block->fresh();

        return response()->json([
            'message' => 'Entry updated.',
            'block' => $block,
            'edit_url' => route('blocks.update', $block),
            'hide_url' => route('blocks.visibility', $block),
            'delete_url' => route('blocks.destroy', $block),
            'updated_time' => $request->user()->formatTime($block->updated_at),
        ]);
    }

    public function destroy(Request $request, LogBlock $block)
    {
        $startedAt = hrtime(true);
        $this->authorizeBlock($request, $block);
        $goalEntry = GoalEntry::query()->whereKey(data_get($block->metadata, 'goal_entry_id'))
            ->whereHas('goal', fn ($query) => $query->where('user_id', $request->user()->id))
            ->first();
        $goal = $goalEntry?->goal;
        $block->attachments->each(function ($attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
            $attachment->delete();
        });
        $block->delete();
        $goalEntry?->delete();
        if ($goal) {
            $this->goalProgress->sync($goal);
        }

        return response()->json(['message' => 'Entry deleted.'])
            ->header('Server-Timing', sprintf('block-delete;dur=%.1f', (hrtime(true) - $startedAt) / 1_000_000));
    }

    private function authorizeBlock(Request $request, LogBlock $block): void
    {
        abort_unless($block->dailyLog()->where('user_id', $request->user()->id)->exists(), 403);
    }
}
