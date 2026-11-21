<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A link pinned to the bar under a channel's header. */
class ChannelBookmark extends Model
{
    protected $fillable = ['channel_id', 'created_by', 'title', 'url'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return array{id: int, title: string, url: string} */
    public function toPayload(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'url' => $this->url];
    }
}
