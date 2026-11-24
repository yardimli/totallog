<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCall extends Model
{
    use \App\Models\Concerns\AtomicMobileWrites;

    protected $fillable = ['user_id', 'daily_log_id', 'log_block_id', 'operation', 'model', 'request_id', 'status_code', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost', 'duration_ms', 'error', 'metadata'];

    protected $casts = ['cost' => 'decimal:8', 'metadata' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(DailyLog::class);
    }
}
