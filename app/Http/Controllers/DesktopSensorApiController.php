<?php

namespace App\Http\Controllers;

use App\Models\Sensor;
use App\Services\DesktopActivityRecorder;
use Illuminate\Http\Request;

class DesktopSensorApiController extends Controller
{
    public function check(Request $request)
    {
        $sensor = $this->desktopSensor($request);
        $sensor->update(['last_checked_at' => now(), 'last_error' => null]);

        return response()->json([
            'message' => 'Desktop sensor connection verified.',
            'connected' => true,
        ]);
    }

    public function __invoke(Request $request, DesktopActivityRecorder $recorder)
    {
        $batch = $request->has('activities');
        $rules = [
            'client_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
        $rules += $batch ? [
            'activities' => ['required', 'array', 'min:1', 'max:500'],
            'activities.*.application' => ['required', 'string', 'max:255'],
            'activities.*.process_name' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\/]+$/'],
            'activities.*.started_at' => ['required', 'date'],
            'activities.*.ended_at' => ['required', 'date'],
            'activities.*.duration_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
        ] : [
            'application' => ['required', 'string', 'max:255'],
            'process_name' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\/]+$/'],
            'observed_at' => ['nullable', 'date'],
        ];
        $data = $request->validate($rules);
        $sensor = $this->desktopSensor($request);

        if ($batch) {
            $activities = $recorder->recordBatch($sensor, $data['client_id'], $data['activities']);

            return response()->json([
                'message' => 'Desktop activity batch recorded.',
                'recorded' => $activities->count(),
                'duration_seconds' => (int) $activities->sum('duration_seconds'),
            ], 201);
        }

        $activity = $recorder->record($sensor, $data);

        return response()->json([
            'message' => 'Desktop activity recorded.',
            'application' => $activity->application,
            'log_date' => $activity->dailyLog->log_date->toDateString(),
            'started_at' => $activity->started_at->toIso8601String(),
        ], 201);
    }

    private function desktopSensor(Request $request): Sensor
    {
        $key = (string) $request->header('X-TotalLog-Key');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{32,128}$/', $key), 401, 'Desktop sensor key required.');
        $sensor = Sensor::with('user')
            ->where('type', Sensor::DESKTOP)
            ->where('enabled', true)
            ->where('pairing_key_hash', hash('sha256', $key))
            ->first();
        abort_unless($sensor, 401, 'Desktop sensor is not paired.');

        return $sensor;
    }
}
