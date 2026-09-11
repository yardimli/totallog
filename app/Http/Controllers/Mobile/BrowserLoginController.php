<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BrowserLoginController extends Controller
{
    public function start(Request $request)
    {
        $data = $request->validate([
            'challenge' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'state' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'device_name' => 'required|string|max:100',
            'expected_user_id' => 'nullable|integer|min:1',
        ]);
        $id = (string) Str::uuid();
        DB::table('mobile_browser_logins')->where('expires_at', '<', now())->delete();
        DB::table('mobile_browser_logins')->insert($data + ['id' => $id, 'expires_at' => now()->addMinutes(10)]);

        return response()->json(['request_id' => $id, 'url' => route('mobile.browser.authorize', $id)]);
    }

    public function authorizeBrowser(Request $request, string $id)
    {
        abort_if($request->user()->is_guest, 403, 'Sign in with a regular TotalLog account.');

        return DB::transaction(function () use ($request, $id) {
            $login = DB::table('mobile_browser_logins')->where('id', $id)->whereNull('consumed_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($login, 410, 'This sign-in expired. Return to the app and try again.');
            abort_if($login->expected_user_id && (int) $login->expected_user_id !== $request->user()->id, 403, 'Sign in to the account that owns the pending iPhone edits.');
            $code = Str::random(64);
            DB::table('mobile_browser_logins')->where('id', $id)->update(['user_id' => $request->user()->id, 'code_hash' => hash('sha256', $code)]);

            // Only a short-lived, verifier-bound code crosses the browser callback URL.
            return redirect()->away('totallog://signin?'.http_build_query(['request_id' => $id, 'state' => $login->state, 'code' => $code]))
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        });
    }

    public function exchange(Request $request)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'code' => 'required|string|size:64', 'verifier' => ['required', 'regex:/^[a-f0-9]{64}$/']]);

        return DB::transaction(function () use ($data) {
            $login = DB::table('mobile_browser_logins')->where('id', $data['request_id'])->whereNull('consumed_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($login && $login->user_id && $login->code_hash
                && hash_equals($login->challenge, hash('sha256', $data['verifier']))
                && hash_equals($login->code_hash, hash('sha256', $data['code'])), 422, 'Invalid or expired browser sign-in.');
            $user = User::findOrFail($login->user_id);
            abort_if($user->is_guest, 403);
            DB::table('mobile_browser_logins')->where('id', $login->id)->update(['consumed_at' => now()]);

            return response()->json(['token' => $user->createToken($login->device_name, ['mobile'])->plainTextToken, 'user' => $user->only('id', 'name', 'email'), 'server_time' => now()->utc()->toISOString()])->header('Cache-Control', 'no-store');
        });
    }
}
