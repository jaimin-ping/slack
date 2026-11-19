<?php

namespace App\Services\AI;

use App\Ai\AgentContext;
use App\Ai\Contracts\LanguageModel;
use App\Ai\Exceptions\AgentHalted;
use App\Ai\Exceptions\ProviderException;
use App\Ai\RunRecorder;
use App\Models\AiAgent;
use App\Models\AiAgentMessageScan;
use App\Models\AiAgentRun;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Feeds new or historical messages to an agent's task extraction.
 *
 * Each message is claimed per agent and per version of its text before it is read, so retries,
 * restarts and overlapping jobs never read it twice, while an edit gets a fresh look.
 * A failed run releases its claims, so the messages are read again by the retry.
 */
class MessageAnalysis
{
    public function __construct(
        private RunRecorder $recorder,
        private TaskExtractor $extractor,
        private MessagePrefilter $prefilter,
        private LanguageModel $model,
    ) {}

    /** New messages in one conversation, read shortly after they arrive. */
    public function analyzeRecent(int $agentId, int $channelId, int $attempt = 1): void
    {
        $agent = AiAgent::find($agentId);
        $channel = Channel::find($channelId);

        if (! $agent || ! $channel || ! $this->detects($agent, realtime: true)) {
            return;
        }

        $since = now()->subHours((int) config('ai.realtime.window_hours'));

        $this->process($agent, $channel, $this->unscanned($agent, $channel, $since, null, (int) config('ai.limits.messages_per_batch')), 'message', $attempt);
    }

    /**
     * A look back over conversations the owner chose, for a time range they chose.
     *
     * @param  array<int, int>  $channelIds
     */
    public function analyzeHistory(int $agentId, array $channelIds, Carbon $from, Carbon $to, int $attempt = 1): int
    {
        $agent = AiAgent::find($agentId);

        if (! $agent || ! $this->detects($agent, realtime: false)) {
            return 0;
        }

        $budget = (int) config('ai.limits.history_messages');
        $read = 0;

        foreach (Channel::whereIn('id', $channelIds)->get() as $channel) {
            while ($read < $budget) {
                $batch = $this->unscanned($agent, $channel, $from, $to, min((int) config('ai.limits.messages_per_batch'), $budget - $read));

                if ($batch->isEmpty() || ! $this->process($agent, $channel, $batch, 'history', $attempt)) {
                    break;
                }

                $read += $batch->count();
            }
        }

        return $read;
    }

    private function detects(AiAgent $agent, bool $realtime): bool
    {
        $settings = $agent->resolvedSettings()['task_detection'];

        return $agent->isActive() && $settings['enabled'] && (! $realtime || $settings['realtime']);
    }

    /** @return Collection<int, Message> */
    private function unscanned(AiAgent $agent, Channel $channel, Carbon $from, ?Carbon $to, int $limit): Collection
    {
        $query = Message::with('user:id,name')
            ->where('channel_id', $channel->id)
            ->whereNull('removed_at')
            ->whereNotNull('user_id') // only people: bots and other agents are never read as instructions or tasks
            ->where('created_at', '>=', $from)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ai_agent_message_scans')
                ->where('ai_agent_message_scans.ai_agent_id', $agent->id)
                ->whereColumn('ai_agent_message_scans.message_id', 'messages.id'))
            ->orderBy('id');

        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        $fresh = $query->limit($limit)->get();

        // Edited messages already have a scan for their old text; include them when the text changed.
        $edited = Message::with('user:id,name')
            ->where('channel_id', $channel->id)
            ->whereNull('removed_at')
            ->whereNotNull('user_id')
            ->whereNotNull('edited_at')
            ->where('edited_at', '>=', $from)
            ->whereIn('id', AiAgentMessageScan::where('ai_agent_id', $agent->id)->select('message_id'))
            ->orderBy('id')->limit($limit)->get()
            ->reject(fn (Message $message) => AiAgentMessageScan::where([
                'ai_agent_id' => $agent->id, 'message_id' => $message->id, 'content_hash' => AiAgentMessageScan::hashFor($message),
            ])->exists());

        return $fresh->concat($edited)->unique('id')->sortBy('id')->take($limit)->values();
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return bool whether anything was claimed
     */
    private function process(AiAgent $agent, Channel $channel, Collection $messages, string $trigger, int $attempt): bool
    {
        if ($messages->isEmpty() || ! $this->ownerCanRead($agent, $channel)) {
            return false;
        }

        [$work, $chatter] = $messages->partition(fn (Message $message) => $this->prefilter->mightContainWork($message->body));
        $this->claim($agent, $chatter, 'skipped');
        $claimed = $this->claim($agent, $work, 'claimed');

        if ($claimed->isEmpty()) {
            return $chatter->isNotEmpty();
        }

        $key = hash('sha256', $agent->id.'|'.$trigger.'|'.$attempt.'|'.$claimed->map(fn ($m) => $m->id.':'.AiAgentMessageScan::hashFor($m))->implode(','));

        if (AiAgentRun::where('idempotency_key', $key)->exists()) {
            return true;
        }

        $run = $this->recorder->start($agent, $trigger, [
            'channel_id' => $channel->id,
            'message_ids' => $claimed->pluck('id')->all(),
        ], $key, $this->model->model(), $this->model->provider());

        $scans = AiAgentMessageScan::where('ai_agent_id', $agent->id)->whereIn('message_id', $claimed->pluck('id'))->where('status', 'claimed');
        (clone $scans)->update(['ai_agent_run_id' => $run->id]);

        try {
            $result = $this->extractor->extract(new AgentContext($agent, $run), $channel, $claimed);
            (clone $scans)->update(['status' => 'done']);
            $this->recorder->finish($run, AiAgentRun::SUCCEEDED, $this->describe($result, $claimed->count()));
        } catch (AgentHalted $e) {
            (clone $scans)->delete();
            $this->recorder->finish($run, $e->runStatus, null, $e->getMessage());

            return false;
        } catch (ProviderException $e) {
            (clone $scans)->delete();
            $this->recorder->finish($run, AiAgentRun::FAILED, null, $e->getMessage());

            if ($e->retryable) {
                throw $e; // The queue tries again with backoff.
            }

            return false;
        } catch (Throwable $e) {
            (clone $scans)->delete();
            $this->recorder->finish($run, AiAgentRun::FAILED, null, 'Unexpected error while reading messages.');
            report($e);

            return false;
        }

        return true;
    }

    /**
     * Claims messages for this agent. A unique index makes the claim atomic across workers.
     *
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, Message> the messages this call claimed
     */
    private function claim(AiAgent $agent, Collection $messages, string $status): Collection
    {
        return $messages->filter(fn (Message $message) => AiAgentMessageScan::insertOrIgnore([
            'ai_agent_id' => $agent->id,
            'message_id' => $message->id,
            'content_hash' => AiAgentMessageScan::hashFor($message),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1)->values();
    }

    private function ownerCanRead(AiAgent $agent, Channel $channel): bool
    {
        return $channel->workspace_id === $agent->workspace_id
            && $channel->archived_at === null
            && $agent->sources()->where('channel_id', $channel->id)->exists()
            && $channel->hasMember($agent->owner);
    }

    /** @param  array<string, int>  $result */
    private function describe(array $result, int $read): string
    {
        return "Read {$read} message(s): {$result['created']} task(s) created, {$result['suggested']} suggested, "
            ."{$result['updated']} updated, {$result['dropped']} not kept.";
    }
}
