<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An action an agent wants to take that its owner must allow first. */
class AiAgentApproval extends Model
{
    public const PENDING = 'pending';

    protected $fillable = [
        'ai_agent_id', 'workspace_id', 'owner_id', 'ai_agent_run_id', 'task_id', 'action', 'summary', 'payload', 'status',
        'dedupe_key', 'result', 'error', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'decided_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'agent' => $this->agent ? ['id' => $this->agent->id, 'name' => $this->agent->displayName()] : null,
            'action' => $this->action,
            'summary' => $this->summary,
            'payload' => $this->payload,
            'task_id' => $this->task_id,
            'status' => $this->status,
            'result' => $this->result,
            'error' => $this->error,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
