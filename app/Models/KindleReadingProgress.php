<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KindleReadingProgress extends Model
{
    use \App\Models\Concerns\AtomicMobileWrites;

    protected $table = 'kindle_reading_progress';

    protected $fillable = [
        'user_id', 'daily_log_id', 'log_block_id', 'book_key', 'asin', 'title', 'author',
        'percentage_read', 'location', 'client_id', 'observed_at',
    ];

    protected $casts = [
        'percentage_read' => 'decimal:2',
        'observed_at' => 'datetime',
    ];

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
