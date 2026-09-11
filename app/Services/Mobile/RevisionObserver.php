<?php

namespace App\Services\Mobile;

use App\Models\Goal;
use App\Models\LogBlock;
use App\Models\Sensor;
use App\Models\TaskDefinition;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RevisionObserver
{
    public const ENTITIES = [TaskDefinition::class => 'tasks', Goal::class => 'goals', LogBlock::class => 'blocks', TaskEvent::class => 'events', User::class => 'settings', Sensor::class => 'sensors'];

    public function saved(Model $model): void
    {
        $this->record($model, false);
    }

    public function deleting(Model $model): void
    {
        // Database cascade deletes do not fire Eloquent child observers.
        if ($model instanceof LogBlock && $model->taskEvent) {
            $this->record($model->taskEvent, true);
        }
        $this->record($model, true);
    }

    private function record(Model $model, bool $deleted): void
    {
        // Also allow old installations to boot before running the migration.
        if (! Schema::hasTable('mobile_revisions')) {
            return;
        }
        $userId = $model instanceof User ? $model->id : ($model->user_id ?? $model->dailyLog?->user_id);
        if (! $userId || ($model instanceof User && $deleted)) {
            return;
        }
        DB::table('mobile_revisions')->updateOrInsert(
            ['user_id' => $userId, 'entity' => self::ENTITIES[$model::class], 'entity_id' => $model->id],
            ['edited_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'), 'deleted' => $deleted, 'payload' => $deleted && ! ($model instanceof User) && ! ($model instanceof Sensor) ? json_encode(['attributes' => $model->getAttributes(), 'block' => $model instanceof TaskEvent ? $model->block?->getAttributes() : null, 'event' => $model instanceof LogBlock ? $model->taskEvent?->getAttributes() : null, 'sources' => $model instanceof Goal ? $model->sources->map->getAttributes()->all() : [], 'entries' => $model instanceof Goal ? $model->entries->map->getAttributes()->all() : []]) : null]
        );
    }
}
