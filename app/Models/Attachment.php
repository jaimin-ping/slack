<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    /** Only these open in the browser. SVG is excluded because it can carry scripts. */
    public const INLINE_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    protected $fillable = ['message_id', 'name', 'path', 'mime', 'size'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isInlineImage(): bool
    {
        return in_array($this->mime, self::INLINE_IMAGE_MIMES, true);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'is_image' => $this->isInlineImage(),
            'url' => route('attachments.show', $this->id, false),
        ];
    }
}
