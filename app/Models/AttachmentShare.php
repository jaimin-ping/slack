<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachmentShare extends Model
{
    protected $fillable = [
        'attachment_id',
        'shared_by',
        'shared_to_type',
        'shared_to_id',
        'message_id',
        'message',
    ];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }

    public function sharedToChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'shared_to_id');
    }

    public function messageRecord(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function toPayload(): array
    {
        $sharedToName = null;
        if ($this->shared_to_type === 'channel') {
            $channel = Channel::find($this->shared_to_id);
            $sharedToName = $channel?->name;
        }

        return [
            'id' => $this->id,
            'shared_by' => $this->sharedBy ? [
                'id' => $this->sharedBy->id,
                'name' => $this->sharedBy->name,
            ] : null,
            'shared_to_type' => $this->shared_to_type,
            'shared_to_id' => $this->shared_to_id,
            'shared_to_name' => $sharedToName,
            'message' => $this->message,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
