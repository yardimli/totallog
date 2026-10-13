<?php

namespace App\Services;

use App\Models\BrowsingActivity;
use App\Models\DailyLog;
use App\Models\LogBlock;
use App\Models\Sensor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BrowsingActivityRecorder
{
    public const INACTIVITY_SECONDS = 180;

    public function record(Sensor $sensor, string $url, string $clientId, ?string $observedAt = null): BrowsingActivity
    {
        $domain = $this->fullHostname($url);
        $observed = filled($observedAt) ? Carbon::parse($observedAt) : now();
        $observed->setTimezone(config('app.timezone'));
        if ($observed->diffInSeconds(now(), true) > 300) {
            throw ValidationException::withMessages(['observed_at' => 'The browsing timestamp must be within five minutes of the server time.']);
        }

        return DB::transaction(function () use ($sensor, $domain, $clientId, $observed) {
            // Lock an existing row, including when no activity/hour block exists yet.
            // Different clients and paired sensors share the same account's hour.
            User::whereKey($sensor->user_id)->lockForUpdate()->firstOrFail();
            $this->finalizeStale($sensor->user, $observed);
            $active = BrowsingActivity::where('user_id', $sensor->user_id)
                ->where('client_id', $clientId)
                ->whereNull('ended_at')
                ->latest('last_seen_at')
                ->lockForUpdate()
                ->first();
            $hourStart = $observed->copy()->startOfHour();
            $sameHour = $active
                && $active->daily_log_id === $this->dailyLog($sensor->user, $observed)->id
                && $active->started_at->copy()->startOfHour()->equalTo($hourStart);
            $gap = $active ? $active->last_seen_at->diffInSeconds($observed, false) : null;

            // Network requests can arrive out of order. Never rewind the live session.
            if ($active && $gap < 0) {
                return $active;
            }

            if ($active && $gap >= 0 && $gap <= self::INACTIVITY_SECONDS && $sameHour && $active->domain === $domain) {
                $active->update([
                    'last_seen_at' => $observed,
                    'duration_seconds' => $active->started_at->diffInSeconds($observed),
                ]);
                $block = $this->hourBlock($active->dailyLog, $hourStart, $observed);
                $this->refreshBlock($block);
                $sensor->update(['last_checked_at' => now(), 'last_error' => null]);

                return $active->fresh();
            }

            if ($active) {
                $endedAt = $gap !== null && $gap > self::INACTIVITY_SECONDS
                    ? $active->last_seen_at
                    : ($sameHour ? $observed : $hourStart);
                $this->finish($active, $endedAt);
            }

            $log = $this->dailyLog($sensor->user, $observed);
            $block = $this->hourBlock($log, $hourStart, $observed);
            $activity = BrowsingActivity::create([
                'user_id' => $sensor->user_id,
                'daily_log_id' => $log->id,
                'log_block_id' => $block->id,
                'domain' => $domain,
                'client_id' => $clientId,
                'started_at' => $observed,
                'last_seen_at' => $observed,
                'duration_seconds' => 0,
            ]);
            $this->refreshBlock($block);
            $sensor->update(['last_checked_at' => now(), 'last_error' => null]);

            return $activity;
        });
    }

    public function finalizeStale(User $user, ?Carbon $at = null): void
    {
        $at ??= now();
        DB::transaction(function () use ($user, $at) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $stale = BrowsingActivity::where('user_id', $user->id)
                ->whereNull('ended_at')
                ->where('last_seen_at', '<=', $at->copy()->subSeconds(self::INACTIVITY_SECONDS))
                ->lockForUpdate()
                ->get();

            foreach ($stale as $activity) {
                $this->finish($activity, $activity->last_seen_at);
            }
        });
    }

    public function repairForLog(DailyLog $log): void
    {
        DB::transaction(function () use ($log) {
            User::whereKey($log->user_id)->lockForUpdate()->firstOrFail();
            $groups = $log->browsingActivities()->orderBy('started_at')->get()
                ->groupBy(fn (BrowsingActivity $activity) => $activity->started_at->format('Y-m-d H'));
            foreach ($groups as $activities) {
                $observed = $activities->first()->started_at;
                $this->hourBlock($log, $observed->copy()->startOfHour(), $observed);
            }
        });
    }

    private function finish(BrowsingActivity $activity, Carbon $endedAt): void
    {
        // A heartbeat session belongs to one hour. Never manufacture hours of
        // activity when an old record or an hour boundary is inconsistent.
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
        $block = $this->findOrCreateHourBlock($log, $hourStart, $observed);
        $this->repairHourAssignments($log, $hourStart, $block);

        return $block;
    }

    private function findOrCreateHourBlock(DailyLog $log, Carbon $hourStart, Carbon $observed): LogBlock
    {
        $block = $log->blocks()
            ->where('type', 'sensor_browser')
            ->where('occurred_at', '>=', $hourStart)
            ->where('occurred_at', '<', $hourStart->copy()->addHour())
            ->first();
        if (! $block) {
            $block = $log->blocks()->create([
                'type' => 'sensor_browser',
                'emoji' => '🌐',
                'content' => 'Browsing · 1 domain · under 1 min',
                'position' => ((int) $log->blocks()->max('position')) + 1,
                'occurred_at' => $observed,
                'metadata' => [
                    'sensor' => Sensor::BROWSER,
                    'hour_start' => $hourStart->toIso8601String(),
                    'total_seconds' => 0,
                    'domain_count' => 1,
                ],
            ]);
        }

        return $block;
    }

    private function repairHourAssignments(DailyLog $log, Carbon $hourStart, LogBlock $block): void
    {
        $mislinked = BrowsingActivity::where('daily_log_id', $log->id)
            ->where('started_at', '>=', $hourStart)
            ->where('started_at', '<', $hourStart->copy()->addHour())
            ->where('log_block_id', '!=', $block->id)
            ->get();
        if ($mislinked->isEmpty()) {
            return;
        }

        $oldBlocks = LogBlock::whereIn('id', $mislinked->pluck('log_block_id')->unique())->get();
        BrowsingActivity::whereKey($mislinked->pluck('id'))->update(['log_block_id' => $block->id]);
        foreach ($oldBlocks as $oldBlock) {
            $this->splitMislinkedHours($log, $oldBlock);
            if ($oldBlock->browsingActivities()->exists()) {
                $this->refreshBlock($oldBlock);
            } else {
                $oldBlock->delete();
            }
        }
        $this->refreshBlock($block);
    }

    private function splitMislinkedHours(DailyLog $log, LogBlock $block): void
    {
        $blockHour = filled(data_get($block->metadata, 'hour_start'))
            ? Carbon::parse(data_get($block->metadata, 'hour_start'))->setTimezone(config('app.timezone'))->startOfHour()
            : $block->occurred_at->copy()->startOfHour();
        $groups = $block->browsingActivities()->get()
            ->filter(fn (BrowsingActivity $activity) => ! $activity->started_at->copy()->startOfHour()->equalTo($blockHour))
            ->groupBy(fn (BrowsingActivity $activity) => $activity->started_at->format('Y-m-d H'));

        foreach ($groups as $activities) {
            $observed = $activities->min('started_at');
            $hourStart = $observed->copy()->startOfHour();
            $target = $this->findOrCreateHourBlock($log, $hourStart, $observed);
            BrowsingActivity::whereKey($activities->pluck('id'))->update(['log_block_id' => $target->id]);
            $this->refreshBlock($target);
        }
    }

    private function refreshBlock(LogBlock $block): void
    {
        $activities = $block->browsingActivities()->get();
        $seconds = (int) $activities->sum('duration_seconds');
        $domains = $activities->pluck('domain')->unique()->count();
        $block->update([
            'content' => 'Browsing · '.$domains.' '.($domains === 1 ? 'domain' : 'domains').' · '.$this->duration($seconds),
            'occurred_at' => $activities->min('started_at') ?? $block->occurred_at,
            'metadata' => array_merge($block->metadata ?? [], ['total_seconds' => $seconds, 'domain_count' => $domains]),
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

    private function fullHostname(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = trim($host, '.');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages(['url' => 'Send an HTTP or HTTPS URL with a domain name.']);
        }

        return $host;
    }
}
