<?php

namespace App\Events\AI;

use App\Models\AiAgent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Tells the owner's open windows that an agent was paused, resumed or deactivated. */
class AgentStatusChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    public function __construct(public AiAgent $agent) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->agent->owner_id);
    }

    public function broadcastAs(): string
    {
        return 'agent.status';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->agent->id,
            'workspace_id' => $this->agent->workspace_id,
            'status' => $this->agent->status,
            'paused_at' => $this->agent->paused_at?->toIso8601String(),
        ];
    }
}
