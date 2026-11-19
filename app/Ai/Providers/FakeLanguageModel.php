<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\LanguageModel;
use App\Ai\Exceptions\ProviderException;
use App\Ai\ModelRequest;
use App\Ai\ModelResponse;
use App\Ai\TokenUsage;
use Closure;

/**
 * Scripted replies for tests and local development: no network, no cost.
 * Each queued reply is a ModelResponse, a Throwable to throw, or a closure that receives the request.
 */
class FakeLanguageModel implements LanguageModel
{
    /** @var array<int, ModelResponse|\Throwable|Closure> */
    private array $queue = [];

    /** @var array<int, ModelRequest> */
    public array $requests = [];

    /** @param  array<int, ModelResponse|\Throwable|Closure>  $replies */
    public static function fake(array $replies = []): self
    {
        $fake = new self;
        $fake->queue = $replies;
        app()->instance(LanguageModel::class, $fake);

        return $fake;
    }

    public function push(ModelResponse|\Throwable|Closure ...$replies): self
    {
        array_push($this->queue, ...$replies);

        return $this;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function generate(ModelRequest $request): ModelResponse
    {
        $this->requests[] = $request;
        $reply = array_shift($this->queue);

        if ($reply instanceof Closure) {
            $reply = $reply($request);
        }

        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return $reply ?? ($request->outputSchema !== null ? self::json(['tasks' => [], 'updates' => []]) : self::text('Done.'));
    }

    public static function text(string $text, int $tokens = 100): ModelResponse
    {
        return new ModelResponse($text, [], ModelResponse::END, new TokenUsage($tokens, $tokens), 'fake-model');
    }

    /** @param  array<string, mixed>  $data */
    public static function json(array $data, int $tokens = 100): ModelResponse
    {
        return self::text(json_encode($data), $tokens);
    }

    /** @param  array<int, array{0: string, 1: array<string, mixed>}>|array{0: string, 1: array<string, mixed>}  $calls */
    public static function tools(array $calls, int $tokens = 100): ModelResponse
    {
        $calls = is_string($calls[0] ?? null) ? [$calls] : $calls;

        return new ModelResponse(
            '',
            array_map(fn (array $call, int $i) => ['id' => 'call_'.$i.'_'.$call[0], 'name' => $call[0], 'input' => $call[1]], $calls, array_keys($calls)),
            ModelResponse::TOOL_USE,
            new TokenUsage($tokens, $tokens),
            'fake-model',
        );
    }

    public static function refusal(): ModelResponse
    {
        return new ModelResponse('', [], ModelResponse::REFUSAL, new TokenUsage(10, 0), 'fake-model');
    }

    public static function failure(bool $retryable = true): ProviderException
    {
        return new ProviderException('Fake provider failure.', $retryable);
    }
}
