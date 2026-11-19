<?php

namespace App\Services\AI;

use App\Events\SidebarChanged;
use App\Models\AiAgent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\MessagePoster;
use App\Services\Tasks\TaskAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The only way an agent's words reach other people. Every check runs at the moment of sending, also for
 * messages the owner approved earlier, because channels, memberships and the agent itself may have changed.
 *
 *  - An agent posts only where its owner could post, and only in conversations granted to it.
 *  - Direct messages go into the owner's own DM with that person, labelled as the owner's AI assistant.
 *  - No @channel, @here or group mentions, and a cap on messages per hour, so an agent can never spam.
 */
class AgentMessenger
{
    public function __construct(private MessagePoster $poster, private TaskAccess $access) {}

    public function directMessage(AiAgent $agent, int $userId, string $text): Message
    {
        [$owner, $text] = $this->prepare($agent, $text);
        $target = $agent->workspace->members()->whereKey($userId)->first();

        if (! $target) {
            throw new ModelNotFoundException('No such member.');
        }

        if ($target->id === $owner->id) {
            throw ValidationException::withMessages(['user_id' => 'Reply to your owner in your private chat, not by direct message.']);
        }

        $channel = $agent->workspace->channels()->createOrFirst(
            ['dm_key' => Channel::dmKey($owner->id, $target->id)],
            ['type' => Channel::DM, 'created_by' => $owner->id],
        );
        $channel->members()->syncWithoutDetaching([$owner->id, $target->id]);

        if ($channel->wasRecentlyCreated) {
            $this->announceSidebar($agent->workspace_id, [$owner->id, $target->id]);
        }

        return $this->poster->asAgent($channel, $agent, $text);
    }

    public function channelMessage(AiAgent $agent, int $channelId, string $text): Message
    {
        [, $text] = $this->prepare($agent, $text);

        return $this->poster->asAgent($this->postable($agent, $channelId), $agent, $text);
    }

    public function threadReply(AiAgent $agent, int $messageId, string $text): Message
    {
        [, $text] = $this->prepare($agent, $text);
        $parent = Message::whereKey($messageId)->whereNull('removed_at')->firstOrFail();
        $channel = $this->postable($agent, $parent->channel_id);

        // Threads are one level deep, as in the rest of the app.
        return $this->poster->asAgent($channel, $agent, $text, $parent->parent_id ?? $parent->id);
    }

    /**
     * A nudge about a task the owner manages, sent to its assignees (never to the owner).
     *
     * @return array<int, Message>
     */
    public function followUp(AiAgent $agent, int $taskId, string $text): array
    {
        [$owner] = $this->prepare($agent, $text);

        $task = $this->access->visibleQuery(Task::query(), $owner, $agent->workspace)->whereKey($taskId)->firstOrFail();

        if (! $this->access->canManage($owner, $task)) {
            throw new AuthorizationException('Only the person who created a task can follow up on it.');
        }

        if (! in_array($task->status, Task::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages(['task_id' => 'This task is no longer open.']);
        }

        $recipients = $task->assignees()->where('users.id', '!=', $owner->id)->orderBy('users.id')
            ->limit((int) config('ai.limits.follow_up_recipients'))->pluck('users.id');

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages(['task_id' => 'Nobody else is assigned to this task.']);
        }

        return $recipients->map(fn (int $userId) => $this->directMessage($agent, $userId, $text."\n\n(Task: {$task->title})"))->all();
    }

    /** Recent outbound messages from this agent, for its owner's review. */
    public static function sentInLastHour(AiAgent $agent): int
    {
        return Message::where('ai_agent_id', $agent->id)->where('created_at', '>=', now()->subHour())->count();
    }

    /** @return array{0: User, 1: string} */
    private function prepare(AiAgent $agent, string $text): array
    {
        $agent->refresh();
        $owner = $agent->owner;

        if ($agent->status === AgentCapabilities::STATUS_INACTIVE) {
            throw ValidationException::withMessages(['agent' => 'This agent is deactivated.']);
        }

        if (! $owner || ! $agent->workspace->hasMember($owner)) {
            throw new AuthorizationException('The agent\'s owner is no longer in this workspace.');
        }

        $text = trim($text);
        $max = (int) config('ai.limits.outbound_message_chars');

        if ($text === '' || mb_strlen($text) > $max) {
            throw ValidationException::withMessages(['text' => "Messages must be between 1 and {$max} characters."]);
        }

        if (preg_match('/(?<![\w@])@(channel|here|everyone)(?!\w)/iu', $text)) {
            throw ValidationException::withMessages(['text' => 'Agents cannot notify a whole channel. Mention people by name instead.']);
        }

        $groups = UserGroup::where('workspace_id', $agent->workspace_id)->pluck('handle')
            ->filter(fn (string $handle) => preg_match('/(?<![\w@])@'.preg_quote($handle, '/').'(?![\w-])/u', $text));

        if ($groups->isNotEmpty()) {
            throw ValidationException::withMessages(['text' => 'Agents cannot mention groups. Mention people by name instead.']);
        }

        $limit = (int) config('ai.limits.outbound_messages_per_hour');

        if (self::sentInLastHour($agent) >= $limit) {
            throw ValidationException::withMessages(['text' => "This agent has sent its limit of {$limit} messages this hour. Try again later."]);
        }

        return [$owner, $text];
    }

    /** A conversation granted to the agent, where its owner is a member and could post. */
    private function postable(AiAgent $agent, int $channelId): Channel
    {
        if (! in_array($channelId, $agent->readableChannelIds(), true)) {
            throw new ModelNotFoundException('Not a conversation this agent can use.');
        }

        return Channel::findOrFail($channelId);
    }

    /** @param  array<int, int>  $userIds */
    private function announceSidebar(int $workspaceId, array $userIds): void
    {
        try {
            event(new SidebarChanged($workspaceId, $userIds));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
