<?php

namespace App\Services\Mobile;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** A per-account change token; stored in the same transaction as each model write. */
class DesktopSyncObserver
{
    public function saved(Model $model): void
    {
        if ($model->wasRecentlyCreated || $model->wasChanged()) {
            $this->invalidate($model);
        }
    }

    public function deleting(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        if (! Schema::hasTable('desktop_sync_versions')) {
            return;
        }
        $userId = $model instanceof User ? $model->id : ($model->user_id
            ?? (method_exists($model, 'dailyLog') ? $model->dailyLog?->user_id : null)
            ?? (method_exists($model, 'goal') ? $model->goal?->user_id : null));
        if ($userId) {
            DB::table('desktop_sync_versions')->updateOrInsert(['user_id' => $userId], ['version' => (string) Str::uuid()]);
        }
    }
}
