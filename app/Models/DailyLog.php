<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyLog extends Model
{
    use \App\Models\Concerns\AtomicMobileWrites;

    protected $fillable = ['user_id', 'log_date'];

    protected $casts = ['log_date' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(LogBlock::class)->orderBy('position')->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function apiCalls(): HasMany
    {
        return $this->hasMany(ApiCall::class)->latest();
    }

    public function taskEvents(): HasMany
    {
        return $this->hasMany(TaskEvent::class);
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

    public function googleCalendarEvents(): HasMany
    {
        return $this->hasMany(GoogleCalendarEvent::class);
    }

    public function noteLinks(): HasMany
    {
        return $this->hasMany(NoteLogLink::class);
    }
}
