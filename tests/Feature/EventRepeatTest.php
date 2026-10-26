<?php

namespace Tests\Feature;

use App\Models\DailyLog;
use App\Models\TaskDefinition;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventRepeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeat_interval_is_saved_edited_and_anchored_to_first_day(): void
    {
        $user = User::factory()->create();
        $data = ['name' => 'Water plants', 'color' => '#4f46e5', 'recurrence_type' => 'daily', 'repeat_interval' => 2, 'repeat_start_date' => '2026-10-30'];
        $this->actingAs($user)->postJson(route('tasks.store'), $data)->assertCreated();
        $task = $user->taskDefinitions()->firstOrFail();
        foreach (['2026-10-29' => false, '2026-10-30' => true, '2026-10-31' => false, '2026-11-01' => true] as $date => $expected) {
            $this->assertSame($expected, $task->occursOn(Carbon::parse($date)));
        }
        $this->get(route('tasks.index'))->assertOk()->assertSee('Every 2 days')->assertSee('First occurrence');
        $this->patchJson(route('tasks.update', $task), array_merge($data, ['repeat_interval' => 3]))->assertOk();
        $this->assertTrue($task->fresh()->occursOn(Carbon::parse('2026-11-02')));
        $this->postJson(route('tasks.store'), array_merge($data, ['repeat_start_date' => null]))->assertUnprocessable()->assertJsonValidationErrors('repeat_start_date');
        $this->postJson(route('tasks.store'), array_merge($data, ['repeat_interval' => 0]))->assertUnprocessable()->assertJsonValidationErrors('repeat_interval');
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-10-31']);
        $this->postJson(route('events.store', [$log, $task]))->assertUnprocessable();
    }

    public function test_untimed_event_stays_at_bottom_until_daily_target_is_reached(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 14:37:00'));
        $user = User::factory()->create();
        $task = $user->taskDefinitions()->create(['name' => 'Drink water', 'is_sticky' => false, 'daily_default_count' => 2]);
        $url = route('logs.show', '2026-10-25');
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'id="daily-log-sticky-events"'), strpos($html, 'id="timeline"'));
        $log = DailyLog::where('user_id', $user->id)->firstOrFail();
        $this->withHeader('X-Day-State', 'json')->get($url)->assertJsonCount(1, 'sticky_events');
        $this->postJson(route('events.store', [$log, $task]))->assertCreated()->assertJsonPath('time', '14:37')->assertJsonPath('count', 1);
        $this->get($url)->assertJsonCount(1, 'sticky_events')->assertJsonPath('sticky_events.0.count', 1);
        $this->postJson(route('events.store', [$log, $task]))->assertCreated()->assertJsonPath('count', 2);
        $this->get($url)->assertJsonCount(0, 'sticky_events');
        $this->get(route('logs.show', '2026-10-26'))->assertJsonCount(1, 'sticky_events')->assertJsonPath('sticky_events.0.count', 0);
        $this->travelBack();
    }
}
