<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Carries only the version: a canvas can be larger than a WebSocket message, so clients refetch it. */
class CanvasUpdated implements ShouldBroadcastNow
{
    public function __construct(public int $channelId, public int $version) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('channel.'.$this->channelId);
    }

    public function broadcastAs(): string
    {
        return 'canvas.updated';
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channelId, 'version' => $this->version];
    }
}
