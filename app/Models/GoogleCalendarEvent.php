<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleCalendarEvent extends Model
{
    use \App\Models\Concerns\AtomicMobileWrites;

    protected $fillable = [
        'sensor_id', 'user_id', 'daily_log_id', 'log_block_id', 'calendar_id', 'google_event_id', 'event_key',
        'title', 'description', 'location', 'html_link', 'starts_at', 'ends_at', 'is_all_day', 'etag',
    ];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_all_day' => 'boolean'];

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(DailyLog::class);
    }

    public function logBlock(): BelongsTo
    {
        return $this->belongsTo(LogBlock::class);
    }
}
