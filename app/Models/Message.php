<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Message extends Model
{
    protected $fillable = [
        'channel_id', 'user_id', 'body', 'parent_id', 'webhook_id', 'bot_name', 'cards', 'edited_at', 'removed_at',
        'pinned_at', 'pinned_by', 'link_preview',
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'cards' => 'array',
            'link_preview' => 'array',
            'edited_at' => 'datetime',
            'removed_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function deleteAttachments(): void
    {
        $this->attachments->each(function (Attachment $file) {
            Storage::disk('local')->delete($file->path);
            $file->delete();
        });
        $this->unsetRelation('attachments');
    }

    /** @return array<int, array{emoji: string, user_ids: array<int, int>}> */
    public function reactionSummary(): array
    {
        return $this->reactions->sortBy('id')->groupBy('emoji')
            ->map(fn ($group, $emoji) => ['emoji' => $emoji, 'user_ids' => $group->pluck('user_id')->all()])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'parent_id' => $this->parent_id,
            'body' => $this->body,
            'created_at' => $this->created_at->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'removed' => $this->removed_at !== null,
            'pinned' => $this->pinned_at !== null,
            'link_preview' => $this->link_preview,
            'is_bot' => $this->user_id === null,
            'user' => $this->user
                ? ['id' => $this->user->id, 'name' => $this->user->name]
                : ['id' => 0, 'name' => $this->bot_name ?? 'App'],
            'cards' => $this->cards ?? [],
            'reactions' => $this->reactionSummary(),
            'attachments' => $this->attachments->map(fn (Attachment $file) => $file->toPayload())->all(),
            'reply_count' => (int) ($this->replies_count ?? 0),
            'last_reply_at' => $this->replies_max_created_at
                ? Carbon::parse($this->replies_max_created_at)->toIso8601String()
                : null,
            // Set on a new reply, so clients can update the parent's count without guessing.
            'parent_reply_count' => $this->parent_reply_count,
        ];
    }
}
