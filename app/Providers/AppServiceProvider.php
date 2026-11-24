<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (array_keys(\App\Services\Mobile\RevisionObserver::ENTITIES) as $model) {
            $model::observe(\App\Services\Mobile\RevisionObserver::class);
        }
        foreach (array_merge(array_keys(\App\Services\Mobile\RevisionObserver::ENTITIES), [
            \App\Models\DailyLog::class, \App\Models\Attachment::class,
            \App\Models\GoalEntry::class, \App\Models\GoalSource::class,
            \App\Models\ApiCall::class, \App\Models\ChatActionProposal::class,
            \App\Models\BrowsingActivity::class, \App\Models\DesktopActivity::class,
            \App\Models\MobileBrowsingVisit::class, \App\Models\KindleReadingProgress::class,
            \App\Models\GoogleCalendarEvent::class,
        ]) as $model) {
            $model::observe(\App\Services\Mobile\DesktopSyncObserver::class);
        }
    }
}
