<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A unit of work in a workspace, created by a person or detected by one of their agents. */
class Task extends Model
{
    public const SUGGESTED = 'suggested';

    public const PENDING = 'pending';

    public const IN_PROGRESS = 'in_progress';

    public const BLOCKED = 'blocked';

    public const PAUSED = 'paused';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::SUGGESTED, self::PENDING, self::IN_PROGRESS, self::BLOCKED, self::PAUSED, self::COMPLETED, self::CANCELLED];

    /** Statuses a person can choose directly. Suggested and paused have their own actions. */
    public const SETTABLE_STATUSES = [self::PENDING, self::IN_PROGRESS, self::BLOCKED, self::COMPLETED, self::CANCELLED];

    /** Work that is still open. */
    public const OPEN_STATUSES = [self::PENDING, self::IN_PROGRESS, self::BLOCKED, self::PAUSED];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = [
        'workspace_id', 'title', 'description', 'creator_id', 'ai_agent_id', 'priority', 'status', 'paused_from',
        'start_date', 'due_at', 'completed_at', 'archived_at', 'confidence', 'source_message_id', 'source_channel_id',
        'source_ref', 'source_state', 'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'confidence' => 'float',
            'source_ref' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees')->withPivot('assigned_by', 'ai_agent_id')->withTimestamps();
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_id')->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class);
    }

    public function messageLinks(): HasMany
    {
        return $this->hasMany(TaskMessageLink::class);
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    public function sourceChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'source_channel_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->isOpen() && $this->due_at->isPast();
    }

    /** Whether a person may move the task from its current status to the given one. */
    public function canMoveTo(string $status): bool
    {
        if ($status === $this->status || ! in_array($status, self::SETTABLE_STATUSES, true)) {
            return false;
        }

        return match ($this->status) {
            self::SUGGESTED, self::PAUSED => false, // approve / resume instead
            self::COMPLETED, self::CANCELLED => in_array($status, [self::PENDING, self::IN_PROGRESS], true),
            default => true,
        };
    }

    /** The same task found in the same message always has the same key, whichever agent or retry finds it. */
    public static function dedupeKey(int $workspaceId, int $messageId, string $title): string
    {
        $normalized = Str::of($title)->lower()->squish()->replaceMatches('/[^\pL\pN ]+/u', '')->toString();

        return hash('sha256', "{$workspaceId}|{$messageId}|{$normalized}");
    }
}
