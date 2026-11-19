<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in a task's history. Comments are history entries with a body. */
class TaskEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'actor_id', 'ai_agent_id', 'type', 'data', 'body'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data ?? [],
            'body' => $this->body,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null,
            'agent' => $this->agent ? ['id' => $this->agent->id, 'name' => $this->agent->displayName()] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
