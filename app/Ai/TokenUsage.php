<?php

namespace App\Ai;

final class TokenUsage
{
    public function __construct(
        public readonly int $input = 0,
        public readonly int $output = 0,
        public readonly int $cacheRead = 0,
        public readonly int $cacheWrite = 0,
    ) {}

    public function total(): int
    {
        return $this->input + $this->output + $this->cacheRead + $this->cacheWrite;
    }

    /** Cost in millionths of a US dollar, from the price table in config/ai.php. */
    public function costMicros(string $model): int
    {
        $price = config("ai.pricing.{$model}");

        if (! is_array($price)) {
            return 0;
        }

        // Price is per million tokens, so tokens × price gives micro-dollars directly.
        return (int) round(
            $this->input * $price['input']
            + $this->output * $price['output']
            + $this->cacheRead * ($price['cache_read'] ?? $price['input'])
            + $this->cacheWrite * ($price['cache_write'] ?? $price['input'])
        );
    }
}
