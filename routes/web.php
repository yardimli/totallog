<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApiUsageController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DayLogController;
use App\Http\Controllers\EmojiController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\GoalEntryController;
use App\Http\Controllers\GoogleCalendarSensorController;
use App\Http\Controllers\GuestDemoController;
use App\Http\Controllers\LogBlockController;
use App\Http\Controllers\LogSearchController;
use App\Http\Controllers\NoteAiController;
use App\Http\Controllers\NotebookController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\NoteTagController;
use App\Http\Controllers\NoteTaskController;
use App\Http\Controllers\NoteTrashController;
use App\Http\Controllers\NoteVersionController;
use App\Http\Controllers\OpenRouterController;
use App\Http\Controllers\PlannerVisibilityController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SensorController;
use App\Http\Controllers\SessionKeepAliveController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskEventController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', [GuestDemoController::class, 'index'])->name('demo.index');
Route::get('/demo', [GuestDemoController::class, 'enter'])->name('demo.enter');
Route::post('/demo/leave', [GuestDemoController::class, 'leave'])->name('demo.leave');
Route::get('/emojis', EmojiController::class)->name('emojis.index');

Route::redirect('/dashboard', '/calendar')->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post('/session/keep-alive', SessionKeepAliveController::class)->name('session.keep-alive');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/calendar/{date?}', [CalendarController::class, 'index'])->where('date', '\\d{4}-\\d{2}-\\d{2}')->name('calendar');
    Route::get('/search', LogSearchController::class)->name('search.index');
    Route::get('/notes', [NoteController::class, 'index'])->name('notes.index');
    Route::post('/notebooks', [NotebookController::class, 'store'])->name('notebooks.store');
    Route::post('/note-tags', [NoteTagController::class, 'store'])->name('note-tags.store');
    Route::delete('/note-tags/{noteTag}', [NoteTagController::class, 'destroy'])->name('note-tags.destroy');
    Route::post('/note-tasks', [NoteTaskController::class, 'store'])->name('note-tasks.store');
    Route::patch('/note-tasks/{noteTask}', [NoteTaskController::class, 'update'])->name('note-tasks.update');
    Route::delete('/note-tasks/{noteTask}', [NoteTaskController::class, 'destroy'])->name('note-tasks.destroy');
    Route::get('/notes/create', [NoteController::class, 'create'])->name('notes.create');
    Route::post('/notes', [NoteController::class, 'store'])->name('notes.store');
    Route::get('/notes/{note}', [NoteController::class, 'show'])->name('notes.show');
    Route::patch('/notes/{note}', [NoteController::class, 'update'])->name('notes.update');
    Route::delete('/notes/{note}', [NoteController::class, 'destroy'])->name('notes.destroy');
    Route::post('/notes/{noteId}/restore', [NoteTrashController::class, 'restore'])->whereNumber('noteId')->name('notes.restore');
    Route::delete('/notes/{noteId}/force', [NoteTrashController::class, 'destroy'])->whereNumber('noteId')->name('notes.force-destroy');
    Route::post('/notes/{note}/ai', NoteAiController::class)->name('notes.ai');
    Route::post('/notes/{note}/versions/{version}/restore', [NoteVersionController::class, 'restore'])->name('notes.versions.restore');
    Route::get('/log/today', [DayLogController::class, 'today'])->name('logs.today');
    Route::get('/logs/{date}', [DayLogController::class, 'show'])->where('date', '\\d{4}-\\d{2}-\\d{2}')->name('logs.show');
    Route::post('/logs/{dailyLog}/blocks', [LogBlockController::class, 'store'])->name('blocks.store');
    Route::patch('/blocks/{block}', [LogBlockController::class, 'update'])->name('blocks.update');
    Route::delete('/blocks/{block}', [LogBlockController::class, 'destroy'])->name('blocks.destroy');
    Route::patch('/blocks/{block}/visibility', [PlannerVisibilityController::class, 'block'])->name('blocks.visibility');

    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::get('/goals', [GoalController::class, 'index'])->name('goals.index');
    Route::post('/goals', [GoalController::class, 'store'])->name('goals.store');
    Route::get('/goals/{goal}', [GoalController::class, 'show'])->name('goals.show');
    Route::patch('/goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
    Route::delete('/goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');
    Route::post('/goals/{goal}/entries', [GoalEntryController::class, 'store'])->name('goals.entries.store');
    Route::post('/logs/{dailyLog}/tasks/{task}/events', [TaskEventController::class, 'store'])->name('events.store');
    Route::get('/events/{event}/edit', [TaskEventController::class, 'edit'])->name('events.edit');
    Route::patch('/events/{event}', [TaskEventController::class, 'update'])->name('events.update');
    Route::patch('/events/{event}/location', [TaskEventController::class, 'updateLocation'])->name('events.location');

    Route::get('/sensors', [SensorController::class, 'index'])->name('sensors.index');
    Route::post('/sensors/github', [SensorController::class, 'linkGithub'])->name('sensors.github.link');
    Route::patch('/sensors/github', [SensorController::class, 'toggleGithub'])->name('sensors.github.toggle');
    Route::delete('/sensors/github', [SensorController::class, 'unlinkGithub'])->name('sensors.github.unlink');
    Route::get('/sensors/browser/pair/{key}', [SensorController::class, 'pairBrowser'])->name('sensors.browser.pair');
    Route::delete('/sensors/browser', [SensorController::class, 'unlinkBrowser'])->name('sensors.browser.unlink');
    Route::get('/sensors/desktop/pair/{key}', [SensorController::class, 'pairDesktop'])->name('sensors.desktop.pair');
    Route::delete('/sensors/desktop', [SensorController::class, 'unlinkDesktop'])->name('sensors.desktop.unlink');
    Route::get('/sensors/google-calendar/connect', [GoogleCalendarSensorController::class, 'connect'])->name('sensors.google-calendar.connect');
    Route::get('/sensors/google-calendar/callback', [GoogleCalendarSensorController::class, 'callback'])->name('sensors.google-calendar.callback');
    Route::patch('/sensors/google-calendar', [GoogleCalendarSensorController::class, 'toggle'])->name('sensors.google-calendar.toggle');
    Route::post('/sensors/google-calendar/sync', [GoogleCalendarSensorController::class, 'sync'])->name('sensors.google-calendar.sync');
    Route::delete('/sensors/google-calendar', [GoogleCalendarSensorController::class, 'unlink'])->name('sensors.google-calendar.unlink');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::get('/settings/screensaver', [SettingsController::class, 'screensaver'])->name('settings.screensaver');
    Route::get('/settings/screensaver/logo', [SettingsController::class, 'screensaverLogo'])->name('settings.screensaver.logo');
    Route::get('/api-usage', [ApiUsageController::class, 'index'])->name('api-usage.index');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::patch('/settings/screensaver', [SettingsController::class, 'updateScreensaver'])->name('settings.screensaver.update');
    Route::patch('/settings/screensaver/toggle', [SettingsController::class, 'toggleScreensaver'])->name('settings.screensaver.toggle');
    Route::get('/openrouter/models', [OpenRouterController::class, 'models'])->name('openrouter.models');
    Route::post('/logs/{dailyLog}/chat', [OpenRouterController::class, 'chat'])->name('openrouter.chat');
    Route::post('/chat-actions/{proposal}/confirm', [OpenRouterController::class, 'confirmChatAction'])->name('openrouter.chat-actions.confirm');
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::delete('/demo-data', [AdminController::class, 'destroyDemoData'])->name('demo-data.destroy');
    });
});

require __DIR__.'/auth.php';
