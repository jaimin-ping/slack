<?php

namespace App\Jobs\AI;

use App\Jobs\AI\Concerns\RunsSafelyInline;
use App\Services\AI\AgentChat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** The agent answers its owner's chat message in the background. */
class RespondToChat implements ShouldQueue
{
    use Queueable;
    use RunsSafelyInline;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $chatMessageId)
    {
        $this->onQueue(config('ai.queue'));
        $this->afterCommit();
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(AgentChat $chat): void
    {
        // On the last try, or when there is no queue to retry on, the agent apologises instead of failing.
        $chat->respond($this->chatMessageId, $this->attempts(), finalAttempt: $this->runningInline() || $this->attempts() >= $this->tries);
    }
}
