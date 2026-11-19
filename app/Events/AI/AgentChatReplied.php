<?php

namespace App\Events\AI;

use App\Models\AiAgentChatMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** The agent answered in its private chat with its owner. */
class AgentChatReplied implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    public function __construct(public AiAgentChatMessage $message, public int $ownerId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->ownerId);
    }

    public function broadcastAs(): string
    {
        return 'agent.chat';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->message->toPayload();
    }
}
