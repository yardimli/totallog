<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalEntry extends Model
{
    use \App\Models\Concerns\AtomicMobileWrites;

    protected $fillable = ['goal_id', 'goal_source_id', 'occurred_at', 'points', 'external_key', 'note'];

    protected $casts = ['occurred_at' => 'datetime', 'points' => 'integer'];

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(GoalSource::class, 'goal_source_id');
    }
}
