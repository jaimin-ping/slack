<?php

namespace App\Ai;

use App\Ai\Contracts\LanguageModel;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolGate;
use Illuminate\Support\Str;

/**
 * The agent loop: ask the model, run the tools it calls through the gate, repeat.
 * It stops at the step limit, the time limit, a refusal, a pause (checked before every step),
 * or when the model gives a final answer.
 */
class AgentRunner
{
    public function __construct(
        private LanguageModel $model,
        private ToolGate $gate,
        private RunRecorder $recorder,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, Tool>  $tools
     * @return array{text: string, outcome: string}
     */
    public function run(AgentContext $context, string $system, array $messages, array $tools, string $effort): array
    {
        $deadline = now()->addSeconds((int) config('ai.limits.run_seconds'));
        $maxSteps = (int) config('ai.limits.max_steps');
        $definitions = array_values(array_map(fn (Tool $tool) => $tool->definition(), $tools));

        for ($step = 1; $step <= $maxSteps; $step++) {
            $context->ensureCanAct();
            $this->recorder->assertWithinBudget($context->agent);

            if (now()->greaterThan($deadline)) {
                return ['text' => 'I ran out of time before finishing. Ask me again to continue.', 'outcome' => 'time_limit'];
            }

            $response = $this->model->generate(new ModelRequest(
                system: $system,
                messages: $messages,
                tools: $definitions,
                effort: $effort,
                maxTokens: (int) config('ai.limits.max_output_tokens'),
            ));

            $this->recorder->addUsage($context->run, $response->usage, $response->model);
            $this->recorder->step($context->run, 'model', $response->model, 'ok', null, [
                'stop' => $response->stopReason,
                'tool_calls' => array_column($response->toolCalls, 'name'),
                'text' => Str::limit($response->text, 500),
            ]);

            if ($response->stopReason === ModelResponse::REFUSAL) {
                return ['text' => 'I can\'t help with that request.', 'outcome' => 'refused'];
            }

            if ($response->toolCalls === []) {
                return ['text' => trim($response->text), 'outcome' => 'answered'];
            }

            $messages[] = ['role' => 'assistant', 'response' => $response];
            $results = [];

            foreach ($response->toolCalls as $call) {
                $results[] = ['id' => $call['id']] + $this->gate->call($tools[$call['name']] ?? null, $call['name'], $call['input'], $context);
            }

            // All results of one turn go back together, in one message.
            $messages[] = ['role' => 'tool', 'results' => $results];
        }

        return ['text' => 'I stopped after reaching my step limit for one request.', 'outcome' => 'step_limit'];
    }
}
