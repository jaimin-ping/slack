<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledMessage extends Model
{
    protected $fillable = ['user_id', 'channel_id', 'body', 'send_at', 'sent_at', 'failed'];

    protected function casts(): array
    {
        return ['send_at' => 'datetime', 'sent_at' => 'datetime', 'failed' => 'boolean'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
