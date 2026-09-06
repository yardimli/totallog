<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Notebook;
use App\Models\NoteTag;
use App\Models\NoteTask;
use App\Models\NoteVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotesFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_notes_workspace_creates_a_default_notebook_and_links_to_todays_log_from_navigation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('notes.index'))->assertOk()
            ->assertSee('id="notes-workspace"', false)
            ->assertSee('New note')
            ->assertDontSee('id="notes-page-heading"', false)
            ->assertSee('aria-label="Open today\'s log"', false)
            ->assertSee('href="'.route('logs.today').'"', false)
            ->assertDontSee('aria-label="Open calendar"', false)
            ->assertDontSee('aria-label="Open notes"', false);

        $this->assertDatabaseHas('notebooks', ['user_id' => $user->id, 'name' => 'Notes', 'is_default' => true]);
        $this->assertDatabaseHas('note_user_settings', ['user_id' => $user->id]);
    }

    public function test_user_can_create_edit_and_delete_an_owned_plain_text_note(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('notes.index'));
        $notebook = Notebook::where('user_id', $user->id)->firstOrFail();

        $this->post(route('notes.store'), [
            'notebook_id' => $notebook->id,
            'title' => 'First contact',
            'content' => "A durable note.\n<script>alert('no')</script>",
        ])->assertRedirect();

        $note = Note::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('text', $note->content_format);
        $this->assertCount(1, $note->versions);
        $this->get(route('notes.show', $note))->assertOk()
            ->assertSee('First contact')
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee("<script>alert('no')</script>", false)
            ->assertSee('id="note-delete-form"', false)
            ->assertSee('data-confirm-title="Move this note to Trash?"', false);

        $this->patch(route('notes.update', $note), [
            'notebook_id' => $notebook->id,
            'title' => 'First contact revised',
            'content' => 'Updated safely.',
        ])->assertRedirect(route('notes.show', $note));
        $this->assertDatabaseHas('notes', ['id' => $note->id, 'title' => 'First contact revised', 'plain_text' => 'Updated safely.']);
        $this->assertDatabaseCount('note_versions', 2);

        $this->delete(route('notes.destroy', $note))->assertRedirect(route('notes.index'));
        $this->assertSoftDeleted('notes', ['id' => $note->id]);
    }

    public function test_notes_and_notebooks_cannot_be_used_across_accounts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerNotebook = Notebook::create(['user_id' => $owner->id, 'name' => 'Private']);
        $note = Note::create(['user_id' => $owner->id, 'notebook_id' => $ownerNotebook->id, 'title' => 'Private note']);

        $this->actingAs($other)->get(route('notes.show', $note))->assertForbidden();
        $this->patch(route('notes.update', $note), ['notebook_id' => $ownerNotebook->id, 'title' => 'Stolen'])->assertForbidden();
        $this->postJson(route('notes.ai', $note), ['prompt' => 'Read it', 'model' => 'test/model', 'mode' => 'append'])->assertForbidden();
        $this->post(route('notes.store'), ['notebook_id' => $ownerNotebook->id, 'title' => 'Cross-account'])->assertSessionHasErrors('notebook_id');
    }

    public function test_user_can_create_a_colored_notebook_and_rich_colored_note(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('notebooks.store'), ['name' => 'Research', 'color' => '#0ea5e9'])->assertRedirect();
        $notebook = Notebook::where('user_id', $user->id)->where('name', 'Research')->firstOrFail();
        $this->assertSame('#0ea5e9', $notebook->color);

        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Formatted note']]]]];
        $this->post(route('notes.store'), [
            'notebook_id' => $notebook->id,
            'title' => 'Rich research',
            'content' => '<p><strong>Formatted</strong> note</p>',
            'content_json' => json_encode($document),
            'plain_text' => 'Formatted note',
            'color' => '#f97316',
        ])->assertRedirect();

        $note = Note::where('title', 'Rich research')->firstOrFail();
        $this->assertSame('tiptap', $note->content_format);
        $this->assertSame('#f97316', $note->color);
        $this->assertSame($document, $note->content_json);
        $this->get(route('notes.show', $note))->assertOk()
            ->assertSee('data-note-rich-editor', false)
            ->assertSee('data-note-command="table"', false)
            ->assertSee('data-note-command="math"', false)
            ->assertSee('data-note-ai-open', false)
            ->assertSee('"auto_title_delay_ms":8000', false)
            ->assertSee('Changes save automatically')
            ->assertDontSee('>Create note<', false)
            ->assertSee('data-notebook-dialog', false)
            ->assertSee('overflow-hidden', false);
    }

    public function test_clicking_a_notebook_filters_notes_and_preserves_the_filter(): void
    {
        $user = User::factory()->create();
        $research = Notebook::create(['user_id' => $user->id, 'name' => 'Research', 'color' => '#0ea5e9']);
        $journal = Notebook::create(['user_id' => $user->id, 'name' => 'Journal', 'color' => '#f97316']);
        $researchNote = Note::create(['user_id' => $user->id, 'notebook_id' => $research->id, 'title' => 'Research result']);
        Note::create(['user_id' => $user->id, 'notebook_id' => $journal->id, 'title' => 'Journal entry']);

        $response = $this->actingAs($user)->get(route('notes.index', ['notebook' => $research->id]))->assertOk()
            ->assertSee('Research result')
            ->assertDontSee('Journal entry')
            ->assertSee('aria-current="page"', false)
            ->assertSee(route('notes.create', ['notebook' => $research->id]), false)
            ->assertSee(route('notes.show', ['note' => $researchNote, 'notebook' => $research->id]), false);

        $response->assertSee('>All notes</span><span class="text-xs text-slate-400">2</span>', false);
    }

    public function test_user_can_create_assign_filter_and_delete_tags(): void
    {
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'name' => 'Tagged']);
        $this->actingAs($user)->post(route('note-tags.store'), ['name' => 'Research', 'color' => '#0ea5e9'])->assertRedirect();
        $tag = NoteTag::where('user_id', $user->id)->firstOrFail();

        $this->post(route('notes.store'), [
            'notebook_id' => $notebook->id,
            'title' => 'Tagged result',
            'content' => 'Tagged content',
            'tag_ids' => [$tag->id],
        ])->assertRedirect();
        $note = Note::where('title', 'Tagged result')->firstOrFail();
        $this->assertTrue($note->tags()->whereKey($tag->id)->exists());

        $this->get(route('notes.index', ['tag' => $tag->id]))->assertOk()->assertSee('Tagged result');
        $this->get(route('notes.index', ['view' => 'tags']))->assertOk()->assertSee('Create tag')->assertSee('Research')
            ->assertSee('data-confirm-title="Delete this tag?"', false);
        $this->delete(route('note-tags.destroy', $tag))->assertRedirect(route('notes.index', ['view' => 'tags']));
        $this->assertDatabaseMissing('note_tags', ['id' => $tag->id]);
    }

    public function test_user_can_manage_lightweight_note_tasks(): void
    {
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'name' => 'Tasks']);
        $note = Note::create(['user_id' => $user->id, 'notebook_id' => $notebook->id, 'title' => 'Launch plan']);

        $this->actingAs($user)->post(route('note-tasks.store'), ['title' => 'Review the launch', 'note_id' => $note->id])->assertRedirect();
        $task = NoteTask::where('user_id', $user->id)->firstOrFail();
        $this->get(route('notes.index', ['view' => 'tasks']))->assertOk()->assertSee('Review the launch')->assertSee('Launch plan')
            ->assertSee('data-confirm-title="Delete this task?"', false);
        $this->patch(route('note-tasks.update', $task), ['is_completed' => true])->assertRedirect();
        $this->assertTrue($task->fresh()->is_completed);
        $this->assertNotNull($task->fresh()->completed_at);
        $this->delete(route('note-tasks.destroy', $task))->assertRedirect();
        $this->assertDatabaseMissing('note_tasks', ['id' => $task->id]);
    }

    public function test_trash_supports_restore_and_permanent_delete_without_cross_user_access(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'name' => 'Trash']);
        $note = Note::create(['user_id' => $user->id, 'notebook_id' => $notebook->id, 'title' => 'Recover me']);
        $note->delete();

        $this->actingAs($other)->post(route('notes.restore', $note->id))->assertNotFound();
        $this->actingAs($user)->get(route('notes.index', ['view' => 'trash']))->assertOk()
            ->assertSee('Recover me')->assertSee('Delete forever')
            ->assertSee('data-confirm-title="Permanently delete this note?"', false)
            ->assertDontSee('onsubmit="return confirm(', false);
        $this->post(route('notes.restore', $note->id))->assertRedirect(route('notes.show', $note->id));
        $this->assertNotSoftDeleted('notes', ['id' => $note->id]);

        $note->fresh()->delete();
        $this->delete(route('notes.force-destroy', $note->id))->assertRedirect(route('notes.index', ['view' => 'trash']));
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }

    public function test_autosave_creates_an_untitled_note_and_only_versions_real_changes(): void
    {
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'name' => 'Autosave']);
        $payload = [
            'notebook_id' => $notebook->id,
            'title' => '',
            'content' => '<p>First words</p>',
            'plain_text' => 'First words',
            'content_json' => json_encode(['type' => 'doc', 'content' => []]),
            'color' => '#6366f1',
        ];

        $response = $this->actingAs($user)->postJson(route('notes.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('title', 'Untitled');
        $note = Note::findOrFail($response->json('note_id'));
        $this->assertSame('Untitled', $note->title);
        $this->assertCount(1, $note->versions);

        $this->patchJson(route('notes.update', $note), $payload)->assertOk()->assertJsonPath('changed', false);
        $this->assertDatabaseCount('note_versions', 1);

        $payload['plain_text'] = 'First words changed';
        $payload['content'] = '<p>First words changed</p>';
        $this->patchJson(route('notes.update', $note), $payload)->assertOk()->assertJsonPath('changed', true);
        $this->assertDatabaseCount('note_versions', 2);
    }

    public function test_note_ai_receives_existing_note_and_remembers_the_model(): void
    {
        Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'model' => 'test/writer',
            'choices' => [['message' => ['content' => 'A generated continuation.']]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 4, 'total_tokens' => 24],
        ])]);
        $user = User::factory()->create(['openrouter_api_key' => 'sk-test']);
        $notebook = Notebook::create(['user_id' => $user->id, 'name' => 'AI']);
        $note = Note::create(['user_id' => $user->id, 'notebook_id' => $notebook->id, 'title' => 'Existing title', 'plain_text' => 'Existing note body']);

        $this->actingAs($user)->postJson(route('notes.ai', $note), [
            'prompt' => 'Write the next paragraph.',
            'model' => 'test/writer',
            'mode' => 'append',
        ])->assertOk()->assertJsonPath('text', 'A generated continuation.')->assertJsonPath('mode', 'append');
        $this->postJson(route('notes.ai', $note), [
            'prompt' => 'Create a concise title.',
            'model' => 'test/writer',
            'mode' => 'title',
        ])->assertOk()->assertJsonPath('mode', 'title');

        $this->assertSame('test/writer', $user->fresh()->default_chat_model);
        $this->assertDatabaseHas('api_calls', ['user_id' => $user->id, 'operation' => 'note_ai', 'model' => 'test/writer']);
        Http::assertSent(fn ($request) => str_contains((string) $request['messages'][1]['content'], 'Existing note body')
            && str_contains((string) $request['messages'][1]['content'], 'Write the next paragraph.'));
    }

    public function test_user_can_preserve_history_or_destructively_undo_when_restoring(): void
    {
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'name' => 'History']);
        $note = Note::create(['user_id' => $user->id, 'notebook_id' => $notebook->id, 'title' => 'Version 3', 'content' => '<p>Three</p>', 'plain_text' => 'Three', 'content_format' => 'tiptap', 'color' => '#ef4444']);
        foreach ([1 => 'One', 2 => 'Two', 3 => 'Three'] as $number => $content) {
            $note->versions()->create(['created_by_user_id' => $user->id, 'version_number' => $number, 'title' => "Version {$number}", 'content' => "<p>{$content}</p>", 'plain_text' => $content, 'content_format' => 'tiptap', 'color' => '#ef4444', 'change_source' => 'manual_save']);
        }
        $first = $note->versions()->where('version_number', 1)->firstOrFail();
        $second = $note->versions()->where('version_number', 2)->firstOrFail();

        $this->actingAs($user)->get(route('notes.show', $note))->assertOk()
            ->assertSee('data-version-dialog', false)
            ->assertSee('data-version-diff-output', false)
            ->assertSee('Add as latest')
            ->assertSee('Undo to this version');

        $this->post(route('notes.versions.restore', [$note, $first]), ['mode' => 'preserve'])->assertRedirect(route('notes.show', $note));
        $this->assertDatabaseCount('note_versions', 4);
        $this->assertDatabaseHas('note_versions', ['note_id' => $note->id, 'version_number' => 4, 'plain_text' => 'One', 'change_source' => 'restored_copy']);
        $this->assertSame('One', $note->fresh()->plain_text);

        $this->post(route('notes.versions.restore', [$note, $second]), ['mode' => 'undo'])->assertRedirect(route('notes.show', $note));
        $this->assertSame('Two', $note->fresh()->plain_text);
        $this->assertSame([1, 2], NoteVersion::where('note_id', $note->id)->orderBy('version_number')->pluck('version_number')->all());
    }

    public function test_user_cannot_restore_a_version_from_another_note(): void
    {
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'name' => 'Private']);
        $first = Note::create(['user_id' => $user->id, 'notebook_id' => $notebook->id]);
        $second = Note::create(['user_id' => $user->id, 'notebook_id' => $notebook->id]);
        $version = NoteVersion::create(['note_id' => $second->id, 'created_by_user_id' => $user->id, 'version_number' => 1, 'change_source' => 'created']);

        $this->actingAs($user)->post(route('notes.versions.restore', [$first, $version]), ['mode' => 'preserve'])->assertNotFound();
    }
}
