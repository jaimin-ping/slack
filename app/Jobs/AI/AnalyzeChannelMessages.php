<?php

namespace App\Jobs\AI;

use App\Ai\Exceptions\ProviderException;
use App\Jobs\AI\Concerns\RunsSafelyInline;
use App\Services\AI\MessageAnalysis;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads new messages in one conversation for one agent. Queued after the message is saved and delayed
 * a little, so a burst of messages is read together. Only one copy waits per agent and conversation.
 */
class AnalyzeChannelMessages implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;
    use RunsSafelyInline;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public int $timeout = 240;

    public function __construct(public int $agentId, public int $channelId)
    {
        $this->onQueue(config('ai.queue'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return "{$this->agentId}:{$this->channelId}";
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [15, 60, 240];
    }

    public function handle(MessageAnalysis $analysis): void
    {
        try {
            $analysis->analyzeRecent($this->agentId, $this->channelId, $this->attempts());
        } catch (ProviderException $e) {
            if (! $this->runningInline()) {
                throw $e; // The queue retries with backoff; the claims were released for it.
            }
        }
    }
}
