<?php

namespace Tests\Feature;

use App\Models\DailyLog;
use App\Models\Goal;
use App\Models\TaskDefinition;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_setup_backfills_event_and_github_progress_and_shows_historical_calendar_bubble(): void
    {
        $user = User::factory()->create();
        $task = TaskDefinition::create(['user_id' => $user->id, 'name' => 'Build', 'recurrence_type' => 'daily']);
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-08-12']);
        foreach ([9, 10] as $hour) {
            $block = $log->blocks()->create(['type' => 'event', 'occurred_at' => "2026-08-12 {$hour}:00:00"]);
            TaskEvent::create(['daily_log_id' => $log->id, 'task_definition_id' => $task->id, 'log_block_id' => $block->id, 'task_name' => 'Build', 'occurred_at' => "2026-08-12 {$hour}:00:00"]);
        }
        $log->blocks()->create(['type' => 'sensor_github', 'content' => 'totallog', 'occurred_at' => '2026-08-12 11:00:00', 'metadata' => ['commits' => [[
            'sha' => 'goal-commit', 'project' => 'totallog', 'repository' => 'owner/totallog', 'message' => 'Ship goals', 'occurred_at' => '2026-08-12T11:00:00+08:00',
        ]]]]);

        $this->actingAs($user)->post(route('goals.store'), [
            'name' => 'Weekly shipping', 'emoji' => '🚀', 'color' => '#4f46e5', 'target_points' => 5,
            'period' => 'weekly', 'start_date' => '2026-08-01', 'task_definition_id' => $task->id,
            'github_projects_text' => 'owner/totallog', 'manual_enabled' => '1',
        ])->assertRedirect(route('goals.index'));

        $goal = Goal::firstOrFail();
        $this->assertSame(3, $goal->entries()->count());
        $this->assertSame('totallog', $goal->sources()->where('type', 'github')->value('github_project'));
        $goalSetup = $this->get(route('goals.index'))->assertOk()
            ->assertSee('data-goal-project-picker', false)->assertSee('data-goal-project-filter', false)
            ->assertSee('data-goal-project-selected', false)->assertSee('data-goal-project-inputs', false)
            ->assertDontSee('data-goal-project-add="owner/totallog"', false);
        $this->assertSame(1, substr_count($goalSetup->getContent(), 'data-goal-project-add="totallog"'));
        $this->get(route('calendar', '2026-08-12').'?view=week')->assertOk()
            ->assertSee('data-calendar-goal="'.$goal->id.'"', false)->assertSee('3/5 points')->assertSee('Weekly shipping');
        $this->get(route('logs.show', '2026-08-12'))->assertOk()
            ->assertSee('data-day-goal="'.$goal->id.'"', false)->assertSee('3/5 points')->assertSee('Weekly shipping');
        $this->withHeader('X-Day-State', 'json')->get(route('logs.show', '2026-08-12'))->assertOk()
            ->assertJsonPath('goals.0.id', $goal->id)->assertJsonPath('goals.0.points', 3)->assertJsonPath('goals.0.target', 5);
        $this->get(route('goals.show', ['goal' => $goal, 'date' => '2026-08-12']))->assertOk()
            ->assertSee('3 <span class="text-lg', false)->assertSee('Ship goals')->assertSee('Add progress');
    }

    public function test_manual_points_reset_by_week_and_preserve_old_periods(): void
    {
        $user = User::factory()->create(['week_starts_on' => 1]);
        $goal = Goal::create(['user_id' => $user->id, 'name' => 'Practice', 'target_points' => 5, 'period' => 'weekly', 'manual_enabled' => true]);

        $this->actingAs($user)->post(route('goals.entries.store', $goal), ['points' => 3, 'note' => 'First week', 'occurred_on' => '2026-08-12'])->assertRedirect();
        $this->post(route('goals.entries.store', $goal), ['points' => 2, 'note' => 'Second week', 'occurred_on' => '2026-08-19'])->assertRedirect();

        $this->get(route('goals.show', ['goal' => $goal, 'date' => '2026-08-12']))->assertOk()->assertSee('3 <span class="text-lg', false)->assertSee('First week')->assertDontSee('Second week');
        $this->get(route('goals.show', ['goal' => $goal, 'date' => '2026-08-19']))->assertOk()->assertSee('2 <span class="text-lg', false)->assertSee('Second week')->assertDontSee('First week');
    }

    public function test_manual_goal_progress_is_also_a_linked_day_event(): void
    {
        $user = User::factory()->create();
        $goal = Goal::create([
            'user_id' => $user->id,
            'name' => 'Write the chapter',
            'emoji' => '✍️',
            'target_points' => 5,
            'period' => 'daily',
            'manual_enabled' => true,
        ]);

        $this->actingAs($user)->post(route('goals.entries.store', $goal), [
            'points' => 2,
            'note' => 'Finished the opening scene',
            'occurred_on' => '2026-08-12',
        ])->assertRedirect();

        $entry = $goal->entries()->sole();
        $event = TaskEvent::whereNull('task_definition_id')->sole();
        $this->assertSame('Write the chapter', $event->task_name);
        $this->assertSame('+2 points', $event->selected_value);
        $this->assertSame($entry->id, data_get($event->block->metadata, 'goal_entry_id'));
        $this->assertSame('✍️', $event->block->emoji);
        $this->assertSame('Finished the opening scene', $event->block->content);

        $this->get(route('logs.show', '2026-08-12'))->assertOk()
            ->assertSee('Write the chapter')
            ->assertSee('+2 points')
            ->assertSee('Finished the opening scene')
            ->assertSee('✍️');

        $this->patchJson(route('events.update', $event), [
            'notes' => 'Revised the opening scene',
            'occurred_at' => '14:30',
        ])->assertOk();
        $this->assertSame('Revised the opening scene', $entry->fresh()->note);
        $this->assertSame('14:30', $entry->fresh()->occurred_at->format('H:i'));

        $this->deleteJson(route('blocks.destroy', $event->log_block_id))->assertOk();
        $this->assertDatabaseMissing('goal_entries', ['id' => $entry->id]);
    }

    public function test_daily_goal_strip_is_single_row_draggable_and_orders_least_recent_activity_first(): void
    {
        $user = User::factory()->create();
        $recent = Goal::create(['user_id' => $user->id, 'name' => 'Recent goal', 'target_points' => 5, 'period' => 'daily']);
        $older = Goal::create(['user_id' => $user->id, 'name' => 'Older goal', 'target_points' => 5, 'period' => 'daily']);
        $inactive = Goal::create(['user_id' => $user->id, 'name' => 'Inactive goal', 'target_points' => 5, 'period' => 'daily']);
        $recent->entries()->create(['occurred_at' => '2026-08-12 15:00:00', 'points' => 1]);
        $older->entries()->create(['occurred_at' => '2026-08-12 09:00:00', 'points' => 1]);

        $response = $this->actingAs($user)->get(route('logs.show', '2026-08-12'))->assertOk()
            ->assertSee('data-horizontal-drag', false)
            ->assertSee('daily-log-goal-strip', false)
            ->assertSee('touch-pan-y select-none flex-nowrap', false)
            ->assertSee('overflow-x-auto overflow-y-hidden', false)
            ->assertSee('draggable="false"', false);
        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'Inactive goal') < strpos($content, 'Older goal'));
        $this->assertTrue(strpos($content, 'Older goal') < strpos($content, 'Recent goal'));

        $this->withHeader('X-Day-State', 'json')->get(route('logs.show', '2026-08-12'))->assertOk()
            ->assertJsonPath('goals.0.id', $inactive->id)
            ->assertJsonPath('goals.1.id', $older->id)
            ->assertJsonPath('goals.2.id', $recent->id);

        $this->get(route('calendar', '2026-08-12'))->assertOk()
            ->assertSee('id="calendar-goals" class="horizontal-drag-strip flex touch-pan-y select-none flex-nowrap', false)
            ->assertSee('data-horizontal-drag', false)
            ->assertSee('draggable="false"', false);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('function initializeHorizontalDrag()', $script);
        $this->assertStringContainsString('section.setPointerCapture?.(event.pointerId)', $script);
        $this->assertStringContainsString("section.addEventListener('pointermove'", $script);
        $this->assertStringContainsString('if (!suppressClick) return;', $script);
        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.horizontal-drag-strip::-webkit-scrollbar', $styles);
        $this->assertStringContainsString('scrollbar-width: none', $styles);
    }

    public function test_completed_one_time_goal_disappears_after_completion_but_remains_in_the_past(): void
    {
        $user = User::factory()->create();
        $goal = Goal::create(['user_id' => $user->id, 'name' => 'Launch', 'target_points' => 2, 'period' => 'none', 'manual_enabled' => true]);
        $this->actingAs($user)->post(route('goals.entries.store', $goal), ['points' => 2, 'note' => 'Done', 'occurred_on' => '2026-08-12'])->assertRedirect();
        $this->assertNotNull($goal->fresh()->completed_at);
        $this->get(route('calendar', '2026-08-12'))->assertOk()->assertSee('Launch');
        $this->get(route('calendar', '2026-08-13'))->assertOk()->assertDontSee('data-calendar-goal="'.$goal->id.'"', false);
    }

    public function test_goals_are_owned_and_setup_uses_a_right_side_editor(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $goal = Goal::create(['user_id' => $owner->id, 'name' => 'Private goal', 'target_points' => 1, 'period' => 'daily']);
        $this->actingAs($other)->get(route('goals.show', $goal))->assertForbidden();
        $this->patch(route('goals.update', $goal), ['name' => 'Stolen'])->assertForbidden();
        $this->actingAs($owner)->get(route('goals.index'))->assertOk()
            ->assertSee('data-overlay="goal-definition"', false)->assertSee('data-overlay-side="right"', false)
            ->assertSee('data-goal-open=', false)->assertSee('Private goal')
            ->assertSee('data-goal-delete-form data-confirm-delete', false)
            ->assertSee('data-confirm-title="Delete this goal?"', false);
    }

    public function test_goal_accepts_selected_project_names_from_the_picker(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('goals.store'), [
            'name' => 'Selected projects', 'color' => '#4f46e5', 'target_points' => 5, 'period' => 'weekly',
            'github_projects' => ['owner/alpha', 'beta'],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing(['alpha', 'beta'], Goal::firstOrFail()->sources()->pluck('github_project')->all());
    }
}
