<?php

namespace App\Services\AI;

use App\Ai\AgentContext;
use App\Events\AI\ApprovalChanged;
use App\Models\AiAgentApproval;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Actions an agent may only propose. The owner approves or rejects them; an approved action runs
 * with the owner's own permissions, checked again at that moment, not when it was proposed.
 */
class ApprovalService
{
    public function __construct(private TaskService $tasks, private AgentMessenger $messenger) {}

    /** @param  array<string, mixed>  $payload */
    public function request(AgentContext $context, string $action, string $summary, array $payload, ?Task $task = null): AiAgentApproval
    {
        // The same proposal from a retry or a repeated run stays one request while it is pending.
        $key = hash('sha256', $context->agent->id.'|'.$action.'|'.json_encode($payload));

        if ($existing = AiAgentApproval::where('dedupe_key', $key)->first()) {
            return $existing;
        }

        try {
            $approval = AiAgentApproval::create([
                'ai_agent_id' => $context->agent->id,
                'workspace_id' => $context->agent->workspace_id,
                'owner_id' => $context->agent->owner_id,
                'ai_agent_run_id' => $context->run->id,
                'task_id' => $task?->id,
                'action' => $action,
                'summary' => Str::limit($summary, 490),
                'payload' => $payload,
                'status' => AiAgentApproval::PENDING,
                'dedupe_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            return AiAgentApproval::where('dedupe_key', $key)->firstOrFail();
        }

        $this->announce($approval);

        return $approval;
    }

    public function approve(AiAgentApproval $approval, User $user): AiAgentApproval
    {
        $this->authorizeDecision($approval, $user);

        try {
            $result = DB::transaction(fn () => $this->execute($approval, $user));
            $approval->update(['status' => 'executed', 'result' => $result] + $this->decided($user));
        } catch (ValidationException $e) {
            $approval->update(['status' => 'failed', 'error' => collect($e->errors())->flatten()->implode(' ')] + $this->decided($user));
        } catch (AuthorizationException|ModelNotFoundException) {
            $approval->update(['status' => 'failed', 'error' => 'This can no longer be done: the task, the conversation or your access to it has changed.'] + $this->decided($user));
        }

        $this->announce($approval);

        return $approval;
    }

    public function reject(AiAgentApproval $approval, User $user, ?string $reason = null): AiAgentApproval
    {
        $this->authorizeDecision($approval, $user);

        $approval->update(['status' => 'rejected', 'result' => $reason ? ['reason' => $reason] : null] + $this->decided($user));
        $this->announce($approval);

        return $approval;
    }

    /** @return array<string, mixed> */
    private function execute(AiAgentApproval $approval, User $owner): array
    {
        $payload = $approval->payload;
        $agent = $approval->agent ?? throw new ModelNotFoundException;
        $task = fn () => Task::findOrFail($payload['task_id']);

        return match ($approval->action) {
            'update_task' => match ($payload['operation']) {
                'update' => ['task_id' => $this->tasks->update($task(), $owner, $payload['fields'], $agent)->id],
                'status' => ['task_id' => $this->tasks->changeStatus($task(), $owner, $payload['status'], $agent)->id],
                'pause' => ['task_id' => $this->tasks->pause($task(), $owner, $agent)->id],
                'resume' => ['task_id' => $this->tasks->resume($task(), $owner, $agent)->id],
            },
            'assign_task' => ['task_id' => $this->tasks->assign($task(), $owner, $payload['user_ids'], $agent)->id],
            'create_reminder' => ['reminder_id' => self::saveReminder($owner, $approval->workspace_id, $payload)->id],
            // Messages are checked again by the messenger: access, mentions and the hourly limit as of now.
            'send_direct_message' => ['message_ids' => [$this->messenger->directMessage($agent, $payload['user_id'], $payload['text'])->id]],
            'send_channel_message' => ['message_ids' => [$this->messenger->channelMessage($agent, $payload['channel_id'], $payload['text'])->id]],
            'reply_in_thread' => ['message_ids' => [$this->messenger->threadReply($agent, $payload['message_id'], $payload['text'])->id]],
            'follow_up' => ['message_ids' => array_map(fn ($m) => $m->id, $this->messenger->followUp($agent, $payload['task_id'], $payload['text']))],
            default => throw ValidationException::withMessages(['action' => 'This kind of action cannot be approved here.']),
        };
    }

    /**
     * Reminders are only ever for the agent's owner, and are delivered by the existing reminder scheduler.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function saveReminder(User $owner, int $workspaceId, array $payload): Reminder
    {
        $remindAt = Carbon::parse($payload['remind_at']);

        if ($remindAt->isPast() || $remindAt->greaterThan(now()->addYear())) {
            throw ValidationException::withMessages(['remind_at' => 'Reminders must be in the future, within a year.']);
        }

        if (($payload['operation'] ?? 'create') === 'update') {
            $reminder = Reminder::where(['user_id' => $owner->id, 'workspace_id' => $workspaceId])->whereNull('sent_at')
                ->findOrFail($payload['reminder_id']);
            $reminder->update(['text' => $payload['text'] ?? $reminder->text, 'remind_at' => $remindAt]);

            return $reminder;
        }

        return Reminder::create([
            'user_id' => $owner->id,
            'workspace_id' => $workspaceId,
            'text' => Str::limit((string) $payload['text'], 255),
            'remind_at' => $remindAt,
        ]);
    }

    private function authorizeDecision(AiAgentApproval $approval, User $user): void
    {
        if ($approval->owner_id !== $user->id) {
            throw new AuthorizationException('Only the agent\'s owner can decide.');
        }

        if ($approval->status !== AiAgentApproval::PENDING) {
            throw ValidationException::withMessages(['status' => 'This request was already decided.']);
        }
    }

    /** Deciding frees the de-duplication key, so the same action can be proposed again later. */
    private function decided(User $user): array
    {
        return ['decided_by' => $user->id, 'decided_at' => now(), 'dedupe_key' => null];
    }

    private function announce(AiAgentApproval $approval): void
    {
        try {
            event(new ApprovalChanged($approval));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
