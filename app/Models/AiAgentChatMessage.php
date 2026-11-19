<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the owner's private conversation with their agent. */
class AiAgentChatMessage extends Model
{
    protected $fillable = ['ai_agent_id', 'role', 'content', 'ai_agent_run_id'];

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->ai_agent_id,
            'role' => $this->role,
            'content' => $this->content,
            'run_id' => $this->ai_agent_run_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
