<?php

namespace App\Ai;

/**
 * A provider-neutral request.
 *
 * Messages are a list of turns:
 *  - ['role' => 'user', 'content' => string]
 *  - ['role' => 'assistant', 'response' => ModelResponse]   (replayed exactly as the provider returned it)
 *  - ['role' => 'tool', 'results' => [['id' => string, 'content' => string, 'is_error' => bool], ...]]
 *
 * The system prompt holds only stable text, so providers can cache it; anything that changes per call
 * (the time, the messages being read) belongs in the user turn.
 */
final class ModelRequest
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array{name: string, description: string, schema: array<string, mixed>}>  $tools
     * @param  array<string, mixed>|null  $outputSchema  JSON schema the reply must follow
     */
    public function __construct(
        public readonly string $system,
        public readonly array $messages,
        public readonly array $tools = [],
        public readonly ?array $outputSchema = null,
        public readonly string $effort = 'medium',
        public readonly int $maxTokens = 16000,
    ) {}
}
