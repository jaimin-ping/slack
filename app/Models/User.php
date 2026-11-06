<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** The path changes with every upload, so it also refreshes browser caches. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? route('avatars.show', ['user' => $this->id, 'v' => substr(md5($this->avatar_path), 0, 8)], false)
            : null;
    }

    public function getTwoFactorEnabledAttribute(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function statusIsActive(): bool
    {
        return ! $this->status_expires_at || $this->status_expires_at->isFuture();
    }

    /** Do Not Disturb: a pause for now, or the daily schedule in this person's own time zone. */
    public function isDnd(): bool
    {
        if ($this->dnd_until?->isFuture()) {
            return true;
        }

        if (! $this->dnd_from || ! $this->dnd_to) {
            return false;
        }

        $now = now($this->timezone ?: config('app.timezone'))->format('H:i');

        return $this->dnd_from <= $this->dnd_to
            ? $now >= $this->dnd_from && $now < $this->dnd_to
            : $now >= $this->dnd_from || $now < $this->dnd_to;
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class)->withTimestamps();
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status_emoji',
        'status_text',
        'is_away',
        'avatar_path',
        'theme',
        'timezone',
        'title',
        'phone',
        'dnd_until',
        'dnd_from',
        'dnd_to',
        'status_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'avatar_path',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_step',
        'sso_issuer',
        'sso_subject',
    ];

    /** @var list<string> */
    protected $appends = ['two_factor_enabled'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_away' => 'boolean',
            'dnd_until' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'status_expires_at' => 'datetime',
        ];
    }
}
