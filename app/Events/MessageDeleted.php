<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class MessageDeleted implements ShouldBroadcastNow
{
    public function __construct(
        public int $channelId,
        public int $id,
        public ?int $parentId = null,
        public ?int $parentReplyCount = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('channel.'.$this->channelId);
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    /** @return array<string, int|null> */
    public function broadcastWith(): array
    {
        return ['id' => $this->id, 'parent_id' => $this->parentId, 'parent_reply_count' => $this->parentReplyCount];
    }
}
