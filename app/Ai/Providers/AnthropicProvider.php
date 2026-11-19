<?php

namespace App\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Ai\Contracts\LanguageModel;
use App\Ai\Exceptions\ProviderException;
use App\Ai\ModelRequest;
use App\Ai\ModelResponse;
use App\Ai\TokenUsage;

/**
 * Claude through the official Anthropic PHP SDK.
 *
 * - The system prompt is sent as a cached block; it holds only stable text.
 * - Thinking is left to the model's default (adaptive on current models), and depth is set with effort.
 * - Tool choice stays automatic: current models reject forced tool use, and every tool input is validated here anyway.
 * - A policy decline is retried on the server-chosen fallback model in the same call.
 */
class AnthropicProvider implements LanguageModel
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private ?Client $client = null;

    /** @param  array<string, mixed>  $config */
    public function __construct(private array $config) {}

    public function provider(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return (string) $this->config['model'];
    }

    public function generate(ModelRequest $request): ModelResponse
    {
        if (empty($this->config['api_key'])) {
            throw new ProviderException('No Anthropic API key is configured (ANTHROPIC_API_KEY).');
        }

        try {
            $message = $this->client()->beta->messages->create(...$this->buildParams($request));
        } catch (RateLimitException|InternalServerException|APIConnectionException $e) {
            // Rate limits, overload (529 is an InternalServerException) and timeouts are worth trying again later.
            throw new ProviderException($this->describe($e), true, $e);
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new ProviderException('The Anthropic API key was rejected.', false, $e);
        } catch (BadRequestException $e) {
            throw new ProviderException($this->describe($e), false, $e);
        } catch (APIStatusException|APIException $e) {
            throw new ProviderException($this->describe($e), false, $e);
        }

        $text = '';
        $calls = [];

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            } elseif ($block->type === 'tool_use') {
                $calls[] = ['id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
            }
        }

        $stop = match ($message->stopReason) {
            'tool_use' => ModelResponse::TOOL_USE,
            'max_tokens' => ModelResponse::MAX_TOKENS,
            'refusal' => ModelResponse::REFUSAL,
            default => ModelResponse::END,
        };

        $usage = $message->usage;

        return new ModelResponse(
            text: $text,
            toolCalls: $stop === ModelResponse::TOOL_USE ? $calls : [],
            stopReason: $stop,
            usage: new TokenUsage(
                input: $usage->inputTokens,
                output: $usage->outputTokens,
                cacheRead: $usage->cacheReadInputTokens ?? 0,
                cacheWrite: $usage->cacheCreationInputTokens ?? 0,
            ),
            model: $message->model,
            native: $message->content,
        );
    }

    /**
     * The named arguments for the SDK call. Public so the request shape can be checked without a network call.
     *
     * @return array<string, mixed>
     */
    public function buildParams(ModelRequest $request): array
    {
        $outputConfig = ['effort' => $request->effort];

        if ($request->outputSchema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $request->outputSchema];
        }

        $params = [
            'model' => $this->model(),
            'maxTokens' => $request->maxTokens,
            'system' => [['type' => 'text', 'text' => $request->system, 'cacheControl' => ['type' => 'ephemeral']]],
            'messages' => array_map(fn (array $turn) => $this->turn($turn), $request->messages),
            'outputConfig' => $outputConfig,
        ];

        if ($request->tools !== []) {
            $params['tools'] = array_map(fn (array $tool) => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['schema'],
            ], $request->tools);
        }

        if (! empty($this->config['fallbacks'])) {
            $params['betas'] = [self::FALLBACK_BETA];
            $params['fallbacks'] = $this->config['fallbacks'];
        }

        return $params;
    }

    /** @param  array<string, mixed>  $turn */
    private function turn(array $turn): array
    {
        return match ($turn['role']) {
            'user' => ['role' => 'user', 'content' => (string) $turn['content']],
            // The provider's own blocks, thinking included, go back unchanged.
            'assistant' => ['role' => 'assistant', 'content' => isset($turn['response'])
                ? ($turn['response']->native ?? $turn['response']->text)
                : (string) $turn['content']],
            'tool' => ['role' => 'user', 'content' => array_map(fn (array $result) => [
                'type' => 'tool_result',
                'toolUseID' => $result['id'],
                'content' => $result['content'],
                'isError' => (bool) ($result['is_error'] ?? false),
            ], $turn['results'])],
        };
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->config['api_key'],
            requestOptions: [
                'timeout' => (float) $this->config['timeout'],
                'maxRetries' => (int) $this->config['max_retries'],
            ],
        );
    }

    private function describe(APIException $e): string
    {
        $type = $e instanceof APIStatusException ? ($e->type?->value ?? 'api_error') : 'connection_error';

        return "Anthropic request failed ({$type}).";
    }
}
