<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatActionProposal extends Model
{
    use \App\Models\Concerns\AtomicMobileWrites;

    protected $fillable = ['user_id', 'daily_log_id', 'log_block_id', 'model', 'actions', 'summary', 'status', 'expires_at', 'confirmed_at'];

    protected $casts = ['actions' => 'array', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime'];

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
