<?php

namespace Tests\Unit;

use App\Ai\Exceptions\ProviderException;
use App\Ai\ModelRequest;
use App\Ai\ModelResponse;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\TokenUsage;
use App\Services\AI\TaskExtractor;
use PHPUnit\Framework\TestCase;

/** Checks the request sent to Anthropic, without any network call. */
class AnthropicProviderTest extends TestCase
{
    private function provider(array $config = []): AnthropicProvider
    {
        return new AnthropicProvider($config + ['api_key' => 'test', 'model' => 'claude-opus-5-5', 'timeout' => 90, 'max_retries' => 2, 'fallbacks' => 'default']);
    }

    public function test_extraction_request_uses_structured_output_and_a_cached_system_prompt(): void
    {
        $params = $this->provider()->buildParams(new ModelRequest(
            system: 'You are Alex.',
            messages: [['role' => 'user', 'content' => 'messages here']],
            outputSchema: TaskExtractor::schema(),
            effort: 'low',
            maxTokens: 16000,
        ));

        $this->assertSame('claude-opus-5-5', $params['model']);
        $this->assertSame(16000, $params['maxTokens']);
        $this->assertSame(['type' => 'ephemeral'], $params['system'][0]['cacheControl']);
        $this->assertSame('low', $params['outputConfig']['effort']);
        $this->assertSame('json_schema', $params['outputConfig']['format']['type']);
        $this->assertFalse($params['outputConfig']['format']['schema']['additionalProperties']);
        $this->assertArrayNotHasKey('thinking', $params, 'Thinking is always on for this model and must not be set.');
        $this->assertArrayNotHasKey('toolChoice', $params);
        $this->assertArrayNotHasKey('tools', $params);
        $this->assertSame(['server-side-fallback-2026-07-01'], $params['betas']);
        $this->assertSame('default', $params['fallbacks']);
    }

    public function test_tool_loop_turns_are_replayed_faithfully(): void
    {
        $native = [['type' => 'thinking', 'thinking' => '…', 'signature' => 'sig'], ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'list_tasks', 'input' => []]];
        $previous = new ModelResponse('', [['id' => 'tu_1', 'name' => 'list_tasks', 'input' => []]], ModelResponse::TOOL_USE, new TokenUsage(1, 1), 'claude-opus-5-5', $native);

        $params = $this->provider(['fallbacks' => null])->buildParams(new ModelRequest(
            system: 'sys',
            messages: [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'response' => $previous],
                ['role' => 'tool', 'results' => [['id' => 'tu_1', 'content' => '{"tasks":[]}', 'is_error' => false]]],
            ],
            tools: [['name' => 'list_tasks', 'description' => 'List tasks', 'schema' => ['type' => 'object', 'properties' => new \stdClass]]],
            effort: 'medium',
        ));

        $this->assertSame($native, $params['messages'][1]['content'], 'Thinking blocks go back unchanged.');
        $this->assertSame('user', $params['messages'][2]['role']);
        $this->assertSame(['type' => 'tool_result', 'toolUseID' => 'tu_1', 'content' => '{"tasks":[]}', 'isError' => false], $params['messages'][2]['content'][0]);
        $this->assertSame('inputSchema', array_keys($params['tools'][0])[2]);
        $this->assertArrayNotHasKey('betas', $params);
    }

    public function test_a_missing_key_fails_without_calling_out(): void
    {
        $this->expectException(ProviderException::class);

        $this->provider(['api_key' => ''])->generate(new ModelRequest(system: 's', messages: [['role' => 'user', 'content' => 'x']]));
    }
}
