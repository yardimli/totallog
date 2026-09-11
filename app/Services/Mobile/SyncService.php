<?php

namespace App\Services\Mobile;

use App\Http\Controllers\GoalController;
use App\Http\Controllers\GoalEntryController;
use App\Http\Controllers\LogBlockController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskEventController;
use App\Models\LogBlock;
use App\Models\Sensor;
use App\Models\TaskDefinition;
use App\Models\TaskEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncService
{
    public function apply(User $user, array $operation): array
    {
        return DB::transaction(function () use ($user, $operation) {
            // Serializes duplicate retries and creates on all devices for this account.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode($operation));
            $old = DB::table('mobile_operations')->where('user_id', $user->id)->where('operation_id', $operation['id'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 422, 'Operation ID was already used with different contents.');

                return json_decode($old->result, true);
            }
            $edited = Carbon::parse($operation['edited_at'])->utc();
            abort_if($edited->gt(now()->addMinutes(5)), 422, 'Device clock is ahead of the server. Correct it and retry.');
            [$entity, $action] = array_pad(explode('.', $operation['kind'], 2), 2, '');
            $classes = array_flip(RevisionObserver::ENTITIES);
            abort_unless(isset($classes[$entity]), 422, 'Unsupported entity.');
            $targetId = $operation['target_id'] ?? null;
            if (is_string($targetId) && ! ctype_digit($targetId)) {
                $parent = DB::table('mobile_operations')->where('user_id', $user->id)->where('operation_id', $targetId)->first();
                $targetId = $parent ? data_get(json_decode($parent->result, true), 'entity_id') : null;
                abort_unless($targetId, 422, 'The creating operation must sync first.');
            }
            $target = null;
            if ($action !== 'create') {
                $targetId = $entity === 'settings' ? $user->id : $targetId;
                abort_unless($targetId, 422, 'A target is required.');
                $target = $classes[$entity]::whereKey($targetId)->lockForUpdate()->first();
                $revision = DB::table('mobile_revisions')->where(['user_id' => $user->id, 'entity' => $entity, 'entity_id' => $targetId])->first();
                if (! $target) {
                    abort_unless($revision, 404);
                    if ($edited->lte(Carbon::parse($revision->edited_at)) || ! $revision->payload || $action === 'delete') {
                        return $this->remember($user, $operation, $hash, ['status' => 'conflict', 'message' => 'The deletion is newer, or this deleted record cannot be restored.', 'entity_id' => (int) $targetId]);
                    }
                    $saved = json_decode($revision->payload, true);
                    if ($entity === 'events' && ! empty($saved['block'])) {
                        $attributes = $saved['block'];
                        if (! LogBlock::find($attributes['id'])) {
                            DB::table('log_blocks')->insert($attributes);
                        }
                        if (! TaskDefinition::find($saved['attributes']['task_definition_id'])) {
                            $saved['attributes']['task_definition_id'] = null;
                        }
                    }
                    $target = new $classes[$entity];
                    $target->forceFill($saved['attributes']);
                    $target->timestamps = false;
                    $target->saveQuietly();
                    $target->timestamps = true;
                    if ($entity === 'goals') {
                        foreach ($saved['sources'] as $source) {
                            DB::table('goal_sources')->insert($source);
                        }
                        foreach ($saved['entries'] as $entry) {
                            DB::table('goal_entries')->insert($entry);
                        }
                    }
                    if ($entity === 'blocks' && ! empty($saved['event'])) {
                        $event = $saved['event'];
                        if (! TaskDefinition::find($event['task_definition_id'])) {
                            $event['task_definition_id'] = null;
                        }
                        DB::table('task_events')->insert($event);
                    }
                }
                $owner = $target instanceof User ? $target->id : ($target->user_id ?? $target->dailyLog?->user_id);
                abort_unless((int) $owner === $user->id, 403);
                $serverTime = Carbon::parse($revision?->edited_at ?? $target->updated_at);
                if ($target->updated_at->gt($serverTime)) {
                    $serverTime = $target->updated_at;
                }
                if ($entity === 'events') {
                    $blockRevision = DB::table('mobile_revisions')->where(['user_id' => $user->id, 'entity' => 'blocks', 'entity_id' => $target->log_block_id])->value('edited_at');
                    if ($blockRevision && Carbon::parse($blockRevision)->gt($serverTime)) {
                        $serverTime = Carbon::parse($blockRevision);
                    }
                }
                if ($action !== 'progress' && $edited->lte($serverTime)) {
                    return $this->remember($user, $operation, $hash, ['status' => 'conflict', 'message' => 'The server version has an equal or newer timestamp.', 'entity_id' => (int) $targetId]);
                }
            }
            $data = $operation['data'] ?? [];
            // Resolve references to offline-created definitions/goals.
            foreach (['task_definition_id'] as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && ! ctype_digit($data[$key])) {
                    $ref = DB::table('mobile_operations')->where('user_id', $user->id)->where('operation_id', $data[$key])->first();
                    $data[$key] = $ref ? data_get(json_decode($ref->result, true), 'entity_id') : null;
                    abort_unless($data[$key], 422, 'Referenced event must sync first.');
                }
            }
            $request = Request::create('/api/mobile/sync', 'POST', $data);
            $request->headers->set('Accept', 'application/json');
            $request->setUserResolver(fn () => $user);
            $request->setLaravelSession(app('session')->driver('array'));
            $log = function () use ($user, $data) {
                validator($data, ['log_date' => 'required|date_format:Y-m-d'])->validate();

                return $user->dailyLogs()->whereDate('log_date', $data['log_date'])->first() ?? $user->dailyLogs()->create(['log_date' => $data['log_date']]);
            };
            $result = match ($operation['kind']) {
                'tasks.create' => app(TaskController::class)->store($request),
                'tasks.update' => app(TaskController::class)->update($request, $target),
                'tasks.position' => $this->position($request, $target),
                'tasks.delete' => app(TaskController::class)->destroy($request, $target),
                'goals.create' => app(GoalController::class)->store($request),
                'goals.update' => app(GoalController::class)->update($request, $target),
                'goals.delete' => app(GoalController::class)->destroy($request, $target),
                'goals.progress' => app(GoalEntryController::class)->store($request, $target),
                'blocks.create' => app(LogBlockController::class)->store($request, $log()),
                'blocks.update' => app(LogBlockController::class)->update($request, $target),
                'blocks.delete' => app(LogBlockController::class)->destroy($request, $target),
                'blocks.visibility' => $this->visibility($request, $target),
                'events.create' => app(TaskEventController::class)->store($request, $log(), TaskDefinition::where('user_id', $user->id)->findOrFail($data['task_definition_id'] ?? null)),
                'events.location' => $this->location($request, $target),
                'events.update' => app(TaskEventController::class)->update($request, $target),
                'settings.update' => app(SettingsController::class)->update($request),
                'settings.logo' => $this->logo($request, $target),
                'settings.screensaver' => app(SettingsController::class)->updateScreensaver($request),
                'sensors.update' => $this->sensor($request, $target),
                'sensors.delete' => $target->delete(),
                default => abort(422, 'Unsupported operation.'),
            };
            if ($operation['kind'] === 'events.create' && $result instanceof \Illuminate\Http\JsonResponse) {
                $created = TaskEvent::find($result->getData(true)['event']['id']);
                $at = Carbon::parse($data['log_date'])->setTimeFrom($edited->copy()->setTimezone(config('app.timezone')));
                $created->update(['occurred_at' => $at]);
                $created->block->update(['occurred_at' => $at]);
            }
            $body = $result instanceof \Illuminate\Http\JsonResponse ? $result->getData(true) : [];
            $id = $targetId ?? data_get($body, match ($entity) {
                'tasks', 'events' => 'event.id', 'goals' => 'goal.id', default => 'block.id'
            });
            if ($id && $action !== 'progress') {
                DB::table('mobile_revisions')->updateOrInsert(['user_id' => $user->id, 'entity' => $entity, 'entity_id' => $id], ['edited_at' => $edited->format('Y-m-d\TH:i:s.u\Z'), 'deleted' => $action === 'delete']);
                // Keep arrival time out of last-writer-wins comparisons.
                $classes[$entity]::whereKey($id)->update(['updated_at' => $edited]);
                if ($entity === 'events') {
                    $event = TaskEvent::find($id);
                    if ($event) {
                        LogBlock::whereKey($event->log_block_id)->update(['updated_at' => $edited]);
                        DB::table('mobile_revisions')->where(['user_id' => $user->id, 'entity' => 'blocks', 'entity_id' => $event->log_block_id])->update(['edited_at' => $edited->format('Y-m-d\TH:i:s.u\Z'), 'deleted' => false]);
                    }
                }
            }

            return $this->remember($user, $operation, $hash, ['status' => 'applied', 'entity_id' => $id ? (int) $id : null]);
        }, 3);
    }

    private function remember(User $user, array $op, string $hash, array $result): array
    {
        $result['id'] = $op['id'];
        DB::table('mobile_operations')->insert(['user_id' => $user->id, 'operation_id' => $op['id'], 'request_hash' => $hash, 'result' => json_encode($result), 'created_at' => now(), 'updated_at' => now()]);

        return $result;
    }

    private function logo(Request $request, User $user)
    {
        $data = $request->validate(['logo_data' => ['nullable', 'string', 'max:500000', new \App\Rules\CroppedIcon]]);
        $previous = $user->screensaver_logo_path;
        $path = null;
        if (! empty($data['logo_data'])) {
            $path = 'screensaver-logos/'.\Illuminate\Support\Str::uuid().'.png';
            abort_unless(\Illuminate\Support\Facades\Storage::disk('public')->put($path, base64_decode(substr($data['logo_data'], strlen('data:image/png;base64,')))), 500, 'Could not save logo.');
        }
        $user->update(['screensaver_logo_path' => $path]);
        if ($previous) {
            DB::afterCommit(fn () => \Illuminate\Support\Facades\Storage::disk('public')->delete($previous));
        }

        return response()->json([]);
    }

    private function location(Request $request, TaskEvent $event)
    {
        $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'location_accuracy' => 'nullable|numeric|min:0|max:100000', 'city' => 'nullable|string|max:255', 'suburb' => 'nullable|string|max:255']);
        $event->update($data);

        return response()->json([]);
    }

    private function position(Request $request, TaskDefinition $task)
    {
        $task->update($request->validate(['position' => 'required|integer|min:0|max:100000']));

        return response()->json([]);
    }

    private function visibility(Request $request, LogBlock $block)
    {
        $block->update($request->validate(['is_hidden' => 'required|boolean']));

        return response()->json([]);
    }

    private function sensor(Request $request, Sensor $sensor)
    {
        $sensor->update($request->validate(['enabled' => 'required|boolean']));

        return response()->json([]);
    }
}
