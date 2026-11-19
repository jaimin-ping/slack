<?php

namespace App\Jobs\AI;

use App\Ai\Exceptions\ProviderException;
use App\Jobs\AI\Concerns\RunsSafelyInline;
use App\Services\AI\MessageAnalysis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/** A look back over conversations and a time range the owner chose explicitly. */
class AnalyzeHistory implements ShouldQueue
{
    use Queueable;
    use RunsSafelyInline;

    public int $tries = 2;

    public int $timeout = 900;

    /** @param  array<int, int>  $channelIds */
    public function __construct(public int $agentId, public array $channelIds, public string $from, public string $to)
    {
        $this->onQueue(config('ai.queue'));
        $this->afterCommit();
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60];
    }

    public function handle(MessageAnalysis $analysis): void
    {
        try {
            $analysis->analyzeHistory($this->agentId, $this->channelIds, Carbon::parse($this->from), Carbon::parse($this->to), $this->attempts());
        } catch (ProviderException $e) {
            if (! $this->runningInline()) {
                throw $e;
            }
        }
    }
}
