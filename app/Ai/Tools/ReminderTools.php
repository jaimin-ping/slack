<?php

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Models\Reminder;
use App\Services\AI\AgentCapabilities;
use App\Services\AI\ApprovalService;
use Illuminate\Support\Carbon;

/**
 * Reminders for the agent's owner only. They are ordinary reminders, delivered by the existing
 * scheduler (chat:run-due) as a message in the owner's notes-to-self.
 */
class ReminderTools
{
    public function __construct(private ApprovalService $approvals) {}

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [
            new Tool(
                'list_reminders',
                'Your owner\'s upcoming reminders in this workspace.',
                Tool::object([]),
                [],
                null,
                fn (array $input, AgentContext $context) => ['reminders' => Reminder::where([
                    'user_id' => $context->agent->owner_id,
                    'workspace_id' => $context->agent->workspace_id,
                ])->whereNull('sent_at')->orderBy('remind_at')->limit(25)->get()
                    ->map(fn (Reminder $reminder) => ['id' => $reminder->id, 'text' => $reminder->text, 'remind_at' => $reminder->remind_at->toIso8601String()])
                    ->all()],
            ),
            new Tool(
                'create_reminder',
                'Remind your owner about something at a given time.',
                Tool::object([
                    'text' => ['type' => 'string'],
                    'remind_at' => ['type' => 'string', 'description' => 'ISO 8601 date-time in the future'],
                ], ['text', 'remind_at']),
                ['text' => ['required', 'string', 'min:2', 'max:255'], 'remind_at' => ['required', 'date', 'after:now']],
                'create_reminder',
                fn (array $input, AgentContext $context, string $policy) => $this->save($context, $policy, ['operation' => 'create'] + $input),
            ),
            new Tool(
                'update_reminder',
                'Change the text or time of one of your owner\'s upcoming reminders.',
                Tool::object([
                    'reminder_id' => ['type' => 'integer'],
                    'text' => ['type' => 'string'],
                    'remind_at' => ['type' => 'string', 'description' => 'ISO 8601 date-time in the future'],
                ], ['reminder_id', 'remind_at']),
                ['reminder_id' => ['required', 'integer'], 'text' => ['sometimes', 'string', 'min:2', 'max:255'], 'remind_at' => ['required', 'date', 'after:now']],
                'create_reminder',
                function (array $input, AgentContext $context, string $policy) {
                    Reminder::where(['user_id' => $context->agent->owner_id, 'workspace_id' => $context->agent->workspace_id])
                        ->whereNull('sent_at')->findOrFail($input['reminder_id']);

                    return $this->save($context, $policy, ['operation' => 'update'] + $input);
                },
            ),
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function save(AgentContext $context, string $policy, array $payload): array
    {
        $payload['remind_at'] = Carbon::parse($payload['remind_at'])->toIso8601String();

        if ($policy === AgentCapabilities::APPROVE) {
            $when = Carbon::parse($payload['remind_at'])->timezone($context->owner()->timezone ?: config('app.timezone'))->format('D j M, H:i');
            $approval = $this->approvals->request($context, 'create_reminder', 'Reminder at '.$when.': '.($payload['text'] ?? 'updated reminder'), $payload);

            return ['status' => 'approval_requested', 'approval_id' => $approval->id, 'message' => 'Your owner must approve this first.'];
        }

        $reminder = ApprovalService::saveReminder($context->owner(), $context->agent->workspace_id, $payload);

        return ['status' => 'done', 'reminder' => ['id' => $reminder->id, 'text' => $reminder->text, 'remind_at' => $reminder->remind_at->toIso8601String()]];
    }
}
