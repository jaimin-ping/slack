<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something an agent keeps between runs. Owner memories are preferences; agent memories are only notes. */
class AiAgentMemory extends Model
{
    public const KINDS = ['preference', 'context', 'instruction', 'fact'];

    protected $fillable = ['ai_agent_id', 'kind', 'content', 'origin', 'pinned'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean'];
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
            'kind' => $this->kind,
            'content' => $this->content,
            'origin' => $this->origin,
            'pinned' => $this->pinned,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
