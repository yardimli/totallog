<?php

namespace Tests\Feature;

use App\Models\DailyLog;
use App\Models\TaskDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_and_recorded_entries_use_distinct_event_routes(): void
    {
        $user = User::factory()->create();
        $data = ['name' => 'Water', 'color' => '#123456', 'recurrence_type' => 'daily'];
        $this->actingAs($user)->get('/tasks')->assertRedirect('/events');
        $this->get('/events')->assertOk();
        $response = $this->postJson('/events', $data)->assertCreated();
        $task = TaskDefinition::findOrFail($response->json('event.id'));
        $this->assertSame('/events/'.$task->id, route('tasks.update', $task, false));
        $this->patchJson('/events/reorder', ['event_ids' => [$task->id]])->assertOk();
        $this->patchJson('/events/'.$task->id, array_merge($data, ['name' => 'Drink water']))->assertOk();
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-10-26']);
        $entry = $this->postJson('/logs/'.$log->id.'/events/'.$task->id.'/entries', ['occurred_at' => '19:05'])->assertCreated();
        $eventId = $entry->json('event.id');
        $this->get('/events/entries/'.$eventId.'/edit')->assertOk();
        $this->patchJson('/events/entries/'.$eventId, ['notes' => 'A glass', 'occurred_at' => '19:10'])->assertOk();
        $this->assertSame('Drink water', $task->fresh()->name);
        $this->assertDatabaseHas('task_events', ['id' => $eventId, 'occurred_at' => '2026-10-26 19:10:00']);
        $this->delete('/events/'.$task->id)->assertRedirect('/events');
        $this->assertDatabaseMissing('task_definitions', ['id' => $task->id]);
    }

    public function test_events_dropdown_has_setup_link_even_when_no_events_are_scheduled(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/logs/2026-10-26')->assertOk();
        preg_match('/<details[^>]*data-events-menu>.*?<\/details>/s', $response->getContent(), $matches);
        $menu = $matches[0] ?? '';
        $this->assertStringContainsString('data-event-setup-link>Event setup</a>', $menu);
        $this->assertStringContainsString('href="'.route('tasks.index').'"', $menu);
        $this->assertStringNotContainsString('class="relative z-50 hidden"', $menu);
    }
}
