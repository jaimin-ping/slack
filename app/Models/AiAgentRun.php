<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One execution of an agent: what started it, what it cost and how it ended. */
class AiAgentRun extends Model
{
    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'ai_agent_id', 'workspace_id', 'owner_id', 'trigger', 'status', 'idempotency_key', 'provider', 'model', 'steps',
        'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost_micros', 'context', 'summary',
        'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['context' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function stepRecords(): HasMany
    {
        return $this->hasMany(AiAgentRunStep::class)->orderBy('sequence');
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens + $this->cache_read_tokens + $this->cache_write_tokens;
    }

    /** @return array<string, mixed> */
    public function toPayload(bool $withSteps = false): array
    {
        $payload = [
            'id' => $this->id,
            'agent_id' => $this->ai_agent_id,
            'trigger' => $this->trigger,
            'status' => $this->status,
            'model' => $this->model,
            'steps' => $this->steps,
            'tokens' => [
                'input' => $this->input_tokens,
                'output' => $this->output_tokens,
                'cache_read' => $this->cache_read_tokens,
                'cache_write' => $this->cache_write_tokens,
            ],
            'cost_usd' => round($this->cost_micros / 1_000_000, 6),
            'summary' => $this->summary,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];

        if ($withSteps) {
            $payload['step_log'] = $this->stepRecords->map(fn (AiAgentRunStep $step) => $step->toPayload())->all();
        }

        return $payload;
    }
}
