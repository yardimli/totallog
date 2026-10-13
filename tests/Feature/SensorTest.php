<?php

namespace Tests\Feature;

use App\Models\BrowsingActivity;
use App\Models\DailyLog;
use App\Models\DesktopActivity;
use App\Models\GoogleCalendarEvent;
use App\Models\KindleReadingProgress;
use App\Models\MobileBrowsingVisit;
use App\Models\Sensor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SensorTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_validate_link_toggle_and_unlink_github(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'api.github.com/user' => Http::response(['login' => 'octocat'], 200),
        ]);

        $this->actingAs($user)->post(route('sensors.github.link'), [
            'github_username' => 'octocat',
            'github_token' => 'github_pat_secret-value',
        ])->assertRedirect()->assertSessionHas('status', 'GitHub linked and enabled.');

        $sensor = Sensor::where('user_id', $user->id)->where('type', Sensor::GITHUB)->firstOrFail();
        $this->assertTrue($sensor->enabled);
        $this->assertSame('octocat', $sensor->username);
        $this->assertSame('github_pat_secret-value', $sensor->token);
        $this->assertNotSame('github_pat_secret-value', DB::table('sensors')->where('id', $sensor->id)->value('token'));

        $this->get(route('sensors.index'))->assertOk()
            ->assertSee('GitHub commits')
            ->assertSee('@octocat')
            ->assertSee('data-sensor-enable', false)
            ->assertSee('data-confirm-sensor-unlink', false);

        $this->patchJson(route('sensors.github.toggle'), ['enabled' => false])
            ->assertOk()->assertJsonPath('message', 'GitHub sensor disabled.');
        $this->assertFalse($sensor->fresh()->enabled);

        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        $block = $log->blocks()->create(['type' => 'sensor_github', 'content' => 'totallog', 'metadata' => ['sensor' => 'github', 'github_sha' => 'abc']]);
        $this->delete(route('sensors.github.unlink'))->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseMissing('sensors', ['id' => $sensor->id]);
        $this->assertDatabaseHas('log_blocks', ['id' => $block->id, 'content' => 'totallog']);
    }

    public function test_github_link_rejects_a_token_for_another_username(): void
    {
        $user = User::factory()->create();
        Http::fake(['api.github.com/user' => Http::response(['login' => 'someone-else'], 200)]);

        $this->actingAs($user)->post(route('sensors.github.link'), [
            'github_username' => 'octocat',
            'github_token' => 'wrong-account-token',
        ])->assertSessionHasErrors('github_token');

        $this->assertDatabaseCount('sensors', 0);
    }

    public function test_past_github_days_sync_once_and_empty_days_are_finalized(): void
    {
        Carbon::setTestNow('2026-08-17 14:00:00');
        $user = User::factory()->create();
        Sensor::create(['user_id' => $user->id, 'type' => Sensor::GITHUB, 'username' => 'octocat', 'token' => 'secret', 'enabled' => true]);
        Http::fake(function (Request $request) {
            $query = $request->data()['q'] ?? '';
            if (str_contains($query, '2026-08-15')) {
                return Http::response([
                    'total_count' => 4,
                    'items' => [[
                        'sha' => 'commit-one',
                        'html_url' => 'https://github.com/octocat/totallog/commit/commit-one',
                        'repository' => ['name' => 'totallog', 'full_name' => 'octocat/totallog'],
                        'commit' => ['message' => 'Add navigation controls', 'author' => ['date' => '2026-08-15T09:35:00Z']],
                    ], [
                        'sha' => 'commit-two',
                        'html_url' => 'https://github.com/octocat/totallog/commit/commit-two',
                        'repository' => ['name' => 'totallog', 'full_name' => 'octocat/totallog'],
                        'commit' => ['message' => "Group sensor entries\n\nMore details", 'author' => ['date' => '2026-08-15T09:50:00Z']],
                    ], [
                        'sha' => 'commit-three',
                        'html_url' => 'https://github.com/octocat/totallog/commit/commit-three',
                        'repository' => ['name' => 'totallog', 'full_name' => 'octocat/totallog'],
                        'commit' => ['message' => 'Start the next hour', 'author' => ['date' => '2026-08-15T10:05:00Z']],
                    ], [
                        'sha' => 'commit-other',
                        'html_url' => 'https://github.com/octocat/side-project/commit/commit-other',
                        'repository' => ['name' => 'side-project', 'full_name' => 'octocat/side-project'],
                        'commit' => ['message' => 'Update the side project', 'author' => ['date' => '2026-08-15T09:45:00Z']],
                    ]],
                ]);
            }

            return Http::response(['total_count' => 0, 'items' => []]);
        });

        $this->actingAs($user)->get('/logs/2026-08-15')->assertOk()
            ->assertSee('totallog')
            ->assertSee('2 commits')
            ->assertSee('data-timeline-github', false)
            ->assertSee('Add navigation controls')
            ->assertSee('Group sensor entries')
            ->assertSee('data-github-event-list', false);
        $pastLog = DailyLog::where('user_id', $user->id)->whereDate('log_date', '2026-08-15')->firstOrFail();
        $this->assertSame(3, $pastLog->blocks()->where('type', 'sensor_github')->count());
        $grouped = $pastLog->blocks()->where('type', 'sensor_github')->where('content', 'totallog')->get()
            ->first(fn ($block) => $block->occurred_at->format('H') === '17');
        $this->assertNotNull($grouped);
        $this->assertCount(2, $grouped->metadata['commits']);
        $this->assertSame(['commit-one', 'commit-two'], collect($grouped->metadata['commits'])->pluck('sha')->all());
        $this->get('/logs/2026-08-15')->assertOk();
        $this->get('/logs/2026-08-16')->assertOk()->assertSee('No Git commits today');
        $this->get('/logs/2026-08-16')->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secret')
            && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
        $this->assertDatabaseHas('log_blocks', ['type' => 'sensor_github', 'content' => 'totallog']);
        $this->assertDatabaseHas('log_blocks', ['type' => 'sensor_github', 'content' => 'No Git commits today']);
        $this->assertDatabaseCount('sensor_day_syncs', 2);
        Carbon::setTestNow();
    }

    public function test_existing_github_commit_blocks_are_consolidated_without_an_api_request(): void
    {
        Carbon::setTestNow('2026-08-17 14:00:00');
        $user = User::factory()->create();
        Sensor::create(['user_id' => $user->id, 'type' => Sensor::GITHUB, 'username' => 'octocat', 'token' => 'secret', 'enabled' => true]);
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        foreach ([['legacy-one', '09:10:00'], ['legacy-two', '09:45:00']] as [$sha, $time]) {
            $log->blocks()->forceCreate([
                'type' => 'sensor_github',
                'content' => 'totallog',
                'occurred_at' => '2026-08-15 '.$time,
                'metadata' => ['sensor' => Sensor::GITHUB, 'github_sha' => $sha, 'repository' => 'octocat/totallog', 'url' => 'https://github.com/octocat/totallog/commit/'.$sha, 'empty' => false],
            ]);
        }
        Http::fake();

        $this->actingAs($user)->get('/logs/2026-08-15')->assertOk()
            ->assertSee('2 commits')
            ->assertSee('legacy-one')
            ->assertSee('legacy-two')
            ->assertSee('data-timeline-github', false);

        Http::assertNothingSent();
        $this->assertSame(1, $log->blocks()->where('type', 'sensor_github')->count());
        $events = $log->blocks()->where('type', 'sensor_github')->firstOrFail()->metadata['commits'];
        $this->assertSame(['legacy-one', 'legacy-two'], collect($events)->pluck('sha')->all());
        Carbon::setTestNow();
    }

    public function test_current_day_rechecks_without_showing_an_empty_marker_then_adds_a_commit(): void
    {
        Carbon::setTestNow('2026-08-17 14:00:00');
        $user = User::factory()->create();
        Sensor::create(['user_id' => $user->id, 'type' => Sensor::GITHUB, 'username' => 'octocat', 'token' => 'secret', 'enabled' => true]);
        $todayLog = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-17']);
        $todayLog->blocks()->create(['type' => 'sensor_github', 'content' => 'No Git commits today', 'metadata' => ['sensor' => Sensor::GITHUB, 'empty' => true]]);
        Http::fake(['api.github.com/search/commits*' => Http::sequence()
            ->push(['total_count' => 0, 'items' => []])
            ->push(['total_count' => 1, 'items' => [[
                'sha' => 'current-commit',
                'html_url' => 'https://github.com/octocat/today-project/commit/current-commit',
                'repository' => ['name' => 'today-project', 'full_name' => 'octocat/today-project'],
                'commit' => ['author' => ['date' => '2026-08-17T13:45:00Z']],
            ]]])
            ->push(['total_count' => 1, 'items' => [[
                'sha' => 'current-commit',
                'html_url' => 'https://github.com/octocat/today-project/commit/current-commit',
                'repository' => ['name' => 'today-project', 'full_name' => 'octocat/today-project'],
                'commit' => ['author' => ['date' => '2026-08-17T13:45:00Z']],
            ]]])]);

        $this->actingAs($user)->get('/logs/2026-08-17')->assertOk()->assertDontSee('No Git commits today');
        $this->assertDatabaseMissing('log_blocks', ['type' => 'sensor_github', 'content' => 'No Git commits today']);
        $this->get('/logs/2026-08-17')->assertOk()->assertDontSee('today-project');
        Carbon::setTestNow('2026-08-17 14:05:01');
        $secondLoad = $this->get('/logs/2026-08-17')->assertOk();
        $this->assertNull(Sensor::first()->fresh()->last_error, Sensor::first()->fresh()->last_error ?? 'GitHub sync should not fail.');
        $secondLoad->assertSee('today-project')->assertDontSee('No Git commits today');
        $this->get('/logs/2026-08-17')->assertOk()->assertSee('today-project');

        Http::assertSentCount(2);
        $this->assertDatabaseCount('log_blocks', 1);
        $this->assertDatabaseHas('log_blocks', ['type' => 'sensor_github', 'content' => 'today-project']);
        Carbon::setTestNow();
    }

    public function test_disabled_sensor_and_future_days_do_not_call_github(): void
    {
        Carbon::setTestNow('2026-08-17 14:00:00');
        $user = User::factory()->create();
        Sensor::create(['user_id' => $user->id, 'type' => Sensor::GITHUB, 'username' => 'octocat', 'token' => 'secret', 'enabled' => false]);
        Http::fake();

        $this->actingAs($user)->get('/logs/2026-08-17')->assertOk();
        $this->get('/logs/2026-08-18')->assertOk();
        Http::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_chrome_extension_pairs_with_a_random_key_and_can_be_unlinked(): void
    {
        $user = User::factory()->create();
        $key = str_repeat('pairing-key-', 6);

        $this->actingAs($user)->get(route('sensors.browser.pair', $key))
            ->assertRedirect(route('sensors.index'))
            ->assertSessionHas('status', 'Chrome browsing sensor paired and enabled.');

        $sensor = Sensor::where('user_id', $user->id)->where('type', Sensor::BROWSER)->firstOrFail();
        $this->assertTrue($sensor->enabled);
        $this->assertSame(hash('sha256', $key), $sensor->pairing_key_hash);
        $this->assertDatabaseMissing('sensors', ['pairing_key_hash' => $key]);
        $this->get(route('sensors.index'))->assertOk()
            ->assertSee('Chrome browsing')
            ->assertSee('Legacy direct extension pairing')
            ->assertSee('public/totallog-chrome-extension')
            ->assertSee('data-confirm-browser-unlink', false);

        $this->delete(route('sensors.browser.unlink'))->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseMissing('sensors', ['id' => $sensor->id]);
    }

    public function test_desktop_app_pairs_separately_from_the_browser_extension(): void
    {
        $user = User::factory()->create();
        $key = str_repeat('d', 64);

        $this->actingAs($user)->get(route('sensors.desktop.pair', $key))
            ->assertRedirect(route('sensors.index'))
            ->assertSessionHas('status', 'Desktop activity sensor paired and enabled.');

        $sensor = Sensor::where('user_id', $user->id)->where('type', Sensor::DESKTOP)->firstOrFail();
        $this->assertTrue($sensor->enabled);
        $this->assertSame(hash('sha256', $key), $sensor->pairing_key_hash);
        $this->withHeader('X-TotalLog-Key', $key)
            ->getJson(route('api.sensors.desktop.check'))
            ->assertOk()
            ->assertJsonPath('connected', true);
        $this->assertNotNull($sensor->fresh()->last_checked_at);
        $this->get(route('sensors.index'))->assertOk()
            ->assertSee('Windows desktop and Kindle')
            ->assertSee('Desktop app paired');

        $this->delete(route('sensors.desktop.unlink'))->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseMissing('sensors', ['id' => $sensor->id]);
    }

    public function test_pairing_key_can_move_from_browser_to_desktop_without_a_unique_constraint_error(): void
    {
        $user = User::factory()->create();
        $key = str_repeat('shared-client-key-', 4);
        $hash = hash('sha256', $key);
        $browser = Sensor::create([
            'user_id' => $user->id,
            'type' => Sensor::BROWSER,
            'username' => 'Chrome extension',
            'pairing_key_hash' => $hash,
            'enabled' => true,
        ]);

        $this->actingAs($user)->get(route('sensors.desktop.pair', $key))
            ->assertRedirect(route('sensors.index'))
            ->assertSessionHas('status', 'Desktop activity sensor paired and enabled.');

        $this->assertNull($browser->fresh()->pairing_key_hash);
        $this->assertFalse($browser->fresh()->enabled);
        $this->assertDatabaseHas('sensors', [
            'user_id' => $user->id,
            'type' => Sensor::DESKTOP,
            'pairing_key_hash' => $hash,
            'enabled' => true,
        ]);
    }

    public function test_desktop_sensor_groups_foreground_apps_by_hour_without_storing_window_titles_or_paths(): void
    {
        Carbon::setTestNow('2026-09-03 10:00:00');
        $user = User::factory()->create();
        $key = str_repeat('a', 64);
        Sensor::create([
            'user_id' => $user->id,
            'type' => Sensor::DESKTOP,
            'username' => 'Windows desktop app',
            'pairing_key_hash' => hash('sha256', $key),
            'enabled' => true,
        ]);

        $send = function (string $application, string $processName) use ($key) {
            return $this->withHeader('X-TotalLog-Key', $key)->postJson(route('api.sensors.desktop.activity'), [
                'application' => $application,
                'process_name' => $processName,
                'observed_at' => now()->toIso8601String(),
                'client_id' => 'desktop-test-client',
            ]);
        };

        $send('Code', 'Code.exe')->assertCreated()->assertJsonPath('application', 'Code');
        Carbon::setTestNow('2026-09-03 10:01:00');
        $send('Code', 'Code.exe')->assertCreated();
        Carbon::setTestNow('2026-09-03 10:02:00');
        $send('Kindle', 'Kindle.exe')->assertCreated();
        Carbon::setTestNow('2026-09-03 10:03:00');
        $send('Kindle', 'Kindle.exe')->assertCreated();

        $this->assertDatabaseCount('desktop_activities', 2);
        $this->assertDatabaseCount('log_blocks', 1);
        $this->assertSame(180, DesktopActivity::sum('duration_seconds'));
        $this->assertDatabaseHas('desktop_activities', ['application' => 'Code', 'process_name' => 'code.exe']);
        $this->assertDatabaseMissing('desktop_activities', ['process_name' => 'C:\\Private\\Code.exe']);

        $this->actingAs($user)->get('/logs/2026-09-03')->assertOk()
            ->assertSee('Desktop activity · 2 apps · 3 min')
            ->assertSee('data-browsing-mode="applications"', false)
            ->assertSee('Code')
            ->assertSee('Kindle');
        Carbon::setTestNow();
    }

    public function test_desktop_sensor_api_rejects_unknown_keys_and_executable_paths(): void
    {
        $payload = ['application' => 'Code', 'process_name' => 'Code.exe', 'client_id' => 'desktop-client'];
        $this->withHeader('X-TotalLog-Key', str_repeat('x', 64))
            ->postJson(route('api.sensors.desktop.activity'), $payload)->assertUnauthorized();

        $user = User::factory()->create();
        $key = str_repeat('z', 64);
        Sensor::create(['user_id' => $user->id, 'type' => Sensor::DESKTOP, 'pairing_key_hash' => hash('sha256', $key), 'enabled' => true]);
        $this->withHeader('X-TotalLog-Key', $key)
            ->postJson(route('api.sensors.desktop.activity'), [...$payload, 'process_name' => 'C:\\Private\\Code.exe'])
            ->assertUnprocessable()->assertJsonValidationErrors('process_name');
    }

    public function test_desktop_sensor_accepts_one_five_minute_activity_batch(): void
    {
        Carbon::setTestNow('2026-09-03 10:05:00');
        $user = User::factory()->create();
        $key = str_repeat('f', 64);
        Sensor::create([
            'user_id' => $user->id,
            'type' => Sensor::DESKTOP,
            'username' => 'Windows desktop app',
            'pairing_key_hash' => hash('sha256', $key),
            'enabled' => true,
        ]);

        $this->withHeader('X-TotalLog-Key', $key)->postJson(route('api.sensors.desktop.activity'), [
            'client_id' => 'desktop-batch-client',
            'activities' => [
                ['application' => 'Code', 'process_name' => 'Code.exe', 'started_at' => '2026-09-03T10:00:00+08:00', 'ended_at' => '2026-09-03T10:03:00+08:00', 'duration_seconds' => 120],
                ['application' => 'Firefox', 'process_name' => 'firefox.exe', 'started_at' => '2026-09-03T10:01:00+08:00', 'ended_at' => '2026-09-03T10:05:00+08:00', 'duration_seconds' => 150],
            ],
        ])->assertCreated()->assertJsonPath('recorded', 2)->assertJsonPath('duration_seconds', 270);

        $this->assertDatabaseCount('desktop_activities', 2);
        $this->assertSame(270, DesktopActivity::sum('duration_seconds'));
        $this->actingAs($user)->get('/logs/2026-09-03')->assertOk()
            ->assertSee('Desktop activity · 2 apps · 4 min')
            ->assertSee('Code')->assertSee('Firefox');
        Carbon::setTestNow();
    }

    public function test_desktop_pairing_key_accepts_browser_extension_data_forwarded_by_the_local_bridge(): void
    {
        Carbon::setTestNow('2026-09-03 10:05:00');
        $user = User::factory()->create();
        $key = str_repeat('r', 64);
        Sensor::create([
            'user_id' => $user->id,
            'type' => Sensor::DESKTOP,
            'username' => 'Windows desktop app',
            'pairing_key_hash' => hash('sha256', $key),
            'enabled' => true,
        ]);

        $this->withHeader('X-TotalLog-Key', $key)->postJson(route('api.sensors.browser.activity'), [
            'url' => 'https://docs.github.com/private/path?secret=yes',
            'observed_at' => now()->toIso8601String(),
            'client_id' => 'desktop-bridge-extension',
        ])->assertCreated()->assertJsonPath('domain', 'docs.github.com');

        $this->withHeader('X-TotalLog-Key', $key)->postJson(route('api.sensors.browser.mobile-history'), [
            'visits' => [],
        ])->assertCreated();

        $this->assertDatabaseHas('browsing_activities', [
            'user_id' => $user->id,
            'domain' => 'docs.github.com',
            'client_id' => 'desktop-bridge-extension',
        ]);
        Carbon::setTestNow();
    }

    public function test_browser_sensor_groups_domains_into_hourly_log_blocks_and_closes_after_inactivity(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00');
        $user = User::factory()->create();
        $key = str_repeat('b', 64);
        Sensor::create([
            'user_id' => $user->id,
            'type' => Sensor::BROWSER,
            'username' => 'Chrome extension',
            'pairing_key_hash' => hash('sha256', $key),
            'enabled' => true,
        ]);

        $send = function (string $url) use ($key) {
            return $this->withHeader('X-TotalLog-Key', $key)->postJson(route('api.sensors.browser.activity'), [
                'url' => $url,
                'observed_at' => now()->toIso8601String(),
                'client_id' => 'chrome-test-client',
            ]);
        };

        $send('https://docs.github.com/en/rest?private=query')->assertCreated()->assertJsonPath('domain', 'docs.github.com');
        Carbon::setTestNow('2026-08-17 10:01:00');
        $send('https://github.com/yardimli/totallog')->assertCreated()->assertJsonPath('domain', 'github.com');
        Carbon::setTestNow('2026-08-17 10:02:00');
        $send('https://news.ycombinator.com/item?id=1')->assertCreated()->assertJsonPath('domain', 'news.ycombinator.com');

        $this->assertDatabaseCount('daily_logs', 1);
        $this->assertDatabaseCount('browsing_activities', 3);
        $this->assertDatabaseCount('log_blocks', 1);
        $github = BrowsingActivity::where('domain', 'github.com')->firstOrFail();
        $this->assertNotNull($github->ended_at);
        $this->assertSame(60, $github->duration_seconds);

        Carbon::setTestNow('2026-08-17 10:06:00');
        $page = $this->actingAs($user)->get('/logs/2026-08-17')->assertOk()
            ->assertSee('data-timeline-browsing', false)
            ->assertSee('docs.github.com')
            ->assertSee('github.com')
            ->assertSee('news.ycombinator.com')
            ->assertSee('data-browsing-domain-list', false)
            ->assertDontSee('/en/rest?private=query');
        $this->assertNotNull(BrowsingActivity::where('domain', 'news.ycombinator.com')->firstOrFail()->ended_at);

        Carbon::setTestNow('2026-08-17 11:00:00');
        $send('https://mail.google.com/mail/u/0/')->assertCreated()->assertJsonPath('domain', 'mail.google.com');
        $this->assertDatabaseCount('log_blocks', 2);
        $this->assertDatabaseHas('log_blocks', ['type' => 'sensor_browser']);
        Carbon::setTestNow();
    }

    public function test_browser_sensor_does_not_reuse_a_block_from_an_older_hour_when_an_activity_is_mislinked(): void
    {
        Carbon::setTestNow('2026-09-02 23:37:20');
        $user = User::factory()->create();
        $key = str_repeat('p', 64);
        $sensor = Sensor::create([
            'user_id' => $user->id,
            'type' => Sensor::BROWSER,
            'username' => 'Chrome extension',
            'pairing_key_hash' => hash('sha256', $key),
            'enabled' => true,
        ]);
        $log = $user->dailyLogs()->create(['log_date' => '2026-09-02']);
        $oldBlock = $log->blocks()->create([
            'type' => 'sensor_browser',
            'content' => 'Browsing · 2 domains · 1 min',
            'occurred_at' => '2026-09-02 19:11:04',
            'metadata' => ['sensor' => Sensor::BROWSER, 'hour_start' => '2026-09-02T19:00:00+08:00'],
            'position' => 1,
        ]);
        $oldActivity = BrowsingActivity::create([
            'user_id' => $user->id,
            'daily_log_id' => $log->id,
            'log_block_id' => $oldBlock->id,
            'domain' => 'older.example.com',
            'client_id' => 'old-client',
            'started_at' => '2026-09-02 19:11:04',
            'last_seen_at' => '2026-09-02 19:12:04',
            'ended_at' => '2026-09-02 19:12:04',
            'duration_seconds' => 60,
        ]);
        BrowsingActivity::create([
            'user_id' => $user->id,
            'daily_log_id' => $log->id,
            'log_block_id' => $oldBlock->id,
            'domain' => 'mislinked.example.com',
            'client_id' => 'old-client',
            'started_at' => '2026-09-02 23:32:52',
            'last_seen_at' => '2026-09-02 23:32:52',
            'ended_at' => '2026-09-02 23:32:52',
            'duration_seconds' => 0,
        ]);
        BrowsingActivity::create([
            'user_id' => $user->id,
            'daily_log_id' => $log->id,
            'log_block_id' => $oldBlock->id,
            'domain' => 'also-mislinked.example.com',
            'client_id' => 'old-client',
            'started_at' => '2026-09-02 21:15:00',
            'last_seen_at' => '2026-09-02 21:16:00',
            'ended_at' => '2026-09-02 21:16:00',
            'duration_seconds' => 60,
        ]);

        $response = $this->withHeader('X-TotalLog-Key', $key)->postJson(route('api.sensors.browser.activity'), [
            'url' => 'https://github.com',
            'observed_at' => now()->toIso8601String(),
            'client_id' => 'current-client',
        ])->assertCreated();

        $activity = BrowsingActivity::where('domain', 'github.com')->firstOrFail();
        $this->assertNotSame($oldBlock->id, $activity->log_block_id);
        $this->assertSame('23', $activity->logBlock->occurred_at->format('H'));
        $this->assertSame('sensor_browser', $activity->logBlock->type);
        $this->assertSame($activity->log_block_id, BrowsingActivity::where('domain', 'mislinked.example.com')->value('log_block_id'));
        $this->assertSame($oldBlock->id, $oldActivity->fresh()->log_block_id);
        $repairedActivity = BrowsingActivity::where('domain', 'also-mislinked.example.com')->firstOrFail();
        $this->assertNotSame($oldBlock->id, $repairedActivity->log_block_id);
        $this->assertSame('21', $repairedActivity->logBlock->occurred_at->format('H'));
        $this->assertSame('Browsing · 1 domain · 1 min', $oldBlock->fresh()->content);
        $this->assertSame('2026-09-02', $response->json('log_date'));
        Carbon::setTestNow();
    }

    public function test_browser_heartbeat_repairs_duplicate_hour_blocks_without_losing_activity(): void
    {
        Carbon::setTestNow('2026-10-13 04:25:00');
        $user = User::factory()->create();
        $sensor = Sensor::create(['user_id' => $user->id, 'type' => Sensor::BROWSER, 'enabled' => true]);
        $log = $user->dailyLogs()->create(['log_date' => '2026-10-13']);
        foreach (['04:21:00', '04:21:20', '04:24:00'] as $index => $time) {
            $block = $log->blocks()->create([
                'type' => 'sensor_browser', 'occurred_at' => '2026-10-13 '.$time,
                'position' => $index, 'metadata' => ['hour_start' => '2026-10-13T04:00:00+08:00'],
            ]);
            BrowsingActivity::create([
                'user_id' => $user->id, 'daily_log_id' => $log->id, 'log_block_id' => $block->id,
                'domain' => 'example.com', 'client_id' => 'browser',
                'started_at' => '2026-10-13 '.$time, 'last_seen_at' => '2026-10-13 '.$time,
                'ended_at' => $index < 2 ? '2026-10-13 '.$time : null, 'duration_seconds' => 0,
            ]);
        }
        app(\App\Services\BrowsingActivityRecorder::class)->record($sensor, 'https://example.com', 'browser', now()->toIso8601String());
        $this->assertDatabaseCount('log_blocks', 1);
        $this->assertDatabaseCount('browsing_activities', 3);
        $this->assertSame(1, BrowsingActivity::distinct()->count('log_block_id'));
        $this->assertSame('Browsing · 1 domain · 1 min', $log->blocks()->first()->content);
        Carbon::setTestNow();
    }

    public function test_delayed_browser_heartbeat_does_not_close_or_duplicate_the_active_session(): void
    {
        Carbon::setTestNow('2026-10-13 04:25:00');
        $user = User::factory()->create();
        $sensor = Sensor::create(['user_id' => $user->id, 'type' => Sensor::BROWSER, 'enabled' => true]);
        $recorder = app(\App\Services\BrowsingActivityRecorder::class);
        $first = $recorder->record($sensor, 'https://example.com', 'browser', now()->toIso8601String());
        $recorder->record($sensor, 'https://delayed.example.com', 'browser', now()->subMinute()->toIso8601String());
        $this->assertDatabaseCount('browsing_activities', 1);
        $this->assertNull($first->fresh()->ended_at);
        Carbon::setTestNow();
    }

    public function test_viewing_a_day_regroups_existing_browser_rows_and_preserves_other_hours_and_accounts(): void
    {
        Carbon::setTestNow('2026-10-13 12:00:00');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $log = $user->dailyLogs()->create(['log_date' => '2026-10-12']);
        $otherLog = $other->dailyLogs()->create(['log_date' => '2026-10-12']);
        foreach ([$log, $otherLog] as $day) {
            foreach (['04:21:00', '04:22:00', '05:01:00'] as $index => $time) {
                $block = $day->blocks()->create([
                    'type' => 'sensor_browser', 'occurred_at' => '2026-10-12 '.$time, 'position' => $index,
                ]);
                BrowsingActivity::create([
                    'user_id' => $day->user_id, 'daily_log_id' => $day->id, 'log_block_id' => $block->id,
                    'domain' => 'example.com', 'client_id' => 'browser',
                    'started_at' => '2026-10-12 '.$time,
                    'last_seen_at' => Carbon::parse('2026-10-12 '.$time)->addMinute(),
                    'ended_at' => Carbon::parse('2026-10-12 '.$time)->addMinute(), 'duration_seconds' => 60,
                ]);
            }
        }
        $this->actingAs($user)->get('/logs/2026-10-12')->assertOk()->assertSee('Browsing · 1 domain · 2 min');
        $this->assertSame(2, $log->blocks()->count());
        $this->assertSame(3, $otherLog->blocks()->count());
        $this->assertDatabaseCount('browsing_activities', 6);
        $this->assertSame(180, (int) $log->browsingActivities()->sum('duration_seconds'));
        $ids = $log->blocks()->pluck('id')->all();
        $this->get('/logs/2026-10-12')->assertOk();
        $this->assertSame($ids, $log->blocks()->pluck('id')->all());
        Carbon::setTestNow();
    }

    public function test_browser_sensor_api_rejects_unknown_keys_and_extension_bundle_is_present(): void
    {
        $this->withHeader('X-TotalLog-Key', str_repeat('x', 64))->postJson(route('api.sensors.browser.activity'), [
            'url' => 'https://example.com',
            'client_id' => 'unknown-client',
        ])->assertUnauthorized();

        $manifestPath = public_path('totallog-chrome-extension/manifest.json');
        $this->assertFileExists($manifestPath);
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(3, $manifest['manifest_version']);
        $this->assertSame('service-worker.js', $manifest['background']['service_worker']);
        $this->assertSame(file_get_contents(public_path('favicon.svg')), file_get_contents(public_path('totallog-chrome-extension/favicon.svg')));
        foreach ([16, 32, 48, 128] as $size) {
            $iconPath = public_path("totallog-chrome-extension/icons/icon-{$size}.png");
            $this->assertFileExists($iconPath);
            $this->assertSame([$size, $size], array_slice(getimagesize($iconPath), 0, 2));
            $this->assertSame("icons/icon-{$size}.png", $manifest['icons'][(string) $size]);
        }
        $this->assertFileExists(public_path('totallog-chrome-extension/options.html'));
        $worker = file_get_contents(public_path('totallog-chrome-extension/service-worker.js'));
        $this->assertStringContainsString('http://127.0.0.1:32145/', $worker);
        $this->assertStringContainsString('v1/browser/activity', $worker);
        $this->assertStringContainsString('v1/browser/mobile-history', $worker);
        $this->assertStringContainsString('visit.isLocal === false', $worker);
        $this->assertStringContainsString("message?.type === 'mobile-history-sync-past'", $worker);
        $this->assertStringContainsString('syncMobileHistory(true)', $worker);
        $this->assertStringContainsString("chrome.action.setBadgeText({text: connected ? '' : '!'})", $worker);
        $this->assertStringNotContainsString('sensors/browser/pair/', $worker);
        $this->assertStringNotContainsString('X-TotalLog-Key', $worker);
        $this->assertStringContainsString('browsingUrl.hostname', $worker);
        $this->assertStringContainsString('lastLogDate: body.log_date || null', $worker);
        $this->assertStringNotContainsString('kindle', strtolower($worker));
        $this->assertArrayNotHasKey('optional_permissions', $manifest);
        $this->assertArrayNotHasKey('content_scripts', $manifest);
        $this->assertContains('history', $manifest['permissions']);
        $this->assertFileDoesNotExist(public_path('totallog-chrome-extension/kindle-tracker.js'));
        $this->assertSame('2.0.2', $manifest['version']);
        $this->assertSame(['http://127.0.0.1:32145/*'], $manifest['host_permissions']);
        $options = file_get_contents(public_path('totallog-chrome-extension/options.html'));
        $this->assertStringContainsString('Sync past data', $options);
        $this->assertStringContainsString('Chrome history debug', $options);
        $this->assertStringContainsString('data-options-tab="debug"', $options);
        $this->assertStringContainsString('history-scan-complete', $worker);
        $desktopRust = file_get_contents(base_path('desktop/src-tauri/src/lib.rs'));
        $desktopBridge = file_get_contents(base_path('desktop/src-tauri/src/browser_bridge.rs'));
        $desktopUi = file_get_contents(base_path('desktop/index.html'));
        $desktopPermissions = file_get_contents(base_path('desktop/src-tauri/permissions/main.toml'));
        $desktopKindle = file_get_contents(base_path('desktop/src-tauri/src/kindle.js'));
        $this->assertStringContainsString('send_kindle_book', $desktopRust);
        $this->assertStringContainsString('kindle_manual_sync_ready', $desktopRust);
        $this->assertStringContainsString('kindle_book_observed', $desktopRust);
        $this->assertStringContainsString('api/sensors/kindle/book', $desktopRust);
        $this->assertStringContainsString('browser-extension-status', $desktopBridge);
        $this->assertStringContainsString('Received and forwarded', $desktopBridge);
        $this->assertStringContainsString('id="extension-health"', $desktopUi);
        $this->assertStringContainsString('<dialog id="connection-panel"', $desktopUi);
        $this->assertStringContainsString('Sync now · log first book', $desktopUi);
        $this->assertStringContainsString('id="kindle-connect" type="button" class="compact"', $desktopUi);
        $this->assertStringNotContainsString('Active now', $desktopUi);
        $this->assertStringNotContainsString('id="application-name"', $desktopUi);
        $this->assertStringContainsString('Recent active applications', $desktopUi);
        $this->assertStringContainsString('class="help-tip tooltip-below"', $desktopUi);
        $this->assertStringNotContainsString('class="activity-help muted"', $desktopUi);
        $this->assertStringNotContainsString('<aside class="note">', $desktopUi);
        $this->assertStringContainsString('allow-configure-browser-bridge', $desktopPermissions);
        $this->assertStringContainsString('allow-check-server-connection', $desktopPermissions);
        $this->assertStringContainsString('document.querySelector(\'li[role="listitem"]\')', $desktopKindle);
        $this->assertStringContainsString('[id^="title-"]', $desktopKindle);
        $this->assertStringNotContainsString('percentage', $desktopKindle);
        $this->assertStringNotContainsString('setInterval', $desktopKindle);
    }

    public function test_mobile_browser_sensor_groups_synced_visits_by_hour_and_counts_domains(): void
    {
        Carbon::setTestNow('2026-09-01 13:00:00');
        $user = User::factory()->create();
        $key = str_repeat('m', 64);
        Sensor::create([
            'user_id' => $user->id,
            'type' => Sensor::BROWSER,
            'username' => 'Chrome extension',
            'pairing_key_hash' => hash('sha256', $key),
            'enabled' => true,
        ]);
        $visits = [
            ['url' => 'https://example.com/one?private=yes', 'visited_at' => '2026-09-01T10:05:00+08:00', 'visit_key' => hash('sha256', 'visit-1')],
            ['url' => 'https://example.com/two', 'visited_at' => '2026-09-01T10:12:00+08:00', 'visit_key' => hash('sha256', 'visit-2')],
            ['url' => 'https://example.com/three', 'visited_at' => '2026-09-01T10:20:00+08:00', 'visit_key' => hash('sha256', 'visit-3')],
            ['url' => 'https://example.com/four', 'visited_at' => '2026-09-01T10:30:00+08:00', 'visit_key' => hash('sha256', 'visit-4')],
            ['url' => 'https://example.com/five', 'visited_at' => '2026-09-01T10:45:00+08:00', 'visit_key' => hash('sha256', 'visit-5')],
            ['url' => 'https://example.com/later', 'visited_at' => '2026-09-01T11:15:00+08:00', 'visit_key' => hash('sha256', 'visit-6')],
            ['url' => 'https://news.example.org/story', 'visited_at' => '2026-09-01T10:50:00+08:00', 'visit_key' => hash('sha256', 'visit-7')],
            ['url' => 'http://127.0.0.1/private', 'visited_at' => '2026-09-01T10:55:00+08:00', 'visit_key' => hash('sha256', 'visit-invalid-ip')],
        ];

        $this->withHeader('X-TotalLog-Key', $key)
            ->postJson(route('api.sensors.browser.mobile-history'), ['visits' => $visits])
            ->assertCreated()
            ->assertJson(['imported' => 7, 'duplicates' => 0, 'rejected' => 1, 'blocks' => 2]);

        $this->withHeader('X-TotalLog-Key', $key)
            ->postJson(route('api.sensors.browser.mobile-history'), ['visits' => $visits])
            ->assertCreated()
            ->assertJson(['imported' => 0, 'duplicates' => 7, 'rejected' => 1, 'blocks' => 0]);

        $this->assertDatabaseCount('mobile_browsing_visits', 7);
        $this->assertDatabaseCount('log_blocks', 2);
        $this->assertDatabaseHas('log_blocks', ['type' => 'sensor_mobile_browser', 'content' => 'Mobile browsing · 2 domains · 6 visits']);
        $this->assertDatabaseHas('log_blocks', ['type' => 'sensor_mobile_browser', 'content' => 'Mobile browsing · 1 domain · 1 visit']);
        $this->assertSame(5, MobileBrowsingVisit::where('domain', 'example.com')->whereBetween('visited_at', ['2026-09-01 10:00:00', '2026-09-01 10:59:59'])->count());

        $this->actingAs($user)->get('/logs/2026-09-01')->assertOk()
            ->assertSee('Mobile browsing')
            ->assertSee('data-browsing-mode="visits"', false)
            ->assertSee('"visits":5', false)
            ->assertDontSee('/one?private=yes');
        Carbon::setTestNow();
    }

    public function test_mobile_browser_sensor_accepts_an_empty_scan_to_verify_pairing(): void
    {
        $user = User::factory()->create();
        $key = str_repeat('v', 64);
        $sensor = Sensor::create([
            'user_id' => $user->id,
            'type' => Sensor::BROWSER,
            'username' => 'Chrome extension',
            'pairing_key_hash' => hash('sha256', $key),
            'enabled' => true,
        ]);

        $this->withHeader('X-TotalLog-Key', $key)
            ->postJson(route('api.sensors.browser.mobile-history'), ['visits' => []])
            ->assertCreated()
            ->assertExactJson(['imported' => 0, 'duplicates' => 0, 'rejected' => 0, 'blocks' => 0]);

        $this->assertNotNull($sensor->fresh()->last_checked_at);
    }

    public function test_manual_kindle_sync_logs_the_first_library_book_without_a_reading_position(): void
    {
        Carbon::setTestNow('2026-08-18 20:00:00');
        $user = User::factory()->create();
        $key = str_repeat('k', 64);
        Sensor::create([
            'user_id' => $user->id,
            'type' => Sensor::DESKTOP,
            'username' => 'Windows desktop app',
            'pairing_key_hash' => hash('sha256', $key),
            'enabled' => true,
        ]);

        $send = fn () => $this->withHeader('X-TotalLog-Key', $key)->postJson(route('api.sensors.kindle.book'), [
            'title' => '<b>The Left Hand of Darkness</b>',
            'author' => 'Ursula K. Le Guin',
            'asin' => 'B000FC1HBY',
            'observed_at' => now()->toIso8601String(),
            'client_id' => 'desktop-kindle-test',
        ]);

        $send()->assertCreated()->assertJsonPath('title', 'The Left Hand of Darkness');
        Carbon::setTestNow('2026-08-18 20:05:00');
        $send()->assertCreated()->assertJsonPath('log_date', '2026-08-18');

        $this->assertDatabaseCount('kindle_reading_progress', 2);
        $this->assertDatabaseCount('daily_logs', 1);
        $this->assertDatabaseCount('log_blocks', 1);
        $this->assertDatabaseHas('log_blocks', ['type' => 'sensor_kindle', 'content' => 'The Left Hand of Darkness']);
        $this->assertNull(KindleReadingProgress::latest('observed_at')->firstOrFail()->percentage_read);
        $this->assertNull(KindleReadingProgress::latest('observed_at')->firstOrFail()->location);
        $this->actingAs($user)->get('/logs/2026-08-18')->assertOk()->assertSee('Kindle')->assertSee('The Left Hand of Darkness');
        Carbon::setTestNow();
    }

    public function test_kindle_endpoint_rejects_a_browser_extension_key(): void
    {
        $key = str_repeat('b', 64);
        Sensor::create([
            'user_id' => User::factory()->create()->id,
            'type' => Sensor::BROWSER,
            'username' => 'Chrome extension',
            'pairing_key_hash' => hash('sha256', $key),
            'enabled' => true,
        ]);

        $this->withHeader('X-TotalLog-Key', $key)->postJson(route('api.sensors.kindle.book'), [
            'title' => 'Browser should not own Kindle',
            'client_id' => 'browser-extension',
        ])->assertUnauthorized();
    }

    public function test_google_calendar_oauth_links_account_and_syncs_current_month_events(): void
    {
        Carbon::setTestNow('2026-08-19 09:00:00');
        config([
            'services.google_calendar.client_id' => 'google-client-id',
            'services.google_calendar.client_secret' => 'google-client-secret',
        ]);
        $user = User::factory()->create();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'oauth-access', 'refresh_token' => 'refresh-secret'])
                ->push(['access_token' => 'sync-access']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-user-1', 'email' => 'guest@example.com']),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['items' => [[
                'id' => 'timed-event',
                'status' => 'confirmed',
                'summary' => 'Yoga with the admiral',
                'description' => '<b>Breathe before engaging.</b>',
                'location' => 'Holodeck 2',
                'htmlLink' => 'https://calendar.google.com/calendar/event?eid=timed',
                'start' => ['dateTime' => '2026-08-19T10:30:00+08:00'],
                'end' => ['dateTime' => '2026-08-19T11:30:00+08:00'],
            ], [
                'id' => 'all-day-event',
                'status' => 'confirmed',
                'summary' => 'Dog medication inventory',
                'start' => ['date' => '2026-08-20'],
                'end' => ['date' => '2026-08-21'],
            ]]]),
        ]);

        $connect = $this->actingAs($user)->get(route('sensors.google-calendar.connect'))->assertRedirect();
        $authorizeUrl = $connect->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $authorizeUrl);
        parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertStringContainsString('calendar.readonly', $query['scope']);

        $this->get(route('sensors.google-calendar.callback', ['state' => $query['state'], 'code' => 'authorization-code']))
            ->assertRedirect(route('sensors.index'))
            ->assertSessionHas('status');

        $sensor = Sensor::where('user_id', $user->id)->where('type', Sensor::GOOGLE_CALENDAR)->firstOrFail();
        $this->assertSame('guest@example.com', $sensor->username);
        $this->assertSame('refresh-secret', $sensor->token);
        $this->assertNotSame('refresh-secret', DB::table('sensors')->where('id', $sensor->id)->value('token'));
        $this->assertTrue($sensor->enabled);
        $this->assertDatabaseCount('google_calendar_events', 2);
        $this->assertDatabaseHas('log_blocks', ['type' => 'sensor_google_calendar', 'content' => 'Yoga with the admiral']);
        $this->assertSame('Breathe before engaging.', GoogleCalendarEvent::where('google_event_id', 'timed-event')->firstOrFail()->description);
        $this->actingAs($user)->get('/logs/2026-08-19')->assertOk()
            ->assertSee('data-timeline-google-calendar', false)
            ->assertSee('Yoga with the admiral')
            ->assertSee('Holodeck 2')
            ->assertSee('data-overlay="google-calendar"', false);
        Carbon::setTestNow();
    }

    public function test_google_calendar_resync_moves_updates_and_removes_events(): void
    {
        Carbon::setTestNow('2026-08-19 09:00:00');
        config([
            'services.google_calendar.client_id' => 'google-client-id',
            'services.google_calendar.client_secret' => 'google-client-secret',
        ]);
        $user = User::factory()->create();
        $sensor = Sensor::create(['user_id' => $user->id, 'type' => Sensor::GOOGLE_CALENDAR, 'username' => 'guest@example.com', 'token' => 'refresh-secret', 'enabled' => true, 'settings' => ['calendar_id' => 'primary']]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'sync-access']),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::sequence()
                ->push(['items' => [[
                    'id' => 'moving-event', 'summary' => 'Bridge meeting',
                    'start' => ['dateTime' => '2026-08-19T10:00:00+08:00'], 'end' => ['dateTime' => '2026-08-19T11:00:00+08:00'],
                ], [
                    'id' => 'removed-event', 'summary' => 'Cancelled briefing',
                    'start' => ['dateTime' => '2026-08-19T12:00:00+08:00'], 'end' => ['dateTime' => '2026-08-19T12:30:00+08:00'],
                ]]])
                ->push(['items' => [[
                    'id' => 'moving-event', 'summary' => 'Updated bridge meeting',
                    'start' => ['dateTime' => '2026-08-20T15:00:00+08:00'], 'end' => ['dateTime' => '2026-08-20T16:00:00+08:00'],
                ]]]),
        ]);

        $sync = app(\App\Services\GoogleCalendarSync::class);
        $this->assertTrue($sync->syncSensor($sensor, true));
        $movingBlockId = GoogleCalendarEvent::where('google_event_id', 'moving-event')->firstOrFail()->log_block_id;
        $this->assertTrue($sync->syncSensor($sensor->fresh(), true));

        $this->assertDatabaseCount('google_calendar_events', 1);
        $moving = GoogleCalendarEvent::where('google_event_id', 'moving-event')->firstOrFail();
        $this->assertSame($movingBlockId, $moving->log_block_id);
        $this->assertSame('2026-08-20', $moving->dailyLog->log_date->toDateString());
        $this->assertSame('Updated bridge meeting', $moving->logBlock->content);
        $this->assertDatabaseMissing('google_calendar_events', ['google_event_id' => 'removed-event']);
        $this->assertDatabaseMissing('log_blocks', ['content' => 'Cancelled briefing']);

        $this->actingAs($user)->patchJson(route('sensors.google-calendar.toggle'), ['enabled' => false])->assertOk();
        $this->delete(route('sensors.google-calendar.unlink'))->assertRedirect();
        $this->assertDatabaseMissing('sensors', ['id' => $sensor->id]);
        $this->assertDatabaseHas('log_blocks', ['id' => $movingBlockId, 'content' => 'Updated bridge meeting']);
        Carbon::setTestNow();
    }
}
