<?php

namespace Tests\Feature;

use App\Models\ApiCall;
use App\Models\Attachment;
use App\Models\DailyLog;
use App\Models\TaskDefinition;
use App\Models\TaskEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TotalLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_and_dated_log_are_refreshable_and_owned(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/calendar/2026-08-15?view=week')->assertOk()
            ->assertSee('data-navigation-date', false)
            ->assertSee('Aug 10 &ndash; Aug 16, 2026', false)
            ->assertDontSee('calendar-page-heading', false)
            ->assertDontSee('page-heading-container', false);
        $this->get('/logs/2026-08-15')->assertOk()->assertSee('Saturday, August 15, 2026');
        $this->get('/logs/2026-08-15')->assertOk()->assertSee('Saturday, August 15, 2026');
        $this->assertDatabaseHas('daily_logs', ['user_id' => $user->id, 'log_date' => '2026-08-15 00:00:00']);
    }

    public function test_today_route_resolves_the_date_at_request_time_and_preserves_the_query(): void
    {
        Carbon::setTestNow('2026-09-06 00:01:00');
        try {
            $this->actingAs(User::factory()->create())
                ->get(route('logs.today', ['date' => '1999-01-01', 'panel' => 'chat']))
                ->assertRedirect(route('logs.show', ['date' => '2026-09-06', 'panel' => 'chat']));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_daily_log_navigation_contains_the_date_and_icon_only_day_controls(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/logs/2026-08-15')->assertOk();

        $response->assertSee('data-navigation-date', false)
            ->assertSee('Saturday, August 15, 2026')
            ->assertSee('data-day-navigation', false)
            ->assertSee('href="'.route('logs.show', '2026-08-14').'"', false)
            ->assertSee('href="'.route('logs.today').'"', false)
            ->assertSee('href="'.route('logs.show', '2026-08-16').'"', false)
            ->assertSee('aria-label="Previous day"', false)
            ->assertSee('aria-label="Today"', false)
            ->assertSee('aria-label="Next day"', false)
            ->assertSee('href="'.route('calendar').'" aria-label="Open calendar"', false)
            ->assertDontSee('daily-log-page-heading', false)
            ->assertDontSee('&larr; Previous', false)
            ->assertDontSee('Next &rarr;', false)
            ->assertDontSee('href="'.route('calendar', '2026-08-15').'?view=week"', false);
    }

    public function test_navbar_home_opens_todays_log_instead_of_the_calendar(): void
    {
        Carbon::setTestNow('2026-09-04 12:00:00');
        try {
            $user = User::factory()->create();

            foreach ([route('calendar'), route('tasks.index')] as $page) {
                $this->actingAs($user)->get($page)->assertOk()
                    ->assertSee('href="'.route('logs.today').'" class="flex min-w-0 items-center gap-2 font-bold" data-navbar-home', false);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_calendar_uses_week_and_month_icons_and_caps_activity_markers_at_thirty_two(): void
    {
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-19']);
        foreach (range(1, 35) as $position) {
            $log->blocks()->create(['type' => 'text', 'content' => "Entry {$position}", 'position' => $position]);
        }

        $response = $this->actingAs($user)->get(route('calendar', '2026-08-19').'?view=week')->assertOk()
            ->assertSee('data-calendar-view="week"', false)
            ->assertSee('aria-label="Week view"', false)
            ->assertSee('data-calendar-view="month"', false)
            ->assertSee('aria-label="Month view"', false)
            ->assertSee('data-calendar-view-current="week"', false)
            ->assertDontSee('<select data-calendar-view', false)
            ->assertDontSee('data-day-url', false)
            ->assertSee('35 recorded activities');

        $this->assertSame(32, substr_count($response->getContent(), 'data-calendar-activity-marker '));
        $this->get(route('calendar', '2026-08-19').'?view=day')->assertOk()
            ->assertSee('data-calendar-view-current="week"', false);
        $calendarScript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("const validViews = ['week', 'month']", $calendarScript);
        $this->assertStringContainsString('localStorage.setItem(storageKey, link.dataset.calendarView)', $calendarScript);
        $this->assertStringNotContainsString("requestedView === 'day'", $calendarScript);
    }

    public function test_calendar_today_always_uses_the_date_free_today_route(): void
    {
        Carbon::setTestNow('2026-09-04 12:00:00');
        try {
            $user = User::factory()->create(['week_starts_on' => 1]);
            $this->actingAs($user);

            foreach ([
                route('calendar', '2026-09-02').'?view=week',
                route('calendar', '2026-09-15').'?view=month',
                route('calendar', '2026-07-15').'?view=week',
                route('calendar', '2026-07-15').'?view=month',
            ] as $url) {
                $this->get($url)->assertOk()
                    ->assertSee('href="'.route('logs.today').'" data-calendar-today-action="open-log"', false);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_daily_log_exposes_composer_and_chat_through_responsive_navigation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('logs.show', today()->toDateString()))->assertOk()
            ->assertSee('data-mobile-nav-toggle', false)
            ->assertSee('data-panel-open="chat"', false)
            ->assertSee('data-composer-open', false)
            ->assertSee('data-overlay="composer"', false)
            ->assertSee('data-overlay="chat"', false)
            ->assertDontSee('data-panel-open="image"', false)
            ->assertDontSee('data-overlay="image"', false);
    }

    public function test_authenticated_navigation_uses_one_hamburger_on_all_screen_sizes(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/logs/2026-08-16')->assertOk()
            ->assertSee('data-mobile-nav-toggle', false)
            ->assertSee('Event setup')
            ->assertSee('Account setup')
            ->assertDontSee('href="'.route('settings.edit').'"', false)
            ->assertDontSee('href="'.route('sensors.index').'"', false)
            ->assertDontSee('href="'.route('api-usage.index').'"', false)
            ->assertSee('nav-link flex items-center gap-2', false)
            ->assertSee('Chat with log')
            ->assertDontSee('Generate image')
            ->assertSee('aria-label="Toggle theme"', false)
            ->assertSee('aria-label="Sign out"', false)
            ->assertSee('data-navigation-sign-out', false)
            ->assertDontSee('md:hidden', false);

        $response->assertSee('aria-label="Open notes" title="Notes"', false)
            ->assertSee('aria-label="Search logs" title="Search logs"', false)
            ->assertSee('<span>Notes</span>', false)
            ->assertSee('<span>Search</span>', false)
            ->assertSee('<span>Appearance</span>', false);
        preg_match('/<div id="account-navigation".*?<\/nav>/s', $response->getContent(), $matches);
        $mobileMenu = $matches[0] ?? '';
        $this->assertStringContainsString('sm:hidden', $mobileMenu);
        $this->assertStringContainsString('<span>Notes</span>', $mobileMenu);
        $this->assertStringContainsString('<span>Search</span>', $mobileMenu);
        $this->assertStringContainsString('<span>Appearance</span>', $mobileMenu);
        $this->assertLessThan(strpos($response->getContent(), 'data-mobile-nav-menu'), strpos($response->getContent(), 'aria-label="Today"'));

        $this->get(route('calendar'))->assertOk()
            ->assertSee(route('logs.today', ['panel' => 'chat']))
            ->assertDontSee('aria-label="Open calendar"', false)
            ->assertDontSee(route('logs.today', ['panel' => 'image']));

        $this->get(route('tasks.index'))->assertOk()
            ->assertSee('aria-label="Open notes"', false)
            ->assertSee('href="'.route('logs.today').'" aria-label="Open today\'s log"', false)
            ->assertDontSee('href="'.route('calendar').'" aria-label="Open calendar"', false);
    }

    public function test_account_setup_pages_share_settings_tabs_without_exposing_admin_to_regular_users(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach ([route('profile.edit'), route('settings.edit'), route('settings.screensaver'), route('sensors.index'), route('api-usage.index')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('id="account-setup-tabs"', false)
                ->assertSee('href="'.route('profile.edit').'"', false)
                ->assertSee('href="'.route('settings.edit').'"', false)
                ->assertSee('href="'.route('settings.screensaver').'"', false)
                ->assertSee('href="'.route('sensors.index').'"', false)
                ->assertSee('href="'.route('api-usage.index').'"', false)
                ->assertDontSee('href="'.route('admin.users').'"', false);
        }
    }

    public function test_screensaver_preferences_are_saved_and_exposed_to_the_app_shell(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('settings.screensaver.update'), [
            'screensaver_enabled' => '1',
            'screensaver_style' => 'messages',
            'screensaver_wait_minutes' => 5,
            'screensaver_speed' => 1.5,
            'screensaver_message' => 'Back after lunch',
        ])->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->screensaver_enabled);
        $this->assertSame('messages', $user->screensaver_style);
        $this->assertSame(5, $user->screensaver_wait_minutes);
        $this->assertSame(1.5, $user->screensaver_speed);
        $this->assertSame('Back after lunch', $user->screensaver_message);

        $response = $this->get(route('settings.screensaver'))->assertOk()
            ->assertSee('id="screensaver-list"', false)
            ->assertSee('data-screensaver-preview', false)
            ->assertSee('data-screensaver-message-setting', false)
            ->assertSee('data-screensaver-logo-setting', false)
            ->assertSee('data-screensaver-spotlight-preview', false)
            ->assertSee('data-screensaver-style="messages"', false)
            ->assertSee('data-screensaver-message="Back after lunch"', false)
            ->assertSee('value="messages" data-screensaver-option', false);

        $this->assertSame(2, substr_count($response->getContent(), '<iframe'));

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("document.querySelector('input[data-screensaver-logo]')", $script);
        $this->assertStringNotContainsString("document.querySelector('[data-screensaver-logo]')", $script);
    }

    public function test_hamburger_can_toggle_the_screensaver(): void
    {
        $user = User::factory()->create(['screensaver_enabled' => false]);

        $this->actingAs($user)->get(route('calendar'))->assertOk()
            ->assertSee('data-screensaver-toggle', false)
            ->assertSee('Enable screensaver')
            ->assertDontSee('Start screensaver now')
            ->assertDontSee('Screensaver settings')
            ->assertSee('data-screensaver-overlay', false);

        $this->patchJson(route('settings.screensaver.toggle'))
            ->assertOk()
            ->assertJson(['enabled' => true]);

        $this->assertTrue($user->refresh()->screensaver_enabled);
    }

    public function test_user_can_upload_a_custom_screensaver_logo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $logo = UploadedFile::fake()->image('my-logo.png', 320, 180);

        $this->actingAs($user)->patch(route('settings.screensaver.update'), [
            'screensaver_style' => 'logo',
            'screensaver_wait_minutes' => 10,
            'screensaver_speed' => 1,
            'screensaver_message' => 'OUT TO LUNCH',
            'screensaver_logo' => $logo,
        ])->assertRedirect();

        $path = $user->refresh()->screensaver_logo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->get(route('settings.screensaver.logo'))->assertOk();
    }

    public function test_screensaver_settings_reject_unknown_savers_and_unsafe_waits(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from(route('settings.screensaver'))->patch(route('settings.screensaver.update'), [
            'screensaver_style' => '../other',
            'screensaver_wait_minutes' => 0,
            'screensaver_speed' => 99,
        ])->assertRedirect(route('settings.screensaver'))
            ->assertSessionHasErrors(['screensaver_style', 'screensaver_wait_minutes', 'screensaver_speed']);
    }

    public function test_starry_night_is_available_with_oled_safe_five_second_drift(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('settings.screensaver.update'), [
            'screensaver_style' => 'starry-night',
            'screensaver_wait_minutes' => 10,
            'screensaver_speed' => 1,
        ])->assertRedirect();

        $this->assertSame('starry-night', $user->refresh()->screensaver_style);
        $this->get(route('settings.screensaver'))->assertOk()
            ->assertSee('value="starry-night"', false)
            ->assertSee('data-screensaver-starry-night="/screensavers/starry-night/index.html"', false);

        $source = file_get_contents(public_path('screensavers/starry-night/index.html'));
        $this->assertStringContainsString('window.setTimeout(move, 5000)', $source);
        $this->assertStringContainsString('[[1, 0], [1, 1], [0, 1], [0, 0]]', $source);
    }

    public function test_full_screen_screensavers_hide_scrollbars_and_exit_from_iframe_activity(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("frameDocument.addEventListener(eventName, activity", $script);
        $this->assertStringContainsString("['pointermove', 'pointerdown', 'keydown', 'touchstart', 'wheel']", $script);
        $this->assertStringContainsString("document.documentElement.style.overflow = 'hidden'", $script);
        $this->assertStringContainsString('html:has([data-screensaver-overlay].block)', $styles);
    }

    public function test_screensaver_speed_is_injected_as_a_css_timing_variable(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $ball = file_get_contents(public_path('screensavers/after-dark/all/bouncing-ball.html'));
        $warp = file_get_contents(public_path('screensavers/after-dark/all/warp.html'));
        $starry = file_get_contents(public_path('screensavers/starry-night/index.html'));

        $this->assertStringContainsString("style.setProperty('--screensaver-speed', String(settings.speed))", $script);
        $this->assertStringNotContainsString('playbackRate = settings.speed', $script);
        $this->assertStringContainsString('calc(3.4s / var(--screensaver-speed, 1))', $ball);
        $this->assertStringContainsString('animation-delay: calc(500ms / var(--screensaver-speed, 1))', $warp);
        $this->assertStringContainsString('calc(4s / var(--screensaver-speed))', $starry);
    }

    public function test_account_display_and_chat_preferences_are_saved_and_applied(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('settings.update'), [
            'time_format' => '12',
            'week_starts_on' => 0,
            'default_chat_model' => 'test/structured-model',
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('12', $user->time_format);
        $this->assertSame(0, $user->week_starts_on);
        $this->assertSame('test/structured-model', $user->default_chat_model);

        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-19']);
        $log->blocks()->create(['type' => 'text', 'content' => 'Evening note', 'position' => 1, 'occurred_at' => '2026-08-19 18:30:00']);
        TaskDefinition::create(['user_id' => $user->id, 'name' => 'Evening event', 'is_sticky' => true, 'scheduled_times' => ['19:15'], 'recurrence_type' => 'daily']);

        $this->get(route('logs.show', '2026-08-19'))->assertOk()
            ->assertSee('>6:30 PM</time>', false)
            ->assertDontSee('Recorded 6:30 PM')
            ->assertSee('7:15 PM')
            ->assertSee('data-selected="test/structured-model"', false)
            ->assertSee('data-time-picker', false);

        $this->get(route('calendar', '2026-08-19').'?view=week')->assertOk()
            ->assertSeeInOrder([
                route('logs.show', '2026-08-16'),
                route('logs.show', '2026-08-17'),
                route('logs.show', '2026-08-18'),
                route('logs.show', '2026-08-19'),
                route('logs.show', '2026-08-20'),
                route('logs.show', '2026-08-21'),
                route('logs.show', '2026-08-22'),
            ]);
    }

    public function test_an_undecryptable_openrouter_key_does_not_break_settings_or_model_requests(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['openrouter_api_key' => 'not-valid-encrypted-data']);
        $user->refresh();

        $this->actingAs($user)->get(route('settings.edit'))->assertOk()
            ->assertSee('id="openrouter-api-key-warning"', false)
            ->assertSee('Enter your OpenRouter API key again');

        $this->getJson(route('openrouter.models'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.api_key.0', 'Your saved OpenRouter API key can no longer be decrypted. Replace it in Settings.');

        $this->patch(route('settings.update'), [
            'openrouter_api_key' => 'sk-or-replacement',
            'time_format' => '24',
            'week_starts_on' => 1,
            'default_chat_model' => '',
        ])->assertRedirect();

        $this->assertSame('sk-or-replacement', $user->refresh()->openRouterApiKey());
    }

    public function test_event_time_slots_use_add_remove_visual_controls(): void
    {
        $user = User::factory()->create();
        TaskDefinition::create(['user_id' => $user->id, 'name' => 'Five-minute event', 'scheduled_times' => ['08:00', '14:30'], 'visible_after' => '07:30', 'is_sticky' => true, 'recurrence_type' => 'daily']);

        $this->actingAs($user)->get(route('tasks.index'))->assertOk()
            ->assertSee('data-time-slots', false)
            ->assertSee('data-time-slot-add', false)
            ->assertSee('data-event-definition-open=', false)
            ->assertSee('data-event-sort-list', false)
            ->assertSee('data-event-sort-item', false)
            ->assertSee('data-event-drag-handle', false)
            ->assertSee('>Edit</button>', false)
            ->assertSee('data-overlay="event-definition"', false)
            ->assertSee('data-overlay-side="right"', false)
            ->assertSee('data-event-definition-form', false)
            ->assertSee('max-h-[80dvh]', false)
            ->assertSee('data-time-dialog-cancel', false)
            ->assertDontSee('data-time-keyboard-toggle', false)
            ->assertDontSee('data-time-dialog-apply', false)
            ->assertSee('"scheduled_times":["08:00","14:30"]', false)
            ->assertSee('"visible_after":"07:30"', false)
            ->assertSee('data-visible-after-toggle', false)
            ->assertSee('data-sticky-visibility-field', false)
            ->assertSee('+ Add time slot')
            ->assertSee('Tap a time to slide through hours');

        $timePickerScript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("if (kind === 'minute') dismiss();", $timePickerScript);
        $this->assertStringNotContainsString('hourClicked && minuteClicked', $timePickerScript);

        $response = $this->postJson(route('tasks.store'), [
            'name' => 'Visual slots', 'color' => '#4f46e5', 'recurrence_type' => 'daily',
            'scheduled_times' => ['06:15', '18:45'], 'visible_after' => '05:30', 'is_sticky' => '1',
        ])->assertCreated()->assertJsonPath('reload', true);
        $this->assertDatabaseHas('task_definitions', ['user_id' => $user->id, 'name' => 'Visual slots']);
        $created = TaskDefinition::findOrFail($response->json('event.id'));
        $this->assertSame(['06:15', '18:45'], $created->scheduled_times);
        $this->assertSame('05:30', $created->visible_after);
        $this->patchJson(route('tasks.update', $created), [
            'name' => 'Updated visual slots', 'emoji' => '⏰', 'color' => '#059669', 'recurrence_type' => 'weekly',
            'weekdays' => [1, 5], 'scheduled_times' => ['07:00'], 'is_sticky' => '1',
        ])->assertOk()->assertJsonPath('event.name', 'Updated visual slots')->assertJsonPath('reload', true);
        $this->assertDatabaseHas('task_definitions', ['id' => $created->id, 'name' => 'Updated visual slots', 'emoji' => '⏰']);
    }

    public function test_event_order_is_saved_and_shared_by_setup_sticky_events_and_the_dropdown(): void
    {
        $user = User::factory()->create();
        $other = TaskDefinition::create(['user_id' => User::factory()->create()->id, 'name' => 'Not owned']);
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-09-06']);
        $first = TaskDefinition::create(['user_id' => $user->id, 'name' => 'First event', 'is_sticky' => true]);
        $second = TaskDefinition::create(['user_id' => $user->id, 'name' => 'Second event', 'is_sticky' => true]);
        $third = TaskDefinition::create(['user_id' => $user->id, 'name' => 'Third event', 'is_sticky' => true]);
        $this->actingAs($user);

        $this->patchJson(route('tasks.reorder'), ['event_ids' => [$third->id, $first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('message', 'Event order saved.');
        $this->assertDatabaseHas('task_definitions', ['id' => $third->id, 'position' => 0]);
        $this->assertDatabaseHas('task_definitions', ['id' => $first->id, 'position' => 1]);
        $this->assertDatabaseHas('task_definitions', ['id' => $second->id, 'position' => 2]);

        $setup = $this->get(route('tasks.index'))->assertOk()->getContent();
        $this->assertTrue(strpos($setup, 'Third event') < strpos($setup, 'First event'));
        $this->assertTrue(strpos($setup, 'First event') < strpos($setup, 'Second event'));

        $state = $this->withHeader('X-Day-State', 'json')->get(route('logs.show', '2026-09-06'))->assertOk();
        $state->assertJsonPath('tasks.0.id', $third->id)
            ->assertJsonPath('tasks.1.id', $first->id)
            ->assertJsonPath('tasks.2.id', $second->id)
            ->assertJsonPath('sticky_events.0.id', $third->id)
            ->assertJsonPath('sticky_events.1.id', $first->id)
            ->assertJsonPath('sticky_events.2.id', $second->id);

        $this->patchJson(route('tasks.reorder'), ['event_ids' => [$first->id, $second->id, $other->id]])
            ->assertUnprocessable();
    }

    public function test_guest_navigation_has_auth_and_theme_icons_without_a_hamburger(): void
    {
        auth()->logout();

        $this->get(route('demo.index'))->assertOk()
            ->assertSee('aria-label="Sign in"', false)
            ->assertSee('aria-label="Register"', false)
            ->assertSee('aria-label="Toggle theme"', false)
            ->assertSee('data-theme-option="light"', false)
            ->assertSee('data-theme-option="paper"', false)
            ->assertSee('data-theme-option="blue"', false)
            ->assertSee('data-theme-option="red"', false)
            ->assertSee('data-theme-option="dark"', false)
            ->assertSee('Paper garden')
            ->assertSee('Blue horizon')
            ->assertSee('Red alert')
            ->assertDontSee('data-mobile-nav-toggle', false);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('document.documentElement.dataset.theme = selected', $script);
        $this->assertStringContainsString('icon.dataset.themeIcon !== selected', $script);
    }

    public function test_task_buttons_accept_custom_browser_colors_and_legacy_colors_still_render(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tasks.store'), [
            'name' => 'Hydration alert',
            'color' => '#12Ab9F',
            'is_sticky' => '1',
            'recurrence_type' => 'daily',
            'scheduled_times_text' => '09:00',
        ])->assertRedirect(route('tasks.index'));

        $custom = TaskDefinition::where('user_id', $user->id)->where('name', 'Hydration alert')->firstOrFail();
        $legacy = TaskDefinition::create(['user_id' => $user->id, 'name' => 'Legacy event', 'color' => 'rose']);
        $this->assertSame('#12ab9f', $custom->color);
        $this->assertSame('#12ab9f', $custom->color_hex);
        $this->assertSame('#e11d48', $legacy->color_hex);
        $this->actingAs($user)->post(route('tasks.store'), ['name' => 'Bad color', 'color' => 'javascript:red'])->assertSessionHasErrors('color');
    }

    public function test_log_entries_and_events_have_searchable_category_emoji_pickers_and_defaults(): void
    {
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        $this->assertSame('📝', $log->blocks()->create(['type' => 'text', 'content' => 'Default note'])->emoji);
        $this->assertSame('💬', $log->blocks()->create(['type' => 'chat_user', 'content' => 'Default chat'])->emoji);
        $this->assertSame('🤖', $log->blocks()->create(['type' => 'chat_assistant', 'content' => 'Default answer'])->emoji);
        $this->assertSame('🎨', $log->blocks()->create(['type' => 'generated_image', 'content' => 'Default generated image'])->emoji);

        $task = TaskDefinition::create(['user_id' => $user->id, 'name' => 'Yoga flow', 'emoji' => '🧘', 'recurrence_type' => 'daily']);
        $this->assertSame('✅', TaskDefinition::create(['user_id' => $user->id, 'name' => 'Default event'])->emoji);

        $this->actingAs($user)->get(route('tasks.index'))->assertOk()
            ->assertSee('data-emoji-picker', false)
            ->assertSee('data-emoji-search', false)
            ->assertSee('data-emoji-url="'.route('emojis.index', [], false).'"', false)
            ->assertSee('aria-label="Emoji category"', false)
            ->assertSee('data-emoji-categories', false)
            ->assertDontSee('data-emoji-category-template', false)
            ->assertSee('data-emoji-option-template', false)
            ->assertSee('data-emoji-loading-spinner', false)
            ->assertSee('overflow-x-hidden', false)
            ->assertSee('z-[100]', false)
            ->assertSee('h-[300px]', false)
            ->assertSee('🧘');

        $emojiGroups = json_decode(file_get_contents(public_path('data/data-by-group.json')), true, flags: JSON_THROW_ON_ERROR);
        $allEmojis = collect($emojiGroups)->flatMap(fn ($group) => $group['emojis']);
        $this->assertGreaterThan(1800, $allEmojis->count());
        $this->assertSame('💻', $allEmojis->firstWhere('name', 'laptop')['emoji']);
        $this->assertCount(9, $emojiGroups);
        $firstPage = $this->getJson(route('emojis.index'))->assertOk()
            ->assertJsonCount(9, 'categories')
            ->assertJsonPath('group', 'smileys_emotion')
            ->assertJsonMissing(['name' => 'laptop']);
        $this->assertCount(171, $firstPage->json('emojis'));
        $this->getJson(route('emojis.index', ['group' => 'objects']))->assertOk()
            ->assertJsonFragment(['emoji' => '💻', 'name' => 'laptop']);
        $this->getJson(route('emojis.index', ['q' => 'laptop']))->assertOk()
            ->assertJsonFragment(['emoji' => '💻', 'name' => 'laptop']);
        $this->getJson(route('emojis.index', ['q' => 'lap']))->assertOk()
            ->assertJsonFragment(['emoji' => '💻', 'name' => 'laptop']);
        $this->getJson(route('emojis.index', ['q' => 'aptop']))->assertOk()
            ->assertJsonMissing(['name' => 'laptop']);
        $this->getJson(route('emojis.index', ['q' => 'clo fa']))->assertOk()
            ->assertJsonFragment(['name' => 'clown face']);
        $this->getJson(route('emojis.index', ['q' => 'own']))->assertOk()
            ->assertJsonMissing(['name' => 'clown face']);
        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('emoji-picker-host-active', $script);
        $this->assertStringContainsString('window.setTimeout(() => requestEmojis', $script);
        $this->assertStringContainsString("loading?.classList.toggle('grid', busy)", $script);
        $this->assertStringContainsString('option.title = emoji.name', $script);
        $this->assertStringContainsString("categorySelect?.addEventListener('change'", $script);
        $this->assertStringNotContainsString('data-by-group.json', $script);
        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringNotContainsString('.block-emoji-picker-category-tabs', $styles);

        $created = $this->postJson(route('blocks.store', $log), [
            'type' => 'text', 'content' => 'Custom emoji note', 'emoji' => '🌈', 'occurred_at' => '09:15',
        ])->assertCreated()->json('block.id');
        $this->assertDatabaseHas('log_blocks', ['id' => $created, 'emoji' => '🌈']);
        $this->patchJson(route('blocks.update', $created), ['content' => 'Changed emoji note', 'emoji' => '🚀'])->assertOk();
        $this->assertDatabaseHas('log_blocks', ['id' => $created, 'emoji' => '🚀']);

        $eventResponse = $this->postJson(route('events.store', [$log, $task]), [])->assertCreated()->assertJsonPath('emoji', '🧘');
        $this->assertDatabaseHas('log_blocks', ['id' => $eventResponse->json('block_id'), 'emoji' => '🧘']);
        $this->get(route('logs.show', '2026-08-15'))->assertOk()
            ->assertSee('data-edit-emoji="🚀"', false)
            ->assertSee('data-block-emoji', false)
            ->assertSee('id="composer-entry-emoji-input"', false);
    }

    public function test_tasks_support_daily_weekly_and_monthly_recurrence_with_time_slots(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tasks.store'), [
            'name' => 'Saturday rounds',
            'color' => '#4f46e5',
            'is_sticky' => '1',
            'recurrence_type' => 'weekly',
            'weekdays' => [6],
            'scheduled_times_text' => '08:00, 17:00',
        ])->assertRedirect(route('tasks.index'));

        $task = TaskDefinition::where('name', 'Saturday rounds')->firstOrFail();
        $this->assertSame('weekly', $task->recurrence_type);
        $this->assertSame([6], $task->recurrence_days);
        $this->assertSame(['08:00', '17:00'], $task->scheduled_times);

        $mondayTask = TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Monday rounds',
            'recurrence_type' => 'weekly',
            'recurrence_days' => [1],
            'scheduled_times' => ['08:00'],
            'is_sticky' => true,
        ]);

        $this->get('/logs/2026-08-15')->assertOk()
            ->assertSee('Saturday rounds')
            ->assertDontSee('Monday rounds');
        $saturdayLog = DailyLog::where('user_id', $user->id)->whereDate('log_date', '2026-08-15')->firstOrFail();
        $this->postJson(route('events.store', [$saturdayLog, $mondayTask]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This event is not scheduled for this day.');

        $this->post(route('tasks.store'), [
            'name' => 'Visible without slot',
            'color' => '#4f46e5',
            'is_sticky' => '1',
            'recurrence_type' => 'monthly',
            'month_days_text' => '1, 15',
            'visible_after' => '18:00',
        ])->assertRedirect(route('tasks.index'));
        $withoutSlot = TaskDefinition::where('name', 'Visible without slot')->firstOrFail();
        $this->assertTrue($withoutSlot->is_sticky);
        $this->assertNull($withoutSlot->scheduled_times);
        $this->assertSame('18:00', $withoutSlot->visible_after);
    }

    public function test_deleting_an_event_preserves_recorded_entries_as_editable_text_with_media(): void
    {
        Carbon::setTestNow('2026-08-16 14:00:00');
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        $definition = TaskDefinition::create(['user_id' => $user->id, 'name' => 'Stress level', 'options' => ['1', '2', '3'], 'is_sticky' => true]);
        $block = $log->blocks()->create(['type' => 'event', 'content' => 'Settled after breathing practice.', 'position' => 1, 'occurred_at' => '2026-08-15 10:30:00']);
        $event = TaskEvent::create([
            'daily_log_id' => $log->id, 'task_definition_id' => $definition->id, 'log_block_id' => $block->id,
            'task_name' => 'Stress level', 'selected_value' => '2', 'occurred_at' => '2026-08-15 10:30:00',
        ]);
        $attachment = Attachment::create([
            'user_id' => $user->id, 'daily_log_id' => $log->id, 'log_block_id' => $block->id, 'type' => 'audio',
            'disk' => 'local', 'path' => 'test/event-audio.webm', 'original_name' => 'event-audio.webm', 'mime_type' => 'audio/webm', 'size' => 128,
        ]);

        $this->actingAs($user)->delete(route('tasks.destroy', $definition))->assertRedirect(route('tasks.index'));

        $this->assertDatabaseMissing('task_definitions', ['id' => $definition->id]);
        $this->assertDatabaseMissing('task_events', ['id' => $event->id]);
        $this->assertDatabaseHas('log_blocks', [
            'id' => $block->id, 'type' => 'text', 'occurred_at' => '2026-08-15 10:30:00',
            'content' => "Event: Stress level\nValue: 2\n\nSettled after breathing practice.",
        ]);
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id, 'log_block_id' => $block->id]);
        $converted = $block->fresh();
        $this->assertSame('Stress level', data_get($converted->metadata, 'converted_from_event.name'));
        $this->actingAs($user)->get(route('logs.show', '2026-08-15'))->assertOk()
            ->assertSee('Event: Stress level')
            ->assertSee('data-edit-kind="block"', false);
        Carbon::setTestNow();
    }

    public function test_daily_timeline_orders_real_entries_around_scheduled_sticky_events(): void
    {
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Evening check',
            'is_sticky' => true,
            'recurrence_type' => 'daily',
            'scheduled_times' => ['17:00'],
        ]);
        $log->blocks()->forceCreate(['type' => 'text', 'content' => 'Before the scheduled slot', 'position' => 1, 'created_at' => '2026-08-15 13:00:00', 'updated_at' => '2026-08-15 13:00:00']);
        $log->blocks()->forceCreate(['type' => 'text', 'content' => 'After the scheduled slot', 'position' => 2, 'created_at' => '2026-08-15 19:00:00', 'updated_at' => '2026-08-15 19:00:00']);

        $this->actingAs($user)->get('/logs/2026-08-15')->assertOk()
            ->assertSeeInOrder(['Before the scheduled slot', 'Evening check', 'After the scheduled slot'])
            ->assertSee('data-timeline-time="17:00"', false);
    }

    public function test_unscheduled_sticky_events_scroll_in_one_row_while_timed_events_stay_in_the_timeline(): void
    {
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        $firstFloating = TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Drink water',
            'emoji' => '💧',
            'color' => '#f50000',
            'is_sticky' => true,
            'recurrence_type' => 'daily',
        ]);
        $secondFloating = TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Stretch',
            'emoji' => '🧘',
            'is_sticky' => true,
            'recurrence_type' => 'daily',
        ]);
        $timed = TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Evening check',
            'is_sticky' => true,
            'recurrence_type' => 'daily',
            'scheduled_times' => ['17:00'],
        ]);

        $response = $this->actingAs($user)->get('/logs/2026-08-15')->assertOk()
            ->assertSee('id="daily-log-sticky-events" class="horizontal-drag-strip flex sticky z-30 touch-auto select-none flex-nowrap', false)
            ->assertSee('style="top:var(--primary-navigation-height, 4rem)"', false)
            ->assertSee('data-horizontal-drag', false)
            ->assertSee('data-sticky-event-bubble', false)
            ->assertSee('data-scheduled-time="17:00"', false)
            ->assertDontSee('>Any</time>', false);
        preg_match('/<section id="daily-log-sticky-events".*?<\/section>/s', $response->getContent(), $matches);
        $floatingMarkup = $matches[0] ?? '';
        $this->assertStringContainsString('Drink water', $floatingMarkup);
        $this->assertStringContainsString('Stretch', $floatingMarkup);
        $this->assertStringNotContainsString('Evening check', $floatingMarkup);
        $this->assertStringNotContainsString('timeline-item', $floatingMarkup);
        $this->assertStringNotContainsString('<time', $floatingMarkup);
        $this->assertStringNotContainsString('h-3 w-3 shrink-0 rounded-sm', $floatingMarkup);

        $state = $this->withHeader('X-Day-State', 'json')->get('/logs/2026-08-15')->assertOk()->json();
        $this->assertEqualsCanonicalizing([$firstFloating->id, $secondFloating->id], collect($state['sticky_events'])->pluck('id')->all());
        $this->assertSame([$timed->id], collect($state['timeline'])->where('kind', 'schedule')->pluck('task.id')->values()->all());

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("document.documentElement.style.setProperty('--primary-navigation-height'", $script);
        $this->assertStringContainsString("new ResizeObserver(update).observe(navigation)", $script);
        $this->assertStringContainsString("if (event.pointerType !== 'mouse'", $script);
        $this->assertStringContainsString("captureTarget = event.target.closest?.('a, button') || section;", $script);
        $this->assertStringContainsString('captureTarget.setPointerCapture?.(event.pointerId);', $script);
    }

    public function test_sticky_event_disappears_after_reaching_its_daily_default_count(): void
    {
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        $task = TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Dog medication',
            'is_sticky' => true,
            'daily_default_count' => 2,
            'recurrence_type' => 'daily',
            'scheduled_times' => ['08:00'],
        ]);

        $this->actingAs($user)->get('/logs/2026-08-15')->assertOk()->assertSee('data-scheduled-event', false);
        $this->postJson(route('events.store', [$log, $task]), ['scheduled_time' => '08:00'])->assertCreated()->assertJsonPath('count', 1)->assertJsonPath('slot_count', 1);
        $this->get('/logs/2026-08-15')->assertOk()->assertSee('data-scheduled-event', false);
        $this->postJson(route('events.store', [$log, $task]), ['scheduled_time' => '08:00'])->assertCreated()->assertJsonPath('count', 2)->assertJsonPath('slot_count', 2);
        $this->get('/logs/2026-08-15')->assertOk()
            ->assertDontSee('data-scheduled-event', false)
            ->assertSee('data-task-event="'.route('events.store', [$log, $task]).'"', false);
    }

    public function test_each_sticky_time_slot_has_its_own_daily_default_count(): void
    {
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-19']);
        $task = TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Dog medication',
            'is_sticky' => true,
            'daily_default_count' => 2,
            'recurrence_type' => 'daily',
            'scheduled_times' => ['08:00', '20:00'],
        ]);
        $url = route('events.store', [$log, $task]);

        $this->actingAs($user)->get('/logs/2026-08-19')->assertOk()
            ->assertSee('data-scheduled-time="08:00"', false)
            ->assertSee('data-scheduled-time="20:00"', false);
        $this->postJson($url, ['scheduled_time' => '08:00'])->assertCreated()->assertJsonPath('slot_count', 1);
        $this->postJson($url, ['scheduled_time' => '08:00'])->assertCreated()->assertJsonPath('slot_count', 2)->assertJsonPath('count', 2);
        $this->get('/logs/2026-08-19')->assertOk()
            ->assertDontSee('data-scheduled-time="08:00"', false)
            ->assertSee('data-scheduled-time="20:00"', false);
        $this->postJson($url, ['scheduled_time' => '08:00'])->assertUnprocessable();

        $this->postJson($url, ['scheduled_time' => '20:00'])->assertCreated()->assertJsonPath('slot_count', 1);
        $this->postJson($url, ['scheduled_time' => '20:00'])->assertCreated()->assertJsonPath('slot_count', 2)->assertJsonPath('count', 4);
        $this->get('/logs/2026-08-19')->assertOk()
            ->assertDontSee('data-scheduled-event', false)
            ->assertSee('data-task-event="'.$url.'"', false);
        $this->get(route('tasks.index'))->assertOk()->assertSee('Daily target 4')->assertSee('(2 per time slot)');
    }

    public function test_sticky_event_visibility_time_only_delays_todays_planner_button(): void
    {
        Carbon::setTestNow('2026-08-17 17:45:00');
        $user = User::factory()->create();
        TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Bedtime',
            'is_sticky' => true,
            'recurrence_type' => 'daily',
            'visible_after' => '18:00',
        ]);
        TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Always ready',
            'is_sticky' => true,
            'recurrence_type' => 'daily',
            'scheduled_times' => ['20:00'],
        ]);

        $this->actingAs($user)->get('/logs/2026-08-17')->assertOk()
            ->assertDontSee('data-timeline-time="00:00"', false)
            ->assertDontSee('data-sticky-event-bubble', false)
            ->assertSee('data-timeline-time="20:00"', false)
            ->assertSee('data-next-sticky-visibility="18:00"', false)
            ->assertSee('data-name="Bedtime"', false)
            ->assertSee('data-name="Always ready"', false);

        $this->get('/logs/2026-08-18')->assertOk()
            ->assertSee('data-sticky-event-bubble', false)
            ->assertDontSee('data-timeline-time="00:00"', false);

        Carbon::setTestNow('2026-08-17 18:00:00');
        $this->get('/logs/2026-08-17')->assertOk()
            ->assertSee('data-sticky-event-bubble', false)
            ->assertDontSee('data-timeline-time="00:00"', false)
            ->assertDontSee('data-next-sticky-visibility', false);
        Carbon::setTestNow();
    }

    public function test_more_events_menu_has_a_foreground_layer_and_outside_click_hook(): void
    {
        $user = User::factory()->create();
        TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Dropdown event',
            'is_sticky' => false,
            'recurrence_type' => 'daily',
        ]);

        $this->actingAs($user)->get('/logs/2026-08-16')->assertOk()
            ->assertDontSee('data-event-schedule-panel', false)
            ->assertSee('data-events-menu', false)
            ->assertSee('aria-label="Events"', false)
            ->assertDontSee('>More events<', false)
            ->assertSee('max-h-[65vh] w-72 gap-1 overflow-y-auto overscroll-contain', false)
            ->assertSee('style="z-index:70"', false);
    }

    public function test_open_timeline_items_are_hidden_while_timed_items_and_now_remain_ordered(): void
    {
        Carbon::setTestNow('2026-08-16 14:00:00');
        $user = User::factory()->create();
        TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Scheduled checkpoint',
            'is_sticky' => true,
            'recurrence_type' => 'daily',
            'scheduled_times' => ['10:00', '18:00'],
        ]);

        $this->actingAs($user)->get('/logs/2026-08-16')->assertOk()
            ->assertDontSee('data-time-gap', false)
            ->assertDontSee('>Open</span>', false)
            ->assertSeeInOrder([
                'data-timeline-time="10:00"',
                'data-current-time="14:00"',
                'data-timeline-time="18:00"',
            ], false);
        Carbon::setTestNow();
    }

    public function test_ajax_day_rendering_omits_open_timeline_items(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("if (item.kind === 'gap') return null;", $script);
        $this->assertStringNotContainsString("timelineItem.matches('[data-time-gap]')", $script);
    }

    public function test_empty_daily_log_space_opens_the_default_composer(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("e.target === document.querySelector('#timeline')", $script);
        $this->assertStringContainsString("e.target === document.querySelector('#daily-log-page-container')", $script);
        $this->assertStringContainsString("e.target === document.querySelector('#page-content') && supportsDayStateNavigation()", $script);
        $this->assertStringContainsString('if (emptyLogSpace && !timelineItem && !composerTrigger && !demoReadOnly) configureComposer();', $script);
    }

    public function test_recorded_planner_entries_can_be_hidden_but_scheduled_controls_cannot(): void
    {
        Carbon::setTestNow('2026-08-16 14:00:00');
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-16']);
        $block = $log->blocks()->create(['type' => 'text', 'content' => 'Private planner note', 'position' => 1, 'occurred_at' => '2026-08-16 15:00:00']);
        $task = TaskDefinition::create([
            'user_id' => $user->id,
            'name' => 'Evening medicine',
            'is_sticky' => true,
            'recurrence_type' => 'daily',
            'scheduled_times' => ['18:00'],
        ]);

        $this->actingAs($user)->patchJson(route('blocks.visibility', $block), ['hidden' => true])->assertOk();

        $this->get('/logs/2026-08-16')->assertOk()
            ->assertSee('Show hidden entries')
            ->assertDontSee('Private planner note')
            ->assertSee('Evening medicine')
            ->assertDontSee('Hide Evening medicine for this day');

        $this->get('/logs/2026-08-16?show_hidden=1')->assertOk()
            ->assertSee('Hide hidden entries')
            ->assertSee('Private planner note')
            ->assertSee('Evening medicine')
            ->assertSee('data-hidden-planner-item', false)
            ->assertSee('data-is-hidden="true"', false)
            ->assertSee('data-hide-url=', false);

        $this->actingAs($otherUser)->patchJson(route('blocks.visibility', $block), ['hidden' => false])->assertForbidden();
        $this->actingAs($user)->patchJson(route('blocks.visibility', $block), ['hidden' => false])->assertOk();
        $this->assertDatabaseHas('log_blocks', ['id' => $block->id, 'is_hidden' => false]);
        Carbon::setTestNow();
    }

    public function test_user_can_create_edit_and_delete_a_text_block_but_not_another_users_block(): void
    {
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        $response = $this->actingAs($user)->postJson(route('blocks.store', $log), ['type' => 'text', 'content' => 'Steady as she goes.', 'occurred_at' => '13:30'])->assertCreated();
        $blockId = $response->json('block.id');
        $this->assertDatabaseHas('log_blocks', ['id' => $blockId, 'occurred_at' => '2026-08-15 13:30:00']);
        $this->patchJson("/blocks/$blockId", ['content' => 'Course corrected.', 'occurred_at' => '15:20'])->assertOk();
        $this->assertDatabaseHas('log_blocks', ['id' => $blockId, 'occurred_at' => '2026-08-15 15:20:00']);
        $this->actingAs(User::factory()->create())->deleteJson("/blocks/$blockId")->assertForbidden();
        $this->actingAs($user)->deleteJson("/blocks/$blockId")->assertOk();
        $this->assertDatabaseMissing('log_blocks', ['id' => $blockId]);
    }

    public function test_task_event_is_committed_before_optional_notes_and_requires_configured_value(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'address' => ['suburb' => 'Xindian District', 'city' => 'New Taipei'],
            ]),
        ]);
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        $task = TaskDefinition::create(['user_id' => $user->id, 'name' => 'Stress level', 'icon_data' => 'data:image/png;base64,event-image', 'options' => ['1', '2', '3', '4', '5'], 'is_sticky' => true]);
        $this->actingAs($user)->postJson(route('events.store', [$log, $task]), [])->assertUnprocessable();
        Carbon::setTestNow('2026-08-15 15:45:00');
        $response = $this->postJson(route('events.store', [$log, $task]), ['value' => '4'])->assertCreated()->assertJsonPath('count', 1)->assertHeader('Server-Timing');
        $response->assertJsonStructure(['hide_url', 'delete_url', 'location_url']);
        $response->assertJsonMissingPath('slot_counts');
        $eventId = $response->json('event.id');
        $this->assertDatabaseHas('task_events', ['id' => $eventId, 'selected_value' => '4', 'occurred_at' => '2026-08-15 15:45:00']);
        $this->patchJson(route('events.location', $eventId), ['latitude' => 25.033, 'longitude' => 121.5654, 'accuracy' => 15.5])
            ->assertOk()
            ->assertJsonPath('location.latitude', 25.033)
            ->assertJsonPath('location.longitude', 121.5654)
            ->assertJsonPath('location.city', 'New Taipei')
            ->assertJsonPath('location.suburb', 'Xindian District');
        $locatedEvent = TaskEvent::findOrFail($eventId);
        $this->assertSame(25.033, $locatedEvent->latitude);
        $this->assertSame(121.5654, $locatedEvent->longitude);
        $this->assertSame(15.5, $locatedEvent->location_accuracy);
        $this->assertSame('New Taipei', $locatedEvent->city);
        $this->assertSame('Xindian District', $locatedEvent->suburb);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/reverse?')
            && $request['format'] === 'jsonv2'
            && $request['accept-language'] === 'en'
            && $request->hasHeader('User-Agent'));
        $this->actingAs(User::factory()->create())->patchJson(route('events.location', $eventId), ['latitude' => 1, 'longitude' => 1])->assertForbidden();
        $this->actingAs($user)->patchJson(route('events.location', $eventId), ['latitude' => 91, 'longitude' => 1])->assertUnprocessable();
        Carbon::setTestNow();
        $this->get(route('events.edit', $eventId))->assertOk()
            ->assertSee('data-event-autosave-form', false)
            ->assertSee('Xindian District, New Taipei')
            ->assertDontSee('Recorded location')
            ->assertDontSee('Accuracy approximately')
            ->assertSee('Changes save automatically.')
            ->assertDontSee('Save notes &amp; time', false);
        $this->get(route('logs.show', '2026-08-15'))->assertOk()
            ->assertSee('data-capture-location', false)
            ->assertSee('data-edit-event-name="Stress level"', false)
            ->assertSee('data-edit-icon="data:image/png;base64,event-image"', false)
            ->assertSee('data-composer-event-source', false)
            ->assertSee('data-composer-event-image', false)
            ->assertSee('class="hidden h-8 w-8 shrink-0 rounded-lg object-cover" data-composer-event-image', false)
            ->assertDontSee('id="composer-event-image"', false)
            ->assertSee('data-edit-location=', false)
            ->assertSee('"latitude":25.033', false)
            ->assertSee('"city":"New Taipei"', false)
            ->assertSee('data-composer-location', false);
        $this->patchJson(route('events.update', $eventId), ['notes' => 'Recovered after a walk.', 'occurred_at' => '16:10'])
            ->assertOk()
            ->assertJsonPath('event.id', $eventId)
            ->assertJsonStructure(['updated_time']);
        $this->assertDatabaseHas('log_blocks', ['content' => 'Recovered after a walk.', 'occurred_at' => '2026-08-15 16:10:00']);
        $this->assertDatabaseHas('task_events', ['id' => $eventId, 'occurred_at' => '2026-08-15 16:10:00']);
        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('navigator.geolocation.getCurrentPosition', $script);
        $this->assertStringContainsString('body.location_url', $script);
        $this->assertStringContainsString("noteHeading.classList.toggle('hidden', mode === 'edit')", $script);
        $this->assertStringContainsString('renderComposerEventImage(root, kind, iconData)', $script);
        $this->assertStringContainsString("image.classList.toggle('hidden', !showImage)", $script);
    }

    public function test_log_entry_media_long_text_and_recording_features_are_removed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/logs/2026-08-15')->assertOk()
            ->assertDontSee('data-composer-media-form', false)
            ->assertDontSee('data-composer-long-text-form', false)
            ->assertDontSee('data-recording-status', false)
            ->assertDontSee('Attach media and text');

        $this->assertFalse(Schema::hasTable('long_text_attachments'));
        $this->assertFalse(Route::has('attachments.store'));
        $this->assertFalse(Route::has('attachments.destroy'));
        $this->assertFalse(Route::has('long-texts.store'));
        $this->assertFalse(Route::has('openrouter.transcribe'));
        $this->assertFalse(Route::has('openrouter.images'));
        $this->assertTrue(Route::has('attachments.show'));
    }

    public function test_openrouter_chat_reply_and_cost_are_added_to_the_day(): void
    {
        Carbon::setTestNow('2026-08-16 13:20:00');
        Http::fake(function ($request) {
            $schema = data_get($request->data(), 'response_format.json_schema.name');
            if ($schema === 'total_log_intent') {
                return Http::response(['id' => 'classify-123', 'model' => 'test/model', 'choices' => [['message' => ['content' => json_encode(['intent' => 'question', 'normalized_request' => 'Summarize this day.'])]]], 'usage' => ['total_tokens' => 6, 'cost' => 0.0001]], 200);
            }

            return Http::response([
                'id' => 'gen-123', 'model' => 'test/model', 'choices' => [['message' => ['content' => 'A concise reflection.']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5, 'total_tokens' => 17, 'cost' => 0.0012],
            ], 200);
        });
        $user = User::factory()->create(['openrouter_api_key' => 'sk-test']);
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        $contextLog = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-01']);
        $contextLog->blocks()->create(['type' => 'text', 'content' => 'Month context signal', 'position' => 1, 'occurred_at' => '2026-08-01 09:00:00']);
        $this->actingAs($user)->postJson(route('openrouter.chat', $log), ['message' => 'Summarize this day.', 'model' => 'test/model'])->assertCreated()->assertJsonPath('kind', 'answer')->assertJsonPath('answer', 'A concise reflection.');
        $this->assertDatabaseHas('log_blocks', ['daily_log_id' => $log->id, 'type' => 'chat_user', 'emoji' => '💬']);
        $this->assertDatabaseHas('log_blocks', ['daily_log_id' => $log->id, 'type' => 'chat_assistant', 'content' => 'A concise reflection.']);
        $this->assertDatabaseHas('log_blocks', ['daily_log_id' => $log->id, 'type' => 'chat_assistant', 'emoji' => '🤖']);
        $this->assertDatabaseHas('api_calls', ['daily_log_id' => $log->id, 'operation' => 'chat', 'total_tokens' => 17]);
        $this->assertSame('0.00120000', ApiCall::where('operation', 'chat')->first()->cost);
        Http::assertSent(fn ($request) => ! isset($request['response_format']) && str_contains(json_encode($request['messages']), 'Month context signal'));
        Carbon::setTestNow();
    }

    public function test_smart_chat_previews_actions_and_only_executes_them_after_confirmation(): void
    {
        Carbon::setTestNow('2026-08-16 13:20:00');
        Http::fake(function ($request) {
            $schema = data_get($request->data(), 'response_format.json_schema.name');
            $content = $schema === 'total_log_intent'
                ? ['intent' => 'action', 'normalized_request' => 'Create and record a wellness event.']
                : ['actions' => [
                    ['type' => 'add_log_entry', 'date' => '2026-08-17', 'time' => '08:15', 'content' => 'Prepared for the morning mission.', 'emoji' => '🚀', 'event_name' => null, 'value' => null, 'notes' => null, 'name' => null, 'color' => null, 'options' => null, 'recurrence_type' => null, 'recurrence_days' => null, 'scheduled_times' => null, 'visible_after' => null, 'is_sticky' => null],
                    ['type' => 'create_event', 'date' => null, 'time' => null, 'content' => null, 'emoji' => '💚', 'event_name' => null, 'value' => null, 'notes' => null, 'name' => 'Wellness level', 'color' => '#4f46e5', 'options' => ['1', '2', '3'], 'recurrence_type' => 'daily', 'recurrence_days' => null, 'scheduled_times' => null, 'visible_after' => '07:30', 'is_sticky' => true],
                    ['type' => 'record_event', 'date' => '2026-08-17', 'time' => '09:30', 'content' => null, 'emoji' => null, 'event_name' => 'Wellness level', 'value' => '2', 'notes' => 'A little tense.', 'name' => null, 'color' => null, 'options' => null, 'recurrence_type' => null, 'recurrence_days' => null, 'scheduled_times' => null, 'visible_after' => null, 'is_sticky' => null],
                ]];

            return Http::response(['id' => 'smart-123', 'model' => 'test/model', 'choices' => [['message' => ['content' => json_encode($content)]]], 'usage' => ['total_tokens' => 20, 'cost' => 0.001]], 200);
        });
        $user = User::factory()->create(['openrouter_api_key' => 'sk-test']);
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-16']);

        $proposal = $this->actingAs($user)->postJson(route('openrouter.chat', $log), [
            'message' => 'Create a purple wellness event with values 1 to 3 and record 2 tomorrow at 9:30.', 'model' => 'test/model',
        ])->assertStatus(202)->assertJsonPath('kind', 'action')->assertJsonStructure(['summary', 'confirm_url']);

        $this->assertDatabaseMissing('task_definitions', ['user_id' => $user->id, 'name' => 'Wellness level']);
        $this->assertDatabaseMissing('log_blocks', ['content' => 'Prepared for the morning mission.']);

        $this->postJson($proposal->json('confirm_url'))->assertOk()->assertJsonPath('kind', 'confirmed');
        $this->assertDatabaseHas('task_definitions', ['user_id' => $user->id, 'name' => 'Wellness level', 'emoji' => '💚', 'color' => '#4f46e5', 'scheduled_times' => null, 'visible_after' => '07:30']);
        $this->assertDatabaseHas('log_blocks', ['content' => 'Prepared for the morning mission.', 'emoji' => '🚀', 'occurred_at' => '2026-08-17 08:15:00']);
        $this->assertDatabaseHas('log_blocks', ['content' => 'A little tense.', 'emoji' => '💚', 'occurred_at' => '2026-08-17 09:30:00']);
        $this->assertDatabaseHas('task_events', ['task_name' => 'Wellness level', 'selected_value' => '2', 'occurred_at' => '2026-08-17 09:30:00']);
        $this->assertDatabaseHas('chat_action_proposals', ['user_id' => $user->id, 'status' => 'confirmed']);
        Carbon::setTestNow();
    }

    public function test_api_usage_has_its_own_owned_page_and_is_removed_from_daily_log(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        ApiCall::create(['user_id' => $user->id, 'daily_log_id' => $log->id, 'operation' => 'chat', 'model' => 'test/model', 'status_code' => 200, 'total_tokens' => 42, 'cost' => 0.001]);
        ApiCall::create(['user_id' => $other->id, 'operation' => 'chat', 'model' => 'private/model', 'status_code' => 200, 'total_tokens' => 99, 'cost' => 1]);

        $this->actingAs($user)->get(route('api-usage.index'))->assertOk()
            ->assertSee('test/model')
            ->assertDontSee('private/model')
            ->assertSee(route('logs.show', '2026-08-15'));
        $this->get(route('logs.show', '2026-08-15'))->assertOk()
            ->assertDontSee('API usage ·')
            ->assertDontSee('href="'.route('api-usage.index').'"', false)
            ->assertSee('href="'.route('profile.edit').'"', false)
            ->assertSee('Account setup');
    }

    public function test_timeline_entries_expose_the_timestamp_aware_side_editor(): void
    {
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-15']);
        $log->blocks()->create(['type' => 'text', 'content' => 'Editable bridge note', 'position' => 1, 'occurred_at' => '2026-08-15 09:35:00']);
        $this->actingAs($user)->get(route('logs.show', '2026-08-15'))->assertOk()
            ->assertSee('data-timeline-edit', false)
            ->assertSee('data-timeline-time="09:35"', false)
            ->assertSee('>09:35</time>', false)
            ->assertSee('data-edit-updated=', false)
            ->assertSee('data-hide-url=', false)
            ->assertSee('data-delete-url=', false)
            ->assertSee('data-composer-entry-actions', false)
            ->assertSee('data-composer-updated', false)
            ->assertDontSee('Recorded 09:35')
            ->assertSee('data-composer-time', false)
            ->assertSee('data-composer-time-now', false)
            ->assertSee('data-composer-note-form', false)
            ->assertSee('data-autosave-status', false)
            ->assertSee('data-composer-cancel', false)
            ->assertDontSee('data-composer-media-panel', false)
            ->assertDontSee('data-composer-media-form', false)
            ->assertSee('data-day-view-fragment', false)
            ->assertSee('data-busy-label="Waiting for response"', false)
            ->assertDontSee('data-busy-label="Generating image"', false)
            ->assertSee('data-button-spinner', false);

        $this->withHeader('X-Day-View', 'main')->get(route('logs.show', '2026-08-15'))->assertOk()
            ->assertHeader('Server-Timing')
            ->assertSee('<main id="page-content">', false)
            ->assertDontSee('<html', false)
            ->assertDontSee('primary-navigation-content', false);

        $script = file_get_contents(resource_path('js/app.js'));
        $templates = file_get_contents(resource_path('views/partials/javascript-templates.blade.php'));
        $this->assertStringContainsString("'X-Day-State': 'json'", $script);
        $this->assertStringContainsString('const dayStateCache = new Map()', $script);
        $this->assertStringContainsString('function mutateDayState(mutator)', $script);
        $this->assertStringContainsString('const timelineContent = document.createDocumentFragment()', $script);
        $this->assertStringContainsString('timeline.replaceWith(nextTimeline)', $script);
        $this->assertStringContainsString('await refreshDayViewOrReload()', $script);
        $this->assertStringContainsString('const modelLoadOutcomes = new Map()', $script);
        $this->assertStringContainsString('if (modelLoadOutcomes.has(url))', $script);
        $this->assertStringContainsString('if (modelLoadRequests.has(url))', $script);
        $this->assertStringContainsString('const originalCounts = new Map()', $script);
        $this->assertStringContainsString('pendingEventCreates.set(optimisticId, eventCreatePromise)', $script);
        $this->assertStringContainsString('pendingEventId:optimisticId', $script);
        $this->assertStringContainsString('eventName:taskName', $script);
        $this->assertStringContainsString("eventSource.querySelector('[data-composer-event-name]').textContent = eventName", $script);
        $this->assertStringContainsString('function reorderTimeline(state)', $script);
        $this->assertStringContainsString('reorderTimeline(state)', $script);
        $this->assertStringContainsString('isNew:true', $script);
        $this->assertStringContainsString("const showVisibility = mode === 'edit' && !isNew", $script);
        $this->assertStringContainsString('queueBackgroundSync(`visibility:${visibilityUrl}`', $script);
        $this->assertStringNotContainsString("title:'Event tracked'", $script);
        $this->assertStringContainsString('Changes save when you close this panel.', $script);
        $this->assertStringContainsString("root.querySelector('[data-composer-time-now]')?.classList.toggle('hidden', mode !== 'edit')", $script);
        $this->assertStringContainsString('composerTimeInput.dispatchEvent', $script);
        $this->assertStringContainsString('textarea.value === form.dataset.originalContent', $script);
        $this->assertStringContainsString('list.scrollTop = index * 48', $script);
        $this->assertStringContainsString("behavior:smooth ? 'smooth' : 'auto'", $script);
        $this->assertStringContainsString('const adaptedDelta = notchedGesture', $script);
        $this->assertStringContainsString('requestAnimationFrame(frameTime =>', $script);
        $this->assertStringContainsString('Number(item.task.daily_default_count || 1)', $script);
        $this->assertStringContainsString("item.kind !== 'schedule' || item.task.event_url !== eventUrl", $script);
        $this->assertStringNotContainsString("submit.textContent = mode === 'edit' ? 'Save changes'", $script);
        $this->assertStringContainsString('style="z-index:120"', $templates);
        $this->assertStringContainsString('data-time-wheel-step="-1"', $templates);
        $this->assertStringContainsString('data-time-wheel-step="1"', $templates);
        $this->assertStringContainsString('selectIndex(values.indexOf(selected())', $script);
    }
}
