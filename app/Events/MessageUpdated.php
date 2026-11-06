<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class MessageUpdated implements ShouldBroadcastNow
{
    public function __construct(public Message $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('channel.'.$this->message->channel_id);
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->message->toPayload();
    }
}
