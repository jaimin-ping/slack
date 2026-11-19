<?php

namespace App\Services\Tasks;

use App\Models\Channel;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may see and change a task. Every web and mobile endpoint, and every agent tool, asks here.
 *
 * - The creator (for an agent's task: the agent's owner) manages it.
 * - Assignees can see it and move it along.
 * - Members of the conversation it came from can see it ("team tasks").
 * - A suggested task is visible to its creator only, until they approve it.
 */
class TaskAccess
{
    public function visibleQuery(Builder $query, User $user, Workspace $workspace): Builder
    {
        $myChannels = DB::table('channel_user')->where('user_id', $user->id)->select('channel_id');

        return $query->where('tasks.workspace_id', $workspace->id)
            ->where(function (Builder $query) use ($user, $myChannels) {
                $query->where('tasks.creator_id', $user->id)
                    ->orWhere(function (Builder $query) use ($user, $myChannels) {
                        $query->where('tasks.status', '!=', Task::SUGGESTED)
                            ->where(function (Builder $query) use ($user, $myChannels) {
                                $query->whereHas('assignees', fn (Builder $q) => $q->whereKey($user->id))
                                    ->orWhereIn('tasks.source_channel_id', $myChannels);
                            });
                    });
            });
    }

    public function canView(User $user, Task $task): bool
    {
        if (! $this->inWorkspace($user, $task)) {
            return false;
        }

        if ($task->creator_id === $user->id) {
            return true;
        }

        return $task->status !== Task::SUGGESTED
            && ($this->isAssignee($user, $task) || $this->canSeeSource($user, $task));
    }

    /** Edit, assign, approve, archive and delete. */
    public function canManage(User $user, Task $task): bool
    {
        return $task->creator_id === $user->id && $this->inWorkspace($user, $task);
    }

    /** Change status, pause and resume. */
    public function canWork(User $user, Task $task): bool
    {
        return $this->canManage($user, $task)
            || ($task->status !== Task::SUGGESTED && $this->inWorkspace($user, $task) && $this->isAssignee($user, $task));
    }

    /** Only members of the original conversation may see where a task came from. */
    public function canSeeSource(User $user, Task $task): bool
    {
        $channel = $task->sourceChannel;

        return $channel !== null && $channel->hasMember($user);
    }

    /**
     * People who can be given the task: workspace members, and when it came from a conversation,
     * only members of that conversation, so a private discussion never reaches someone outside it.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int> the ids that are not allowed
     */
    public function invalidAssignees(Workspace $workspace, ?Channel $source, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $allowed = $workspace->members()->whereIn('users.id', $userIds)->pluck('users.id');

        if ($source) {
            $allowed = $allowed->intersect($source->members()->whereIn('users.id', $userIds)->pluck('users.id'));
        }

        return array_values(array_diff($userIds, $allowed->all()));
    }

    private function inWorkspace(User $user, Task $task): bool
    {
        return $task->workspace->hasMember($user);
    }

    private function isAssignee(User $user, Task $task): bool
    {
        return $task->relationLoaded('assignees')
            ? $task->assignees->contains('id', $user->id)
            : $task->assignees()->whereKey($user->id)->exists();
    }
}
