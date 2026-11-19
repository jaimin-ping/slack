<?php

namespace App\Ai;

/** A provider-neutral reply. */
final class ModelResponse
{
    public const END = 'end';

    public const TOOL_USE = 'tool_use';

    public const MAX_TOKENS = 'max_tokens';

    public const REFUSAL = 'refusal';

    /**
     * @param  array<int, array{id: string, name: string, input: array<string, mixed>}>  $toolCalls
     * @param  mixed  $native  the provider's own content, replayed unchanged on the next call of the same loop
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly string $stopReason,
        public readonly TokenUsage $usage,
        public readonly string $model,
        public readonly mixed $native = null,
    ) {}

    /** The reply parsed as JSON, for requests that set an output schema. */
    public function json(): ?array
    {
        $decoded = json_decode($this->text, true);

        return is_array($decoded) ? $decoded : null;
    }
}
