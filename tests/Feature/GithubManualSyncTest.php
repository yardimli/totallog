<?php

namespace Tests\Feature;

use App\Models\DailyLog;
use App\Models\LogBlock;
use App\Models\Sensor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubManualSyncTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $sha, string $date): array
    {
        return ['sha' => $sha, 'repository' => ['name' => 'project', 'full_name' => 'octocat/project'],
            'html_url' => 'https://github.com/octocat/project/commit/'.$sha,
            'commit' => ['message' => 'Commit '.$sha, 'author' => ['date' => $date]]];
    }

    public function test_sync_fetches_latest_commits_inserts_missing_days_and_reports_retries(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 19:00:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $sensor = Sensor::create(['user_id' => $user->id, 'type' => Sensor::GITHUB, 'username' => 'octocat',
            'token' => 'secret', 'enabled' => false, 'last_checked_at' => now(), 'settings' => ['keep' => true]]);
        $log = DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-10-13']);
        $log->blocks()->create(['type' => 'sensor_github', 'content' => 'project', 'occurred_at' => '2026-10-13 10:00:00',
            'metadata' => ['github_sha' => 'existing']]);
        $sensor->daySyncs()->create(['log_date' => '2026-10-13', 'status' => 'complete', 'item_count' => 1]);
        Http::fake(['api.github.com/search/commits*' => Http::response(['total_count' => 3, 'items' => [
            $this->item('morning', '2026-10-13T19:00:00Z'),
            $this->item('late-push', '2026-10-13T03:00:00Z'),
            $this->item('existing', '2026-10-13T02:00:00Z'),
        ]])]);

        $this->actingAs($user)->postJson(route('sensors.github.sync'))->assertOk()
            ->assertJsonPath('report.commits.0.status', 'new')
            ->assertJsonPath('report.commits.1.status', 'new')
            ->assertJsonPath('report.commits.2.status', 'already_inserted');
        Http::assertSent(fn (Request $request) => $request['q'] === 'author:octocat'
            && $request['sort'] === 'author-date' && $request['order'] === 'desc' && $request['per_page'] === 20);
        $today = DailyLog::where('user_id', $user->id)->whereDate('log_date', '2026-10-14')->firstOrFail();
        $this->assertSame('03:00', $today->blocks()->firstOrFail()->occurred_at->format('H:i'));
        $this->assertSame(3, LogBlock::count());
        $this->assertTrue($sensor->fresh()->settings['keep']);
        $this->assertFalse($sensor->fresh()->enabled);
        // Fetching the latest 20 must not mark an entire additional day as fully synced.
        $this->assertSame(1, $sensor->daySyncs()->count());
        $this->get(route('sensors.index'))->assertOk()->assertSee('Latest 20 commits')
            ->assertSee('New')->assertSee('Already inserted')->assertSee('Oct 14, 2026');

        $response = $this->postJson(route('sensors.github.sync'))->assertOk();
        $this->assertSame(['already_inserted'], collect($response->json('report.commits'))->pluck('status')->unique()->values()->all());
        $this->assertSame(3, LogBlock::count());
    }

    public function test_sync_is_user_scoped_and_limited_to_twenty_results(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Sensor::create(['user_id' => $owner->id, 'type' => Sensor::GITHUB, 'username' => 'octocat', 'token' => 'secret', 'enabled' => true]);
        $log = DailyLog::create(['user_id' => $other->id, 'log_date' => '2026-10-14']);
        $log->blocks()->create(['type' => 'sensor_github', 'content' => 'project', 'metadata' => ['github_sha' => 'sha-1']]);
        $items = collect(range(1, 21))->map(fn ($i) => $this->item('sha-'.$i, '2026-10-14T10:00:00Z'))->all();
        Http::fake(['api.github.com/search/commits*' => Http::response(['total_count' => 21, 'items' => $items])]);
        $this->actingAs($other)->postJson(route('sensors.github.sync'))->assertNotFound();
        Http::assertNothingSent();
        $this->actingAs($owner)->postJson(route('sensors.github.sync'))->assertOk()
            ->assertJsonCount(20, 'report.commits')->assertJsonPath('report.commits.0.status', 'new');
        $this->assertSame(1, $log->blocks()->count());
    }

    public function test_failed_sync_preserves_previous_report_and_inserts_nothing(): void
    {
        $user = User::factory()->create();
        $previous = ['synced_at' => now()->subDay()->toIso8601String(), 'commits' => []];
        $sensor = Sensor::create(['user_id' => $user->id, 'type' => Sensor::GITHUB, 'username' => 'octocat', 'token' => 'secret',
            'enabled' => true, 'settings' => ['last_github_sync' => $previous]]);
        Http::fake(['api.github.com/search/commits*' => Http::response([], 403)]);
        $this->actingAs($user)->postJson(route('sensors.github.sync'))->assertUnprocessable();
        $this->assertSame($previous, $sensor->fresh()->settings['last_github_sync']);
        $this->assertNotNull($sensor->fresh()->last_error);
        $this->assertSame(0, LogBlock::count());
    }
}
