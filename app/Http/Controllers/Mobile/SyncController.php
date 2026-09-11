<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ApiCall;
use App\Models\LogBlock;
use App\Services\GoalProgressService;
use App\Services\Mobile\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SyncController extends Controller
{
    public function push(Request $request, SyncService $sync)
    {
        $data = $request->validate([
            'operations' => 'present|array|max:100', 'operations.*.id' => 'required|uuid',
            'operations.*.kind' => 'required|string|max:50', 'operations.*.target_id' => ['nullable', 'regex:/^(?:[0-9]+|[0-9a-fA-F-]{36})$/'],
            'operations.*.edited_at' => 'required|date', 'operations.*.data' => 'present|array',
        ]);
        $results = [];
        foreach ($data['operations'] as $op) {
            try {
                $results[] = $sync->apply($request->user(), $op);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                $results[] = ['id' => $op['id'], 'status' => 'rejected', 'message' => 'A referenced record is missing or unavailable.'];
            } catch (ValidationException $e) {
                $results[] = ['id' => $op['id'], 'status' => 'rejected', 'message' => collect($e->errors())->flatten()->implode(' ')];
            } catch (HttpExceptionInterface $e) {
                $results[] = ['id' => $op['id'], 'status' => 'rejected', 'message' => $e->getMessage() ?: 'Operation could not be applied.'];
            }
        }

        return response()->json(['results' => $results, 'server_time' => now()->utc()->toISOString()]);
    }

    public function snapshot(Request $request, GoalProgressService $progress)
    {
        $user = $request->user();
        $progress->syncForUser($user);

        // Complete account snapshot: missing records are deletions, including DB cascades.
        return response()->json([
            'screensaver_logo' => $user->screensaver_logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($user->screensaver_logo_path) ? 'data:image/png;base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('public')->get($user->screensaver_logo_path)) : null,
            'schema_version' => 1, 'server_time' => now()->utc()->toISOString(),
            'user' => $user->fresh()->only('id', 'name', 'email', 'time_format', 'week_starts_on', 'default_chat_model', 'screensaver_enabled', 'screensaver_style', 'screensaver_wait_minutes', 'screensaver_speed', 'screensaver_message', 'is_admin'),
            'tasks' => $user->taskDefinitions()->orderBy('position')->orderBy('id')->get(),
            // Calendar dates are not instants: preserve their database day across timezones.
            'goals' => $user->goals()->with(['sources', 'entries'])->get()->each(fn ($goal) => $goal->mergeCasts(['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'])),
            'logs' => $user->dailyLogs()->orderBy('log_date')->get()->each(fn ($log) => $log->mergeCasts(['log_date' => 'date:Y-m-d'])),
            'blocks' => LogBlock::whereHas('dailyLog', fn ($q) => $q->where('user_id', $user->id))->with(['taskEvent', 'attachments', 'browsingActivities', 'desktopActivities', 'mobileBrowsingVisits', 'kindleReadingProgress', 'googleCalendarEvent'])->orderBy('position')->get(),
            'sensors' => $user->sensors()->get()->map(fn ($s) => $s->only('id', 'type', 'username', 'enabled', 'last_checked_at', 'last_error', 'updated_at')),
            'chat_proposals' => \App\Models\ChatActionProposal::where('user_id', $user->id)->where('status', 'pending')->where('expires_at', '>', now())->get(['id', 'daily_log_id', 'summary', 'expires_at']),
            'api_calls' => ApiCall::where('user_id', $user->id)->latest()->get()->map(fn ($c) => $c->only('id', 'operation', 'model', 'total_tokens', 'cost', 'created_at', 'status_code', 'error')),
            'revisions' => DB::table('mobile_revisions')->where('user_id', $user->id)->get(['entity', 'entity_id', 'edited_at', 'deleted']),
        ])->header('Cache-Control', 'no-store');
    }
}
