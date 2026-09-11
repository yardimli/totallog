<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        DB::table('mobile_browser_logins')->where(fn ($query) => $query->whereNull('expires_at_epoch')->orWhere('expires_at_epoch', '<=', now()->getTimestamp()))->delete();
        DB::table('mobile_browser_logins')->insert($data + ['id' => $id, 'expires_at' => now()->addMinutes(10), 'expires_at_epoch' => now()->getTimestamp() + 600]);

        return response()->json(['request_id' => $id, 'url' => route('mobile.browser.authorize', $id)]);
    }

    public function authorizeBrowser(Request $request, string $id)
    {
        abort_if($request->user()->is_guest, 403, 'Sign in with a regular TotalLog account.');

        return DB::transaction(function () use ($request, $id) {
            $login = DB::table('mobile_browser_logins')->where('id', $id)->whereNull('consumed_at')->where('expires_at_epoch', '>', now()->getTimestamp())->lockForUpdate()->first();
            abort_unless($login, 410, 'This sign-in expired. Return to the app and try again.');
            abort_if($login->expected_user_id && (int) $login->expected_user_id !== $request->user()->id, 403, 'Sign in to the account that owns the pending iPhone edits.');
            abort_if($login->user_id && (int) $login->user_id !== $request->user()->id, 409, 'This sign-in was already approved by another account. Start again from the app.');
            $key = config('app.key');
            abort_unless(is_string($key) && $key !== '', 503, 'The server application key is not configured.');
            // Refreshes, repeated redirects and browser retries must return the same code.
            // It remains unpredictable without the server key and bound to this account/request.
            $code = hash_hmac('sha256', implode('|', ['mobile-browser-login-v1', $id, $login->state, $login->challenge, $request->user()->id]), $key);
            DB::table('mobile_browser_logins')->where('id', $id)->update(['user_id' => $request->user()->id, 'code_hash' => hash('sha256', $code)]);

            // Only a short-lived, verifier-bound code crosses the browser callback URL.
            return response()->view('mobile.sign-in', ['callbackURL' => 'totallog://signin?'.http_build_query(['request_id' => $id, 'state' => $login->state, 'code' => $code]), 'statusURL' => route('mobile.browser.status', $id), 'accountName' => $request->user()->name])
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        });
    }

    public function browserStatus(Request $request, string $id)
    {
        $active = DB::table('mobile_browser_logins')->where('id', $id)->where('user_id', $request->user()->id)
            ->whereNull('consumed_at')->where('expires_at_epoch', '>', now()->getTimestamp())->exists();

        return response()->json(['active' => $active])->header('Cache-Control', 'no-store');
    }

    public function cancel(Request $request)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'verifier' => ['required', 'regex:/^[a-f0-9]{64}$/']]);

        return DB::transaction(function () use ($data) {
            $login = DB::table('mobile_browser_logins')->where('id', $data['request_id'])->lockForUpdate()->first();
            if ($login) {
                abort_unless(hash_equals($login->challenge, hash('sha256', $data['verifier'])), 403);
                if ($login->consumed_at === null) {
                    DB::table('mobile_browser_logins')->where('id', $login->id)->delete();
                }
            }

            return response()->json(['message' => 'Sign-in cancelled.'])->header('Cache-Control', 'no-store');
        });
    }

    public function exchange(Request $request)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'code' => 'required|string|size:64', 'verifier' => ['required', 'regex:/^[a-f0-9]{64}$/']]);

        return DB::transaction(function () use ($data) {
            $login = DB::table('mobile_browser_logins')->where('id', $data['request_id'])->lockForUpdate()->first();
            $failure = match (true) {
                ! $login => ['sign_in_missing', 'This sign-in request is no longer available. Start a new sign-in from the app.'],
                ! $login->expires_at_epoch || (int) $login->expires_at_epoch <= now()->getTimestamp() => ['sign_in_expired', 'Browser sign-in expired after ten minutes. Start a new sign-in from the app.'],
                $login->consumed_at !== null => ['sign_in_used', 'This browser sign-in was already completed. Start a new sign-in from the app.'],
                ! $login->user_id || ! $login->code_hash => ['sign_in_pending', 'Finish signing in on the website before returning to the app.'],
                ! hash_equals($login->challenge, hash('sha256', $data['verifier'])) => ['sign_in_device_mismatch', 'This sign-in belongs to a different iPhone request. Cancel it and start again.'],
                ! hash_equals($login->code_hash, hash('sha256', $data['code'])) => ['sign_in_code_mismatch', 'This browser return link is no longer valid. Reopen the browser from the app.'],
                default => null,
            };
            if ($failure) {
                // Do not log codes, verifiers, tokens, URLs, or account credentials.
                Log::notice('Mobile browser sign-in exchange rejected', ['request_id' => $data['request_id'], 'reason' => $failure[0]]);

                return response()->json(['message' => $failure[1], 'error_code' => $failure[0]], 422)->header('Cache-Control', 'no-store');
            }
            $user = User::findOrFail($login->user_id);
            abort_if($user->is_guest, 403);
            DB::table('mobile_browser_logins')->where('id', $login->id)->update(['consumed_at' => now()]);

            return response()->json(['token' => $user->createToken($login->device_name, ['mobile'])->plainTextToken, 'user' => $user->only('id', 'name', 'email'), 'server_time' => now()->utc()->toISOString()])->header('Cache-Control', 'no-store');
        });
    }
}
