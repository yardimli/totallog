<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LogBlock extends Model
{
    use \App\Models\Concerns\AtomicMobileWrites;

    public const DEFAULT_EMOJIS = [
        'event' => '✅',
        'text' => '📝',
        'generated_image' => '🎨',
        'chat_user' => '💬',
        'chat_assistant' => '🤖',
        'sensor_github' => '💻',
        'sensor_browser' => '🌐',
        'sensor_desktop' => '🖥️',
        'sensor_mobile_browser' => '📱',
        'sensor_kindle' => '📖',
        'sensor_google_calendar' => '📅',
    ];

    protected $fillable = ['daily_log_id', 'type', 'emoji', 'icon_data', 'content', 'metadata', 'position', 'occurred_at', 'is_hidden'];

    protected $casts = ['metadata' => 'array', 'occurred_at' => 'datetime', 'is_hidden' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (LogBlock $block) {
            $block->emoji = filled($block->emoji) ? $block->emoji : self::defaultEmojiForType($block->type);
        });
    }

    public static function defaultEmojiForType(string $type): string
    {
        return self::DEFAULT_EMOJIS[$type] ?? self::DEFAULT_EMOJIS['text'];
    }

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(DailyLog::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function taskEvent(): HasOne
    {
        return $this->hasOne(TaskEvent::class);
    }

    public function browsingActivities(): HasMany
    {
        return $this->hasMany(BrowsingActivity::class);
    }

    public function desktopActivities(): HasMany
    {
        return $this->hasMany(DesktopActivity::class);
    }

    public function mobileBrowsingVisits(): HasMany
    {
        return $this->hasMany(MobileBrowsingVisit::class);
    }

    public function kindleReadingProgress(): HasMany
    {
        return $this->hasMany(KindleReadingProgress::class);
    }

    public function googleCalendarEvent(): HasOne
    {
        return $this->hasOne(GoogleCalendarEvent::class);
    }

    public function noteLinks(): HasMany
    {
        return $this->hasMany(NoteLogLink::class);
    }
}
