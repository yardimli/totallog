<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    use \App\Models\Concerns\AtomicMobileWrites;

    protected $fillable = ['user_id', 'daily_log_id', 'log_block_id', 'type', 'disk', 'path', 'original_name', 'mime_type', 'size', 'metadata'];

    protected $casts = ['metadata' => 'array'];

    protected $appends = ['url'];

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

    public function getUrlAttribute(): string
    {
        return route('attachments.show', $this);
    }
}
