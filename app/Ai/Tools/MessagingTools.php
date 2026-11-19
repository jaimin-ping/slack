<?php

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\AgentCapabilities;
use App\Services\AI\AgentMessenger;
use App\Services\AI\ApprovalService;
use App\Services\Tasks\TaskAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Writing to other people. Messages always appear as "<Agent> (AI Assistant)", never as the owner.
 * The owner's policy decides allow / approve / deny, and any run that has read other people's words is
 * treated as "approve", so a planted instruction can at most produce a request the owner sees and rejects.
 */
class MessagingTools
{
    public function __construct(
        private AgentMessenger $messenger,
        private ApprovalService $approvals,
        private TaskAccess $access,
    ) {}

    /** @return array<int, Tool> */
    public function tools(): array
    {
        $text = ['type' => 'string', 'description' => 'What to say, written as the AI assistant, never pretending to be your owner'];
        $textRule = ['required', 'string', 'min:1', 'max:'.config('ai.limits.outbound_message_chars')];

        return [
            new Tool(
                'send_direct_message',
                'Send a direct message to a workspace member on your owner\'s behalf. It appears in their DM with your owner, labelled as you.',
                Tool::object(['user_id' => ['type' => 'integer'], 'text' => $text], ['user_id', 'text']),
                ['user_id' => ['required', 'integer'], 'text' => $textRule],
                'send_direct_message',
                fn (array $input, AgentContext $context, string $policy) => $this->send($context, $policy, 'send_direct_message', $input,
                    fn () => 'Message '.$this->member($context, $input['user_id'])->name,
                    fn () => [$this->messenger->directMessage($context->agent, $input['user_id'], $input['text'])]),
            ),
            new Tool(
                'send_channel_message',
                'Post a message in a conversation you have access to.',
                Tool::object(['channel_id' => ['type' => 'integer'], 'text' => $text], ['channel_id', 'text']),
                ['channel_id' => ['required', 'integer'], 'text' => $textRule],
                'send_channel_message',
                fn (array $input, AgentContext $context, string $policy) => $this->send($context, $policy, 'send_channel_message', $input,
                    fn () => 'Post in '.$this->place($context, $input['channel_id']),
                    fn () => [$this->messenger->channelMessage($context->agent, $input['channel_id'], $input['text'])]),
            ),
            new Tool(
                'reply_in_thread',
                'Reply in the thread of a message in a conversation you have access to.',
                Tool::object(['message_id' => ['type' => 'integer'], 'text' => $text], ['message_id', 'text']),
                ['message_id' => ['required', 'integer'], 'text' => $textRule],
                'reply_in_thread',
                fn (array $input, AgentContext $context, string $policy) => $this->send($context, $policy, 'reply_in_thread', $input,
                    fn () => 'Reply in a thread in '.$this->place($context, $this->readableMessage($context, $input['message_id'])->channel_id),
                    fn () => [$this->messenger->threadReply($context->agent, $input['message_id'], $input['text'])]),
            ),
            new Tool(
                'follow_up_on_task',
                'Send the assignees of a task your owner created a short follow-up, for example about a missed deadline.',
                Tool::object(['task_id' => ['type' => 'integer'], 'text' => $text], ['task_id', 'text']),
                ['task_id' => ['required', 'integer'], 'text' => $textRule],
                'follow_up',
                fn (array $input, AgentContext $context, string $policy) => $this->send($context, $policy, 'follow_up', $input,
                    fn () => 'Follow up on "'.$this->access->visibleQuery(Task::query(), $context->owner(), $context->workspace())
                        ->whereKey($input['task_id'])->firstOrFail()->title.'"',
                    fn () => $this->messenger->followUp($context->agent, $input['task_id'], $input['text'])),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  \Closure(): string  $describe  a short description, which also checks the target exists for the owner
     * @param  \Closure(): array<int, Message>  $deliver
     * @return array<string, mixed>
     */
    private function send(AgentContext $context, string $policy, string $action, array $input, \Closure $describe, \Closure $deliver): array
    {
        $summary = $describe().': "'.Str::limit($input['text'], 200).'"';
        $guarded = $policy === AgentCapabilities::ALLOW && $context->readWorkspaceContent;

        if ($policy === AgentCapabilities::APPROVE || $guarded) {
            $approval = $this->approvals->request($context, $action, $summary, $input);

            return [
                'status' => 'approval_requested',
                'approval_id' => $approval->id,
                'message' => $guarded
                    ? 'You read messages from other people in this conversation, so your owner must approve messages you send. Tell them it is waiting.'
                    : 'Your owner must approve this first.',
            ];
        }

        $sent = $deliver();

        return ['status' => 'sent', 'message_ids' => array_map(fn (Message $m) => $m->id, $sent)];
    }

    private function member(AgentContext $context, int $userId): User
    {
        return $context->workspace()->members()->whereKey($userId)->firstOrFail();
    }

    private function place(AgentContext $context, int $channelId): string
    {
        if (! $context->canRead($channelId)) {
            throw new ModelNotFoundException;
        }

        $channel = Channel::findOrFail($channelId);

        return $channel->type === Channel::DM ? 'a direct message' : "#{$channel->name}";
    }

    private function readableMessage(AgentContext $context, int $messageId): Message
    {
        return Message::whereKey($messageId)->whereNull('removed_at')->whereIn('channel_id', $context->readableChannelIds())->firstOrFail();
    }
}
