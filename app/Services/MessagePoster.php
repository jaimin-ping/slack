<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Jobs\FetchLinkPreview;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
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
