<?php

namespace App\Services\Tasks;

use App\Models\Channel;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;

/** Turns a task into JSON for one viewer, hiding what that viewer may not see. */
class TaskPresenter
{
    public const RELATIONS = ['assignees:id,name,avatar_path', 'agent', 'creator:id,name,avatar_path', 'sourceChannel', 'sourceMessage:id,parent_id', 'workspace'];

    public function __construct(private TaskAccess $access) {}

    /** @return array<string, mixed> */
    public function present(Task $task, User $viewer, bool $detailed = false): array
    {
        $task->loadMissing(self::RELATIONS);

        $payload = [
            'id' => $task->id,
            'workspace_id' => $task->workspace_id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'paused_from' => $task->paused_from,
            'priority' => $task->priority,
            'start_date' => $task->start_date?->toDateString(),
            'due_at' => $task->due_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'archived' => $task->archived_at !== null,
            'is_overdue' => $task->isOverdue(),
            'confidence' => $task->confidence,
            'creator' => $task->creator ? $this->person($task->creator) : null,
            'agent' => $task->agent ? [
                'id' => $task->agent->id,
                'name' => $task->agent->name,
                'display_name' => $task->agent->displayName(),
                'avatar_url' => $task->agent->avatarUrl(),
                'avatar_preset' => $task->agent->avatar_preset,
            ] : null,
            'assignees' => $task->assignees->map(fn (User $user) => $this->person($user))->values()->all(),
            'source' => $this->source($task, $viewer),
            'can' => [
                'manage' => $this->access->canManage($viewer, $task),
                'work' => $this->access->canWork($viewer, $task),
            ],
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $payload['dependencies'] = $task->dependencies()->with(['workspace', 'sourceChannel'])->get()
                ->map(fn (Task $dependency) => $this->access->canView($viewer, $dependency)
                    ? ['id' => $dependency->id, 'title' => $dependency->title, 'status' => $dependency->status, 'visible' => true]
                    : ['id' => $dependency->id, 'title' => null, 'status' => $dependency->status, 'visible' => false])
                ->values()->all();
            $payload['history'] = $task->events()->with(['actor:id,name', 'agent'])->orderBy('id')->get()
                ->map(fn (TaskEvent $event) => $event->toPayload())->all();
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function source(Task $task, User $viewer): ?array
    {
        if (! $task->source_channel_id && ! $task->source_message_id && ! $task->source_state) {
            return null;
        }

        if (! $this->access->canSeeSource($viewer, $task)) {
            return ['available' => false, 'state' => $task->source_state];
        }

        $channel = $task->sourceChannel;
        $partnerId = $channel->dmPartnerId($viewer->id);
        $threadId = $task->sourceMessage?->parent_id;

        return [
            'available' => true,
            'state' => $task->source_state,
            'message_id' => $task->source_message_id,
            'channel_id' => $channel->id,
            'channel_type' => $channel->type,
            'channel_label' => match (true) {
                $channel->type !== Channel::DM => '#'.$channel->name,
                $partnerId === $viewer->id => 'Notes to yourself',
                default => User::find($partnerId)?->name ?? 'Direct message',
            },
            'quote' => $task->source_ref['quote'] ?? null,
            'url' => route('channels.show', array_filter([
                'workspace' => $task->workspace->slug,
                'channel' => $channel->id,
                'thread' => $threadId,
                'message' => $task->source_message_id,
            ]), false),
        ];
    }

    /** @return array<string, mixed> */
    private function person(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'avatar_url' => $user->avatarUrl()];
    }
}
