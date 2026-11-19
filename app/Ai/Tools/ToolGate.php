<?php

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Ai\Exceptions\AgentHalted;
use App\Ai\RunRecorder;
use App\Services\AI\AgentCapabilities;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The only way a model's tool call reaches the application. In order, every call:
 *  1. re-reads the agent's status (a pause stops the very next action),
 *  2. validates the input the model produced,
 *  3. applies the owner's policy for the action (deny / approve / allow),
 *  4. runs the tool with the owner's own permissions, which the task and message services check again.
 * Whatever a workspace message says, it cannot get past these checks.
 */
class ToolGate
{
    /** Tools whose results include text written by other people (see AgentContext::$readWorkspaceContent). */
    public const RETURNS_WORKSPACE_CONTENT = [
        'search_messages', 'get_message', 'get_thread_replies', 'get_channel', 'list_conversations',
        'list_tasks', 'get_task', 'get_overdue_tasks',
        // These return an existing task, whose title or description may have been taken from a message.
        'update_task', 'assign_task', 'change_task_status', 'pause_task', 'resume_task',
    ];

    /** Tool inputs that are moments in time. */
    private const DATE_TIME_FIELDS = ['due_at', 'remind_at'];

    public function __construct(private RunRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{content: string, is_error: bool}
     */
    public function call(?Tool $tool, string $name, array $input, AgentContext $context): array
    {
        if ($tool === null) {
            return $this->result($context, $name, $input, 'error', ['error' => 'unknown_tool', 'message' => "There is no tool called {$name}."]);
        }

        // A pause or a removed owner ends the run here, before anything happens.
        $context->ensureCanAct();

        if ($context->readOnly && $tool->writes()) {
            return $this->result($context, $name, $input, 'denied', ['error' => 'paused', 'message' => 'You are paused, so you can answer questions but cannot take actions.']);
        }

        // Times are read in the owner's time zone before validation, so "after now" checks compare real moments.
        foreach (self::DATE_TIME_FIELDS as $field) {
            if (isset($input[$field]) && is_string($input[$field]) && strtotime($input[$field]) !== false) {
                $input[$field] = $context->toUtc($input[$field]);
            }
        }

        $validator = Validator::make($input, $tool->rules);

        if ($validator->fails()) {
            return $this->result($context, $name, $input, 'error', ['error' => 'invalid_input', 'messages' => $validator->errors()->all()]);
        }

        $policy = $tool->action !== null ? $context->policy($tool->action) : AgentCapabilities::ALLOW;

        if ($policy === AgentCapabilities::DENY) {
            $label = AgentCapabilities::ACTIONS[$tool->action] ?? $tool->action;

            return $this->result($context, $name, $input, 'denied', [
                'error' => 'not_permitted',
                'message' => "Your owner has not allowed this action ({$label}). Tell them instead of trying another way.",
            ]);
        }

        try {
            $output = ($tool->handler)($validator->validated(), $context, $policy);
        } catch (AgentHalted $e) {
            throw $e;
        } catch (AuthorizationException) {
            return $this->result($context, $name, $input, 'denied', ['error' => 'not_permitted', 'message' => 'Your owner does not have permission to do that.']);
        } catch (ModelNotFoundException) {
            return $this->result($context, $name, $input, 'error', ['error' => 'not_found', 'message' => 'Nothing was found with that id, or you cannot see it.']);
        } catch (ValidationException $e) {
            return $this->result($context, $name, $input, 'error', ['error' => 'rejected', 'messages' => collect($e->errors())->flatten()->all()]);
        } catch (Throwable $e) {
            report($e);

            return $this->result($context, $name, $input, 'error', ['error' => 'failed', 'message' => 'The tool failed. Do not retry it in this run.']);
        }

        if (in_array($tool->name, self::RETURNS_WORKSPACE_CONTENT, true)) {
            $context->readWorkspaceContent = true;
        }

        $status = ($output['status'] ?? null) === 'approval_requested' ? 'approval_required' : 'ok';

        return $this->result($context, $name, $input, $status, $output);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     * @return array{content: string, is_error: bool}
     */
    private function result(AgentContext $context, string $name, array $input, string $status, array $output): array
    {
        $this->recorder->step($context->run, 'tool', $name, $status, $input, $output);

        return [
            'content' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'is_error' => in_array($status, ['error', 'denied'], true),
        ];
    }
}
