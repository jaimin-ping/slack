<?php

namespace App\Events;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Tells the person who rang how someone answered. */
class HuddleReplied implements ShouldBroadcastNow
{
    public function __construct(public Channel $channel, public User $user, public int $to, public string $status) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('channel.'.$this->channel->id);
    }

    public function broadcastAs(): string
    {
        return 'huddle.replied';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'to' => $this->to,
            'status' => $this->status,
            'by' => ['id' => $this->user->id, 'name' => $this->user->name],
        ];
    }
}
