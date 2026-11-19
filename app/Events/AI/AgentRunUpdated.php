<?php

namespace App\Events\AI;

use App\Models\AiAgentRun;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** An agent started, finished or failed a run. Only its owner hears about it. */
class AgentRunUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    public function __construct(public AiAgentRun $run) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->run->owner_id);
    }

    public function broadcastAs(): string
    {
        return 'agent.run';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'workspace_id' => $this->run->workspace_id,
            'agent_id' => $this->run->ai_agent_id,
            'run_id' => $this->run->id,
            'trigger' => $this->run->trigger,
            'status' => $this->run->status,
        ];
    }
}
