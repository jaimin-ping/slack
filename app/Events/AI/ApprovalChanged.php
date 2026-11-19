<?php

namespace App\Events\AI;

use App\Models\AiAgentApproval;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** An agent asked its owner to approve an action, or the owner decided. */
class ApprovalChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    public function __construct(public AiAgentApproval $approval) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->approval->owner_id);
    }

    public function broadcastAs(): string
    {
        return 'agent.approval';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'workspace_id' => $this->approval->workspace_id,
            'approval_id' => $this->approval->id,
            'agent_id' => $this->approval->ai_agent_id,
            'status' => $this->approval->status,
        ];
    }
}
