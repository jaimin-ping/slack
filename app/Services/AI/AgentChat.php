<?php

namespace App\Services\AI;

use App\Ai\AgentContext;
use App\Ai\AgentRunner;
use App\Ai\Contracts\LanguageModel;
use App\Ai\Exceptions\AgentHalted;
use App\Ai\Exceptions\ProviderException;
use App\Ai\Prompts\AgentPrompt;
use App\Ai\RunRecorder;
use App\Ai\Tools\Toolbox;
use App\Events\AI\AgentChatReplied;
use App\Jobs\AI\RespondToChat;
use App\Models\AiAgent;
use App\Models\AiAgentChatMessage;
use App\Models\AiAgentRun;
use App\Models\User;
use Throwable;

/**
 * The owner's private chat with their agent. A paused agent still answers, using read-only tools;
 * a deactivated one does not answer at all.
 */
class AgentChat
{
    private const HISTORY = 12;

    public function __construct(
        private AgentRunner $runner,
        private RunRecorder $recorder,
        private AgentPrompt $prompt,
        private Toolbox $toolbox,
        private LanguageModel $model,
    ) {}

    public function send(AiAgent $agent, User $owner, string $text): AiAgentChatMessage
    {
        $message = AiAgentChatMessage::create(['ai_agent_id' => $agent->id, 'role' => 'owner', 'content' => trim($text)]);

        RespondToChat::dispatch($message->id);

        return $message;
    }

    public function respond(int $chatMessageId, int $attempt = 1, bool $finalAttempt = true): void
    {
        $question = AiAgentChatMessage::find($chatMessageId);
        $agent = $question ? AiAgent::with(['owner', 'workspace'])->find($question->ai_agent_id) : null;

        if (! $question || ! $agent || $this->alreadyAnswered($chatMessageId)) {
            return;
        }

        if ($agent->status === AgentCapabilities::STATUS_INACTIVE) {
            $this->reply($agent, 'I am deactivated. Reactivate me to chat.', null);

            return;
        }

        $run = $this->recorder->start($agent, 'chat', ['chat_message_id' => $chatMessageId], "chat:{$chatMessageId}:{$attempt}", $this->model->model(), $this->model->provider());
        $context = new AgentContext($agent, $run, readOnly: $agent->status !== AgentCapabilities::STATUS_ACTIVE);

        try {
            $result = $this->runner->run(
                $context,
                $this->prompt->system($agent, 'chat'),
                $this->history($agent, $question),
                $this->toolbox->all(includeWrites: ! $context->readOnly),
                (string) config('ai.effort.agent'),
            );
            $text = $result['text'] !== '' ? $result['text'] : 'I have nothing to add.';
            $this->recorder->finish($run, AiAgentRun::SUCCEEDED, "Answered ({$result['outcome']}).");
        } catch (AgentHalted $e) {
            $this->recorder->finish($run, $e->runStatus, null, $e->getMessage());
            $text = 'I had to stop: '.lcfirst($e->getMessage());
        } catch (ProviderException $e) {
            $this->recorder->finish($run, AiAgentRun::FAILED, null, $e->getMessage());

            if ($e->retryable && ! $finalAttempt) {
                throw $e;
            }

            $text = 'I could not reach the AI service just now. Please try again in a moment.';
        } catch (Throwable $e) {
            report($e);
            $this->recorder->finish($run, AiAgentRun::FAILED, null, 'Unexpected error.');
            $text = 'Something went wrong on my side. Please try again.';
        }

        $this->reply($agent, $text, $run);
    }

    /** @return array<int, array<string, mixed>> */
    private function history(AiAgent $agent, AiAgentChatMessage $question): array
    {
        $turns = AiAgentChatMessage::where('ai_agent_id', $agent->id)->where('id', '<=', $question->id)
            ->latest('id')->limit(self::HISTORY)->get()->reverse()->values()
            ->map(fn (AiAgentChatMessage $m) => ['role' => $m->role === 'owner' ? 'user' : 'assistant', 'content' => $m->content])
            ->all();

        // A conversation sent to the model starts with the owner.
        while ($turns !== [] && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        // Volatile facts go in the last user turn, keeping the system prompt cacheable.
        $now = now($agent->owner->timezone ?: config('app.timezone'));
        $notes = ["Now: {$now->format('l, Y-m-d H:i')} in {$agent->owner->name}'s time zone ({$now->timezoneName}, UTC{$now->format('P')}). Give tools times in this zone."];

        if ($agent->status === AgentCapabilities::STATUS_PAUSED) {
            $notes[] = 'You are paused: answer from what you can look up, but you cannot change anything. Say so simply if asked to act.';
        }

        $last = array_key_last($turns);
        $turns[$last]['content'] = '('.implode(' ', $notes).")\n\n".$turns[$last]['content'];

        return $turns;
    }

    private function alreadyAnswered(int $chatMessageId): bool
    {
        return AiAgentRun::where('idempotency_key', 'like', "chat:{$chatMessageId}:%")->where('status', AiAgentRun::SUCCEEDED)->exists();
    }

    private function reply(AiAgent $agent, string $text, ?AiAgentRun $run): void
    {
        $message = AiAgentChatMessage::create([
            'ai_agent_id' => $agent->id,
            'role' => 'agent',
            'content' => $text,
            'ai_agent_run_id' => $run?->id,
        ]);

        try {
            event(new AgentChatReplied($message, $agent->owner_id));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
