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
        $this->postJson('/api/mobile/browser-login/exchange', $request)->assertUnprocessable()->assertJsonPath('error_code', 'sign_in_used');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_intercepted_code_needs_the_device_verifier(): void
    {
        $login = $this->start();
        $callback = $this->authorize($login, User::factory()->create());
        $this->postJson('/api/mobile/browser-login/exchange', ['request_id' => $login['request_id'], 'code' => $callback['code'], 'verifier' => str_repeat('c', 64)])->assertUnprocessable()->assertJsonPath('error_code', 'sign_in_device_mismatch');
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
        $this->postJson('/api/mobile/browser-login/exchange', ['request_id' => $login['request_id'], 'code' => $callback['code'], 'verifier' => str_repeat('a', 64)])->assertUnprocessable()->assertJsonPath('error_code', 'sign_in_expired');
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

    public function test_reloading_browser_authorization_does_not_invalidate_the_first_callback(): void
    {
        $user = User::factory()->create();
        $login = $this->start();
        $first = $this->authorize($login, $user);
        $second = $this->authorize($login, $user);
        $this->assertSame($first['code'], $second['code']);
        $this->postJson('/api/mobile/browser-login/exchange', ['request_id' => $login['request_id'], 'code' => $first['code'], 'verifier' => str_repeat('a', 64)])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
    public function test_invalid_code_does_not_consume_sign_in_and_reports_the_actual_reason(): void
    {
        $login = $this->start();
        $user = User::factory()->create();
        $data = ['request_id' => $login['request_id'], 'code' => str_repeat('z', 64), 'verifier' => str_repeat('a', 64)];
        $this->postJson('/api/mobile/browser-login/exchange', $data)->assertUnprocessable()->assertJsonPath('error_code', 'sign_in_pending');
        $callback = $this->authorize($login, $user);
        $this->postJson('/api/mobile/browser-login/exchange', $data)->assertUnprocessable()->assertJsonPath('error_code', 'sign_in_code_mismatch');
        $data['code'] = $callback['code'];
        $this->postJson('/api/mobile/browser-login/exchange', $data)->assertOk();
    }

    public function test_browser_cannot_replace_an_approved_account(): void
    {
        $login = $this->start();
        $owner = User::factory()->create();
        $this->authorize($login, $owner);
        $this->actingAs(User::factory()->create())->get($login['url'])->assertStatus(409);
        $this->assertSame($owner->id, (int) DB::table('mobile_browser_logins')->where('id', $login['request_id'])->value('user_id'));
    }

}
