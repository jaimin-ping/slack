<?php

namespace App\Ai;

use App\Ai\Exceptions\AgentHalted;
use App\Events\AI\AgentRunUpdated;
use App\Models\AiAgent;
use App\Models\AiAgentRun;
use Illuminate\Support\Str;
use Throwable;

/** Keeps the execution history: every run, every step, and what it cost. */
class RunRecorder
{
    private const MAX_STORED_CHARS = 4000;

    /** @param  array<string, mixed>  $context */
    public function start(AiAgent $agent, string $trigger, array $context = [], ?string $idempotencyKey = null, ?string $model = null, ?string $provider = null): AiAgentRun
    {
        $run = AiAgentRun::create([
            'ai_agent_id' => $agent->id,
            'workspace_id' => $agent->workspace_id,
            'owner_id' => $agent->owner_id,
            'trigger' => $trigger,
            'status' => AiAgentRun::RUNNING,
            'idempotency_key' => $idempotencyKey,
            'provider' => $provider,
            'model' => $model,
            'context' => $context ?: null,
            'started_at' => now(),
        ]);

        $agent->forceFill(['last_run_at' => now()])->save();
        $this->announce($run);

        return $run;
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @param  array<string, mixed>|null  $output
     */
    public function step(AiAgentRun $run, string $type, ?string $name, string $status, ?array $input = null, ?array $output = null): void
    {
        $run->stepRecords()->create([
            'sequence' => $run->stepRecords()->count() + 1,
            'type' => $type,
            'name' => $name,
            'status' => $status,
            'input' => $this->trim($input),
            'output' => $this->trim($output),
        ]);
    }

    public function addUsage(AiAgentRun $run, TokenUsage $usage, string $model): void
    {
        $run->increment('steps');
        $run->increment('input_tokens', $usage->input);
        $run->increment('output_tokens', $usage->output);
        $run->increment('cache_read_tokens', $usage->cacheRead);
        $run->increment('cache_write_tokens', $usage->cacheWrite);
        $run->increment('cost_micros', $usage->costMicros($model));
        $run->forceFill(['model' => $model])->save();
    }

    public function finish(AiAgentRun $run, string $status, ?string $summary = null, ?string $error = null): void
    {
        $run->update([
            'status' => $status,
            'summary' => $summary !== null ? Str::limit($summary, 2000) : $run->summary,
            'error' => $error !== null ? Str::limit($error, 2000) : null,
            'finished_at' => now(),
        ]);

        $this->announce($run);
    }

    /**
     * Daily budgets for the agent, its owner and the workspace. Checked before every model call.
     *
     * @throws AgentHalted
     */
    public function assertWithinBudget(AiAgent $agent): void
    {
        $today = now()->startOfDay();
        $spent = fn (string $column, int $id) => (int) AiAgentRun::where($column, $id)->where('created_at', '>=', $today)
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens + cache_read_tokens + cache_write_tokens), 0) as total')
            ->value('total');

        $limits = [
            ['ai_agent_id', $agent->id, 'ai.budgets.agent_daily_tokens', 'this agent'],
            ['owner_id', $agent->owner_id, 'ai.budgets.member_daily_tokens', 'your agents'],
            ['workspace_id', $agent->workspace_id, 'ai.budgets.workspace_daily_tokens', 'this workspace'],
        ];

        foreach ($limits as [$column, $id, $key, $label]) {
            if ($spent($column, $id) >= (int) config($key)) {
                throw new AgentHalted("The daily AI budget for {$label} is used up.", AiAgentRun::SKIPPED);
            }
        }
    }

    private function announce(AiAgentRun $run): void
    {
        try {
            event(new AgentRunUpdated($run));
        } catch (Throwable $e) {
            report($e); // The run is saved; the live update is best effort.
        }
    }

    /** Stored history is kept short; a full message dump is never needed to understand a step. */
    private function trim(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return mb_strlen($json) <= self::MAX_STORED_CHARS ? $data : ['truncated' => mb_substr($json, 0, self::MAX_STORED_CHARS)];
    }
}
