<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileBrowsingVisit extends Model
{
    use \App\Models\Concerns\AtomicMobileWrites;

    protected $fillable = [
        'user_id', 'daily_log_id', 'log_block_id', 'domain', 'visit_key', 'visited_at',
    ];

    protected $casts = ['visited_at' => 'datetime'];

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
