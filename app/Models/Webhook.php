<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Webhook extends Model
{
    protected $fillable = ['workspace_id', 'channel_id', 'created_by', 'name', 'token_hash', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel_id' => $this->channel_id,
            'channel_name' => $this->channel->name,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
