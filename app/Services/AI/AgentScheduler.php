<?php

namespace App\Services\AI;

use App\Ai\AgentContext;
use App\Ai\RunRecorder;
use App\Events\AI\AgentChatReplied;
use App\Models\AiAgent;
use App\Models\AiAgentApproval;
use App\Models\AiAgentChatMessage;
use App\Models\AiAgentNotice;
use App\Models\AiAgentRun;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Scheduled agent work. None of it uses the AI model: the facts come straight from the task records, so it
 * costs nothing, cannot be steered by message text, and says exactly what the records say.
 *
 *  - Daily summary to the owner, at the time they chose, in their time zone.
 *  - Overdue alerts to the owner, once per task and deadline.
 *  - Follow-ups to the assignees of overdue tasks the owner created, only if the owner's "follow up" policy
 *    allows it (or after approval), once per task and deadline.
 */
class AgentScheduler
{
    public function __construct(
        private TaskAccess $access,
        private RunRecorder $recorder,
        private AgentMessenger $messenger,
        private ApprovalService $approvals,
    ) {}

    /** @return int how many agents did something */
    public function runDue(?Carbon $now = null): int
    {
        $now ??= now();
        $acted = 0;

        AiAgent::with(['owner', 'workspace'])->where('status', AgentCapabilities::STATUS_ACTIVE)->orderBy('id')
            ->chunkById(100, function ($agents) use ($now, &$acted) {
                foreach ($agents as $agent) {
                    try {
                        $acted += $this->runFor($agent, $now) ? 1 : 0;
                    } catch (Throwable $e) {
                        report($e); // One agent's problem never stops the others.
                    }
                }
            });

        return $acted;
    }

    private function runFor(AiAgent $agent, Carbon $now): bool
    {
        $owner = $agent->owner;

        if (! $owner || ! $agent->workspace->hasMember($owner)) {
            return false;
        }

        $settings = $agent->resolvedSettings();
        $local = $now->copy()->setTimezone($owner->timezone ?: config('app.timezone'));
        $lines = [];
        $steps = [];

        if ($settings['notifications']['overdue_alerts']) {
            $overdue = $this->overdueFor($agent, $owner, $now)
                ->filter(fn (Task $task) => $task->assignees->contains('id', $owner->id))
                ->filter(fn (Task $task) => AiAgentNotice::claim($agent->id, 'overdue', $this->deadlineKey($task)));

            if ($overdue->isNotEmpty()) {
                $lines[] = "These tasks of yours are past their deadline:\n".$this->list($overdue, $local);
                $steps[] = ['overdue_alert', ['task_ids' => $overdue->pluck('id')->all()]];
            }
        }

        $followUps = $this->followUps($agent, $owner, $now, $local);

        if ($followUps !== []) {
            $steps[] = ['follow_up', $followUps];
        }

        $summary = $settings['daily_summary'];

        if ($summary['enabled'] && $local->format('H:i') >= $summary['time']
            && AiAgentNotice::claim($agent->id, 'daily_summary', $local->toDateString())) {
            $lines[] = $this->dailySummary($agent, $owner, $now, $local);
            $steps[] = ['daily_summary', ['date' => $local->toDateString()]];
        }

        if ($steps === []) {
            return false;
        }

        $run = $this->recorder->start($agent, 'schedule', ['at' => $now->toIso8601String()], null, 'none', 'none');

        foreach ($steps as [$name, $output]) {
            $this->recorder->step($run, 'schedule', $name, 'ok', null, $output);
        }

        if ($lines !== []) {
            $this->tell($agent, implode("\n\n", $lines), $run);
        }

        $this->recorder->finish($run, AiAgentRun::SUCCEEDED, implode(', ', array_column($steps, 0)));

        return true;
    }

    /**
     * Follow-ups to other people about overdue tasks the owner created.
     *
     * @return array<int, array<string, mixed>>
     */
    private function followUps(AiAgent $agent, User $owner, Carbon $now, Carbon $local): array
    {
        $policy = $agent->policyFor('follow_up');

        if ($policy === AgentCapabilities::DENY) {
            return [];
        }

        $done = [];

        $tasks = $this->overdueFor($agent, $owner, $now)
            ->filter(fn (Task $task) => $task->creator_id === $owner->id && $task->assignees->contains(fn (User $u) => $u->id !== $owner->id));

        foreach ($tasks as $task) {
            $key = $this->deadlineKey($task);

            if (! AiAgentNotice::claim($agent->id, 'follow_up', $key)) {
                continue;
            }

            $due = $task->due_at->copy()->setTimezone($local->timezone)->format('D j M, H:i');
            $text = "Hi! {$owner->name} asked me to keep track of \"{$task->title}\", which was due {$due}. Could you share an update?";

            try {
                if ($policy === AgentCapabilities::APPROVE) {
                    $run = $this->recorder->start($agent, 'schedule', ['task_id' => $task->id], null, 'none', 'none');
                    $approval = $this->approvals->request(new AgentContext($agent, $run), 'follow_up',
                        "Follow up on \"{$task->title}\": \"{$text}\"", ['task_id' => $task->id, 'text' => $text], $task);
                    $this->recorder->finish($run, AiAgentRun::SUCCEEDED, 'Follow-up waiting for approval.');
                    $done[] = ['task_id' => $task->id, 'status' => 'approval_requested', 'approval_id' => $approval->id];
                } else {
                    $sent = $this->messenger->followUp($agent, $task->id, $text);
                    $done[] = ['task_id' => $task->id, 'status' => 'sent', 'message_ids' => array_map(fn ($m) => $m->id, $sent)];
                }
            } catch (ValidationException $e) {
                // For example the hourly message limit: try again on a later pass.
                AiAgentNotice::release($agent->id, 'follow_up', $key);
                $done[] = ['task_id' => $task->id, 'status' => 'skipped', 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        return $done;
    }

    private function dailySummary(AiAgent $agent, User $owner, Carbon $now, Carbon $local): string
    {
        $visible = fn () => $this->access->visibleQuery(Task::query(), $owner, $agent->workspace)->whereNull('archived_at');
        $mine = fn () => $visible()->whereHas('assignees', fn ($q) => $q->whereKey($owner->id));
        $dayStart = $local->copy()->startOfDay()->utc();
        $dayEnd = $local->copy()->endOfDay()->utc();

        $dueToday = $mine()->whereIn('status', Task::OPEN_STATUSES)->whereBetween('due_at', [$dayStart, $dayEnd])->orderBy('due_at')->limit(10)->get();
        $overdue = $mine()->whereIn('status', Task::OPEN_STATUSES)->where('due_at', '<', $now)->count();
        $completed = $mine()->where('status', Task::COMPLETED)->whereBetween('completed_at', [$dayStart, $dayEnd])->count();
        $suggested = $visible()->where('status', Task::SUGGESTED)->where('creator_id', $owner->id)->count();
        $approvals = AiAgentApproval::where(['owner_id' => $owner->id, 'workspace_id' => $agent->workspace_id, 'status' => AiAgentApproval::PENDING])->count();

        $parts = ["Your summary for {$local->format('l j F')}:"];
        $parts[] = $dueToday->isEmpty() ? '• Nothing of yours is due today.' : "• Due today:\n".$this->list($dueToday, $local);
        $parts[] = "• Overdue: {$overdue}   • Completed today: {$completed}";

        if ($suggested + $approvals > 0) {
            $parts[] = "• Waiting for you: {$suggested} suggested task(s), {$approvals} approval request(s).";
        }

        return implode("\n", $parts);
    }

    /** @return Collection<int, Task> open, overdue tasks the owner can see, from places the agent may use */
    private function overdueFor(AiAgent $agent, User $owner, Carbon $now): Collection
    {
        $readable = $agent->readableChannelIds();

        return $this->access->visibleQuery(Task::query(), $owner, $agent->workspace)
            ->whereIn('status', [Task::PENDING, Task::IN_PROGRESS, Task::BLOCKED])
            ->whereNull('archived_at')
            ->whereNotNull('due_at')->where('due_at', '<', $now)
            ->where(fn ($q) => $q->whereNull('source_channel_id')->orWhereIn('source_channel_id', $readable))
            ->with('assignees:id,name')
            ->orderBy('due_at')->limit(25)->get();
    }

    /** A new deadline gives a new notice. */
    private function deadlineKey(Task $task): string
    {
        return $task->id.':'.$task->due_at->getTimestamp();
    }

    /** @param  Collection<int, Task>  $tasks */
    private function list(Collection $tasks, Carbon $local): string
    {
        return $tasks->values()->map(fn (Task $task, int $i) => ($i + 1).'. '.$task->title
            .($task->due_at ? ' (due '.$task->due_at->copy()->setTimezone($local->timezone)->format('D j M, H:i').')' : ''))
            ->implode("\n");
    }

    private function tell(AiAgent $agent, string $text, AiAgentRun $run): void
    {
        $message = AiAgentChatMessage::create([
            'ai_agent_id' => $agent->id,
            'role' => 'agent',
            'content' => $text,
            'ai_agent_run_id' => $run->id,
        ]);

        try {
            event(new AgentChatReplied($message, $agent->owner_id));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
