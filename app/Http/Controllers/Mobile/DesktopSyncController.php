<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\GoalProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Content-addressed deltas: nested updates and cascade deletions are included. */
class DesktopSyncController extends Controller
{
    public function pull(Request $request, GoalProgressService $progress)
    {
        $request->validate(['cursor' => 'nullable|regex:/^[a-f0-9]{64}$/']);
        $prefix = 'desktop-sync:v1:'.$request->user()->id.':';
        $version = null;
        if (Schema::hasTable('desktop_sync_versions')) {
            $version = DB::table('desktop_sync_versions')->where('user_id', $request->user()->id)->value('version');
            if (! $version) {
                // Existing accounts may predate the observer's migration.
                DB::table('desktop_sync_versions')->insertOrIgnore(['user_id' => $request->user()->id, 'version' => (string) Str::uuid()]);
                $version = DB::table('desktop_sync_versions')->where('user_id', $request->user()->id)->value('version');
            }
        }
        // Hourly reconciliation also catches maintenance SQL and time-based expiry.
        $versionKey = $prefix.'version:'.($version ?? 'initial').':'.now()->utc()->format('Y-m-d-H');
        if ($version && $request->filled('cursor') && Cache::get($versionKey) === $request->input('cursor')) {
            return response()->json(['schema_version' => 1, 'user_id' => $request->user()->id,
                'cursor' => $request->input('cursor'), 'reset' => false, 'changes' => [], 'deleted' => [],
                'server_time' => now()->utc()->toISOString()])->header('Cache-Control', 'private, no-store');
        }
        // Iterate small database batches; never build/encode/decode the whole snapshot.
        $progress->syncForUser($request->user());
        $time = now()->utc()->toISOString();
        $old = $request->input('cursor') ? Cache::get($prefix.$request->input('cursor')) : null;
        $spool = tmpfile();
        if ($spool === false) {
            throw new \RuntimeException('Unable to prepare sync download.');
        }
        $hashes = [];
        try {
            $first = true;
            foreach ($this->records($request->user()) as [$collection, $id, $row]) {
                $encoded = json_encode($row, JSON_THROW_ON_ERROR);
                $hashes[$collection][$id] = hash('sha256', $encoded);
                if (($old[$collection][$id] ?? null) !== $hashes[$collection][$id]) {
                    $change = ($first ? '' : ',').'{"collection":'.json_encode($collection).',"id":'.json_encode((string) $id).',"value":'.$encoded.'}';
                    if (fwrite($spool, $change) !== strlen($change)) {
                        throw new \RuntimeException('Unable to write sync download.');
                    }
                    $first = false;
                }
            }
        } catch (\Throwable $e) {
            fclose($spool);
            throw $e;
        }
        $cursor = hash('sha256', json_encode($hashes));
        $deleted = [];
        foreach ($old ?? [] as $collection => $values) {
            foreach (array_diff(array_keys($values), array_keys($hashes[$collection] ?? [])) as $id) {
                $deleted[] = ['collection' => $collection, 'id' => (string) $id];
            }
        }
        // Only hashes are cached, never a second copy of account content or secrets.
        Cache::put($prefix.$cursor, $hashes, now()->addDays(7));
        // Keep the version read BEFORE serialization. Concurrent writes force another pull.
        if ($version) {
            Cache::put($versionKey, $cursor, now()->addHours(2));
        }

        $header = json_encode(['schema_version' => 1, 'user_id' => $request->user()->id,
            'cursor' => $cursor, 'reset' => $old === null, 'server_time' => $time], JSON_THROW_ON_ERROR);
        $tail = '],"deleted":'.json_encode($deleted, JSON_THROW_ON_ERROR).'}';
        rewind($spool);
        return response()->stream(function () use ($spool, $header, $tail) {
            try {
                echo substr($header, 0, -1).',"changes":[';
                while (! feof($spool)) {
                    echo fread($spool, 65536);
                }
                echo $tail;
            } finally {
                fclose($spool);
            }
        }, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
    }

    private function records(\App\Models\User $user): \Generator
    {
        yield ['schema_version', 'singleton', 1];
        yield ['user', 'singleton', $user->fresh()->only('id', 'name', 'email', 'time_format', 'week_starts_on', 'default_chat_model', 'screensaver_enabled', 'screensaver_style', 'screensaver_wait_minutes', 'screensaver_speed', 'screensaver_message', 'is_admin')];
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        yield ['screensaver_logo', 'singleton', $user->screensaver_logo_path && $disk->exists($user->screensaver_logo_path)
            ? 'data:image/png;base64,'.base64_encode($disk->get($user->screensaver_logo_path)) : null];
        $queries = [
            'tasks' => $user->taskDefinitions(),
            'goals' => $user->goals()->with(['sources', 'entries']),
            'logs' => $user->dailyLogs(),
            'blocks' => \App\Models\LogBlock::whereHas('dailyLog', fn ($q) => $q->where('user_id', $user->id))
                ->with(['taskEvent', 'attachments', 'browsingActivities', 'desktopActivities', 'mobileBrowsingVisits', 'kindleReadingProgress', 'googleCalendarEvent']),
            'sensors' => $user->sensors()->select('id', 'type', 'username', 'enabled', 'last_checked_at', 'last_error', 'updated_at'),
            'chat_proposals' => \App\Models\ChatActionProposal::where('user_id', $user->id)->where('status', 'pending')->where('expires_at', '>', now())->select('id', 'daily_log_id', 'summary', 'expires_at'),
            // Do not hydrate potentially huge AI request/response metadata.
            'api_calls' => \App\Models\ApiCall::where('user_id', $user->id)->select('id', 'operation', 'model', 'total_tokens', 'cost', 'created_at', 'status_code', 'error'),
        ];
        foreach ($queries as $collection => $query) {
            foreach ($query->reorder()->lazyById(25) as $row) {
                $casts = match ($collection) {
                    'tasks' => ['repeat_start_date' => 'date:Y-m-d'],
                    'goals' => ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'],
                    'logs' => ['log_date' => 'date:Y-m-d'],
                    default => [],
                };
                yield [$collection, (string) $row->id, $row->mergeCasts($casts)];
            }
        }
    }
}
