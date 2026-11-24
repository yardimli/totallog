<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoalSource extends Model
{
    use \App\Models\Concerns\AtomicMobileWrites;

    protected $fillable = ['goal_id', 'type', 'task_definition_id', 'github_project'];

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function taskDefinition(): BelongsTo
    {
        return $this->belongsTo(TaskDefinition::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(GoalEntry::class);
    }
}
