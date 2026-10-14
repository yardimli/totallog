<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\LogBlock;
use App\Models\Sensor;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GithubSensorSync
{
    public function __construct(private GithubSensorClient $github) {}

    public function sync(User $user, DailyLog $log, CarbonInterface $day): void
    {
        if ($day->isAfter(today())) {
            return;
        }

        $sensor = Sensor::where('user_id', $user->id)->where('type', Sensor::GITHUB)->where('enabled', true)->first();
        if (! $sensor || blank($sensor->username)) {
            return;
        }
        if ($day->isToday() && $sensor->last_checked_at?->greaterThan(
            now()->subSeconds(max(1, (int) config('services.github.sync_interval_seconds', 300)))
        )) {
            return;
        }

        $existingSensorBlocks = $this->consolidateExistingBlocks($log);
        if ($day->isToday()) {
            $existingSensorBlocks
                ->filter(fn (LogBlock $block) => data_get($block->metadata, 'empty') === true)
                ->each->delete();
            $existingSensorBlocks = $existingSensorBlocks
                ->reject(fn (LogBlock $block) => data_get($block->metadata, 'empty') === true)
                ->values();
        }

        $completed = $sensor->daySyncs()->whereDate('log_date', $day)->exists();
        if (! $day->isToday() && ($completed || $existingSensorBlocks->isNotEmpty())) {
            if (! $completed) {
                $sensor->daySyncs()->create([
                    'log_date' => $day,
                    'status' => 'complete',
                    'item_count' => $existingSensorBlocks->sum(fn (LogBlock $block) => count(data_get($block->metadata, 'commits', []))),
                ]);
            }

            return;
        }

        try {
            $token = $sensor->token;
            if (blank($token)) {
                return;
            }
            $commits = $this->github->commitsForDay($sensor->username, $token, $day);
            DB::transaction(function () use ($sensor, $log, $day, $commits) {
                Sensor::whereKey($sensor->id)->lockForUpdate()->firstOrFail();
                $this->insertCommits($log, $commits);
                $blocks = $this->consolidateExistingBlocks($log);
                $position = (int) ($log->blocks()->max('position') ?? 0);

                if (! $day->isToday() && $commits->isEmpty() && $blocks->isEmpty()) {
                    $log->blocks()->create([
                        'type' => 'sensor_github',
                        'emoji' => '💻',
                        'content' => 'No Git commits today',
                        'position' => ++$position,
                        'occurred_at' => $day->isToday() ? now() : $day->copy()->endOfDay(),
                        'metadata' => ['sensor' => Sensor::GITHUB, 'empty' => true],
                    ]);
                }

                $syncData = ['status' => 'complete', 'item_count' => $commits->count(), 'metadata' => ['synced_at' => now()->toIso8601String()]];
                $daySync = $sensor->daySyncs()->whereDate('log_date', $day)->first();
                if ($daySync) {
                    $daySync->update($syncData);
                } else {
                    $sensor->daySyncs()->create(['log_date' => $day->toDateString(), ...$syncData]);
                }
                $sensor->update(['last_checked_at' => now(), 'last_error' => null]);
            });
        } catch (Throwable $error) {
            $sensor->update(['last_checked_at' => now(), 'last_error' => $error->getMessage()]);
            Log::warning('GitHub sensor sync failed.', ['sensor_id' => $sensor->id, 'date' => $day->toDateString(), 'message' => $error->getMessage()]);
        }
    }

    public function syncLatest(Sensor $sensor): array
    {
        try {
            if (blank($sensor->token)) {
                throw new \RuntimeException('Reconnect GitHub before syncing.');
            }
            $commits = $this->github->latestCommits($sensor->username, $sensor->token);

            return DB::transaction(function () use ($sensor, $commits) {
                $sensor = Sensor::whereKey($sensor->id)->lockForUpdate()->firstOrFail();
                $known = [];
                // Include legacy entries and every day, so a manual retry never duplicates a commit.
                foreach (LogBlock::where('type', 'sensor_github')
                    ->whereHas('dailyLog', fn ($query) => $query->where('user_id', $sensor->user_id))->cursor() as $block) {
                    foreach ($this->blockEvents($block) as $event) {
                        $known[$event['sha']] = true;
                    }
                }
                $missing = $commits->reject(fn ($commit) => isset($known[$commit['sha']]));
                $inserted = [];
                foreach ($missing->groupBy(fn ($commit) => $commit['occurred_at']->toDateString()) as $date => $dayCommits) {
                    $log = DailyLog::where('user_id', $sensor->user_id)->whereDate('log_date', $date)->first()
                        ?? DailyLog::create(['user_id' => $sensor->user_id, 'log_date' => $date]);
                    $inserted = array_merge($inserted, $this->insertCommits($log, $dayCommits));
                }
                $report = [
                    'synced_at' => now()->toIso8601String(),
                    'commits' => $commits->map(fn ($commit) => [
                        ...$this->commitEvent($commit),
                        'status' => in_array($commit['sha'], $inserted, true) ? 'new' : 'already_inserted',
                    ])->all(),
                ];
                $sensor->update([
                    'last_checked_at' => now(),
                    'last_error' => null,
                    'settings' => array_merge($sensor->settings ?? [], ['last_github_sync' => $report]),
                ]);

                return $report;
            });
        } catch (Throwable $error) {
            $sensor->update(['last_checked_at' => now(), 'last_error' => $error->getMessage()]);
            throw $error;
        }
    }

    private function insertCommits(DailyLog $log, Collection $commits): array
    {
        $blocks = $this->consolidateExistingBlocks($log);
        $existingShas = $blocks->flatMap(fn (LogBlock $block) => collect(data_get($block->metadata, 'commits', []))->pluck('sha'))->filter()->all();
        if ($commits->isNotEmpty()) {
            $log->blocks()->where('type', 'sensor_github')->get()
                ->filter(fn (LogBlock $block) => data_get($block->metadata, 'empty') === true)
                ->each->delete();
            $blocks = $blocks->reject(fn (LogBlock $block) => data_get($block->metadata, 'empty') === true)->values();
        }

        $inserted = [];
        $position = (int) ($log->blocks()->max('position') ?? 0);
        foreach ($commits as $commit) {
            if (in_array($commit['sha'], $existingShas, true)) {
                continue;
            }
            $event = $this->commitEvent($commit);
            $key = $this->commitGroupKey($event['project'], $event['occurred_at']);
            $block = $blocks->first(fn (LogBlock $candidate) => $this->blockGroupKey($candidate) === $key);
            if (! $block) {
                $block = $log->blocks()->create([
                    'type' => 'sensor_github',
                    'emoji' => '💻',
                    'content' => $event['project'],
                    'position' => ++$position,
                    'occurred_at' => Carbon::parse($event['occurred_at']),
                    'metadata' => ['sensor' => Sensor::GITHUB, 'empty' => false, 'commits' => []],
                ]);
                $blocks->push($block);
            }
            $events = collect(data_get($block->metadata, 'commits', []))->push($event)->unique('sha')->sortBy('occurred_at')->values();
            $this->updateGroupBlock($block, $event['project'], $events);
            $existingShas[] = $event['sha'];
            $inserted[] = $event['sha'];
        }

        return $inserted;
    }

    private function consolidateExistingBlocks(DailyLog $log): Collection
    {
        return DB::transaction(function () use ($log) {
            $blocks = $log->blocks()->where('type', 'sensor_github')->get()
                ->reject(fn (LogBlock $block) => data_get($block->metadata, 'empty') === true)
                ->values();

            foreach ($blocks->groupBy(fn (LogBlock $block) => $this->blockGroupKey($block)) as $group) {
                $keeper = $group->first();
                $project = $this->blockProject($keeper);
                $events = $group->flatMap(fn (LogBlock $block) => $this->blockEvents($block))
                    ->filter(fn (array $event) => filled($event['sha'] ?? null))
                    ->unique('sha')
                    ->sortBy('occurred_at')
                    ->values();
                if ($events->isNotEmpty()) {
                    $this->updateGroupBlock($keeper, $project, $events);
                }
                $group->skip(1)->each->delete();
            }

            return $log->blocks()->where('type', 'sensor_github')->get();
        });
    }

    private function blockEvents(LogBlock $block): Collection
    {
        $events = collect(data_get($block->metadata, 'commits', []));
        if ($events->isNotEmpty()) {
            return $events;
        }
        $sha = data_get($block->metadata, 'github_sha');
        if (blank($sha)) {
            return collect();
        }

        return collect([[
            'sha' => $sha,
            'project' => $this->blockProject($block),
            'repository' => data_get($block->metadata, 'repository'),
            'url' => data_get($block->metadata, 'url'),
            'message' => data_get($block->metadata, 'message'),
            'occurred_at' => ($block->occurred_at ?? $block->created_at)->toIso8601String(),
        ]]);
    }

    private function commitEvent(array $commit): array
    {
        return [
            'sha' => $commit['sha'],
            'project' => $commit['project'],
            'repository' => $commit['repository'],
            'url' => $commit['url'],
            'message' => $commit['message'] ?? null,
            'occurred_at' => $commit['occurred_at']->toIso8601String(),
        ];
    }

    private function updateGroupBlock(LogBlock $block, string $project, Collection $events): void
    {
        $first = Carbon::parse($events->first()['occurred_at'])->setTimezone(config('app.timezone'));
        $metadata = [
            'sensor' => Sensor::GITHUB,
            'project' => $project,
            'hour_start' => $first->copy()->startOfHour()->toIso8601String(),
            'commit_count' => $events->count(),
            'commits' => $events->all(),
            'empty' => false,
        ];
        $changes = [];
        if ($block->content !== $project) {
            $changes['content'] = $project;
        }
        if (! $block->occurred_at?->equalTo($first)) {
            $changes['occurred_at'] = $first;
        }
        if ($block->metadata !== $metadata) {
            $changes['metadata'] = $metadata;
        }
        if ($changes) {
            $block->update($changes);
        }
    }

    private function blockProject(LogBlock $block): string
    {
        return (string) (data_get($block->metadata, 'project') ?: $block->content);
    }

    private function blockGroupKey(LogBlock $block): string
    {
        $hour = data_get($block->metadata, 'hour_start') ?: ($block->occurred_at ?? $block->created_at)->toIso8601String();

        return $this->commitGroupKey($this->blockProject($block), $hour);
    }

    private function commitGroupKey(string $project, string $occurredAt): string
    {
        return mb_strtolower($project).'|'.Carbon::parse($occurredAt)->setTimezone(config('app.timezone'))->format('Y-m-d H');
    }
}
