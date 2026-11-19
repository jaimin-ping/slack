<?php

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\AgentCapabilities;
use App\Services\AI\ApprovalService;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskQuery;
use App\Services\Tasks\TaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Task tools. They act through TaskService with the owner's permissions, and only reach tasks the owner
 * can see that are either personal or come from a conversation this agent may read.
 */
class TaskTools
{
    private const SETTABLE = ['pending', 'in_progress', 'blocked', 'completed', 'cancelled'];

    public function __construct(
        private TaskService $tasks,
        private TaskAccess $access,
        private TaskQuery $query,
        private ApprovalService $approvals,
    ) {}

    /** @return array<int, Tool> */
    public function tools(): array
    {
        $taskId = ['task_id' => ['type' => 'integer']];

        return [
            new Tool(
                'list_tasks',
                'List tasks. Scopes: mine, assigned_to_me, assigned_by_me, team, pending_approval, completed, all. Open tasks unless a status is given.',
                Tool::object([
                    'scope' => ['type' => 'string', 'enum' => TaskQuery::SCOPES],
                    'status' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Task::STATUSES]],
                    'search' => ['type' => 'string', 'description' => 'Words in the title'],
                    'limit' => ['type' => 'integer', 'description' => 'At most 25 (default 15)'],
                ]),
                [
                    'scope' => ['sometimes', 'in:'.implode(',', TaskQuery::SCOPES)],
                    'status' => ['sometimes', 'array'],
                    'status.*' => ['in:'.implode(',', Task::STATUSES)],
                    'search' => ['sometimes', 'string', 'max:100'],
                    'limit' => ['sometimes', 'integer', 'between:1,25'],
                ],
                null,
                function (array $input, AgentContext $context) {
                    $query = $this->query->filtered($context->owner(), $context->workspace(), [
                        'scope' => $input['scope'] ?? 'mine',
                        'status' => $input['status'] ?? null,
                        'q' => $input['search'] ?? null,
                    ]);

                    return ['tasks' => $this->restrict($query, $context)->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                        ->orderBy('due_at')->orderByDesc('tasks.id')->limit($input['limit'] ?? 15)->get()
                        ->map(fn (Task $task) => $this->present($task))->all()];
                },
            ),
            new Tool(
                'get_task',
                'Full details of one task, including its history.',
                Tool::object($taskId, ['task_id']),
                ['task_id' => ['required', 'integer']],
                null,
                function (array $input, AgentContext $context) {
                    $task = $this->find($input['task_id'], $context);

                    return ['task' => $this->present($task) + [
                        'history' => $task->events()->with('actor:id,name')->latest('id')->limit(15)->get()
                            ->map(fn ($event) => ['type' => $event->type, 'by' => $event->actor?->name, 'at' => $event->created_at?->toIso8601String(), 'data' => $event->data])
                            ->all(),
                    ]];
                },
            ),
            new Tool(
                'get_overdue_tasks',
                'Open tasks for your owner whose deadline has passed.',
                Tool::object([]),
                [],
                null,
                fn (array $input, AgentContext $context) => ['tasks' => $this->restrict(
                    $this->query->filtered($context->owner(), $context->workspace(), ['scope' => 'all', 'due' => 'overdue']),
                    $context,
                )->orderBy('due_at')->limit(25)->get()->map(fn (Task $task) => $this->present($task))->all()],
            ),
            new Tool(
                'create_task',
                'Create a task. Only use assignee ids from list_members or a conversation\'s members, and only set a deadline that someone actually stated.',
                Tool::object([
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'priority' => ['type' => 'string', 'enum' => Task::PRIORITIES],
                    'due_at' => ['type' => 'string', 'description' => 'ISO 8601 date-time, only if a deadline was stated'],
                    'assignee_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'source_message_id' => ['type' => 'integer', 'description' => 'The message the task came from'],
                ], ['title']),
                [
                    'title' => ['required', 'string', 'min:2', 'max:200'],
                    'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
                    'priority' => ['sometimes', 'in:'.implode(',', Task::PRIORITIES)],
                    'due_at' => ['sometimes', 'nullable', 'date'],
                    'assignee_ids' => ['sometimes', 'array', 'max:10'],
                    'assignee_ids.*' => ['integer'],
                    'source_message_id' => ['sometimes', 'integer'],
                ],
                'create_task',
                fn (array $input, AgentContext $context, string $policy) => $this->create($input, $context, $policy),
            ),
            new Tool(
                'update_task',
                'Change a task\'s title, description, priority or deadline.',
                Tool::object($taskId + [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'priority' => ['type' => 'string', 'enum' => Task::PRIORITIES],
                    'due_at' => ['type' => 'string', 'description' => 'ISO 8601 date-time'],
                ], ['task_id']),
                [
                    'task_id' => ['required', 'integer'],
                    'title' => ['sometimes', 'string', 'min:2', 'max:200'],
                    'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
                    'priority' => ['sometimes', 'in:'.implode(',', Task::PRIORITIES)],
                    'due_at' => ['sometimes', 'nullable', 'date'],
                ],
                'update_task',
                function (array $input, AgentContext $context, string $policy) {
                    $task = $this->find($input['task_id'], $context);
                    $fields = array_intersect_key($input, array_flip(['title', 'description', 'priority', 'due_at']));

                    return $this->perform($context, $policy, 'update_task', $task, ['operation' => 'update', 'task_id' => $task->id, 'fields' => $fields],
                        "Update \"{$task->title}\": ".implode(', ', array_keys($fields)),
                        fn () => $this->tasks->update($task, $context->owner(), $fields, $context->agent));
                },
            ),
            new Tool(
                'assign_task',
                'Set who a task is assigned to (replaces the current assignees).',
                Tool::object($taskId + ['user_ids' => ['type' => 'array', 'items' => ['type' => 'integer']]], ['task_id', 'user_ids']),
                ['task_id' => ['required', 'integer'], 'user_ids' => ['present', 'array', 'max:10'], 'user_ids.*' => ['integer']],
                'assign_task',
                function (array $input, AgentContext $context, string $policy) {
                    $task = $this->find($input['task_id'], $context);
                    $names = User::whereIn('id', $input['user_ids'])->pluck('name')->implode(', ') ?: 'nobody';

                    return $this->perform($context, $policy, 'assign_task', $task, ['task_id' => $task->id, 'user_ids' => $input['user_ids']],
                        "Assign \"{$task->title}\" to {$names}",
                        fn () => $this->tasks->assign($task, $context->owner(), $input['user_ids'], $context->agent));
                },
            ),
            new Tool(
                'change_task_status',
                'Move a task to pending, in_progress, blocked, completed or cancelled.',
                Tool::object($taskId + ['status' => ['type' => 'string', 'enum' => self::SETTABLE]], ['task_id', 'status']),
                ['task_id' => ['required', 'integer'], 'status' => ['required', 'in:'.implode(',', self::SETTABLE)]],
                'update_task',
                function (array $input, AgentContext $context, string $policy) {
                    $task = $this->find($input['task_id'], $context);

                    return $this->perform($context, $policy, 'update_task', $task, ['operation' => 'status', 'task_id' => $task->id, 'status' => $input['status']],
                        "Mark \"{$task->title}\" as {$input['status']}",
                        fn () => $this->tasks->changeStatus($task, $context->owner(), $input['status'], $context->agent));
                },
            ),
            new Tool(
                'pause_task',
                'Pause an open task. It keeps its status to return to when resumed.',
                Tool::object($taskId, ['task_id']),
                ['task_id' => ['required', 'integer']],
                'update_task',
                function (array $input, AgentContext $context, string $policy) {
                    $task = $this->find($input['task_id'], $context);

                    return $this->perform($context, $policy, 'update_task', $task, ['operation' => 'pause', 'task_id' => $task->id],
                        "Pause \"{$task->title}\"", fn () => $this->tasks->pause($task, $context->owner(), $context->agent));
                },
            ),
            new Tool(
                'resume_task',
                'Resume a paused task.',
                Tool::object($taskId, ['task_id']),
                ['task_id' => ['required', 'integer']],
                'update_task',
                function (array $input, AgentContext $context, string $policy) {
                    $task = $this->find($input['task_id'], $context);

                    return $this->perform($context, $policy, 'update_task', $task, ['operation' => 'resume', 'task_id' => $task->id],
                        "Resume \"{$task->title}\"", fn () => $this->tasks->resume($task, $context->owner(), $context->agent));
                },
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function create(array $input, AgentContext $context, string $policy): array
    {
        $owner = $context->owner();
        $others = array_diff($input['assignee_ids'] ?? [], [$owner->id]);
        $suggest = $policy === AgentCapabilities::APPROVE;

        if ($others !== []) {
            $assignPolicy = $context->policy('assign_task');

            if ($assignPolicy === AgentCapabilities::DENY) {
                throw ValidationException::withMessages(['assignee_ids' => 'You may not assign tasks to other people. Create it without them, or tell your owner.']);
            }

            $suggest = $suggest || $assignPolicy === AgentCapabilities::APPROVE;
        }

        if (isset($input['source_message_id'])) {
            $message = Message::find($input['source_message_id']);

            if (! $message || ! $context->canRead($message->channel_id)) {
                throw new ModelNotFoundException;
            }
        }

        $task = $this->tasks->create($context->workspace(), $owner, [...$input, 'status' => $suggest ? Task::SUGGESTED : Task::PENDING], $context->agent);

        return [
            'status' => $task->status === Task::SUGGESTED ? 'suggested' : 'created',
            'task' => $this->present($task->fresh()),
            'note' => $task->status === Task::SUGGESTED ? 'Saved as a suggestion; your owner will approve or reject it.' : null,
        ];
    }

    /**
     * Runs the change now when allowed, or files it for the owner's approval.
     *
     * @param  array<string, mixed>  $payload
     */
    private function perform(AgentContext $context, string $policy, string $action, Task $task, array $payload, string $summary, \Closure $run): array
    {
        if ($policy === AgentCapabilities::APPROVE) {
            $approval = $this->approvals->request($context, $action, $summary, $payload, $task);

            return ['status' => 'approval_requested', 'approval_id' => $approval->id, 'message' => 'Your owner must approve this first.'];
        }

        return ['status' => 'done', 'task' => $this->present($run()->fresh())];
    }

    private function restrict(Builder $query, AgentContext $context): Builder
    {
        $readable = $context->readableChannelIds();

        return $query->where(fn (Builder $q) => $q->whereNull('tasks.source_channel_id')->orWhereIn('tasks.source_channel_id', $readable));
    }

    private function find(int $id, AgentContext $context): Task
    {
        return $this->restrict($this->access->visibleQuery(Task::query(), $context->owner(), $context->workspace()), $context)
            ->whereKey($id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(Task $task): array
    {
        $task->loadMissing(['assignees:id,name', 'sourceChannel']);

        return array_filter([
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'paused_from' => $task->paused_from,
            'priority' => $task->priority,
            'due_at' => $task->due_at?->toIso8601String(),
            'is_overdue' => $task->isOverdue(),
            'assignees' => $task->assignees->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->all(),
            'created_by_agent' => $task->ai_agent_id !== null,
            'source_channel' => $task->sourceChannel ? ($task->sourceChannel->name ? '#'.$task->sourceChannel->name : 'direct message') : null,
            'source_message_id' => $task->source_message_id,
            'source_state' => $task->source_state,
        ], fn ($value) => $value !== null);
    }
}
