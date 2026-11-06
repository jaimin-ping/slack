<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Moves a removed person out of the workspace page straight away. */
class MemberRemoved implements ShouldBroadcastNow
{
    public function __construct(public int $workspaceId, public int $userId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'member.removed';
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['workspace_id' => $this->workspaceId];
    }
}
