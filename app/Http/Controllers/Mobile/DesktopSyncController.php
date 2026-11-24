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
    public function pull(Request $request, SyncController $sync, GoalProgressService $progress)
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
        // Use the same owned, redacted representation as the mobile client.
        $snapshotRequest = clone $request;
        $snapshotRequest->headers->remove('If-None-Match');
        $snapshot = $sync->snapshot($snapshotRequest, $progress)->getData(true);
        $time = $snapshot['server_time'];
        unset($snapshot['server_time'], $snapshot['revisions']);
        $rows = [];
        foreach ($snapshot as $collection => $value) {
            if (in_array($collection, ['user', 'screensaver_logo', 'schema_version'], true)) {
                $rows[$collection]['singleton'] = $value;
            } else {
                foreach ($value as $row) {
                    $rows[$collection][(string) $row['id']] = $row;
                }
                $rows[$collection] ??= [];
            }
        }
        $hashes = [];
        foreach ($rows as $collection => $values) {
            foreach ($values as $id => $row) {
                $hashes[$collection][$id] = hash('sha256', json_encode($row));
            }
            $hashes[$collection] ??= [];
        }
        $cursor = hash('sha256', json_encode($hashes));
        $old = $request->input('cursor') ? Cache::get($prefix.$request->input('cursor')) : null;
        $changes = [];
        $deleted = [];
        foreach ($rows as $collection => $values) {
            foreach ($values as $id => $row) {
                if (($old[$collection][$id] ?? null) !== $hashes[$collection][$id]) {
                    $changes[] = ['collection' => $collection, 'id' => (string) $id, 'value' => $row];
                }
            }
            foreach (array_diff(array_keys($old[$collection] ?? []), array_keys($values)) as $id) {
                $deleted[] = ['collection' => $collection, 'id' => (string) $id];
            }
        }
        // Only hashes are cached, never a second copy of account content or secrets.
        Cache::put($prefix.$cursor, $hashes, now()->addDays(7));
        // Keep the version read BEFORE serialization. Concurrent writes force another pull.
        if ($version) {
            Cache::put($versionKey, $cursor, now()->addHours(2));
        }

        return response()->json(['schema_version' => 1, 'user_id' => $request->user()->id,
            'cursor' => $cursor, 'reset' => $old === null, 'changes' => $changes,
            'deleted' => $deleted, 'server_time' => $time])->header('Cache-Control', 'private, no-store');
    }
}
