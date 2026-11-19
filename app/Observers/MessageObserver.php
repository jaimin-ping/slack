<?php

namespace App\Observers;

use App\Jobs\AI\AnalyzeChannelMessages;
use App\Models\AiAgent;
use App\Models\Message;
use App\Services\AI\AgentCapabilities;
use App\Services\Tasks\TaskService;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Connects messages to agents and tasks without touching the messaging code itself.
 * Every hook is guarded: a problem here is reported, never allowed to fail sending, editing or deleting a message.
 */
class MessageObserver
{
    public function __construct(private TaskService $tasks) {}

    public function created(Message $message): void
    {
        // Queued only once the message is saved: dispatching takes a unique-job lock, and must never run inside
        // the transaction that stores the message.
        DB::afterCommit(fn () => $this->safely(fn () => $this->queueAnalysis($message)));
    }

    public function updated(Message $message): void
    {
        $this->safely(function () use ($message) {
            if ($message->wasChanged('removed_at') && $message->removed_at !== null) {
                $this->tasks->sourceRemoved($message);

                return;
            }

            if ($message->wasChanged('body')) {
                $this->tasks->sourceEdited($message);
            }
        });

        if ($message->wasChanged('body') && $message->removed_at === null) {
            DB::afterCommit(fn () => $this->safely(fn () => $this->queueAnalysis($message)));
        }
    }

    public function deleting(Message $message): void
    {
        $this->safely(fn () => $this->tasks->sourceRemoved($message));
    }

    /** Only messages from people are read, and only by active agents that were given this conversation. */
    private function queueAnalysis(Message $message): void
    {
        if ($message->user_id === null) {
            return;
        }

        $agentIds = AiAgent::where('status', AgentCapabilities::STATUS_ACTIVE)
            ->whereHas('sources', fn ($query) => $query->where('channel_id', $message->channel_id))
            ->pluck('id');

        foreach ($agentIds as $agentId) {
            AnalyzeChannelMessages::dispatch($agentId, $message->channel_id)
                ->delay(now()->addSeconds((int) config('ai.realtime.debounce_seconds')));
        }
    }

    /**
     * Inside someone's transaction, the work runs in a savepoint: on PostgreSQL one failed statement aborts the
     * whole transaction, so without it a problem here would silently roll back the person's own message.
     */
    private function safely(Closure $callback): void
    {
        try {
            DB::transactionLevel() > 0 ? DB::transaction($callback) : $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
