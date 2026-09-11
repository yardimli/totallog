<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MobileBrowserLoginTest extends TestCase
{
    use RefreshDatabase;

    private function start(array $extra = []): array
    {
        return $this->postJson('/api/mobile/browser-login/start', $extra + [
            'challenge' => hash('sha256', str_repeat('a', 64)),
            'state' => str_repeat('b', 64), 'device_name' => 'Test iPhone',
        ])->assertOk()->json();
    }

    private function authorize(array $login, User $user): array
    {
        $response = $this->actingAs($user)->get($login['url'])->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertStringStartsWith('totallog://signin?', $url);
        $this->assertStringNotContainsString('token=', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    public function test_browser_uses_existing_login_and_preserves_return_destination(): void
    {
        $login = $this->start();
        $this->get($login['url'])->assertRedirect(route('login'))->assertSessionHas('url.intended', $login['url']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_browser_callback_exchanges_once_for_a_mobile_token(): void
    {
        $user = User::factory()->create();
        $login = $this->start();
        $callback = $this->authorize($login, $user);
        $this->assertSame(str_repeat('b', 64), $callback['state']);
        $this->assertSame($login['request_id'], $callback['request_id']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $request = ['request_id' => $login['request_id'], 'code' => $callback['code'], 'verifier' => str_repeat('a', 64)];
        $this->postJson('/api/mobile/browser-login/exchange', $request)->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonStructure(['token']);
        $this->assertSame(['mobile'], $user->tokens()->first()->abilities);
        $this->postJson('/api/mobile/browser-login/exchange', $request)->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_intercepted_code_needs_the_device_verifier(): void
    {
        $login = $this->start();
        $callback = $this->authorize($login, User::factory()->create());
        $this->postJson('/api/mobile/browser-login/exchange', ['request_id' => $login['request_id'], 'code' => $callback['code'], 'verifier' => str_repeat('c', 64)])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(DB::table('mobile_browser_logins')->first()->consumed_at);
        $this->assertNotSame($callback['code'], DB::table('mobile_browser_logins')->first()->code_hash);
    }

    public function test_expired_request_cannot_authorize_or_exchange(): void
    {
        $user = User::factory()->create();
        $login = $this->start();
        $callback = $this->authorize($login, $user);
        $this->travel(11)->minutes();
        $this->get($login['url'])->assertStatus(410);
        $this->postJson('/api/mobile/browser-login/exchange', ['request_id' => $login['request_id'], 'code' => $callback['code'], 'verifier' => str_repeat('a', 64)])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_pending_edits_require_the_same_account_and_guests_cannot_pair(): void
    {
        $owner = User::factory()->create();
        $login = $this->start(['expected_user_id' => $owner->id]);
        $this->actingAs(User::factory()->create())->get($login['url'])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_guest' => true]))->get($login['url'])->assertRedirect(route('calendar'));
        $this->assertNull(DB::table('mobile_browser_logins')->where('id', $login['request_id'])->value('user_id'));
        $this->authorize($login, $owner);
    }
}
