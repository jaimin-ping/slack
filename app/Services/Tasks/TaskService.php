<?php

namespace App\Services\Tasks;

use App\Events\Tasks\TaskChanged;
use App\Jobs\SendPushNotification;
use App\Models\AiAgent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Every change to a task goes through here, inside a transaction, and leaves a history entry.
 * Each method checks permission itself, so the web app, the mobile API and agent tools are held to the same rules.
 * When an agent acts, $actor is its owner and $agent is recorded alongside.
 */
class TaskService
{
    private const EDITABLE = ['title', 'description', 'priority', 'start_date', 'due_at'];

    private const MAX_DEPENDENCIES = 20;

    public function __construct(private TaskAccess $access) {}

    /**
     * Another person's agent may already have suggested this task, unapproved and visible only to that person.
     * When the work is for the actor themself, their own agent's detection wins: the suggestion becomes theirs
     * to confirm, under their own agent's policy, so someone else's pending suggestion never hides their own work.
     *
     * @param  array<int, int>  $assigneeIds
     */
    private function takeOverSuggestion(Task $existing, User $actor, ?AiAgent $agent, array $assigneeIds, string $status): ?Task
    {
        if ($existing->status !== Task::SUGGESTED || $existing->creator_id === $actor->id
            || ! in_array($actor->id, $assigneeIds, true)
            || ! $existing->assignees()->whereKey($actor->id)->exists()) {
            return null;
        }

        DB::transaction(function () use ($existing, $actor, $agent, $status) {
            $existing->update(['creator_id' => $actor->id, 'ai_agent_id' => $agent?->id, 'status' => $status]);
            $this->record($existing, 'updated', $actor, $agent, ['fields' => ['creator', 'status'], 'taken_over' => true]);
        });

        $this->announce($existing, 'updated');

        return $existing;
    }

    /** @param  array<string, mixed>  $data */
    public function create(Workspace $workspace, User $actor, array $data, ?AiAgent $agent = null): Task
    {
        $this->authorize($workspace->hasMember($actor));

        $source = isset($data['source_message_id']) ? $this->sourceMessage($workspace, $actor, (int) $data['source_message_id']) : null;
        $channel = $source?->channel;
        $assigneeIds = $this->ids($data['assignee_ids'] ?? []);
        $status = ($data['status'] ?? Task::PENDING) === Task::SUGGESTED ? Task::SUGGESTED : Task::PENDING;

        $this->assertAssignable($workspace, $channel, $assigneeIds);

        // Only an agent's detections are de-duplicated: a person may deliberately log the same work twice.
        $dedupeKey = $agent && $source ? Task::dedupeKey($workspace->id, $source->id, (string) $data['title']) : null;

        if ($dedupeKey && ($existing = Task::where('dedupe_key', $dedupeKey)->first())) {
            return $this->takeOverSuggestion($existing, $actor, $agent, $assigneeIds, $status) ?? $existing;
        }

        try {
            $task = DB::transaction(function () use ($workspace, $actor, $agent, $data, $source, $channel, $assigneeIds, $status, $dedupeKey) {
                $task = Task::create([
                    'workspace_id' => $workspace->id,
                    'title' => Str::limit(trim((string) $data['title']), 200, ''),
                    'description' => $data['description'] ?? null,
                    'creator_id' => $actor->id,
                    'ai_agent_id' => $agent?->id,
                    'priority' => $data['priority'] ?? 'normal',
                    'status' => $status,
                    'start_date' => $data['start_date'] ?? null,
                    'due_at' => isset($data['due_at']) ? Carbon::parse($data['due_at']) : null,
                    'confidence' => $agent ? ($data['confidence'] ?? null) : null,
                    'source_message_id' => $source?->id,
                    'source_channel_id' => $channel?->id,
                    'source_ref' => $source ? array_filter([
                        'quote' => Str::limit((string) ($data['source_quote'] ?? $source->body), 280),
                        'parent_id' => $source->parent_id,
                    ]) : null,
                    'dedupe_key' => $dedupeKey,
                ]);

                foreach ($assigneeIds as $userId) {
                    $task->assignees()->attach($userId, ['assigned_by' => $actor->id, 'ai_agent_id' => $agent?->id]);
                }

                if ($source) {
                    $task->messageLinks()->create(['message_id' => $source->id, 'relation' => 'source']);
                }

                $this->record($task, 'created', $actor, $agent, array_filter(['status' => $status, 'assignee_ids' => $assigneeIds]));

                if (! empty($data['depends_on_ids'])) {
                    $this->writeDependencies($task, $actor, $this->ids($data['depends_on_ids']));
                }

                return $task;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two workers found the same task at the same moment; the first one wins.
            return Task::where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        $this->announce($task, 'created');
        $this->notifyAssigned($task, $actor, $agent, $assigneeIds);

        return $task;
    }

    /** @param  array<string, mixed>  $data */
    public function update(Task $task, User $actor, array $data, ?AiAgent $agent = null): Task
    {
        $this->authorize($this->access->canManage($actor, $task));

        DB::transaction(function () use ($task, $actor, $agent, $data) {
            $changes = Arr::only($data, self::EDITABLE);

            if (array_key_exists('due_at', $changes)) {
                $changes['due_at'] = $changes['due_at'] ? Carbon::parse($changes['due_at']) : null;
            }

            $task->fill($changes);
            $dirty = $task->getDirty();

            if ($dirty !== []) {
                $before = Arr::only($task->getOriginal(), array_keys($dirty));
                $task->save();
                $this->record($task, 'updated', $actor, $agent, [
                    'fields' => array_keys($dirty),
                    'from' => $this->plain($before),
                    'to' => $this->plain(Arr::only($task->getAttributes(), array_keys($dirty))),
                ]);
            }

            if (array_key_exists('depends_on_ids', $data)) {
                $this->writeDependencies($task, $actor, $this->ids($data['depends_on_ids'] ?? []));
            }
        });

        $this->announce($task, 'updated');

        return $task->fresh();
    }

    /** @param  array<int, int>  $userIds */
    public function assign(Task $task, User $actor, array $userIds, ?AiAgent $agent = null): Task
    {
        $this->authorize($this->access->canManage($actor, $task));

        $userIds = $this->ids($userIds);
        $this->assertAssignable($task->workspace, $task->sourceChannel, $userIds);

        $current = $task->assignees()->pluck('users.id')->all();
        $added = array_values(array_diff($userIds, $current));
        $removed = array_values(array_diff($current, $userIds));

        if ($added === [] && $removed === []) {
            return $task;
        }

        DB::transaction(function () use ($task, $actor, $agent, $added, $removed) {
            $task->assignees()->detach($removed);

            foreach ($added as $userId) {
                $task->assignees()->attach($userId, ['assigned_by' => $actor->id, 'ai_agent_id' => $agent?->id]);
            }

            $this->record($task, 'assigned', $actor, $agent, ['added' => $added, 'removed' => $removed]);
        });

        // People who were just removed also hear about it, so the task leaves their panel.
        $this->announce($task, 'assigned', $removed);
        $this->notifyAssigned($task, $actor, $agent, $added);

        return $task->fresh();
    }

    public function changeStatus(Task $task, User $actor, string $status, ?AiAgent $agent = null): Task
    {
        $this->authorize($this->access->canWork($actor, $task));

        if (! $task->canMoveTo($status)) {
            throw ValidationException::withMessages(['status' => $this->statusError($task, $status)]);
        }

        $from = $task->status;

        DB::transaction(function () use ($task, $actor, $agent, $status, $from) {
            $task->update([
                'status' => $status,
                'completed_at' => $status === Task::COMPLETED ? now() : null,
            ]);
            $this->record($task, 'status', $actor, $agent, ['from' => $from, 'to' => $status]);
        });

        $this->announce($task, 'status');

        return $task;
    }

    public function pause(Task $task, User $actor, ?AiAgent $agent = null): Task
    {
        $this->authorize($this->access->canWork($actor, $task));

        if (! in_array($task->status, [Task::PENDING, Task::IN_PROGRESS, Task::BLOCKED], true)) {
            throw ValidationException::withMessages(['status' => 'Only open tasks can be paused.']);
        }

        DB::transaction(function () use ($task, $actor, $agent) {
            $from = $task->status;
            $task->update(['status' => Task::PAUSED, 'paused_from' => $from]);
            $this->record($task, 'paused', $actor, $agent, ['from' => $from]);
        });

        $this->announce($task, 'status');

        return $task;
    }

    public function resume(Task $task, User $actor, ?AiAgent $agent = null): Task
    {
        $this->authorize($this->access->canWork($actor, $task));

        if ($task->status !== Task::PAUSED) {
            throw ValidationException::withMessages(['status' => 'This task is not paused.']);
        }

        DB::transaction(function () use ($task, $actor, $agent) {
            $to = $task->paused_from ?: Task::PENDING;
            $task->update(['status' => $to, 'paused_from' => null]);
            $this->record($task, 'resumed', $actor, $agent, ['to' => $to]);
        });

        $this->announce($task, 'status');

        return $task;
    }

    /** Turns a suggestion into real work. Assignees hear about it only now. */
    public function approve(Task $task, User $actor): Task
    {
        $this->authorize($this->access->canManage($actor, $task));
        $this->assertSuggested($task);

        DB::transaction(function () use ($task, $actor) {
            $task->update(['status' => Task::PENDING]);
            $this->record($task, 'approved', $actor, null);
        });

        $this->announce($task, 'created');
        $this->notifyAssigned($task, $actor, $task->agent, $task->assignees()->pluck('users.id')->all());

        return $task;
    }

    public function reject(Task $task, User $actor, ?string $reason = null): Task
    {
        $this->authorize($this->access->canManage($actor, $task));
        $this->assertSuggested($task);

        DB::transaction(function () use ($task, $actor, $reason) {
            $task->update(['status' => Task::CANCELLED]);
            $this->record($task, 'rejected', $actor, null, array_filter(['reason' => $reason]));
        });

        $this->announce($task, 'status');

        return $task;
    }

    public function comment(Task $task, User $actor, string $body, ?AiAgent $agent = null): TaskEvent
    {
        $this->authorize($this->access->canView($actor, $task));

        $event = DB::transaction(fn () => $this->record($task, 'comment', $actor, $agent, [], trim($body)));
        $task->touch();

        $this->announce($task, 'comment');

        return $event;
    }

    public function archive(Task $task, User $actor, bool $archived = true): Task
    {
        $this->authorize($this->access->canManage($actor, $task));

        if (($task->archived_at !== null) === $archived) {
            return $task;
        }

        DB::transaction(function () use ($task, $actor, $archived) {
            $task->update(['archived_at' => $archived ? now() : null]);
            $this->record($task, $archived ? 'archived' : 'unarchived', $actor, null);
        });

        $this->announce($task, 'updated');

        return $task;
    }

    public function delete(Task $task, User $actor): void
    {
        $this->authorize($this->access->canManage($actor, $task));

        $audience = $this->audience($task);
        $task->delete();

        $this->broadcast($task->workspace_id, $task->id, 'deleted', $audience);
    }

    public function linkMessage(Task $task, Message $message, string $relation, User $actor, ?AiAgent $agent = null): void
    {
        $this->authorize($this->access->canWork($actor, $task) && $message->channel->hasMember($actor));

        $link = $task->messageLinks()->firstOrCreate(['message_id' => $message->id, 'relation' => $relation]);

        if ($link->wasRecentlyCreated) {
            $this->record($task, 'message_linked', $actor, $agent, ['message_id' => $message->id, 'relation' => $relation]);
        }
    }

    /** A source message was edited: the task stays as it is, but is marked so people can review it. */
    public function sourceEdited(Message $message): void
    {
        Task::where('source_message_id', $message->id)->get()->each(function (Task $task) {
            $task->update(['source_state' => 'edited']);
            $this->record($task, 'source_edited', null, null);
            $this->announce($task, 'updated');
        });
    }

    /**
     * A source message is being removed. A suggestion based on it is withdrawn;
     * accepted work stays, marked so people know the original is gone. Call this before deleting the row.
     */
    public function sourceRemoved(Message $message): void
    {
        Task::where('source_message_id', $message->id)->get()->each(function (Task $task) {
            DB::transaction(function () use ($task) {
                $suggested = $task->status === Task::SUGGESTED;
                $task->update([
                    'source_state' => 'removed',
                    'source_ref' => null, // The removed text must not live on in a copied quote.
                    'status' => $suggested ? Task::CANCELLED : $task->status,
                ]);
                $this->record($task, 'source_removed', null, null, $suggested ? ['withdrawn' => true] : []);
            });

            $this->announce($task, 'updated');
        });
    }

    /** @param  array<int, int>  $dependencyIds */
    private function writeDependencies(Task $task, User $actor, array $dependencyIds): void
    {
        $dependencyIds = array_values(array_diff($dependencyIds, [$task->id]));

        if (count($dependencyIds) > self::MAX_DEPENDENCIES) {
            throw ValidationException::withMessages(['depends_on_ids' => 'A task can depend on up to '.self::MAX_DEPENDENCIES.' tasks.']);
        }

        $dependencies = Task::with(['workspace', 'sourceChannel', 'assignees'])->whereIn('id', $dependencyIds)
            ->where('workspace_id', $task->workspace_id)->get()
            ->filter(fn (Task $dependency) => $this->access->canView($actor, $dependency));

        if ($dependencies->count() !== count($dependencyIds)) {
            throw ValidationException::withMessages(['depends_on_ids' => 'Choose tasks you can see in this workspace.']);
        }

        foreach ($dependencyIds as $id) {
            if ($this->reaches($id, $task->id)) {
                throw ValidationException::withMessages(['depends_on_ids' => 'That would make tasks wait on each other in a loop.']);
            }
        }

        $current = $task->dependencies()->pluck('tasks.id')->all();

        if (array_diff($current, $dependencyIds) === [] && array_diff($dependencyIds, $current) === []) {
            return;
        }

        $task->dependencies()->sync($dependencyIds);
        $this->record($task, 'dependencies', $actor, null, ['depends_on_ids' => $dependencyIds]);
    }

    /** Whether $from already depends, directly or through others, on $target. */
    private function reaches(int $from, int $target): bool
    {
        $seen = [];
        $queue = [$from];

        while ($queue !== []) {
            $id = array_shift($queue);

            if ($id === $target) {
                return true;
            }

            if (isset($seen[$id]) || count($seen) > 500) {
                continue;
            }

            $seen[$id] = true;
            array_push($queue, ...DB::table('task_dependencies')->where('task_id', $id)->pluck('depends_on_id')->map(fn ($v) => (int) $v)->all());
        }

        return false;
    }

    private function sourceMessage(Workspace $workspace, User $actor, int $messageId): Message
    {
        $message = Message::with('channel')->find($messageId);

        if (! $message || $message->channel->workspace_id !== $workspace->id || ! $message->channel->hasMember($actor) || $message->removed_at) {
            throw ValidationException::withMessages(['source_message_id' => 'Choose a message from a conversation you are in.']);
        }

        return $message;
    }

    /** @param  array<int, int>  $userIds */
    private function assertAssignable(Workspace $workspace, ?Channel $source, array $userIds): void
    {
        $invalid = $this->access->invalidAssignees($workspace, $source, $userIds);

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'assignee_ids' => $source
                    ? 'Assignees must be members of the conversation this task came from.'
                    : 'Assignees must be members of this workspace.',
            ]);
        }
    }

    private function assertSuggested(Task $task): void
    {
        if ($task->status !== Task::SUGGESTED) {
            throw ValidationException::withMessages(['status' => 'This task is not waiting for approval.']);
        }
    }

    private function statusError(Task $task, string $status): string
    {
        return match ($task->status) {
            Task::SUGGESTED => 'Approve or reject this suggestion first.',
            Task::PAUSED => 'Resume this task first.',
            default => "A {$task->status} task cannot be moved to {$status}.",
        };
    }

    private function record(Task $task, string $type, ?User $actor, ?AiAgent $agent, array $data = [], ?string $body = null): TaskEvent
    {
        return $task->events()->create([
            'actor_id' => $actor?->id,
            'ai_agent_id' => $agent?->id,
            'type' => $type,
            'data' => $data ?: null,
            'body' => $body,
        ]);
    }

    /** @return array<int, int> */
    private function audience(Task $task): array
    {
        $ids = [$task->creator_id];

        if ($task->status !== Task::SUGGESTED) {
            $ids = [...$ids, ...$task->assignees()->pluck('users.id')->all()];
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** @param  array<int, int>  $extra */
    private function announce(Task $task, string $action, array $extra = []): void
    {
        $this->broadcast($task->workspace_id, $task->id, $action, [...$this->audience($task), ...$extra]);
    }

    /** @param  array<int, int>  $userIds */
    private function broadcast(int $workspaceId, int $taskId, string $action, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        try {
            event(new TaskChanged($workspaceId, $taskId, $action, $userIds));
        } catch (Throwable $e) {
            report($e); // The task is saved; the live update is best effort.
        }
    }

    /** @param  array<int, int>  $userIds */
    private function notifyAssigned(Task $task, User $actor, ?AiAgent $agent, array $userIds): void
    {
        $userIds = array_values(array_diff($userIds, [$actor->id]));

        if ($userIds === [] || $task->status === Task::SUGGESTED) {
            return;
        }

        $from = $agent ? $agent->displayName() : $actor->name;

        SendPushNotification::dispatch($userIds, [
            'title' => "New task from {$from}",
            'body' => Str::limit($task->title, 140),
            'tag' => 'task-'.$task->id,
            'slug' => $task->workspace->slug,
            'url' => route('workspaces.show', ['workspace' => $task->workspace->slug, 'task' => $task->id], false),
        ])->afterResponse();
    }

    private function authorize(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException('You cannot change this task.');
        }
    }

    /** @param  array<int, mixed>  $ids */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @param  array<string, mixed>  $values */
    private function plain(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? Carbon::instance($value)->toIso8601String() : $value, $values);
    }
}
