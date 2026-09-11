<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Sensor;
use App\Models\User;
use App\Services\GoogleCalendarClient;
use App\Services\GoogleCalendarSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GoogleController extends Controller
{
    public function start(Request $request, GoogleCalendarClient $google)
    {
        abort_unless($google->configured(), 503, 'Google Calendar OAuth is not configured.');
        $state = Str::random(64);
        Cache::put('mobile-google:'.hash('sha256', $state), $request->user()->id, now()->addMinutes(10));

        return response()->json(['url' => $google->authorizationUrl($state, route('mobile.google.callback'))]);
    }

    public function callback(Request $request, GoogleCalendarClient $google, GoogleCalendarSync $sync)
    {
        $data = $request->validate(['state' => 'required|string|size:64', 'code' => 'required|string|max:4096']);
        $userId = Cache::pull('mobile-google:'.hash('sha256', $data['state']));
        abort_unless($userId, 419, 'Authorization expired. Return to TotalLog and try again.');
        $user = User::findOrFail($userId);
        $tokens = $google->exchangeCode($data['code'], route('mobile.google.callback'));
        abort_unless(filled($tokens['refresh_token'] ?? null), 422, 'Google did not grant offline access.');
        $account = $google->account($tokens['access_token']);
        $sensor = $user->sensors()->updateOrCreate(['type' => Sensor::GOOGLE_CALENDAR], ['username' => $account['email'], 'token' => $tokens['refresh_token'], 'enabled' => true, 'settings' => ['calendar_id' => 'primary', 'google_sub' => $account['sub'] ?? null], 'last_error' => null]);
        $sync->syncSensor($sensor, true);

        return redirect()->away('totallog://oauth/google-complete');
    }
}
