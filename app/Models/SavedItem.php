<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SavedItem extends Model
{
    protected $fillable = ['user_id', 'saveable_type', 'saveable_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function saveable(): MorphTo
    {
        return $this->morphTo();
    }

    public function toPayload(): array
    {
        $saveable = $this->saveable;
        $saveableData = null;

        if ($saveable instanceof Attachment) {
            $saveableData = [
                'id' => $saveable->id,
                'name' => $saveable->name,
                'url' => route('attachments.show', $saveable->id, false),
                'size' => $saveable->size,
                'is_image' => $saveable->isInlineImage(),
                'created_at' => $saveable->created_at?->toISOString(),
            ];
        } elseif ($saveable instanceof Message) {
            $saveableData = [
                'id' => $saveable->id,
                'body' => $saveable->body,
                'channel_id' => $saveable->channel_id,
                'created_at' => $saveable->created_at?->toISOString(),
            ];
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'saveable_type' => $this->saveable_type,
            'saveable_id' => $this->saveable_id,
            'saveable' => $saveableData,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
