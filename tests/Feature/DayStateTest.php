<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\DailyLog;
use App\Models\Sensor;
use App\Models\TaskDefinition;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DayStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_navigation_can_load_a_json_state_document(): void
    {
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-24']);
        $log->blocks()->create(['type' => 'text', 'emoji' => '🧭', 'content' => 'Structured payload', 'occurred_at' => '2026-08-24 09:15:00']);
        TaskDefinition::create(['user_id' => $user->id, 'name' => 'Hydration', 'daily_default_count' => 2]);

        $response = $this->actingAs($user)
            ->withHeader('X-Day-State', 'json')
            ->get(route('logs.show', '2026-08-24'));

        $response->assertOk()
            ->assertHeader('Server-Timing')
            ->assertJsonPath('date', '2026-08-24')
            ->assertJsonStructure([
                'date', 'url', 'title', 'fetched_at',
                'navigation' => ['previous_url', 'today_url', 'next_url', 'calendar_url'],
                'log' => ['id', 'create_block_url', 'chat_url'],
                'tasks', 'timeline',
            ]);

        $response->assertJsonMissingPath('main_html')->assertJsonMissingPath('navigation_html');
        $response->assertJsonPath('navigation.today_url', route('logs.today'));
        $response->assertJsonFragment(['type' => 'text', 'content' => 'Structured payload', 'emoji' => '🧭']);
        $response->assertJsonFragment(['name' => 'Hydration', 'daily_default_count' => 2]);
    }

    public function test_authenticated_shell_contains_the_expired_session_login_overlay(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('calendar'))
            ->assertOk()
            ->assertSee('data-calendar-focus-date', false)
            ->assertSee('data-calendar-today-url', false)
            ->assertSee('data-session-expired-overlay', false)
            ->assertSee('data-page-loading-overlay', false)
            ->assertSee('data-sync-status', false)
            ->assertSee('data-session-keepalive-url', false)
            ->assertSee('data-login-url', false);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('function showSessionExpired()', $script);
        $this->assertStringContainsString('function beginPageLoading()', $script);
        $this->assertStringContainsString('function finishPageLoading()', $script);
        $this->assertStringContainsString('refreshDayView({loading:false})', $script);
        $this->assertStringContainsString("'X-Day-State': 'json'", $script);
        $this->assertStringContainsString('mutateDayState', $script);
        $this->assertStringContainsString('function renderTimelineItem(item)', $script);
        $this->assertStringContainsString("const supportsDayStateNavigation = () => Boolean(document.querySelector('#daily-log-page-container'))", $script);
        $this->assertStringContainsString('if (!supportsDayStateNavigation() || url.origin', $script);
        $this->assertStringContainsString('const backgroundSyncQueue = new Map()', $script);
        $this->assertStringContainsString('scheduleSyncRun(key, entry)', $script);
        $this->assertStringContainsString('const DAY_RETURN_REMINDER_DELAY = 60 * 60 * 1000', $script);
        $this->assertStringContainsString("title: 'Return to today?'", $script);
        $this->assertStringContainsString("confirmText: 'Go to today'", $script);
        $this->assertStringContainsString("cancelText: 'Stay on this day'", $script);
        $this->assertStringContainsString('scheduleDayReturnReminder();', $script);
        $this->assertStringContainsString('function startDateRolloverWatch()', $script);
        $this->assertStringContainsString("title: 'A new day has started'", $script);
        $this->assertStringContainsString("message: 'Total Log will refresh to the real date.'", $script);
        $this->assertStringContainsString("document.querySelector('meta[name=\"today-url\"]')?.content || '/log/today'", $script);
        $this->assertStringContainsString('function startTodayActivityRefresh()', $script);
        $this->assertStringContainsString('!activeDayState?.is_today || backgroundSyncQueue.size > 0', $script);
        $this->assertStringContainsString("'[data-overlay][data-open=\"true\"], [data-modal-backdrop]'", $script);
    }

    public function test_fresh_day_state_contains_new_browser_activity_and_disables_caching(): void
    {
        Carbon::setTestNow('2026-09-02 23:10:00');
        $user = User::factory()->create();
        $key = str_repeat('b', 64);
        Sensor::create([
            'user_id' => $user->id,
            'type' => Sensor::BROWSER,
            'username' => 'Chrome extension',
            'pairing_key_hash' => hash('sha256', $key),
            'enabled' => true,
        ]);

        $this->withHeader('X-TotalLog-Key', $key)->postJson(route('api.sensors.browser.activity'), [
            'url' => 'https://www.izedebiyat.com/story',
            'observed_at' => now()->toIso8601String(),
            'client_id' => 'chrome-day-state-test',
        ])->assertCreated();

        $response = $this->actingAs($user)
            ->withHeader('X-Day-State', 'json')
            ->get(route('logs.show', '2026-09-02'));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonFragment(['type' => 'sensor_browser'])
            ->assertJsonFragment(['domain' => 'www.izedebiyat.com', 'seconds' => 0]);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("cache: fresh ? 'no-store' : 'default'", $script);
        Carbon::setTestNow();
    }
}
