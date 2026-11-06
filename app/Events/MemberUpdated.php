<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Tells a workspace that a member's status or away state changed. */
class MemberUpdated implements ShouldBroadcastNow
{
    /** @param array<int, int> $workspaceIds */
    public function __construct(public array $workspaceIds) {}

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PresenceChannel('workspace.'.$id), $this->workspaceIds);
    }

    public function broadcastAs(): string
    {
        return 'member.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [];
    }
}
