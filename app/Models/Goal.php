<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    use \App\Models\Concerns\AtomicMobileWrites;

    public const DEFAULT_EMOJI = '🎯';

    protected $fillable = ['user_id', 'name', 'emoji', 'icon_data', 'color', 'target_points', 'period', 'start_date', 'end_date', 'manual_enabled', 'completed_at'];

    protected $casts = ['target_points' => 'integer', 'start_date' => 'date', 'end_date' => 'date', 'manual_enabled' => 'boolean', 'completed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(GoalSource::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(GoalEntry::class);
    }

    public function isAvailableOn(CarbonInterface $day): bool
    {
        if ($this->start_date && $day->lt($this->start_date->startOfDay())) {
            return false;
        }
        if ($this->end_date && $day->gt($this->end_date->endOfDay())) {
            return false;
        }
        if ($this->period === 'none' && $this->completed_at && $day->gt($this->completed_at->endOfDay())) {
            return false;
        }

        return true;
    }

    public function getTextColorAttribute(): string
    {
        [$r, $g, $b] = sscanf($this->color, '#%02x%02x%02x');

        return ((($r * 299) + ($g * 587) + ($b * 114)) / 1000) > 155 ? '#0f172a' : '#ffffff';
    }
}
