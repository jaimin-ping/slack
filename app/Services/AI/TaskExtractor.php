<?php

namespace App\Services\AI;

use App\Ai\AgentContext;
use App\Ai\Contracts\LanguageModel;
use App\Ai\ModelRequest;
use App\Ai\ModelResponse;
use App\Ai\Prompts\AgentPrompt;
use App\Ai\RunRecorder;
use App\Ai\Tools\MessageTools;
use App\Events\AI\AgentChatReplied;
use App\Models\AiAgentChatMessage;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Finds action items in a batch of messages from one conversation.
 * The model only proposes; every proposal is checked here before anything is saved:
 *  - the evidence must be a real quote from that message,
 *  - assignees must be members of that conversation (no invented people),
 *  - a deadline is kept only when its wording appears in the message (no invented dates),
 *  - the owner's policies decide between "suggested" and a real task.
 */
class TaskExtractor
{
    private const UPDATE_STATUSES = [Task::IN_PROGRESS, Task::BLOCKED, Task::COMPLETED];

    public function __construct(
        private LanguageModel $model,
        private RunRecorder $recorder,
        private AgentPrompt $prompt,
        private TaskService $tasks,
        private TaskAccess $access,
        private ApprovalService $approvals,
    ) {}

    /**
     * @param  Collection<int, Message>  $messages
     * @return array{created: int, suggested: int, updated: int, dropped: int}
     */
    public function extract(AgentContext $context, Channel $channel, Collection $messages): array
    {
        $summary = ['created' => 0, 'suggested' => 0, 'updated' => 0, 'dropped' => 0];
        $context->ensureCanAct();
        $this->recorder->assertWithinBudget($context->agent);

        $owner = $context->owner();
        $members = $channel->members()->orderBy('name')->get(['users.id', 'users.name']);
        $openTasks = $this->openTasks($context, $channel);

        $response = $this->model->generate(new ModelRequest(
            system: $this->prompt->system($context->agent, 'extraction'),
            messages: [['role' => 'user', 'content' => $this->userTurn($owner, $channel, $members, $openTasks, $messages)]],
            outputSchema: self::schema(),
            effort: (string) config('ai.effort.extraction'),
            maxTokens: (int) config('ai.limits.max_output_tokens'),
        ));

        $this->recorder->addUsage($context->run, $response->usage, $response->model);

        if ($response->stopReason === ModelResponse::REFUSAL || ($data = $response->json()) === null) {
            $this->recorder->step($context->run, 'extraction', 'parse', 'error', null, ['stop' => $response->stopReason, 'reason' => 'No usable answer from the model.']);

            return $summary;
        }

        $byId = $messages->keyBy('id');
        $memberIds = $members->pluck('id')->map(fn ($id) => (int) $id)->all();
        $settings = $context->agent->resolvedSettings()['task_detection'];
        $outcomes = [];

        foreach (array_slice($data['tasks'] ?? [], 0, 20) as $item) {
            $outcome = $this->applyTask($context, $item, $byId, $memberIds, $settings);
            $outcomes[] = $outcome;
            $summary[$outcome['result']] = ($summary[$outcome['result']] ?? 0) + 1;
        }

        foreach (array_slice($data['updates'] ?? [], 0, 20) as $item) {
            $outcome = $this->applyUpdate($context, $item, $byId, $openTasks, $settings);
            $outcomes[] = $outcome;
            $summary[$outcome['result']] = ($summary[$outcome['result']] ?? 0) + 1;
        }

        $this->recorder->step($context->run, 'extraction', 'apply', 'ok', ['message_ids' => $messages->pluck('id')->all()], [
            'outcomes' => $outcomes,
        ]);

        return array_intersect_key($summary, array_flip(['created', 'suggested', 'updated', 'dropped']));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, Message>  $byId
     * @param  array<int, int>  $memberIds
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function applyTask(AgentContext $context, array $item, Collection $byId, array $memberIds, array $settings): array
    {
        $context->ensureCanAct();
        $message = $byId->get((int) ($item['message_id'] ?? 0));
        $title = Str::limit(trim((string) ($item['title'] ?? '')), 200, '');
        $drop = fn (string $why) => ['result' => 'dropped', 'title' => $title, 'reason' => $why];

        if (! $message) {
            return $drop('Refers to a message that was not in this batch.');
        }

        if (mb_strlen($title) < 2 || ! $this->quotes($message->body, $item['evidence'] ?? '')) {
            return $drop('The evidence is not a quote from the message.');
        }

        $createPolicy = $context->policy('create_task');

        if ($createPolicy === AgentCapabilities::DENY) {
            return $drop('Creating tasks is not permitted for this agent.');
        }

        $threshold = (float) $settings['min_confidence'];
        $confidence = max(0.0, min(1.0, (float) ($item['confidence'] ?? 0)));

        if ($confidence < $threshold / 2) {
            return $drop('Too unlikely to be a real task.');
        }

        $owner = $context->owner();
        $proposed = array_values(array_unique(array_map('intval', $item['assignee_ids'] ?? [])));
        $assignees = array_values(array_intersect($proposed, $memberIds));
        $unresolved = array_values(array_filter(array_map('strval', $item['unresolved_assignees'] ?? [])));
        $needsClarification = (bool) ($item['needs_clarification'] ?? false) || $unresolved !== [] || count($assignees) !== count($proposed);

        if ($settings['only_mine'] && ! in_array($owner->id, $assignees, true) && ! ($assignees === [] && $message->user_id === $owner->id)) {
            return $drop('Not for the agent\'s owner.');
        }

        $notes = [];
        $others = array_values(array_diff($assignees, [$owner->id]));
        $assignPolicy = $others !== [] ? $context->policy('assign_task') : AgentCapabilities::ALLOW;

        if ($assignPolicy === AgentCapabilities::DENY) {
            $notes[] = 'Mentioned for: '.User::whereIn('id', $others)->pluck('name')->implode(', ').'.';
            $assignees = array_values(array_intersect($assignees, [$owner->id]));
        }

        $suggest = $createPolicy === AgentCapabilities::APPROVE
            || $confidence < $threshold
            || $needsClarification
            || $assignPolicy !== AgentCapabilities::ALLOW;

        if ($needsClarification && ! empty($item['clarification_question'])) {
            $notes[] = 'Question: '.Str::limit((string) $item['clarification_question'], 300);
        }

        $description = trim(implode("\n\n", array_filter([$item['description'] ?? null, ...$notes])));

        try {
            $task = $this->tasks->create($context->workspace(), $owner, [
                'title' => $title,
                'description' => $description ?: null,
                'priority' => in_array($item['priority'] ?? null, Task::PRIORITIES, true) ? $item['priority'] : 'normal',
                'due_at' => $this->dueAt($message, $item, $owner),
                'assignee_ids' => $assignees,
                'source_message_id' => $message->id,
                'source_quote' => $item['evidence'],
                'confidence' => round($confidence, 2),
                'status' => $suggest ? Task::SUGGESTED : Task::PENDING,
            ], $context->agent);
        } catch (ValidationException $e) {
            return $drop(collect($e->errors())->flatten()->first());
        }

        if (! $task->wasRecentlyCreated && ! $task->wasChanged('creator_id')) {
            return ['result' => 'dropped', 'title' => $title, 'reason' => 'Already recorded as task '.$task->id.'.'];
        }

        if ($needsClarification) {
            $this->ask($context, $task, $item['clarification_question'] ?? null, $unresolved);
        }

        return ['result' => $task->status === Task::SUGGESTED ? 'suggested' : 'created', 'title' => $title, 'task_id' => $task->id];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, Message>  $byId
     * @param  Collection<int, Task>  $openTasks
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function applyUpdate(AgentContext $context, array $item, Collection $byId, Collection $openTasks, array $settings): array
    {
        $context->ensureCanAct();
        $message = $byId->get((int) ($item['message_id'] ?? 0));
        $task = $openTasks->firstWhere('id', (int) ($item['task_id'] ?? 0));
        $status = (string) ($item['status'] ?? '');
        $drop = fn (string $why) => ['result' => 'dropped', 'task_id' => $item['task_id'] ?? null, 'reason' => $why];

        if (! $message || ! $task) {
            return $drop('Unknown message or task.');
        }

        if (! in_array($status, self::UPDATE_STATUSES, true) || ! $task->canMoveTo($status)) {
            return $drop("The task cannot move to {$status}.");
        }

        if (! $this->quotes($message->body, $item['evidence'] ?? '') || (float) ($item['confidence'] ?? 0) < (float) $settings['min_confidence']) {
            return $drop('Not certain enough.');
        }

        $policy = $context->policy('update_task');

        if ($policy === AgentCapabilities::DENY) {
            return $drop('Updating tasks is not permitted for this agent.');
        }

        if ($policy === AgentCapabilities::APPROVE) {
            $this->approvals->request($context, 'update_task', "Mark \"{$task->title}\" as {$status}",
                ['operation' => 'status', 'task_id' => $task->id, 'status' => $status], $task);

            return ['result' => 'suggested', 'task_id' => $task->id, 'reason' => 'Waiting for approval.'];
        }

        $owner = $context->owner();
        $this->tasks->changeStatus($task, $owner, $status, $context->agent);
        $this->tasks->linkMessage($task, $message, $status === Task::COMPLETED ? 'completion' : 'update', $owner, $context->agent);

        return ['result' => 'updated', 'task_id' => $task->id, 'status' => $status];
    }

    /** A deadline counts only if the words for it really are in the message. */
    private function dueAt(Message $message, array $item, User $owner): ?string
    {
        $date = $item['due_date'] ?? null;

        if (! $date || ! $this->quotes($message->body, $item['due_phrase'] ?? '')) {
            return null;
        }

        $timezone = $owner->timezone ?: config('app.timezone');

        try {
            $due = Carbon::createFromFormat('!Y-m-d', (string) $date, $timezone);
        } catch (Throwable) {
            return null;
        }

        if (! $due || $due->format('Y-m-d') !== $date) {
            return null;
        }

        $time = $item['due_time'] ?? null;
        $due = is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)
            ? $due->setTimeFromTimeString($time)
            : $due->endOfDay();

        return $due->utc()->toIso8601String();
    }

    private function quotes(?string $body, mixed $quote): bool
    {
        $normalize = fn (string $text) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        $quote = $normalize((string) $quote);

        return mb_strlen($quote) >= 3 && str_contains($normalize((string) $body), $quote);
    }

    /** When it is unclear who should do something, the agent asks its owner privately instead of guessing. */
    private function ask(AgentContext $context, Task $task, ?string $question, array $unresolved): void
    {
        $text = $question ?: 'I found a possible task but could not tell who should do it.';
        $names = $unresolved ? ' (mentioned: '.implode(', ', array_slice($unresolved, 0, 5)).')' : '';

        $message = AiAgentChatMessage::create([
            'ai_agent_id' => $context->agent->id,
            'role' => 'agent',
            'content' => Str::limit("{$text}{$names} — saved as a suggestion: \"{$task->title}\".", 1000),
            'ai_agent_run_id' => $context->run->id,
        ]);

        try {
            event(new AgentChatReplied($message, $context->agent->owner_id));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return Collection<int, Task> */
    private function openTasks(AgentContext $context, Channel $channel): Collection
    {
        return $this->access->visibleQuery(Task::query(), $context->owner(), $context->workspace())
            ->whereIn('status', [Task::PENDING, Task::IN_PROGRESS, Task::BLOCKED])
            ->where(fn ($q) => $q->where('source_channel_id', $channel->id)
                ->orWhereHas('assignees', fn ($a) => $a->whereKey($context->agent->owner_id)))
            ->where(fn ($q) => $q->whereNull('source_channel_id')->orWhereIn('source_channel_id', $context->readableChannelIds()))
            ->with('assignees:id,name')
            ->latest('id')->limit(30)->get();
    }

    /**
     * @param  Collection<int, User>  $members
     * @param  Collection<int, Task>  $openTasks
     * @param  Collection<int, Message>  $messages
     */
    private function userTurn(User $owner, Channel $channel, Collection $members, Collection $openTasks, Collection $messages): string
    {
        $now = now($owner->timezone ?: config('app.timezone'));
        $place = $channel->type === Channel::DM ? 'a direct message' : "#{$channel->name} ({$channel->type} channel)";
        $json = fn ($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return implode("\n\n", [
            "Now: {$now->format('l, Y-m-d H:i')} ({$now->timezoneName}), {$owner->name}'s time zone.",
            "Conversation: {$place}. {$owner->name} is member id {$owner->id}.",
            'People in this conversation (the only valid assignee ids): '.$json($members->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()),
            'Open tasks that a message might update (the only valid task ids): '.$json($openTasks->map(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'assignees' => $t->assignees->pluck('name'),
            ])->values()),
            MessageTools::UNTRUSTED_NOTE."\n<untrusted_messages>\n".$json($messages->map(fn (Message $m) => MessageTools::present($m))->values())."\n</untrusted_messages>",
        ]);
    }

    /** The reply must follow this JSON schema. Every field is required; "no value" is null or an empty list. */
    public static function schema(): array
    {
        $nullableString = ['anyOf' => [['type' => 'string'], ['type' => 'null']]];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['tasks', 'updates'],
            'properties' => [
                'tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['message_id', 'title', 'description', 'assignee_ids', 'unresolved_assignees', 'due_date', 'due_time',
                            'due_phrase', 'priority', 'confidence', 'evidence', 'needs_clarification', 'clarification_question'],
                        'properties' => [
                            'message_id' => ['type' => 'integer'],
                            'title' => ['type' => 'string', 'description' => 'Short imperative title, e.g. "Complete inventory dashboard"'],
                            'description' => $nullableString,
                            'assignee_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'unresolved_assignees' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'due_date' => $nullableString + ['description' => 'YYYY-MM-DD'],
                            'due_time' => $nullableString + ['description' => 'HH:MM, 24-hour'],
                            'due_phrase' => $nullableString + ['description' => 'The exact words that state the deadline'],
                            'priority' => ['type' => 'string', 'enum' => Task::PRIORITIES],
                            'confidence' => ['type' => 'number'],
                            'evidence' => ['type' => 'string', 'description' => 'Exact quote from the message'],
                            'needs_clarification' => ['type' => 'boolean'],
                            'clarification_question' => $nullableString,
                        ],
                    ],
                ],
                'updates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['message_id', 'task_id', 'status', 'evidence', 'confidence'],
                        'properties' => [
                            'message_id' => ['type' => 'integer'],
                            'task_id' => ['type' => 'integer'],
                            'status' => ['type' => 'string', 'enum' => self::UPDATE_STATUSES],
                            'evidence' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
