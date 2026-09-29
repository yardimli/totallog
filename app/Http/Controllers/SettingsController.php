<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public const SCREENSAVERS = [
        'bouncing-ball' => 'Bouncing Ball',
        'fade-out' => 'Fade Out',
        'fish' => 'Fish Aquarium',
        'flying-toasters' => 'Flying Toasters',
        'globe' => 'Globe',
        'hard-rain' => 'Hard Rain',
        'logo' => 'After Dark Logo',
        'messages' => 'Messages',
        'messages2' => 'Macintosh Messages',
        'rainstorm' => 'Rainstorm',
        'spotlight' => 'Spotlight',
        'starry-night' => 'Starry Night',
        'warp' => 'Warp',
    ];

    public function edit(Request $request)
    {
        $user = $request->user();

        return view('settings.edit', [
            'hasApiKey' => (bool) $user->openRouterApiKey(),
            'apiKeyNeedsReplacement' => $user->hasInvalidOpenRouterApiKey(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'openrouter_api_key' => 'nullable|string|max:500',
            'time_format' => 'required|in:12,24',
            'week_starts_on' => 'required|integer|in:0,1',
            'time_picker' => 'sometimes|required|in:scroller,wheel',
            'default_chat_model' => 'nullable|string|max:191',
        ]);
        $user = $request->user();
        if ($user->hasInvalidOpenRouterApiKey()) {
            $user->newQuery()->whereKey($user->getKey())->update(['openrouter_api_key' => null]);
            $user->refresh();
        }

        $user->update([
            'time_format' => $data['time_format'],
            'time_picker' => $data['time_picker'] ?? $user->time_picker ?? 'scroller',
            'week_starts_on' => $data['week_starts_on'],
            'default_chat_model' => ($data['default_chat_model'] ?? '') ?: null,
        ]);
        if (($data['openrouter_api_key'] ?? '') !== '') {
            $user->update(['openrouter_api_key' => $data['openrouter_api_key']]);
        }
        if ($request->boolean('remove_api_key')) {
            $user->update(['openrouter_api_key' => null]);
        }

        return back()->with('status', 'Account and OpenRouter settings saved.');
    }

    public function screensaver(Request $request)
    {
        return view('settings.screensaver', ['screensavers' => self::SCREENSAVERS]);
    }

    public function updateScreensaver(Request $request)
    {
        $data = $request->validate([
            'screensaver_enabled' => 'nullable|boolean',
            'screensaver_style' => 'required|in:'.implode(',', array_keys(self::SCREENSAVERS)),
            'screensaver_wait_minutes' => 'required|integer|in:1,2,5,10,15,30,60',
            'screensaver_speed' => 'required|numeric|in:0.5,0.75,1,1.25,1.5,2',
            'screensaver_message' => 'nullable|string|max:120',
            'screensaver_logo' => 'nullable|image|max:4096',
        ]);

        $user = $request->user();
        $settings = [
            'screensaver_enabled' => $request->boolean('screensaver_enabled'),
            'screensaver_style' => $data['screensaver_style'],
            'screensaver_wait_minutes' => $data['screensaver_wait_minutes'],
            'screensaver_speed' => $data['screensaver_speed'],
            'screensaver_message' => trim($data['screensaver_message'] ?? '') ?: 'OUT TO LUNCH',
        ];

        if ($request->hasFile('screensaver_logo')) {
            $path = $request->file('screensaver_logo')->store('screensaver-logos', 'public');
            if ($user->screensaver_logo_path) {
                Storage::disk('public')->delete($user->screensaver_logo_path);
            }
            $settings['screensaver_logo_path'] = $path;
        }

        $user->update($settings);

        return back()->with('status', 'Screensaver settings saved.');
    }

    public function screensaverLogo(Request $request)
    {
        $path = $request->user()->screensaver_logo_path;
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }

    public function toggleScreensaver(Request $request)
    {
        $user = $request->user();
        $user->update(['screensaver_enabled' => ! $user->screensaver_enabled]);

        return response()->json([
            'enabled' => $user->screensaver_enabled,
            'message' => $user->screensaver_enabled ? 'Screensaver enabled.' : 'Screensaver disabled.',
        ]);
    }
}
