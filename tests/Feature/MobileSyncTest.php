<?php

namespace Tests\Feature;

use App\Models\LogBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileSyncTest extends TestCase
{
    use RefreshDatabase;

    private function operation(string $kind, array $data = [], $target = null, $time = null): array
    {
        return ['id' => (string) Str::uuid(), 'kind' => $kind, 'target_id' => $target, 'edited_at' => ($time ?? now())->toISOString(), 'data' => $data];
    }

    private function push(array $op)
    {
        return $this->postJson('/api/mobile/sync', ['operations' => [$op]]);
    }

    private function signIn(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mobile']);

        return $user;
    }

    public function test_authentication_and_token_scope_are_required(): void
    {
        $this->getJson('/api/mobile/snapshot')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(), ['sensors']);
        $this->getJson('/api/mobile/snapshot')->assertForbidden();
    }

    public function test_login_and_revocation(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        $this->postJson('/api/mobile/login', ['email' => $user->email, 'password' => 'bad', 'device_name' => 'iPhone'])->assertUnprocessable();
        $token = $this->postJson('/api/mobile/login', ['email' => $user->email, 'password' => 'secret123', 'device_name' => 'iPhone'])->assertOk()->json('token');
        $this->withToken($token)->postJson('/api/mobile/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_create_retry_is_idempotent_and_snapshot_excludes_notes_and_other_accounts(): void
    {
        $user = $this->signIn();
        $other = User::factory()->create();
        $other->dailyLogs()->create(['log_date' => '2026-09-09'])->blocks()->create(['type' => 'text', 'content' => 'private']);
        $op = $this->operation('blocks.create', ['log_date' => '2026-09-10', 'type' => 'text', 'content' => 'offline']);
        $id = $this->push($op)->assertOk()->assertJsonPath('results.0.status', 'applied')->json('results.0.entity_id');
        $this->push($op)->assertJsonPath('results.0.entity_id', $id);
        $this->assertSame(1, LogBlock::where('content', 'offline')->count());
        $this->getJson('/api/mobile/snapshot')->assertOk()->assertJsonCount(1, 'blocks')->assertJsonMissing(['content' => 'private'])->assertJsonMissingPath('notes');
        $op['data']['content'] = 'tampered';
        $this->push($op)->assertJsonPath('results.0.status', 'rejected');
    }

    public function test_snapshot_preserves_calendar_dates_in_every_server_timezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        $originalConfig = config('app.timezone');
        try {
            foreach (['Asia/Taipei', 'Pacific/Kiritimati', 'America/Los_Angeles', 'Pacific/Pago_Pago', 'UTC'] as $timezone) {
                config(['app.timezone' => $timezone]);
                date_default_timezone_set($timezone);
                $user = $this->signIn();
                $log = $user->dailyLogs()->create(['log_date' => '2026-09-11']);
                $storedDate = $log->fresh()->getRawOriginal('log_date');
                $log->blocks()->create(['type' => 'text', 'content' => 'Friday']);
                $user->goals()->create([
                    'name' => 'Date boundaries', 'emoji' => '🎯', 'color' => '#4f46e5',
                    'target_points' => 5, 'period' => 'weekly', 'manual_enabled' => true,
                    'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
                ]);
                $user->goals()->create([
                    'name' => 'Open dates', 'emoji' => '🎯', 'color' => '#4f46e5',
                    'target_points' => 1, 'period' => 'weekly', 'manual_enabled' => true,
                ]);

                $snapshot = $this->getJson('/api/mobile/snapshot')->assertOk()
                    ->assertJsonPath('logs.0.log_date', '2026-09-11')
                    ->assertJsonPath('blocks.0.daily_log_id', $log->id);
                $goals = collect($snapshot->json('goals'))->keyBy('name');
                $this->assertSame('2026-09-01', $goals['Date boundaries']['start_date']);
                $this->assertSame('2026-09-30', $goals['Date boundaries']['end_date']);
                $this->assertNull($goals['Open dates']['start_date']);
                $this->assertNull($goals['Open dates']['end_date']);
                $this->assertSame($storedDate, $log->fresh()->getRawOriginal('log_date'));
            }
        } finally {
            config(['app.timezone' => $originalConfig]);
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_last_edit_timestamp_wins_regardless_of_arrival_order(): void
    {
        $user = $this->signIn();
        $this->travelTo(now()->startOfSecond());
        $block = $user->dailyLogs()->create(['log_date' => '2026-09-10'])->blocks()->create(['type' => 'text', 'content' => 'initial']);
        $this->travel(5)->seconds();
        $older = now();
        $this->travel(5)->seconds();
        $newer = now();
        $this->travel(10)->seconds();
        $this->push($this->operation('blocks.update', ['content' => 'newest'], $block->id, $newer))->assertJsonPath('results.0.status', 'applied');
        $this->push($this->operation('blocks.update', ['content' => 'old'], $block->id, $older))->assertJsonPath('results.0.status', 'conflict');
        $this->assertSame('newest', $block->fresh()->content);
        $block->refresh()->update(['content' => 'web']);
        $this->push($this->operation('blocks.update', ['content' => 'newest'], $block->id, $newer))->assertJsonPath('results.0.status', 'conflict');
    }

    public function test_ownership_checked_before_conflict_and_future_dates_rejected(): void
    {
        $this->signIn();
        $task = User::factory()->create()->taskDefinitions()->create(['name' => 'Private']);
        $this->push($this->operation('tasks.delete', [], $task->id))->assertJsonPath('results.0.status', 'rejected');
        $this->push($this->operation('blocks.create', ['log_date' => '2026-09-10', 'type' => 'text', 'content' => 'future'], null, now()->addDay()))->assertJsonPath('results.0.status', 'rejected');
        $this->assertNotNull($task->fresh());
    }

    public function test_deleted_web_entry_does_not_reappear_from_old_offline_edit(): void
    {
        $user = $this->signIn();
        $block = $user->dailyLogs()->create(['log_date' => '2026-09-10'])->blocks()->create(['type' => 'text', 'content' => 'original']);
        $op = $this->operation('blocks.update', ['content' => 'offline'], $block->id);
        $this->travel(5)->seconds();
        $block->delete();
        $this->push($op)->assertJsonPath('results.0.status', 'conflict');
        $this->getJson('/api/mobile/snapshot')->assertJsonCount(0, 'blocks')->assertJsonPath('revisions.0.deleted', 1);
    }

    public function test_offline_create_references_and_validation(): void
    {
        $this->signIn();
        $task = $this->operation('tasks.create', ['name' => 'Water', 'color' => '#000000', 'recurrence_type' => 'daily', 'options_text' => 'Cup,Bottle']);
        $event = $this->operation('events.create', ['log_date' => '2026-09-10', 'task_definition_id' => $task['id'], 'value' => 'Cup']);
        $this->postJson('/api/mobile/sync', ['operations' => [$task, $event]])->assertJsonPath('results.0.status', 'applied')->assertJsonPath('results.1.status', 'applied');
        $this->push($event)->assertJsonPath('results.0.status', 'applied');
        $this->assertDatabaseCount('task_events', 1);
        $event['id'] = (string) Str::uuid();
        $event['data']['value'] = 'Wrong';
        $this->push($event)->assertOk()->assertJsonPath('results.0.status', 'rejected');
        $this->assertDatabaseCount('task_events', 1);
    }

    public function test_newer_offline_edit_restores_a_deleted_text_log(): void
    {
        $user = $this->signIn();
        $block = $user->dailyLogs()->create(['log_date' => '2026-09-10'])->blocks()->create(['type' => 'text', 'content' => 'initial']);
        $this->travel(5)->seconds();
        $block->delete();
        $this->travel(5)->seconds();
        $edited = now();
        $this->travel(5)->seconds();
        $this->push($this->operation('blocks.update', ['content' => 'newer edit'], $block->id, $edited))->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->assertSame('newer edit', LogBlock::findOrFail($block->id)->content);
    }

    public function test_goal_progress_is_append_only_and_retry_safe(): void
    {
        $user = $this->signIn();
        $goal = $user->goals()->create(['name' => 'Read', 'color' => '#000000', 'target_points' => 10, 'period' => 'daily', 'manual_enabled' => true]);
        $op = $this->operation('goals.progress', ['points' => 2, 'occurred_on' => '2026-09-10'], $goal->id, now()->subDay());
        $this->push($op)->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->push($op)->assertJsonPath('results.0.status', 'applied');
        $this->assertDatabaseCount('goal_entries', 1);
        $this->assertDatabaseCount('task_events', 1);
    }

    public function test_admin_endpoints_reject_regular_users_and_snapshot_redacts_secrets(): void
    {
        $user = $this->signIn();
        $user->sensors()->create(['type' => 'github', 'username' => 'example', 'token' => 'private-token', 'settings' => ['secret' => 'sensitive']]);
        $this->getJson('/api/mobile/admin/users')->assertForbidden();
        $this->postJson('/api/mobile/admin/reset-demo')->assertForbidden();
        $this->getJson('/api/mobile/snapshot')->assertOk()->assertJsonMissingPath('sensors.0.token')->assertJsonMissingPath('sensors.0.settings')->assertJsonMissingPath('user.password');
    }
}
