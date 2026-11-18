<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Todo extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'status',
        'due_date',
        'linked_type',
        'linked_id',
        'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function linked(): MorphTo
    {
        return $this->morphTo('linked');
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function markPending(): void
    {
        $this->update([
            'status' => 'pending',
            'completed_at' => null,
        ]);
    }

    public function toPayload(): array
    {
        $linkedItem = null;
        $linked = $this->linked;

        if ($linked instanceof Attachment) {
            $linkedItem = [
                'id' => $linked->id,
                'name' => $linked->name,
                'url' => route('attachments.show', $linked->id, false),
            ];
        } elseif ($linked instanceof Message) {
            $linkedItem = [
                'id' => $linked->id,
                'body' => $linked->body,
                'channel_id' => $linked->channel_id,
            ];
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'due_date' => $this->due_date?->toISOString(),
            'linked_type' => $this->linked_type,
            'linked_id' => $this->linked_id,
            'linked_item' => $linkedItem,
            'created_at' => $this->created_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
