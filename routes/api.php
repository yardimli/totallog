<?php

use App\Http\Controllers\BrowserSensorApiController;
use App\Http\Controllers\DesktopSensorApiController;
use App\Http\Controllers\KindleSensorApiController;
use App\Http\Controllers\MobileBrowserSensorApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/sensors/browser/activity', BrowserSensorApiController::class)->name('api.sensors.browser.activity');
Route::post('/sensors/desktop/activity', DesktopSensorApiController::class)->name('api.sensors.desktop.activity');
Route::get('/sensors/desktop/check', [DesktopSensorApiController::class, 'check'])->name('api.sensors.desktop.check');
Route::post('/sensors/browser/mobile-history', MobileBrowserSensorApiController::class)->name('api.sensors.browser.mobile-history');
Route::post('/sensors/kindle/book', KindleSensorApiController::class)->name('api.sensors.kindle.book');

Route::prefix('mobile')->group(function () {
    Route::post('/browser-login/start', [\App\Http\Controllers\Mobile\BrowserLoginController::class, 'start'])->middleware('throttle:10,1');
    Route::post('/browser-login/exchange', [\App\Http\Controllers\Mobile\BrowserLoginController::class, 'exchange'])->middleware('throttle:10,1');
    Route::get('/google/callback', [\App\Http\Controllers\Mobile\GoogleController::class, 'callback'])->name('mobile.google.callback');
    Route::post('/register', [\App\Http\Controllers\Mobile\AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/forgot-password', [\App\Http\Controllers\Mobile\AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/login', [\App\Http\Controllers\Mobile\AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureMobileToken::class])->group(function () {
        Route::post('/logout', [\App\Http\Controllers\Mobile\AuthController::class, 'logout']);
        Route::post('/profile', [\App\Http\Controllers\Mobile\AccountController::class, 'update']);
        Route::post('/password', [\App\Http\Controllers\Mobile\AccountController::class, 'password']);
        Route::post('/account/delete', [\App\Http\Controllers\Mobile\AccountController::class, 'destroy']);
        Route::get('/admin/users', [\App\Http\Controllers\Mobile\AccountController::class, 'users']);
        Route::post('/admin/reset-demo', [\App\Http\Controllers\Mobile\AccountController::class, 'resetDemo']);
        Route::post('/google/connect', [\App\Http\Controllers\Mobile\GoogleController::class, 'start']);
        Route::get('/snapshot', [\App\Http\Controllers\Mobile\SyncController::class, 'snapshot']);
        Route::post('/sync', [\App\Http\Controllers\Mobile\SyncController::class, 'push']);
        Route::get('/attachments/{attachment}', [\App\Http\Controllers\AttachmentController::class, 'show']);
        Route::post('/settings', function (Request $request) {
            app(\App\Http\Controllers\SettingsController::class)->update($request);

            return response()->json(['message' => 'Settings saved.']);
        });
        Route::get('/models', [\App\Http\Controllers\OpenRouterController::class, 'models']);
        Route::post('/refresh-day/{date}', function (Request $request, string $date) {
            validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
            $day = \Carbon\Carbon::parse($date);
            $log = $request->user()->dailyLogs()->whereDate('log_date', $date)->first() ?? $request->user()->dailyLogs()->create(['log_date' => $date]);
            app(\App\Services\GithubSensorSync::class)->sync($request->user(), $log, $day);
            app(\App\Services\GoogleCalendarSync::class)->syncUser($request->user());
            app(\App\Services\BrowsingActivityRecorder::class)->finalizeStale($request->user());
            app(\App\Services\DesktopActivityRecorder::class)->finalizeStale($request->user());

            return response()->json(['message' => 'Day refreshed.']);
        });
        Route::post('/chat/{date}', function (Request $request, string $date) {
            validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
            $log = $request->user()->dailyLogs()->whereDate('log_date', $date)->first() ?? $request->user()->dailyLogs()->create(['log_date' => $date]);

            return app(\App\Http\Controllers\OpenRouterController::class)->chat($request, $log);
        });
        Route::post('/chat-actions/{proposal}/confirm', [\App\Http\Controllers\OpenRouterController::class, 'confirmChatAction']);
        Route::post('/sensors/pair', function (Request $request) {
            $data = $request->validate(['type' => 'required|in:browser,desktop', 'key' => ['required', 'regex:/^[A-Za-z0-9_-]{32,128}$/']]);
            $controller = app(\App\Http\Controllers\SensorController::class);
            $data['type'] === 'browser' ? $controller->pairBrowser($request, $data['key']) : $controller->pairDesktop($request, $data['key']);

            return response()->json(['message' => 'Client paired.']);
        });
        Route::post('/sensors/github', [\App\Http\Controllers\SensorController::class, 'linkGithub']);
        Route::post('/sensors/google-calendar/sync', [\App\Http\Controllers\GoogleCalendarSensorController::class, 'sync']);
    });
});
