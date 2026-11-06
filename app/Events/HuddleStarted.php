<?php

namespace App\Events;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Rings everyone in a conversation when somebody opens a huddle there. */
class HuddleStarted implements ShouldBroadcastNow
{
    public function __construct(public Channel $channel, public User $user) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('channel.'.$this->channel->id);
    }

    public function broadcastAs(): string
    {
        return 'huddle.started';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->channel->id,
            'by' => ['id' => $this->user->id, 'name' => $this->user->name],
        ];
    }
}
