<?php

namespace Tests\Feature;

use App\Models\DailyLog;
use App\Models\LogBlock;
use App\Models\TaskDefinition;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DayTimePickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_end_of_day_entries_save_and_reopen_at_24_without_becoming_start_of_day(): void
    {
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-10-01']);
        $response = $this->actingAs($user)->postJson(route('blocks.store', $log), [
            'type' => 'text', 'content' => 'Midnight at the end', 'occurred_at' => '24:00',
        ])->assertCreated();
        $block = LogBlock::findOrFail($response->json('block.id'));
        $this->assertSame('2026-10-02 00:00:00', $block->occurred_at->format('Y-m-d H:i:s'));
        $this->get(route('logs.show', '2026-10-01'))->assertOk()
            ->assertSee('data-timeline-time="24:00"', false)
            ->assertSee('>24:00</time>', false);
        $this->patchJson(route('blocks.update', $block), ['occurred_at' => '00:00'])->assertOk();
        $this->assertSame('2026-10-01 00:00:00', $block->fresh()->occurred_at->format('Y-m-d H:i:s'));
        $this->patchJson(route('blocks.update', $block), ['occurred_at' => '24:05'])
            ->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
    }

    public function test_event_and_schedule_pickers_accept_only_the_exact_end_of_day_boundary(): void
    {
        $user = User::factory()->create();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-10-01']);
        $this->actingAs($user)->post(route('tasks.store'), [
            'name' => 'End of day', 'color' => '#123456', 'recurrence_type' => 'daily',
            'is_sticky' => true, 'scheduled_times' => ['24:00'], 'visible_after' => '24:00',
        ])->assertSessionHasNoErrors();
        $task = TaskDefinition::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['24:00'], $task->scheduled_times);
        $response = $this->postJson(route('events.store', [$log, $task]), ['scheduled_time' => '24:00'])->assertCreated();
        $event = TaskEvent::findOrFail($response->json('event.id'));
        $this->patchJson(route('events.update', $event), ['occurred_at' => '24:00'])->assertOk();
        $this->assertSame('2026-10-02 00:00:00', $event->fresh()->occurred_at->format('Y-m-d H:i:s'));
        $this->get(route('events.edit', $event))->assertOk()->assertSee('value="24:00"', false);
        $user->update(['time_format' => '12']);
        $this->get(route('logs.show', '2026-10-01'))->assertOk()->assertSee('>12:00 AM</time>', false);
        $this->patchJson(route('events.update', $event), ['occurred_at' => '25:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
    }
}
