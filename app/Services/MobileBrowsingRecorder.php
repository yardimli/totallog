<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\LogBlock;
use App\Models\MobileBrowsingVisit;
use App\Models\Sensor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MobileBrowsingRecorder
{
    public function recordBatch(Sensor $sensor, array $visits): array
    {
        $imported = 0;
        $duplicates = 0;
        $rejected = 0;
        $blocks = collect();

        DB::transaction(function () use ($sensor, $visits, &$imported, &$duplicates, &$rejected, $blocks) {
            \App\Models\User::whereKey($sensor->user_id)->lockForUpdate()->firstOrFail();
            foreach ($visits as $visit) {
                if (MobileBrowsingVisit::where('user_id', $sensor->user_id)->where('visit_key', $visit['visit_key'])->exists()) {
                    $duplicates++;

                    continue;
                }

                $visitedAt = Carbon::parse($visit['visited_at'])->setTimezone(config('app.timezone'));
                if ($visitedAt->gt(now()->addMinutes(5))) {
                    $rejected++;

                    continue;
                }

                $domain = $this->fullHostname($visit['url']);
                if ($domain === null) {
                    $rejected++;

                    continue;
                }
                $log = DailyLog::where('user_id', $sensor->user_id)->whereDate('log_date', $visitedAt)->first()
                    ?? DailyLog::create(['user_id' => $sensor->user_id, 'log_date' => $visitedAt->copy()->startOfDay()]);
                $hourStart = $visitedAt->copy()->startOfHour();
                $block = LogBlock::where('daily_log_id', $log->id)
                    ->where('type', 'sensor_mobile_browser')
                    ->where('occurred_at', '>=', $hourStart)
                    ->where('occurred_at', '<', $hourStart->copy()->addHour())
                    ->lockForUpdate()
                    ->first();

                if (! $block) {
                    $block = $log->blocks()->create([
                        'type' => 'sensor_mobile_browser',
                        'emoji' => '📱',
                        'content' => 'Mobile browsing · 1 domain · 1 visit',
                        'position' => ((int) $log->blocks()->max('position')) + 1,
                        'occurred_at' => $visitedAt,
                        'metadata' => [
                            'sensor' => 'mobile_browser',
                            'hour_start' => $hourStart->toIso8601String(),
                            'visit_count' => 0,
                            'domain_count' => 0,
                        ],
                    ]);
                }

                MobileBrowsingVisit::create([
                    'user_id' => $sensor->user_id,
                    'daily_log_id' => $log->id,
                    'log_block_id' => $block->id,
                    'domain' => $domain,
                    'visit_key' => $visit['visit_key'],
                    'visited_at' => $visitedAt,
                ]);
                $blocks->put($block->id, $block);
                $imported++;
            }

            $blocks->each(fn (LogBlock $block) => $this->refreshBlock($block));
            $sensor->update(['last_checked_at' => now(), 'last_error' => null]);
        });

        return ['imported' => $imported, 'duplicates' => $duplicates, 'rejected' => $rejected, 'blocks' => $blocks->count()];
    }

    private function refreshBlock(LogBlock $block): void
    {
        $visits = $block->mobileBrowsingVisits()->get();
        $visitCount = $visits->count();
        $domainCount = $visits->pluck('domain')->unique()->count();
        $firstVisit = $visits->min('visited_at');
        $block->update([
            'content' => 'Mobile browsing · '.$domainCount.' '.($domainCount === 1 ? 'domain' : 'domains').' · '.$visitCount.' '.($visitCount === 1 ? 'visit' : 'visits'),
            'occurred_at' => $firstVisit ?? $block->occurred_at,
            'metadata' => array_merge($block->metadata ?? [], [
                'visit_count' => $visitCount,
                'domain_count' => $domainCount,
            ]),
        ]);
    }

    private function fullHostname(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = trim($host, '.');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $host;
    }
}
