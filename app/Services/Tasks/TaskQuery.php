<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** The task panel's tabs and filters, shared by the controller and agent tools. Always starts from what the person can see. */
class TaskQuery
{
    public const SCOPES = ['all', 'mine', 'assigned_to_me', 'assigned_by_me', 'team', 'pending_approval', 'completed'];

    public function __construct(private TaskAccess $access) {}

    /** @param  array<string, mixed>  $filters */
    public function filtered(User $user, Workspace $workspace, array $filters): Builder
    {
        $query = $this->access->visibleQuery(Task::query(), $user, $workspace);
        $assignedToMe = fn (Builder $q) => $q->whereKey($user->id);
        $scope = $filters['scope'] ?? 'all';

        match ($scope) {
            'mine' => $query->where(fn (Builder $q) => $q->whereHas('assignees', $assignedToMe)
                ->orWhere(fn (Builder $q) => $q->where('creator_id', $user->id)->doesntHave('assignees'))),
            'assigned_to_me' => $query->whereHas('assignees', $assignedToMe),
            'assigned_by_me' => $query->where('creator_id', $user->id)
                ->whereHas('assignees', fn (Builder $q) => $q->where('users.id', '!=', $user->id)),
            'team' => $query->whereIn('source_channel_id', DB::table('channel_user')->where('user_id', $user->id)->select('channel_id')),
            'pending_approval' => $query->where('creator_id', $user->id),
            default => null,
        };

        $statuses = $filters['status'] ?? match ($scope) {
            'pending_approval' => [Task::SUGGESTED],
            'completed' => [Task::COMPLETED],
            'all' => null,
            default => Task::OPEN_STATUSES,
        };

        if ($statuses) {
            $query->whereIn('status', $statuses);
        }

        if (! empty($filters['priority'])) {
            $query->whereIn('priority', $filters['priority']);
        }

        if (! empty($filters['q'])) {
            $query->whereRaw('LOWER(title) LIKE ?', ['%'.addcslashes(mb_strtolower($filters['q']), '\\%_').'%']);
        }

        if (isset($filters['agent_id'])) {
            $query->where('ai_agent_id', $filters['agent_id']);
        }

        if (isset($filters['channel_id'])) {
            $query->where('source_channel_id', $filters['channel_id']);
        }

        match ($filters['due'] ?? null) {
            'overdue' => $query->where('due_at', '<', now())->whereIn('status', Task::OPEN_STATUSES),
            'today' => $query->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]),
            'week' => $query->whereBetween('due_at', [now(), now()->addWeek()]),
            'none' => $query->whereNull('due_at'),
            default => null,
        };

        if (! ($filters['archived'] ?? false)) {
            $query->whereNull('archived_at');
        }

        return $query;
    }

    public function sort(Builder $query, string $sort): Builder
    {
        match ($sort) {
            // Tasks without a deadline go last, on every database.
            'due' => $query->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at'),
            'priority' => $query->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END"),
            default => null,
        };

        return $query->orderByDesc('tasks.id');
    }
}
