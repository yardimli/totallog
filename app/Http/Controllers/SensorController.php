<?php

namespace App\Http\Controllers;

use App\Models\Sensor;
use App\Services\GithubSensorClient;
use App\Services\GithubSensorSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SensorController extends Controller
{
    public function __construct(private GithubSensorClient $github) {}

    public function index(Request $request)
    {
        return view('sensors.index', [
            'githubSensor' => $request->user()->sensors()->where('type', Sensor::GITHUB)->first(),
            'browserSensor' => $request->user()->sensors()->where('type', Sensor::BROWSER)->first(),
            'desktopSensor' => $request->user()->sensors()->where('type', Sensor::DESKTOP)->first(),
            'googleCalendarSensor' => $request->user()->sensors()->where('type', Sensor::GOOGLE_CALENDAR)->first(),
            'googleCalendarConfigured' => filled(config('services.google_calendar.client_id')) && filled(config('services.google_calendar.client_secret')),
        ]);
    }

    public function pairBrowser(Request $request, string $key)
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{32,128}$/', $key), 404);
        $this->pairClientSensor($request, Sensor::BROWSER, 'Chrome extension', hash('sha256', $key));

        return redirect()->route('sensors.index')->with('status', 'Chrome browsing sensor paired and enabled.');
    }

    public function unlinkBrowser(Request $request)
    {
        $request->user()->sensors()->where('type', Sensor::BROWSER)->firstOrFail()->delete();

        return back()->with('status', 'Chrome browsing sensor unlinked. Existing browsing logs were preserved.');
    }

    public function pairDesktop(Request $request, string $key)
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{32,128}$/', $key), 404);
        $this->pairClientSensor($request, Sensor::DESKTOP, 'Windows desktop app', hash('sha256', $key));

        return redirect()->route('sensors.index')->with('status', 'Desktop activity sensor paired and enabled.');
    }

    public function unlinkDesktop(Request $request)
    {
        $request->user()->sensors()->where('type', Sensor::DESKTOP)->firstOrFail()->delete();

        return back()->with('status', 'Desktop sensor unlinked. Existing desktop activity logs were preserved.');
    }

    private function pairClientSensor(Request $request, string $type, string $username, string $hash): void
    {
        DB::transaction(function () use ($request, $type, $username, $hash) {
            $target = $request->user()->sensors()
                ->where('type', $type)
                ->lockForUpdate()
                ->first();

            // A pairing key identifies one client installation, so move it from
            // any previous browser or desktop sensor before assigning it here.
            Sensor::where('pairing_key_hash', $hash)
                ->lockForUpdate()
                ->get()
                ->each(function (Sensor $sensor) use ($target) {
                    if (! $target || $sensor->id !== $target->id) {
                        $sensor->update([
                            'pairing_key_hash' => null,
                            'enabled' => false,
                        ]);
                    }
                });

            $values = [
                'username' => $username,
                'token' => null,
                'pairing_key_hash' => $hash,
                'enabled' => true,
                'last_checked_at' => null,
                'last_error' => null,
            ];

            if ($target) {
                $target->update($values);
            } else {
                $request->user()->sensors()->create(['type' => $type] + $values);
            }
        });
    }

    public function linkGithub(Request $request)
    {
        $data = $request->validate([
            'github_username' => ['required', 'string', 'max:39', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/'],
            'github_token' => 'required|string|max:1000',
        ]);

        try {
            $account = $this->github->validateAccount($data['github_username'], $data['github_token']);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['github_token' => $error->getMessage()]);
        }

        $request->user()->sensors()->updateOrCreate(
            ['type' => Sensor::GITHUB],
            [
                'username' => $account['login'],
                'token' => $data['github_token'],
                'settings' => [],
                'enabled' => true,
                'last_checked_at' => null,
                'last_error' => null,
            ]
        );

        if ($request->expectsJson()) {
            return response()->json(['message' => 'GitHub linked and enabled.', 'reload' => true]);
        }

        return back()->with('status', 'GitHub linked and enabled.');
    }

    public function syncGithub(Request $request, GithubSensorSync $sync)
    {
        $sensor = $this->githubSensor($request);
        try {
            $report = $sync->syncLatest($sensor);
        } catch (\Throwable $error) {
            report($error);
            $message = $error instanceof RuntimeException
                ? $error->getMessage()
                : 'GitHub sync failed. Check the connection and try again.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : redirect()->route('sensors.index')->with('error', $message);
        }
        $count = count($report['commits']);
        $new = collect($report['commits'])->where('status', 'new')->count();
        $message = "GitHub sync complete: {$count} commits checked, {$new} new, ".($count - $new).' already inserted.';

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'reload' => true, 'report' => $report])
            : redirect()->route('sensors.index')->with('status', $message);
    }

    public function toggleGithub(Request $request)
    {
        $sensor = $this->githubSensor($request);
        $data = $request->validate(['enabled' => 'required|boolean']);
        $sensor->update(['enabled' => (bool) $data['enabled']]);
        $message = $sensor->enabled ? 'GitHub sensor enabled.' : 'GitHub sensor disabled.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'reload' => true]);
        }

        return back()->with('status', $message);
    }

    public function unlinkGithub(Request $request)
    {
        $this->githubSensor($request)->delete();
        $message = 'GitHub unlinked. Existing log entries were preserved.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'reload' => true]);
        }

        return back()->with('status', $message);
    }

    private function githubSensor(Request $request): Sensor
    {
        return $request->user()->sensors()->where('type', Sensor::GITHUB)->firstOrFail();
    }
}
