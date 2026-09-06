<?php

namespace App\Http\Controllers;

use App\Models\DailyLog;
use App\Models\TaskDefinition;
use App\Services\BrowsingActivityRecorder;
use App\Services\DesktopActivityRecorder;
use App\Services\GithubSensorSync;
use App\Services\GoalProgressService;
use App\Services\GoogleCalendarSync;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DayLogController extends Controller
{
    public function __construct(private GithubSensorSync $githubSensor, private BrowsingActivityRecorder $browsingRecorder, private DesktopActivityRecorder $desktopRecorder, private GoogleCalendarSync $googleCalendar, private GoalProgressService $goalProgress) {}

    public function today(Request $request)
    {
        return redirect()->route('logs.show', array_merge(
            $request->query(),
            ['date' => today()->toDateString()],
        ));
    }

    public function show(Request $request, string $date)
    {
        $startedAt = hrtime(true);
        $day = Carbon::parse($date)->startOfDay();
        $log = DailyLog::where('user_id', $request->user()->id)->whereDate('log_date', $day)->first();
        if (! $log) {
            if ($request->user()->is_guest) {
                return redirect()->route('calendar')->with('status', 'That date is outside the read-only demo data.');
            }
            $log = DailyLog::create(['user_id' => $request->user()->id, 'log_date' => $day]);
        }
        if (! $request->user()->is_guest && $day->format('Y-m') === now()->format('Y-m')) {
            $this->googleCalendar->syncUser($request->user());
            $log = $log->fresh();
        }
        if (! $request->user()->is_guest) {
            $this->githubSensor->sync($request->user(), $log, $day);
            $this->browsingRecorder->finalizeStale($request->user());
            $this->desktopRecorder->finalizeStale($request->user());
            $this->goalProgress->syncForUser($request->user());
        }
        $showHidden = $request->boolean('show_hidden');
        $log->load(['blocks.attachments', 'blocks.taskEvent', 'blocks.browsingActivities', 'blocks.desktopActivities', 'blocks.mobileBrowsingVisits']);
        $tasks = TaskDefinition::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (TaskDefinition $task) => $task->occursOn($day))
            ->values();
        if ($request->user()->is_guest) {
            $tasks = collect();
        }
        $counts = $log->taskEvents()->selectRaw('task_definition_id, count(*) as total')->groupBy('task_definition_id')->pluck('total', 'task_definition_id');
        $slotCounts = $log->taskEvents()
            ->whereNotNull('scheduled_time')
            ->selectRaw('task_definition_id, scheduled_time, count(*) as total')
            ->groupBy('task_definition_id', 'scheduled_time')
            ->get()
            ->groupBy('task_definition_id')
            ->map(fn ($events) => $events->pluck('total', 'scheduled_time')->map(fn ($count) => (int) $count));
        $timelineItems = collect();

        foreach ($log->blocks as $block) {
            if ($block->is_hidden && ! $showHidden) {
                continue;
            }
            $occurredAt = $block->taskEvent?->occurred_at ?? $block->occurred_at ?? $block->created_at;
            $timelineItems->push([
                'kind' => 'block',
                'time' => $occurredAt->format('H:i'),
                'minute' => ($occurredAt->hour * 60) + $occurredAt->minute,
                'sort' => $occurredAt->format('H:i:s').'-1-'.$block->id,
                'block' => $block,
                'is_hidden' => $block->is_hidden,
            ]);
        }

        $incompleteStickyTasks = $tasks->where('is_sticky', true)->filter(function (TaskDefinition $task) use ($counts, $slotCounts) {
            $times = $task->scheduled_times ?? [];
            if (! $times) {
                return (int) ($counts[$task->id] ?? 0) < $task->daily_default_count;
            }

            return collect($times)->contains(fn ($time) => (int) data_get($slotCounts, $task->id.'.'.$time, 0) < $task->daily_default_count);
        });
        $plannerTasks = $incompleteStickyTasks->filter(fn (TaskDefinition $task) => $task->isPlannerVisible($day, now()));
        $floatingStickyTasks = $plannerTasks->filter(fn (TaskDefinition $task) => empty($task->scheduled_times))->values();
        foreach ($plannerTasks as $task) {
            foreach ($task->scheduled_times ?? [] as $time) {
                if ((int) data_get($slotCounts, $task->id.'.'.$time, 0) >= $task->daily_default_count) {
                    continue;
                }
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $timelineItems->push([
                    'kind' => 'schedule',
                    'time' => $time,
                    'minute' => ($hour * 60) + $minute,
                    'sort' => $time.':00-0-'.$task->id,
                    'task' => $task,
                    'is_unscheduled' => false,
                ]);
            }
        }

        $nextStickyVisibility = $day->isToday()
            ? $incompleteStickyTasks
                ->pluck('visible_after')
                ->filter(fn ($time) => $time && $time > now()->format('H:i'))
                ->sort()
                ->first()
            : null;

        $goalSnapshots = $request->user()->goals()->with('entries')->get()
            ->filter(fn ($goal) => $goal->isAvailableOn($day))
            ->map(fn ($goal) => $this->goalProgress->snapshot($goal, $day, $request->user()->week_starts_on ?? 1))
            ->sort(function ($left, $right) {
                $leftActivity = $left['latest']?->occurred_at?->getTimestamp() ?? PHP_INT_MIN;
                $rightActivity = $right['latest']?->occurred_at?->getTimestamp() ?? PHP_INT_MIN;

                return ($leftActivity <=> $rightActivity) ?: ($left['goal']->id <=> $right['goal']->id);
            })->values();

        $itemsByMinute = $timelineItems->sortBy('sort')->groupBy('minute');
        $currentMinute = $day->isToday() ? (now()->hour * 60) + now()->minute : null;
        $positions = $itemsByMinute->keys();
        if ($currentMinute !== null) {
            $positions->push($currentMinute);
        }
        $positions = $positions->push(1440)->unique()->sort()->values();
        $cursor = 0;
        $formatMinute = fn (int $minute) => $minute === 1440 ? '24:00' : sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
        $gapState = function (int $end) use ($day, $currentMinute): string {
            if ($day->isBefore(today())) {
                return 'past';
            }
            if ($day->isAfter(today())) {
                return 'future';
            }

            return $end <= $currentMinute ? 'past' : 'future';
        };
        $timeline = collect();

        foreach ($positions as $position) {
            $position = (int) $position;
            if ($position > $cursor) {
                $timeline->push([
                    'kind' => 'gap',
                    'from' => $formatMinute($cursor),
                    'to' => $formatMinute($position),
                    'state' => $gapState($position),
                ]);
            }
            if ($currentMinute !== null && $position === $currentMinute) {
                $timeline->push(['kind' => 'now', 'time' => $formatMinute($currentMinute)]);
            }
            foreach ($itemsByMinute->get($position, collect()) as $item) {
                $timeline->push($item);
            }
            $cursor = $position;
        }
        if ($request->user()->is_guest) {
            $timeline = $timeline->where('kind', 'block')->values();
        }

        $dayState = $this->dayState($request, $day, $log, $tasks, $counts, $slotCounts, $timeline, $goalSnapshots, $floatingStickyTasks, $showHidden, $nextStickyVisibility);
        $mainFragment = $request->header('X-Day-View') === 'main';
        $viewData = compact('day', 'log', 'tasks', 'counts', 'slotCounts', 'timeline', 'goalSnapshots', 'floatingStickyTasks', 'showHidden', 'mainFragment', 'nextStickyVisibility', 'dayState');
        $timing = sprintf('day-view;dur=%.1f', (hrtime(true) - $startedAt) / 1_000_000);

        if ($request->header('X-Day-State') === 'json') {
            return response()->json($dayState)
                ->header('Server-Timing', $timing)
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->view('logs.show', $viewData)->header('Server-Timing', $timing);
    }

    private function dayState(Request $request, Carbon $day, DailyLog $log, $tasks, $counts, $slotCounts, $timeline, $goalSnapshots, $floatingStickyTasks, bool $showHidden, ?string $nextStickyVisibility): array
    {
        $taskData = $tasks->map(fn (TaskDefinition $task) => $this->taskState($log, $task, $counts, $slotCounts))->values();

        return [
            'date' => $day->toDateString(),
            'url' => route('logs.show', $day->toDateString()).($showHidden ? '?show_hidden=1' : ''),
            'title' => $day->format('l, F j, Y'),
            'is_today' => $day->isToday(),
            'show_hidden' => $showHidden,
            'next_sticky_visibility' => $nextStickyVisibility,
            'fetched_at' => now()->toIso8601String(),
            'navigation' => [
                'previous_url' => route('logs.show', $day->copy()->subDay()->toDateString()),
                'today_url' => route('logs.today'),
                'next_url' => route('logs.show', $day->copy()->addDay()->toDateString()),
                'calendar_url' => route('calendar'),
            ],
            'log' => [
                'id' => $log->id,
                'create_block_url' => route('blocks.store', $log),
                'chat_url' => route('openrouter.chat', $log),
            ],
            'tasks' => $taskData->all(),
            'sticky_events' => $floatingStickyTasks
                ->map(fn (TaskDefinition $task) => $this->taskState($log, $task, $counts, $slotCounts))
                ->values()->all(),
            'goals' => $goalSnapshots->map(fn ($snapshot) => [
                'id' => $snapshot['goal']->id,
                'name' => $snapshot['goal']->name,
                'emoji' => $snapshot['goal']->emoji,
                'icon_data' => $snapshot['goal']->icon_data,
                'color' => $snapshot['goal']->color,
                'text_color' => $snapshot['goal']->text_color,
                'points' => $snapshot['points'],
                'target' => $snapshot['target'],
                'latest' => $snapshot['latest']?->occurred_at->diffForHumans(),
                'url' => route('goals.show', ['goal' => $snapshot['goal'], 'date' => $day->toDateString()]),
            ])->values()->all(),
            'timeline' => $timeline->map(function (array $item) use ($request, $log, $counts, $slotCounts) {
                if ($item['kind'] === 'block') {
                    return ['kind' => 'block', 'time' => $item['time'], 'block' => $this->blockState($request, $item['block'])];
                }
                if ($item['kind'] === 'schedule') {
                    return [
                        'kind' => 'schedule',
                        'time' => $item['time'],
                        'is_unscheduled' => $item['is_unscheduled'],
                        'task' => $this->taskState($log, $item['task'], $counts, $slotCounts, $item['time']),
                    ];
                }

                return $item;
            })->values()->all(),
        ];
    }

    private function taskState(DailyLog $log, TaskDefinition $task, $counts, $slotCounts, ?string $slot = null): array
    {
        return [
            'id' => $task->id,
            'name' => $task->name,
            'emoji' => $task->emoji,
            'icon_data' => $task->icon_data,
            'color' => $task->color_hex,
            'text_color' => $task->button_text_color,
            'options' => $task->options ?? [],
            'scheduled_times' => $task->scheduled_times ?? [],
            'daily_default_count' => $task->daily_default_count,
            'event_url' => route('events.store', [$log, $task]),
            'count' => (int) ($counts[$task->id] ?? 0),
            'slot_count' => $slot === null ? null : (int) data_get($slotCounts, $task->id.'.'.$slot, 0),
        ];
    }

    private function blockState(Request $request, $block): array
    {
        $isBrowsing = $block->type === 'sensor_browser';
        $isDesktop = $block->type === 'sensor_desktop';
        $isMobileBrowsing = $block->type === 'sensor_mobile_browser';
        $isGithub = $block->type === 'sensor_github' && data_get($block->metadata, 'empty') !== true && filled(data_get($block->metadata, 'commits'));
        $isGoogleCalendar = $block->type === 'sensor_google_calendar';
        $browsingDomains = $isBrowsing
            ? $block->browsingActivities->groupBy('domain')->map(fn ($activities, $domain) => ['domain' => $domain, 'seconds' => (int) $activities->sum('duration_seconds')])->sortByDesc('seconds')->values()->all()
            : [];
        $desktopApplications = $isDesktop
            ? $block->desktopActivities->groupBy('application')->map(fn ($activities, $application) => ['domain' => $application, 'seconds' => (int) $activities->sum('duration_seconds')])->sortByDesc('seconds')->values()->all()
            : [];
        $mobileBrowsingDomains = $isMobileBrowsing
            ? $block->mobileBrowsingVisits->groupBy('domain')->map(fn ($visits, $domain) => ['domain' => $domain, 'visits' => $visits->count()])->sortByDesc('visits')->values()->all()
            : [];
        $githubEvents = $isGithub
            ? collect(data_get($block->metadata, 'commits', []))->map(fn ($commit) => [
                'time' => $request->user()->formatTime(Carbon::parse($commit['occurred_at'])),
                'sha' => $commit['sha'] ?? '',
                'message' => $commit['message'] ?? null,
                'url' => $commit['url'] ?? null,
            ])->values()->all()
            : [];
        $calendarEvent = $isGoogleCalendar ? [
            'title' => $block->content,
            'start' => data_get($block->metadata, 'is_all_day') ? 'All day' : $request->user()->formatTime($block->occurred_at),
            'end' => data_get($block->metadata, 'ends_at') ? $request->user()->formatTime(Carbon::parse(data_get($block->metadata, 'ends_at'))) : null,
            'description' => data_get($block->metadata, 'description'),
            'location' => data_get($block->metadata, 'location'),
            'url' => data_get($block->metadata, 'html_link'),
        ] : null;

        return [
            'id' => $block->id,
            'type' => $block->type,
            'emoji' => $block->emoji,
            'icon_data' => $block->icon_data,
            'content' => $block->content,
            'is_hidden' => (bool) $block->is_hidden,
            'updated' => $request->user()->formatTime($block->updated_at),
            'edit_kind' => $block->taskEvent ? 'event' : 'block',
            'edit_url' => $block->taskEvent ? route('events.update', $block->taskEvent) : route('blocks.update', $block),
            'hide_url' => route('blocks.visibility', $block),
            'delete_url' => route('blocks.destroy', $block),
            'event' => $block->taskEvent ? [
                'name' => $block->taskEvent->task_name,
                'value' => $block->taskEvent->selected_value,
                'location' => $block->taskEvent->latitude !== null ? [
                    'latitude' => $block->taskEvent->latitude,
                    'longitude' => $block->taskEvent->longitude,
                    'city' => $block->taskEvent->city,
                    'suburb' => $block->taskEvent->suburb,
                ] : null,
            ] : null,
            'attachments' => $block->attachments->map(fn ($attachment) => [
                'type' => $attachment->type,
                'url' => $attachment->url,
                'name' => $attachment->original_name,
            ])->values()->all(),
            'browsing_domains' => $browsingDomains,
            'desktop_applications' => $desktopApplications,
            'mobile_browsing_domains' => $mobileBrowsingDomains,
            'github_events' => $githubEvents,
            'calendar_event' => $calendarEvent,
        ];
    }
}
