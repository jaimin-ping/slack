<?php

namespace App\Ai\Contracts;

use App\Ai\Exceptions\ProviderException;
use App\Ai\ModelRequest;
use App\Ai\ModelResponse;

/**
 * One model call. Business logic only ever talks to this interface, so the provider can be
 * switched (Anthropic today, others later) without touching agents, tools or tasks.
 */
interface LanguageModel
{
    /** A short provider name stored with each run, e.g. "anthropic". */
    public function provider(): string;

    /** The model the next call will use. */
    public function model(): string;

    /** @throws ProviderException */
    public function generate(ModelRequest $request): ModelResponse;
}
