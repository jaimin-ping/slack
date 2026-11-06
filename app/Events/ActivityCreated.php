<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Str;

/** Tells mentioned people and thread participants about a new message. */
class ActivityCreated implements ShouldBroadcastNow
{
    /** @param array<int, int> $userIds */
    public function __construct(public Message $message, public int $workspaceId, public array $userIds) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'activity.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'channel_id' => $this->message->channel_id,
            'message_id' => $this->message->id,
            'parent_id' => $this->message->parent_id,
            'sender' => $this->message->user?->name ?? $this->message->bot_name ?? 'App',
            'preview' => Str::limit($this->message->body, 140),
        ];
    }
}
