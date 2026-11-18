<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['section_id', 'attachment_id', 'added_at'];

    protected $casts = [
        'added_at' => 'datetime',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'attachment_id' => $this->attachment_id,
            'attachment' => $this->attachment ? [
                'id' => $this->attachment->id,
                'name' => $this->attachment->name,
                'url' => route('attachments.show', $this->attachment->id, false),
                'is_image' => $this->attachment->isInlineImage(),
                'size' => $this->attachment->size,
            ] : null,
            'added_at' => $this->added_at?->toISOString(),
        ];
    }
}
