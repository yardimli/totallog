<?php

namespace Tests\Feature;

use App\Models\DailyLog;
use App\Models\TaskEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventCreationTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_click_time_survives_reload_without_a_note_when_server_uses_utc(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-10-26 11:05:00', 'UTC'));
        try {
            $user = User::factory()->create();
            $task = $user->taskDefinitions()->create(['name' => 'Medication', 'is_sticky' => true, 'scheduled_times' => ['19:00']]);
            $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-10-26']);
            $response = $this->actingAs($user)->postJson(route('events.store', [$log, $task]), [
                'scheduled_time' => '19:00', 'occurred_at' => '19:05',
            ])->assertCreated()->assertJsonPath('time', '19:05');
            $event = TaskEvent::findOrFail($response->json('event.id'));
            $this->assertSame('2026-10-26 19:05', $event->occurred_at->format('Y-m-d H:i'));
            $this->assertSame('2026-10-26 19:05', $event->block->occurred_at->format('Y-m-d H:i'));
            $this->assertNull($event->block->content);
            $state = $this->withHeader('X-Day-State', 'json')->get(route('logs.show', '2026-10-26'))->assertOk()->json();
            $entry = collect($state['timeline'])->firstWhere('kind', 'block');
            $this->assertSame('19:05', $entry['time']);

            // Recording on a different log date must preserve that chosen date,
            // even when the browser is already on the next calendar day.
            $this->postJson(route('events.store', [$log, $task]), ['occurred_at' => '00:05'])->assertCreated()->assertJsonPath('time', '00:05');
            $this->assertDatabaseHas('task_events', ['daily_log_id' => $log->id, 'occurred_at' => '2026-10-26 00:05:00']);
            $this->postJson(route('events.store', [$log, $task]), ['occurred_at' => '25:05'])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
            // Older callers that omit the clock still work.
            $this->postJson(route('events.store', [$log, $task]))->assertCreated()->assertJsonPath('time', '11:05');
        } finally {
            Carbon::setTestNow();
            date_default_timezone_set($timezone);
        }
    }
}
