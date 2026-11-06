<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Tells users that their channel list changed, so their sidebar reloads. */
class SidebarChanged implements ShouldBroadcastNow
{
    /** @param array<int, int> $userIds */
    public function __construct(public int $workspaceId, public array $userIds) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'sidebar.changed';
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['workspace_id' => $this->workspaceId];
    }
}
