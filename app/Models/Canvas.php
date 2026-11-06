<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Canvas extends Model
{
    protected $table = 'canvases';

    protected $fillable = ['channel_id', 'body', 'version', 'updated_by'];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'body' => $this->body,
            'version' => $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by' => $this->editor?->name,
        ];
    }
}
