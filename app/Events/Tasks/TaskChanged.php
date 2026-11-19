<?php

namespace App\Events\Tasks;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Tells the people involved that a task changed. Only the id travels: each client then fetches
 * the task through the normal endpoint, so what it sees is always filtered for that person.
 */
class TaskChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    /** @param  array<int, int>  $userIds */
    public function __construct(
        public int $workspaceId,
        public int $taskId,
        public string $action,
        public array $userIds,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), array_values(array_unique($this->userIds)));
    }

    public function broadcastAs(): string
    {
        return 'task.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['workspace_id' => $this->workspaceId, 'task_id' => $this->taskId, 'action' => $this->action];
    }
}
