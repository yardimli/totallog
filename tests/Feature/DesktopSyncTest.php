<?php

namespace Tests\Feature;

use App\Models\DesktopActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DesktopSyncTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mobile']);

        return $user;
    }

    private function pull(?string $cursor = null)
    {
        $response = $this->postJson('/api/mobile/desktop/pull', ['cursor' => $cursor])->assertOk();
        if ($response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            return \Illuminate\Testing\TestResponse::fromBaseResponse(new \Illuminate\Http\JsonResponse(
                json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR)
            ));
        }
        return $response;
    }

    public function test_initial_download_and_cheap_unchanged_poll(): void
    {
        $user = $this->account();
        $user->dailyLogs()->create(['log_date' => '2026-11-23'])->blocks()->create(['type' => 'text', 'content' => 'Local and remote']);
        DB::table('desktop_sync_versions')->where('user_id', $user->id)->delete(); // Account predates this migration.
        $initial = $this->pull()->assertJsonPath('reset', true);
        $this->assertTrue(collect($initial->json('changes'))->contains(fn ($row) => $row['collection'] === 'blocks' && $row['value']['content'] === 'Local and remote'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->pull($initial->json('cursor'))->assertJsonPath('reset', false)->assertJsonPath('changes', [])->assertJsonPath('deleted', []);
        $this->assertFalse(collect(DB::getQueryLog())->contains(fn ($query) => str_contains($query['query'], 'log_blocks')));
        DB::disableQueryLog();
    }

    public function test_large_history_is_batched_and_api_payloads_are_not_loaded(): void
    {
        $user = $this->account();
        $log = $user->dailyLogs()->create(['log_date' => '2026-11-24']);
        $rows = [];
        for ($i = 0; $i < 61; $i++) {
            $rows[] = ['daily_log_id' => $log->id, 'type' => 'text', 'content' => str_repeat('x', 32768), 'position' => $i];
        }
        DB::table('log_blocks')->insert($rows);
        \App\Models\ApiCall::create(['user_id' => $user->id, 'operation' => 'chat', 'metadata' => ['private_payload' => str_repeat('secret', 200000)]]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->pull();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertCount(61, collect($response->json('changes'))->where('collection', 'blocks'));
        $this->assertFalse($queries->contains(fn ($sql) => str_contains($sql, 'select * from "api_calls"')));
        $this->assertGreaterThanOrEqual(3, $queries->filter(fn ($sql) => str_contains($sql, 'from "log_blocks"') && str_contains($sql, 'limit 25'))->count());
        $api = collect($response->json('changes'))->firstWhere('collection', 'api_calls');
        $this->assertArrayNotHasKey('metadata', $api['value']);
    }

    public function test_nested_sensor_update_and_cascade_deletion_are_downloaded(): void
    {
        $user = $this->account();
        $log = $user->dailyLogs()->create(['log_date' => '2026-11-23']);
        $block = $log->blocks()->create(['type' => 'sensor_desktop']);
        $activity = DesktopActivity::create(['user_id' => $user->id, 'daily_log_id' => $log->id, 'log_block_id' => $block->id,
            'application' => 'Editor', 'process_name' => 'editor.exe', 'client_id' => 'desktop-test',
            'started_at' => now(), 'last_seen_at' => now(), 'duration_seconds' => 10]);
        $first = $this->pull();
        $activity->update(['duration_seconds' => 90]);
        $next = $this->pull($first->json('cursor'));
        $blocks = collect($next->json('changes'))->where('collection', 'blocks');
        $this->assertCount(1, $blocks);
        $this->assertSame(90, $blocks->first()['value']['desktop_activities'][0]['duration_seconds']);
        $log->delete();
        $deleted = $this->pull($next->json('cursor'));
        $this->assertTrue(collect($deleted->json('deleted'))->contains(fn ($row) => $row['collection'] === 'blocks' && $row['id'] === (string) $block->id));
    }

    public function test_cursor_is_account_scoped_and_expired_cache_causes_safe_reset(): void
    {
        $firstUser = $this->account();
        $firstUser->dailyLogs()->create(['log_date' => '2026-11-23'])->blocks()->create(['type' => 'text', 'content' => 'Private to first account']);
        $first = $this->pull();
        $this->account();
        $next = $this->pull($first->json('cursor'))->assertJsonPath('reset', true);
        $next->assertDontSee('Private to first account');
        Cache::flush();
        $this->pull($next->json('cursor'))->assertJsonPath('reset', true);
    }

    public function test_version_rolls_back_with_failed_model_transaction(): void
    {
        $user = $this->account();
        $version = DB::table('desktop_sync_versions')->where('user_id', $user->id)->value('version');
        DB::beginTransaction();
        $user->dailyLogs()->create(['log_date' => '2026-11-23']);
        $this->assertNotSame($version, DB::table('desktop_sync_versions')->where('user_id', $user->id)->value('version'));
        DB::rollBack();
        $this->assertSame($version, DB::table('desktop_sync_versions')->where('user_id', $user->id)->value('version'));
    }

    public function test_desktop_event_preserves_explicit_wall_clock_time_and_retry_is_idempotent(): void
    {
        $user = $this->account();
        $task = $user->taskDefinitions()->create(['name' => 'Coffee', 'color' => '#4f46e5', 'recurrence_type' => 'daily', 'is_active' => true, 'daily_default_count' => 1]);
        $operation = ['id' => (string) Str::uuid(), 'kind' => 'events.create', 'target_id' => null, 'edited_at' => now()->utc()->toISOString(),
            'data' => ['log_date' => '2026-11-23', 'task_definition_id' => $task->id, 'occurred_at' => '08:23']];
        $this->postJson('/api/mobile/sync', ['operations' => [$operation]])->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->postJson('/api/mobile/sync', ['operations' => [$operation]])->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->assertDatabaseCount('task_events', 1);
        $this->assertSame('08:23:00', $user->dailyLogs()->first()->taskEvents()->first()->occurred_at->format('H:i:s'));
    }

    public function test_notes_and_secrets_are_not_in_desktop_download(): void
    {
        $user = $this->account();
        $response = $this->pull();
        $collections = collect($response->json('changes'))->pluck('collection');
        $this->assertFalse($collections->contains('notes'));
        $this->assertStringNotContainsString($user->password, $response->getContent());
        $this->assertStringNotContainsString('openrouter_api_key', $response->getContent());
    }

    public function test_recurrence_start_dates_remain_calendar_dates(): void
    {
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Taipei');
            $user = $this->account();
            $user->taskDefinitions()->create(['name' => 'Every other day', 'color' => '#4f46e5', 'recurrence_type' => 'daily', 'repeat_interval' => 2, 'repeat_start_date' => '2026-11-23']);
            $row = collect($this->pull()->json('changes'))->firstWhere('collection', 'tasks');
            $this->assertSame('2026-11-23', $row['value']['repeat_start_date']);
        } finally {
            date_default_timezone_set($timezone);
        }
    }
}
