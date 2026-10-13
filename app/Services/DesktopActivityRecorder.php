<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\DesktopActivity;
use App\Models\LogBlock;
use App\Models\Sensor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class DesktopActivityRecorder
{
    public const INACTIVITY_SECONDS = 180;

    public function record(Sensor $sensor, array $data): DesktopActivity
    {
        $observed = filled($data['observed_at'] ?? null) ? Carbon::parse($data['observed_at']) : now();
        $observed->setTimezone(config('app.timezone'));
        if ($observed->diffInSeconds(now(), true) > 300) {
            throw ValidationException::withMessages(['observed_at' => 'The desktop timestamp must be within five minutes of the server time.']);
        }
        $application = trim(strip_tags($data['application']));
        $processName = strtolower(trim(basename(str_replace('\\', '/', $data['process_name']))));

        return DB::transaction(function () use ($sensor, $data, $observed, $application, $processName) {
            User::whereKey($sensor->user_id)->lockForUpdate()->firstOrFail();
            $this->finalizeStale($sensor->user, $observed);
            $active = DesktopActivity::where('user_id', $sensor->user_id)
                ->where('client_id', $data['client_id'])->whereNull('ended_at')
                ->latest('last_seen_at')->lockForUpdate()->first();
            $log = $this->dailyLog($sensor->user, $observed);
            $hourStart = $observed->copy()->startOfHour();
            $sameHour = $active && $active->daily_log_id === $log->id
                && $active->started_at->copy()->startOfHour()->equalTo($hourStart);
            $gap = $active ? $active->last_seen_at->diffInSeconds($observed, false) : null;
            if ($active && $gap < 0) {
                return $active;
            }

            if ($active && $gap >= 0 && $gap <= self::INACTIVITY_SECONDS && $sameHour
                && $active->application === $application && $active->process_name === $processName) {
                $active->update([
                    'last_seen_at' => $observed,
                    'duration_seconds' => $active->started_at->diffInSeconds($observed),
                ]);
                $this->refreshBlock($active->logBlock);
                $sensor->update(['last_checked_at' => now(), 'last_error' => null]);

                return $active->fresh();
            }

            if ($active) {
                $endedAt = $gap !== null && $gap > self::INACTIVITY_SECONDS
                    ? $active->last_seen_at : ($sameHour ? $observed : $hourStart);
                $this->finish($active, $endedAt);
            }

            $activity = DesktopActivity::create([
                'user_id' => $sensor->user_id,
                'daily_log_id' => $log->id,
                'log_block_id' => $this->hourBlock($log, $hourStart, $observed)->id,
                'application' => $application,
                'process_name' => $processName,
                'client_id' => $data['client_id'],
                'started_at' => $observed,
                'last_seen_at' => $observed,
                'duration_seconds' => 0,
            ]);
            $this->refreshBlock($activity->logBlock);
            $sensor->update(['last_checked_at' => now(), 'last_error' => null]);

            return $activity;
        });
    }

    public function recordBatch(Sensor $sensor, string $clientId, array $increments): Collection
    {
        return DB::transaction(function () use ($sensor, $clientId, $increments) {
            User::whereKey($sensor->user_id)->lockForUpdate()->firstOrFail();
            $recorded = collect();
            foreach ($increments as $increment) {
                $started = Carbon::parse($increment['started_at'])->setTimezone(config('app.timezone'));
                $ended = Carbon::parse($increment['ended_at'])->setTimezone(config('app.timezone'));
                if ($started->isAfter($ended) || $ended->diffInSeconds(now(), true) > 604800) {
                    throw ValidationException::withMessages(['activities' => 'Desktop activity times must be ordered and no more than seven days old.']);
                }
                $duration = (int) $increment['duration_seconds'];
                if ($duration > $started->diffInSeconds($ended) + 10) {
                    throw ValidationException::withMessages(['activities' => 'Desktop activity duration cannot exceed its time range.']);
                }
                $application = trim(strip_tags($increment['application']));
                $processName = strtolower(trim(basename(str_replace('\\', '/', $increment['process_name']))));
                // Immutable client retries can be recognized from existing activity.
                // The account lock also serializes concurrent identical uploads.
                $existing = DesktopActivity::where('user_id', $sensor->user_id)
                    ->where('client_id', $clientId)->where('application', $application)
                    ->where('process_name', $processName)->where('started_at', $started)
                    ->where('last_seen_at', $ended)->where('ended_at', $ended)
                    ->where('duration_seconds', $duration)->first();
                if ($existing) {
                    $recorded->put($existing->id, $existing);
                    continue;
                }
                $log = $this->dailyLog($sensor->user, $started);
                $activity = DesktopActivity::create([
                    'user_id' => $sensor->user_id,
                    'daily_log_id' => $log->id,
                    'log_block_id' => $this->hourBlock($log, $started->copy()->startOfHour(), $started)->id,
                    'application' => $application,
                    'process_name' => $processName,
                    'client_id' => $clientId,
                    'started_at' => $started,
                    'last_seen_at' => $ended,
                    'ended_at' => $ended,
                    'duration_seconds' => $duration,
                ]);
                $recorded->put($activity->id, $activity);
            }
            $recorded->pluck('log_block_id')->unique()->each(function (int $blockId) {
                $this->refreshBlock(LogBlock::findOrFail($blockId));
            });
            $sensor->update(['last_checked_at' => now(), 'last_error' => null]);

            return $recorded;
        });
    }

    public function finalizeStale(User $user, ?Carbon $at = null): void
    {
        $at ??= now();
        DB::transaction(function () use ($user, $at) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $activities = DesktopActivity::where('user_id', $user->id)->whereNull('ended_at')
                ->where('last_seen_at', '<=', $at->copy()->subSeconds(self::INACTIVITY_SECONDS))
                ->lockForUpdate()->get();
            foreach ($activities as $activity) {
                $this->finish($activity, $activity->last_seen_at);
            }
        });
    }

    private function finish(DesktopActivity $activity, Carbon $endedAt): void
    {
        $boundary = $activity->started_at->copy()->startOfHour()->addHour();
        $endedAt = $endedAt->copy()->min($boundary)
            ->min($activity->last_seen_at->copy()->addSeconds(self::INACTIVITY_SECONDS))
            ->max($activity->started_at);
        $activity->update([
            'last_seen_at' => $endedAt->copy()->max($activity->last_seen_at)->min($boundary),
            'ended_at' => $endedAt,
            'duration_seconds' => $activity->started_at->diffInSeconds($endedAt),
        ]);
        $this->refreshBlock($activity->logBlock);
    }

    private function dailyLog(User $user, Carbon $at): DailyLog
    {
        return DailyLog::where('user_id', $user->id)->whereDate('log_date', $at)->first()
            ?? DailyLog::create(['user_id' => $user->id, 'log_date' => $at->copy()->startOfDay()]);
    }

    private function hourBlock(DailyLog $log, Carbon $hourStart, Carbon $observed): LogBlock
    {
        return $log->blocks()->where('type', 'sensor_desktop')
            ->where('occurred_at', '>=', $hourStart)->where('occurred_at', '<', $hourStart->copy()->addHour())->first()
            ?? $log->blocks()->create([
                'type' => 'sensor_desktop', 'emoji' => '🖥️',
                'content' => 'Desktop activity · 1 app · under 1 min',
                'position' => ((int) $log->blocks()->max('position')) + 1, 'occurred_at' => $observed,
                'metadata' => ['sensor' => Sensor::DESKTOP, 'hour_start' => $hourStart->toIso8601String(), 'total_seconds' => 0, 'application_count' => 1],
            ]);
    }

    private function refreshBlock(LogBlock $block): void
    {
        $activities = $block->desktopActivities()->get();
        $seconds = (int) $activities->sum('duration_seconds');
        $applications = $activities->pluck('application')->unique()->count();
        $block->update([
            'content' => 'Desktop activity · '.$applications.' '.($applications === 1 ? 'app' : 'apps').' · '.$this->duration($seconds),
            'occurred_at' => $activities->min('started_at') ?? $block->occurred_at,
            'metadata' => array_merge($block->metadata ?? [], ['total_seconds' => $seconds, 'application_count' => $applications]),
        ]);
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return 'under 1 min';
        }
        $minutes = (int) floor($seconds / 60);

        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }
}
