<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Jobs\FetchLinkPreview;
use App\Models\AiAgent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Posts a message on someone's behalf or as a bot, outside the normal send request. */
class MessagePoster
{
    public function __construct(
        private ActivityRecorder $activity,
        private LinkPreviewer $previewer,
    ) {}

    public function asUser(Channel $channel, User $user, string $body): Message
    {
        $message = $channel->messages()->create(['user_id' => $user->id, 'body' => $body]);
        $message->setRelation('user', $user);

        return $this->publish($message);
    }

    public function asBot(Channel $channel, string $name, string $body): Message
    {
        return $this->publish($channel->messages()->create(['bot_name' => $name, 'body' => $body]));
    }

    /**
     * A message written by an AI agent. It is a bot message named "<Agent> (AI Assistant)", so no client can
     * mistake it for its owner. Who may post where is decided by AgentMessenger before this is called.
     */
    public function asAgent(Channel $channel, AiAgent $agent, string $body, ?int $parentId = null): Message
    {
        $message = $channel->messages()->create([
            'bot_name' => Str::limit($agent->name, 64, '').' (AI Assistant)',
            'ai_agent_id' => $agent->id,
            'body' => $body,
            'parent_id' => $parentId,
        ]);

        if ($parentId) {
            $message->parent_reply_count = Message::where('parent_id', $parentId)->count();
        }

        // Announced once the surrounding transaction (if any) commits, so nobody sees a message that is rolled back.
        DB::afterCommit(fn () => $this->publish($message));

        return $message;
    }

    private function publish(Message $message): Message
    {
        try {
            event(new MessageSent($message));
        } catch (Throwable $e) {
            report($e); // The message is saved even if the WebSocket server is down.
        }

        $this->activity->record($message);

        if ($this->previewer->firstUrl($message->body)) {
            FetchLinkPreview::dispatch($message->id)->afterResponse();
        }

        return $message;
    }
}
