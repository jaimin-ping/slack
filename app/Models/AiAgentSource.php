<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One conversation an agent was explicitly allowed to read. */
class AiAgentSource extends Model
{
    protected $fillable = ['ai_agent_id', 'channel_id'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return array<string, mixed> */
    public function toPayload(int $ownerId): array
    {
        $channel = $this->channel;
        $label = $channel->type === Channel::DM
            ? (User::find($channel->dmPartnerId($ownerId))?->name ?? 'Direct message')
            : '#'.$channel->name;

        return [
            'channel_id' => $channel->id,
            'type' => $channel->type,
            'label' => $label,
        ];
    }
}
