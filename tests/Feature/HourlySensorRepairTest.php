<?php

namespace Tests\Feature;

use App\Models\BrowsingActivity;
use App\Models\DesktopActivity;
use App\Models\MobileBrowsingVisit;
use App\Models\Sensor;
use App\Models\User;
use App\Services\BrowsingActivityRecorder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HourlySensorRepairTest extends TestCase
{
    use RefreshDatabase;

    private function browser($log, string $time, int $seconds = 60, string $domain = 'example.com')
    {
        $start = Carbon::parse($log->log_date->toDateString().' '.$time);
        $block = $log->blocks()->create(['type' => 'sensor_browser', 'occurred_at' => $start, 'position' => 1]);
        return BrowsingActivity::create([
            'user_id' => $log->user_id, 'daily_log_id' => $log->id, 'log_block_id' => $block->id,
            'client_id' => 'test', 'domain' => $domain, 'started_at' => $start,
            'last_seen_at' => $start->copy()->addSeconds($seconds),
            'ended_at' => $start->copy()->addSeconds($seconds), 'duration_seconds' => $seconds,
        ]);
    }

    public function test_preview_apply_backup_scope_and_repeatability(): void
    {
        $this->app->useStoragePath(sys_get_temp_dir().'/totallog-repair-'.Str::uuid());
        $user = User::factory()->create();
        $log = $user->dailyLogs()->create(['log_date' => '2026-10-13']);
        $first = $this->browser($log, '04:21:00');
        $this->browser($log, '04:22:00');
        $this->browser($log, '04:22:00'); // Duplicate retransmission, not a second minute.
        $this->browser($log, '04:23:00', 7 * 3600, 'drive.google.com');
        $this->browser($log, '05:01:00');
        $other = User::factory()->create()->dailyLogs()->create(['log_date' => '2026-10-13']);
        $this->browser($other, '04:23:00', 7 * 3600);
        $earlier = $user->dailyLogs()->create(['log_date' => '2026-10-12']);
        $this->browser($earlier, '04:23:00', 7 * 3600);
        $options = ['--user' => (string) $user->id, '--from' => '2026-10-13', '--to' => '2026-10-13', '--discard-invalid-browsing' => true];
        $this->artisan('sensors:repair-hourly', $options)->assertSuccessful();
        $this->assertSame(5, $log->blocks()->count());
        $this->assertDatabaseCount('browsing_activities', 7);
        $this->assertFileDoesNotExist(storage_path('app/sensor-repairs'));

        $this->artisan('sensors:repair-hourly', $options + ['--apply' => true])->assertSuccessful();
        $this->assertSame(2, $log->blocks()->count());
        $this->assertSame(3, $log->browsingActivities()->count());
        $this->assertSame('Browsing · 1 domain · 2 min', $first->fresh()->logBlock->content);
        $this->assertSame(180, (int) $log->browsingActivities()->sum('duration_seconds'));
        $this->assertSame(7 * 3600, $other->browsingActivities()->first()->duration_seconds);
        $this->assertSame(7 * 3600, $earlier->browsingActivities()->first()->duration_seconds);
        $backupFiles = glob(storage_path('app/sensor-repairs/*.jsonl'));
        $this->assertCount(1, $backupFiles);
        $backup = json_decode(trim(file_get_contents($backupFiles[0])), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(5, $backup['blocks']);
        $this->assertCount(5, $backup['activities']['sensor_browser']);
        $this->assertSame(7 * 3600, $backup['activities']['sensor_browser'][3]['duration_seconds']);
        $ids = $log->blocks()->pluck('id')->all();
        $this->artisan('sensors:repair-hourly', $options + ['--apply' => true])->assertSuccessful();
        $this->assertSame($ids, $log->blocks()->pluck('id')->all());
        $this->assertSame(180, (int) $log->browsingActivities()->sum('duration_seconds'));
    }

    public function test_desktop_and_mobile_visits_are_grouped_without_combining_sensor_types(): void
    {
        $user = User::factory()->create();
        $log = $user->dailyLogs()->create(['log_date' => '2026-10-13']);
        foreach (['10:01:00', '10:02:00'] as $index => $time) {
            $started = Carbon::parse('2026-10-13 '.$time);
            foreach (['sensor_desktop', 'sensor_mobile_browser'] as $type) {
                $block = $log->blocks()->create(['type' => $type, 'occurred_at' => $started, 'position' => $index]);
                $base = ['user_id' => $user->id, 'daily_log_id' => $log->id, 'log_block_id' => $block->id];
                if ($type === 'sensor_desktop') {
                    DesktopActivity::create($base + ['application' => 'Code', 'process_name' => 'code.exe', 'client_id' => 'desktop',
                        'started_at' => $started, 'last_seen_at' => $started->copy()->addMinute(), 'ended_at' => $started->copy()->addMinute(), 'duration_seconds' => 60]);
                } else {
                    MobileBrowsingVisit::create($base + ['domain' => 'example.com', 'visit_key' => hash('sha256', $time), 'visited_at' => $started]);
                }
            }
        }
        $service = app(\App\Services\HourlySensorRepair::class);
        \Illuminate\Support\Facades\DB::transaction(fn () => $service->apply($log, $service->plan($log, false, [])));
        $this->assertSame(2, $log->blocks()->count());
        $this->assertSame('Desktop activity · 1 app · 2 min', $log->blocks()->where('type', 'sensor_desktop')->first()->content);
        $this->assertSame('Mobile browsing · 1 domain · 2 visits', $log->blocks()->where('type', 'sensor_mobile_browser')->first()->content);
        $this->assertDatabaseCount('desktop_activities', 2);
        $this->assertDatabaseCount('mobile_browsing_visits', 2);
    }

    public function test_invalid_removal_is_opt_in_and_domain_removal_is_explicit_and_bounded(): void
    {
        $this->app->useStoragePath(sys_get_temp_dir().'/totallog-repair-'.Str::uuid());
        $user = User::factory()->create();
        $log = $user->dailyLogs()->create(['log_date' => '2026-10-13']);
        $this->browser($log, '04:21:00', 7 * 3600, 'drive.google.com');
        $this->browser($log, '04:22:00', 60, 'kdp.amazon.com');
        $this->browser($log, '05:01:00');
        $options = ['--user' => $user->email, '--apply' => true];
        $this->artisan('sensors:repair-hourly', $options)->assertSuccessful();
        $this->assertSame(3, $log->browsingActivities()->count());
        $this->artisan('sensors:repair-hourly', $options + ['--remove-browsing-domain' => ['drive.google.com']])->assertFailed();
        $this->artisan('sensors:repair-hourly', $options + [
            '--from' => '2026-10-13', '--to' => '2026-10-13', '--remove-browsing-domain' => ['drive.google.com'],
        ])->assertSuccessful();
        $this->assertSame(['kdp.amazon.com', 'example.com'], $log->browsingActivities()->orderBy('id')->pluck('domain')->all());
    }

    public function test_idle_gap_is_not_counted_and_invalid_duration_does_not_survive_a_heartbeat(): void
    {
        $user = User::factory()->create();
        $sensor = Sensor::create(['user_id' => $user->id, 'type' => Sensor::BROWSER, 'enabled' => true]);
        $recorder = app(BrowsingActivityRecorder::class);
        $this->travelTo(Carbon::parse('2026-10-13 04:21:00'));
        $first = $recorder->record($sensor, 'https://drive.google.com', 'browser');
        $first->update(['duration_seconds' => 7 * 3600]);
        $this->travel(1)->minutes();
        $recorder->record($sensor, 'https://drive.google.com', 'browser');
        $this->assertSame(60, $first->fresh()->duration_seconds);
        $this->travel(7)->hours();
        $recorder->record($sensor, 'https://drive.google.com', 'browser');
        $this->assertSame(60, $first->fresh()->duration_seconds);
        $this->assertSame('04:22:00', $first->fresh()->ended_at->format('H:i:s'));
        $this->assertSame(60, (int) BrowsingActivity::sum('duration_seconds'));
    }

    public function test_desktop_upload_retries_do_not_count_the_same_minutes_twice(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 10:10:00'));
        $user = User::factory()->create();
        $key = str_repeat('b', 64);
        Sensor::create(['user_id' => $user->id, 'type' => Sensor::DESKTOP, 'enabled' => true, 'pairing_key_hash' => hash('sha256', $key)]);
        $payload = ['batch_id' => (string) Str::uuid(), 'client_id' => 'desktop', 'activities' => [[
            'application' => 'Code', 'process_name' => 'code.exe',
            'started_at' => now()->subMinutes(5)->toIso8601String(),
            'ended_at' => now()->toIso8601String(), 'duration_seconds' => 300,
        ]]];
        $this->withHeader('X-TotalLog-Key', $key)->postJson('/api/sensors/desktop/activity', $payload)->assertCreated();
        // Simulate a lost response followed by a retry of the same persisted batch.
        $this->postJson('/api/sensors/desktop/activity', $payload)->assertCreated();
        $this->assertDatabaseCount('desktop_activities', 1);
        $this->assertSame(300, (int) DesktopActivity::sum('duration_seconds'));
        $payload['activities'][0]['duration_seconds'] = 200;
        $this->postJson('/api/sensors/desktop/activity', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('desktop_activities', 1);
    }
}
