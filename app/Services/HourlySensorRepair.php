<?php

namespace App\Services;

use App\Models\BrowsingActivity;
use App\Models\DailyLog;
use App\Models\DesktopActivity;
use App\Models\LogBlock;
use App\Models\MobileBrowsingVisit;
use Carbon\Carbon;

class HourlySensorRepair
{
    private const TYPES = [
        'sensor_browser' => [BrowsingActivity::class, 'started_at', 'domain', 'Browsing', 'domain'],
        'sensor_desktop' => [DesktopActivity::class, 'started_at', 'application', 'Desktop activity', 'app'],
        'sensor_mobile_browser' => [MobileBrowsingVisit::class, 'visited_at', 'domain', 'Mobile browsing', 'domain'],
    ];

    public function plan(DailyLog $log, bool $discardInvalid, array $removeDomains): array
    {
        $blocks = $log->blocks()->whereIn('type', array_keys(self::TYPES))->get()->keyBy('id');
        $groups = [];
        $remove = [];
        $original = [];
        $invalid = 0;
        $duplicates = 0;
        $removed = 0;
        foreach (self::TYPES as $type => [$model, $timeField]) {
            $rows = $model::where('daily_log_id', $log->id)->where('user_id', $log->user_id)->orderBy('id')->get();
            $original[$type] = $rows->map->getAttributes()->all();
            $seen = [];
            foreach ($rows as $row) {
                // Database timestamps are stored in the application timezone. Do not
                // use a block's potentially stale display time or hour_start metadata.
                $time = Carbon::parse($row->getRawOriginal($timeField), config('app.timezone'));
                $bad = $type === 'sensor_browser' && $this->invalidBrowsing($row, $time);
                $invalid += (int) $bad;
                $removeDomain = $type === 'sensor_browser' && in_array(strtolower($row->domain), $removeDomains, true);
                $attributes = $row->getAttributes();
                unset($attributes['id'], $attributes['log_block_id'], $attributes['created_at'], $attributes['updated_at']);
                $key = json_encode($attributes, JSON_THROW_ON_ERROR);
                $duplicate = isset($seen[$key]);
                if ($removeDomain || ($discardInvalid && $bad) || $duplicate) {
                    $remove[$model][] = $row->id;
                    $duplicates += (int) $duplicate;
                    $removed++;
                    continue;
                }
                $seen[$key] = true;
                $groupKey = $type.'|'.$time->format('Y-m-d H');
                if (! isset($groups[$groupKey])) {
                    $groups[$groupKey] = ['type' => $type, 'time' => $time, 'rows' => collect()];
                }
                if ($time->lt($groups[$groupKey]['time'])) {
                    $groups[$groupKey]['time'] = $time;
                }
                $groups[$groupKey]['rows']->push($row);
            }
        }

        // Reuse IDs where possible so links to existing reports remain useful.
        ksort($groups);
        $used = [];
        foreach ($groups as &$group) {
            $group['target_id'] = null;
            foreach ($group['rows'] as $row) {
                $block = $blocks->get($row->log_block_id);
                if ($block && $block->type === $group['type'] && ! isset($used[$block->id])) {
                    $group['target_id'] = $block->id;
                    $used[$block->id] = true;
                    break;
                }
            }
        }
        unset($group);

        return [
            'blocks' => $blocks, 'groups' => $groups, 'remove' => $remove,
            'delete_blocks' => $blocks->keys()->reject(fn ($id) => isset($used[$id]))->all(),
            'summary' => ['before' => $blocks->count(), 'after' => count($groups), 'removed' => $removed, 'duplicates' => $duplicates, 'invalid' => $invalid],
            'backup' => ['schema_version' => 1, 'timezone' => config('app.timezone'), 'saved_at' => now()->toIso8601String(),
                'daily_log' => $log->getAttributes(), 'blocks' => $blocks->values()->map->getAttributes()->all(), 'activities' => $original],
        ];
    }

    private function invalidBrowsing(BrowsingActivity $row, Carbon $start): bool
    {
        $last = Carbon::parse($row->getRawOriginal('last_seen_at'), config('app.timezone'));
        $end = Carbon::parse($row->getRawOriginal('ended_at') ?? $row->getRawOriginal('last_seen_at'), config('app.timezone'));
        $boundary = $start->copy()->startOfHour()->addHour();

        return $last->lt($start) || $end->lt($start) || $last->gt($boundary) || $end->gt($boundary)
            || $row->duration_seconds > $start->diffInSeconds($end)
            || $row->duration_seconds > 3600;
    }

    // Caller holds the account lock and has written the backup before applying.
    public function apply(DailyLog $log, array $plan): void
    {
        foreach ($plan['remove'] as $model => $ids) {
            $model::whereKey($ids)->delete();
        }
        foreach ($plan['groups'] as $group) {
            [$model, , $labelField, $title, $unit] = self::TYPES[$group['type']];
            $block = $group['target_id'] ? $plan['blocks']->get($group['target_id']) : new LogBlock([
                'daily_log_id' => $log->id, 'type' => $group['type'],
                'position' => ((int) $log->blocks()->max('position')) + 1,
            ]);
            $count = $group['rows']->pluck($labelField)->unique()->count();
            $seconds = (int) $group['rows']->sum('duration_seconds');
            $visits = $group['rows']->count();
            $mobile = $group['type'] === 'sensor_mobile_browser';
            $amount = $mobile ? $visits.' '.($visits === 1 ? 'visit' : 'visits') : $this->duration($seconds);
            $block->fill([
                'content' => $title.' · '.$count.' '.$unit.($count === 1 ? '' : 's').' · '.$amount,
                'occurred_at' => $group['time'],
                'metadata' => array_merge($block->metadata ?? [], [
                    'hour_start' => $group['time']->copy()->startOfHour()->toIso8601String(),
                    $unit === 'app' ? 'application_count' : 'domain_count' => $count,
                    $mobile ? 'visit_count' : 'total_seconds' => $mobile ? $visits : $seconds,
                ]),
            ]);
            if (! $block->exists || $block->isDirty()) {
                $block->save();
            }
            $model::whereKey($group['rows']->pluck('id'))->where('log_block_id', '!=', $block->id)->update(['log_block_id' => $block->id]);
        }
        foreach ($plan['delete_blocks'] as $id) {
            $block = $plan['blocks']->get($id);
            // Do not let cascade deletion erase unrelated data on an unusual block.
            if ($block->attachments()->exists() || $block->taskEvent()->exists() || $block->noteLinks()->exists()
                || $block->browsingActivities()->exists() || $block->desktopActivities()->exists()
                || $block->mobileBrowsingVisits()->exists() || $block->kindleReadingProgress()->exists()
                || $block->googleCalendarEvent()->exists()) {
                throw new \RuntimeException("Block {$id} contains other records; this day's repair was rolled back.");
            }
            $block->delete(); // Keep mobile deletion revisions in sync.
        }
    }

    private function duration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);

        return $minutes === 0 ? 'under 1 min' : ($minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).'h '.($minutes % 60).'m');
    }
}
