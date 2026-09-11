<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use \App\Models\Concerns\AtomicMobileWrites;
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'openrouter_api_key',
        'time_format',
        'week_starts_on',
        'default_chat_model',
        'screensaver_enabled',
        'screensaver_style',
        'screensaver_wait_minutes',
        'screensaver_speed',
        'screensaver_message',
        'screensaver_logo_path',
        'is_guest',
        'is_admin',
        'guest_token_hash',
        'demo_seed_version',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'openrouter_api_key',
        'guest_token_hash',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'openrouter_api_key' => 'encrypted',
        'is_guest' => 'boolean',
        'is_admin' => 'boolean',
        'week_starts_on' => 'integer',
        'screensaver_enabled' => 'boolean',
        'screensaver_wait_minutes' => 'integer',
        'screensaver_speed' => 'float',
        'demo_seed_version' => 'integer',
    ];

    public function dailyLogs()
    {
        return $this->hasMany(DailyLog::class);
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }

    public function notebooks()
    {
        return $this->hasMany(Notebook::class);
    }

    public function notebookStacks()
    {
        return $this->hasMany(NotebookStack::class);
    }

    public function noteTags()
    {
        return $this->hasMany(NoteTag::class);
    }

    public function noteTasks()
    {
        return $this->hasMany(NoteTask::class);
    }

    public function noteSettings()
    {
        return $this->hasOne(NoteUserSetting::class);
    }

    public function taskDefinitions()
    {
        return $this->hasMany(TaskDefinition::class);
    }

    public function goals()
    {
        return $this->hasMany(Goal::class);
    }

    public function sensors()
    {
        return $this->hasMany(Sensor::class);
    }

    public function browsingActivities()
    {
        return $this->hasMany(BrowsingActivity::class);
    }

    public function desktopActivities()
    {
        return $this->hasMany(DesktopActivity::class);
    }

    public function mobileBrowsingVisits()
    {
        return $this->hasMany(MobileBrowsingVisit::class);
    }

    public function kindleReadingProgress()
    {
        return $this->hasMany(KindleReadingProgress::class);
    }

    public function googleCalendarEvents()
    {
        return $this->hasMany(GoogleCalendarEvent::class);
    }

    public function openRouterApiKey(): ?string
    {
        try {
            return $this->openrouter_api_key;
        } catch (DecryptException) {
            return null;
        }
    }

    public function hasInvalidOpenRouterApiKey(): bool
    {
        if (! filled($this->getRawOriginal('openrouter_api_key'))) {
            return false;
        }

        try {
            $this->openrouter_api_key;

            return false;
        } catch (DecryptException) {
            return true;
        }
    }

    public function formatTime(DateTimeInterface $time): string
    {
        return $time->format($this->time_format === '12' ? 'g:i A' : 'H:i');
    }

    public function formatClock(string $time): string
    {
        if ($time === '24:00') {
            return $this->time_format === '12' ? '12:00 AM' : '24:00';
        }

        return Carbon::createFromFormat('H:i', $time)->format($this->time_format === '12' ? 'g:i A' : 'H:i');
    }
}
