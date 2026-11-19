<?php

namespace Tests\Feature\AI;

use App\Ai\ModelRequest;
use App\Ai\Providers\FakeLanguageModel;
use App\Ai\Tools\MessageTools;
use App\Events\AI\AgentChatReplied;
use App\Events\AI\ApprovalChanged;
use App\Models\AiAgent;
use App\Models\AiAgentApproval;
use App\Models\AiAgentChatMessage;
use App\Models\AiAgentMemory;
use App\Models\AiAgentRun;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\AI\AgentManager;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\AI\Concerns\BuildsAgents;
use Tests\TestCase;

class AgentRuntimeTest extends TestCase
{
    use BuildsAgents;
    use RefreshDatabase;

    private AiAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkspace();
        $this->agent = $this->makeAgent($this->kirit, ['mode' => 'auto_tasks', 'instructions' => 'Always prioritise my development tasks.']);
    }

    private function url(string $path = ''): string
    {
        return "/w/{$this->workspace->slug}/agents/{$this->agent->id}{$path}";
    }

    private function ask(string $text): string
    {
        $this->actingAs($this->kirit)->postJson($this->url('/chat'), ['message' => $text])->assertStatus(202);

        return AiAgentChatMessage::where('role', 'agent')->latest('id')->value('content');
    }

    /** The JSON a tool returned to the model in the given request. */
    private function toolResult(ModelRequest $request, int $index = 0): array
    {
        return json_decode(collect($request->messages)->last()['results'][$index]['content'], true);
    }

    public function test_agent_answers_task_questions_from_real_records(): void
    {
        Event::fake([AgentChatReplied::class]);
        $tasks = app(TaskService::class);
        $tasks->create($this->workspace, $this->kirit, ['title' => 'Inventory dashboard', 'assignee_ids' => [$this->kirit->id]]);
        $tasks->create($this->workspace, $this->kirit, ['title' => 'Stock adjustment testing', 'assignee_ids' => [$this->kirit->id]]);
        $tasks->create($this->workspace, $this->kirit, ['title' => 'Release notes']);
        config(['ai.pricing.fake-model' => ['input' => 4.0, 'output' => 20.0]]);

        $this->ai->push(
            FakeLanguageModel::tools(['list_tasks', ['scope' => 'mine', 'search' => 'inventory']]),
            fn (ModelRequest $request) => FakeLanguageModel::text('1. Inventory dashboard — Pending'),
        );

        $reply = $this->ask('Alex, show me the status of my inventory tasks.');

        $this->assertSame('1. Inventory dashboard — Pending', $reply);
        $result = $this->toolResult($this->ai->requests[1]);
        $this->assertSame(['Inventory dashboard'], array_column($result['tasks'], 'title'));

        $request = $this->ai->requests[0];
        $this->assertStringContainsString('Always prioritise my development tasks.', $request->system);
        $this->assertStringContainsString('Alex (AI Assistant)', $request->system);
        $this->assertSame('medium', $request->effort);
        $this->assertContains('create_task', array_column($request->tools, 'name'));

        $run = AiAgentRun::where('trigger', 'chat')->firstOrFail();
        $this->assertSame(AiAgentRun::SUCCEEDED, $run->status);
        $this->assertSame(2, $run->steps);
        $this->assertSame(400, $run->totalTokens());
        $this->assertSame(4800, $run->cost_micros); // 200 in × $4 + 200 out × $20 per million tokens
        Event::assertDispatched(AgentChatReplied::class);
    }

    public function test_allowed_actions_happen_and_are_attributed_to_the_agent(): void
    {
        $this->ai->push(
            FakeLanguageModel::tools(['create_task', ['title' => 'Write release notes', 'assignee_ids' => [$this->kirit->id], 'priority' => 'high']]),
            FakeLanguageModel::text('Created it.'),
        );

        $this->ask('Create a task for me to write release notes.');

        $task = Task::firstOrFail();
        $this->assertSame(Task::PENDING, $task->status);
        $this->assertSame($this->agent->id, $task->ai_agent_id);
        $this->assertSame($this->kirit->id, $task->creator_id);
        $this->assertSame('created', $this->toolResult($this->ai->requests[1])['status']);
    }

    public function test_denied_actions_are_refused_by_the_server(): void
    {
        app(AgentManager::class)->setPermissions($this->agent, null, ['create_task' => 'deny']);

        $this->ai->push(FakeLanguageModel::tools(['create_task', ['title' => 'Sneaky task']]), FakeLanguageModel::text('I could not.'));

        $this->ask('Create a task.');

        $this->assertSame(0, Task::count());
        $result = $this->toolResult($this->ai->requests[1]);
        $this->assertSame('not_permitted', $result['error']);
        $this->assertTrue(collect($this->ai->requests[1]->messages)->last()['results'][0]['is_error']);
        $this->assertSame('denied', AiAgentRun::first()->stepRecords()->where('type', 'tool')->value('status'));
    }

    public function test_assigning_someone_else_respects_the_assignment_policy(): void
    {
        app(AgentManager::class)->setPermissions($this->agent, null, ['assign_task' => 'deny']);
        $this->ai->push(FakeLanguageModel::tools(['create_task', ['title' => 'Jaimin chore', 'assignee_ids' => [$this->jaimin->id]]]), FakeLanguageModel::text('ok'));

        $this->ask('Give Jaimin a chore.');

        $this->assertSame(0, Task::count());
        $this->assertSame('rejected', $this->toolResult($this->ai->requests[1])['error']);
    }

    public function test_actions_needing_approval_wait_for_the_owner(): void
    {
        Event::fake([ApprovalChanged::class]);
        $task = app(TaskService::class)->create($this->workspace, $this->kirit, ['title' => 'Old title']);
        app(AgentManager::class)->setPermissions($this->agent, null, ['update_task' => 'approve']);

        $call = FakeLanguageModel::tools(['update_task', ['task_id' => $task->id, 'title' => 'New title']]);
        $this->ai->push($call, FakeLanguageModel::text('Asked you.'), $call, FakeLanguageModel::text('Asked you again.'));

        $this->ask('Rename the task.');
        $this->ask('Rename the task, please.');

        $this->assertSame('Old title', $task->fresh()->title);
        $this->assertSame(1, AiAgentApproval::count(), 'The same proposal is filed once.');
        $approval = AiAgentApproval::firstOrFail();
        $this->assertSame('approval_requested', $this->toolResult($this->ai->requests[1])['status']);
        Event::assertDispatched(ApprovalChanged::class);

        $base = "/w/{$this->workspace->slug}/approvals";
        $this->actingAs($this->kirit)->getJson($base)->assertOk()->assertJsonCount(1)->assertJsonPath('0.summary', 'Update "Old title": title');
        $this->actingAs($this->jaimin)->postJson("{$base}/{$approval->id}/approve")->assertNotFound();
        $this->actingAs($this->jaimin)->getJson($base)->assertOk()->assertJsonCount(0);

        $this->actingAs($this->kirit)->postJson("{$base}/{$approval->id}/approve")->assertOk()->assertJsonPath('status', 'executed');
        $this->assertSame('New title', $task->fresh()->title);
        $this->assertSame($this->agent->id, $task->events()->where('type', 'updated')->value('ai_agent_id'));

        $this->actingAs($this->kirit)->postJson("{$base}/{$approval->id}/approve")->assertJsonValidationErrors('status');
    }

    public function test_an_approval_is_checked_again_when_it_runs(): void
    {
        $task = app(TaskService::class)->create($this->workspace, $this->kirit, ['title' => 'Will vanish']);
        app(AgentManager::class)->setPermissions($this->agent, null, ['update_task' => 'approve']);
        $this->ai->push(FakeLanguageModel::tools(['change_task_status', ['task_id' => $task->id, 'status' => 'completed']]), FakeLanguageModel::text('ok'));
        $this->ask('Complete it.');
        $approval = AiAgentApproval::firstOrFail();

        $task->delete();

        $this->actingAs($this->kirit)->postJson("/w/{$this->workspace->slug}/approvals/{$approval->id}/approve")
            ->assertOk()->assertJsonPath('status', 'failed');
    }

    public function test_rejected_approvals_do_nothing(): void
    {
        app(AgentManager::class)->setPermissions($this->agent, null, ['create_reminder' => 'approve']);
        $this->ai->push(FakeLanguageModel::tools(['create_reminder', ['text' => 'Stand-up', 'remind_at' => now()->addDay()->toIso8601String()]]), FakeLanguageModel::text('ok'));
        $this->ask('Remind me about stand-up tomorrow.');

        $approval = AiAgentApproval::firstOrFail();
        $this->actingAs($this->kirit)->postJson("/w/{$this->workspace->slug}/approvals/{$approval->id}/reject", ['reason' => 'Not needed'])
            ->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertSame(0, Reminder::count());
    }

    public function test_times_without_a_zone_mean_the_owners_local_time(): void
    {
        $this->kirit->update(['timezone' => 'Asia/Kolkata']);
        $local = now('Asia/Kolkata')->addDay()->setTime(18, 0);

        $this->ai->push(
            FakeLanguageModel::tools([
                ['create_reminder', ['text' => 'Stand-up notes', 'remind_at' => $local->format('Y-m-d H:i')]],
                ['create_task', ['title' => 'Sprint report', 'assignee_ids' => [$this->kirit->id], 'due_at' => $local->format('Y-m-d\TH:i:s')]],
            ]),
            FakeLanguageModel::text('Done.'),
        );

        $this->ask('Remind me tomorrow at 6 PM, and make a task due then.');

        // 18:00 in India is 12:30 UTC, not 18:00 UTC.
        $this->assertTrue(Reminder::firstOrFail()->remind_at->equalTo($local->copy()->utc()));
        $this->assertTrue(Task::firstOrFail()->due_at->equalTo($local->copy()->utc()));
        $this->assertStringContainsString('UTC+05:30', collect($this->ai->requests[0]->messages)->last()['content']);
    }

    public function test_reminders_go_through_the_existing_reminder_system(): void
    {
        $at = now()->addHours(3)->startOfMinute();
        $this->ai->push(FakeLanguageModel::tools(['create_reminder', ['text' => 'Check the deploy', 'remind_at' => $at->toIso8601String()]]), FakeLanguageModel::text('Done.'));

        $this->ask('Remind me to check the deploy in 3 hours.');

        $reminder = Reminder::firstOrFail();
        $this->assertSame($this->kirit->id, $reminder->user_id);
        $this->assertSame($this->workspace->id, $reminder->workspace_id);
        $this->assertTrue($reminder->remind_at->equalTo($at));
    }

    public function test_tools_cannot_reach_beyond_granted_conversations(): void
    {
        $private = $this->workspace->channels()->create(['name' => 'leadership', 'type' => Channel::PRIVATE, 'created_by' => $this->boss->id]);
        $private->members()->attach([$this->boss->id, $this->kirit->id]);
        // Seeded quietly, so real-time extraction does not use up the scripted replies.
        [$secret, $public] = Message::withoutEvents(fn () => [
            $private->messages()->create(['user_id' => $this->boss->id, 'body' => 'Salary review numbers']),
            $this->general->messages()->create(['user_id' => $this->boss->id, 'body' => 'Salary review is next week']),
        ]);

        $this->ai->push(
            FakeLanguageModel::tools([['get_message', ['message_id' => $secret->id]], ['search_messages', ['query' => 'salary']], ['get_channel', ['channel_id' => $private->id]]]),
            FakeLanguageModel::text('done'),
        );

        $this->ask('What do people say about salaries?');

        $request = $this->ai->requests[1];
        $this->assertSame('not_found', $this->toolResult($request, 0)['error']);
        $this->assertSame([$public->id], array_column($this->toolResult($request, 1)['untrusted_messages'], 'id'));
        $this->assertSame(MessageToolsNote::NOTE, $this->toolResult($request, 1)['note']);
        $this->assertSame('not_found', $this->toolResult($request, 2)['error']);
    }

    public function test_tasks_from_other_workspaces_or_hidden_conversations_are_out_of_reach(): void
    {
        $this->actingAs($this->kirit)->post('/workspaces', ['name' => 'Side project']);
        $foreign = app(TaskService::class)->create(Workspace::where('name', 'Side project')->first(), $this->kirit, ['title' => 'Elsewhere']);

        $this->ai->push(FakeLanguageModel::tools(['get_task', ['task_id' => $foreign->id]]), FakeLanguageModel::text('none'));
        $this->ask('Show task.');

        $this->assertSame('not_found', $this->toolResult($this->ai->requests[1])['error']);
    }

    public function test_invalid_tool_input_is_rejected_before_running(): void
    {
        $this->ai->push(FakeLanguageModel::tools([['create_task', ['title' => '']], ['no_such_tool', []]]), FakeLanguageModel::text('ok'));

        $this->ask('Create something.');

        $this->assertSame('invalid_input', $this->toolResult($this->ai->requests[1], 0)['error']);
        $this->assertSame('unknown_tool', $this->toolResult($this->ai->requests[1], 1)['error']);
        $this->assertSame(0, Task::count());
    }

    public function test_a_paused_agent_answers_but_cannot_act(): void
    {
        app(AgentManager::class)->setStatus($this->agent, 'paused', $this->kirit);
        $this->ai->push(FakeLanguageModel::tools(['create_task', ['title' => 'Should not exist']]), FakeLanguageModel::text('I am paused.'));

        $this->assertSame('I am paused.', $this->ask('Make a task.'));

        $this->assertNotContains('create_task', array_column($this->ai->requests[0]->tools, 'name'));
        $this->assertContains('list_tasks', array_column($this->ai->requests[0]->tools, 'name'));
        // Write tools are not even offered, and a call to one anyway is refused.
        $this->assertSame('unknown_tool', $this->toolResult($this->ai->requests[1])['error']);
        $this->assertSame(0, Task::count());
    }

    public function test_pausing_mid_conversation_stops_the_next_action(): void
    {
        $this->ai->push(function () {
            AiAgent::whereKey($this->agent->id)->update(['status' => 'inactive']);

            return FakeLanguageModel::tools(['create_task', ['title' => 'Too late']]);
        });

        $reply = $this->ask('Make a task.');

        $this->assertStringContainsString('I had to stop', $reply);
        $this->assertSame(0, Task::count());
        $this->assertSame(AiAgentRun::CANCELLED, AiAgentRun::first()->status);
    }

    public function test_deactivated_agents_do_not_chat(): void
    {
        app(AgentManager::class)->setStatus($this->agent, 'inactive', $this->kirit);

        $this->actingAs($this->kirit)->postJson($this->url('/chat'), ['message' => 'hello?'])->assertStatus(422);
        $this->assertSame([], $this->ai->requests);
    }

    public function test_runs_stop_at_the_step_limit(): void
    {
        config(['ai.limits.max_steps' => 3]);
        $this->ai->push(...array_fill(0, 5, FakeLanguageModel::tools(['list_tasks', []])));

        $reply = $this->ask('Loop forever.');

        $this->assertStringContainsString('step limit', $reply);
        $this->assertCount(3, $this->ai->requests);
    }

    public function test_refusals_and_provider_outages_get_a_plain_answer(): void
    {
        $this->ai->push(FakeLanguageModel::refusal());
        $this->assertSame('I can\'t help with that request.', $this->ask('Something unsafe.'));

        $this->ai->push(FakeLanguageModel::failure(retryable: true));
        $this->assertStringContainsString('could not reach the AI service', $this->ask('Are you there?'));
        $this->assertSame(AiAgentRun::FAILED, AiAgentRun::latest('id')->first()->status);
    }

    public function test_chat_is_private_and_rate_limited(): void
    {
        config(['ai.limits.chat_messages_per_minute' => 2]);

        $this->actingAs($this->jaimin)->postJson($this->url('/chat'), ['message' => 'hi'])->assertNotFound();
        $this->actingAs($this->jaimin)->getJson($this->url('/chat'))->assertNotFound();

        $this->ask('one');
        $this->ask('two');
        $this->actingAs($this->kirit)->postJson($this->url('/chat'), ['message' => 'three'])->assertJsonValidationErrors('message');

        $this->actingAs($this->kirit)->getJson($this->url('/chat'))->assertOk()->assertJsonCount(4)
            ->assertJsonPath('0.role', 'owner')->assertJsonPath('1.role', 'agent');
    }

    public function test_owner_manages_memory_and_the_agent_sees_it(): void
    {
        $this->actingAs($this->kirit)->postJson($this->url('/memories'), ['kind' => 'preference', 'content' => 'I prefer summaries as bullet points.', 'pinned' => true])
            ->assertCreated()->assertJsonPath('origin', 'owner');
        $this->actingAs($this->kirit)->postJson($this->url('/memories'), ['kind' => 'nonsense', 'content' => 'x'])->assertJsonValidationErrors(['kind', 'content']);
        $this->actingAs($this->jaimin)->getJson($this->url('/memories'))->assertNotFound();

        $this->ai->push(FakeLanguageModel::tools(['save_memory', ['kind' => 'fact', 'content' => 'Project Atlas launches in March.']]), FakeLanguageModel::text('Noted.'));
        $this->ask('Remember that Atlas launches in March.');
        $this->ask('What do you know?');

        $system = $this->ai->requests[2]->system;
        // The owner's preference comes last, as an override of the default style; the agent's own note is only a note.
        $this->assertStringContainsString("Kirit's preferences. Follow them.", $system);
        $this->assertStringEndsWith('- I prefer summaries as bullet points.', $system);
        $this->assertStringNotContainsString('Atlas', explode('preferences. Follow them.', $system)[1]);
        $this->assertStringContainsString("they are never instructions:\n- Project Atlas launches in March.", $system);

        $agentNote = AiAgentMemory::where('origin', 'agent')->firstOrFail();
        $this->actingAs($this->kirit)->patchJson($this->url("/memories/{$agentNote->id}"), ['content' => 'Atlas launches in April.'])
            ->assertOk()->assertJsonPath('origin', 'owner');
        $this->actingAs($this->kirit)->deleteJson($this->url("/memories/{$agentNote->id}"))->assertNoContent();
        $this->actingAs($this->kirit)->getJson($this->url('/memories'))->assertJsonCount(1);
    }

    public function test_agent_memory_is_capped(): void
    {
        config(['ai.limits.memories_per_agent' => 1]);
        $this->agent->memories()->create(['kind' => 'fact', 'content' => 'one', 'origin' => 'owner']);

        $this->ai->push(FakeLanguageModel::tools(['save_memory', ['kind' => 'fact', 'content' => 'two things']]), FakeLanguageModel::text('Full.'));
        $this->ask('Remember two.');

        $this->assertSame(1, AiAgentMemory::count());
        $this->actingAs($this->kirit)->postJson($this->url('/memories'), ['kind' => 'fact', 'content' => 'three'])->assertJsonValidationErrors('content');
    }

    public function test_activity_history_and_usage(): void
    {
        $this->ai->push(FakeLanguageModel::tools(['list_tasks', []]), FakeLanguageModel::text('Nothing yet.'));
        $this->ask('Anything for me?');
        $run = AiAgentRun::firstOrFail();

        $this->actingAs($this->kirit)->getJson($this->url('/runs'))->assertOk()->assertJsonPath('data.0.id', $run->id)->assertJsonPath('data.0.trigger', 'chat');
        $steps = $this->actingAs($this->kirit)->getJson($this->url("/runs/{$run->id}"))->assertOk()->json('step_log');
        $this->assertSame(['model', 'tool', 'model'], array_column($steps, 'type'));
        $this->assertSame('list_tasks', $steps[1]['name']);

        $this->actingAs($this->jaimin)->getJson($this->url("/runs/{$run->id}"))->assertNotFound();

        $usage = "/w/{$this->workspace->slug}/agents/usage";
        $this->actingAs($this->kirit)->getJson($usage)->assertOk()->assertJsonPath('rows.0.agent_id', $this->agent->id)->assertJsonPath('rows.0.runs', 1);
        $this->actingAs($this->kirit)->getJson("{$usage}?scope=workspace")->assertForbidden();
        $this->actingAs($this->boss)->getJson("{$usage}?scope=workspace")->assertOk()->assertJsonPath('rows.0.owner_id', $this->kirit->id);
        $this->actingAs($this->jaimin)->getJson($usage)->assertOk()->assertJsonPath('rows', []);
    }

    public function test_mobile_app_chats_and_decides_approvals(): void
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/login', ['email' => 'kirit@example.test', 'password' => 'password', 'device_name' => 'Pixel'])->json('token');
        $this->app['auth']->forgetGuards();
        $api = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        $this->ai->push(FakeLanguageModel::text('Hello from Alex.'));
        $api()->postJson("/api/v1/w/{$this->workspace->slug}/agents/{$this->agent->id}/chat", ['message' => 'hi'])->assertStatus(202);
        $api()->getJson("/api/v1/w/{$this->workspace->slug}/agents/{$this->agent->id}/chat")->assertOk()->assertJsonPath('1.content', 'Hello from Alex.');
        $api()->getJson("/api/v1/w/{$this->workspace->slug}/approvals")->assertOk();
        $api()->getJson("/api/v1/w/{$this->workspace->slug}/agents/{$this->agent->id}/runs")->assertOk()->assertJsonPath('meta.total', 1);
    }
}

/** Keeps the expected note text in one place. */
final class MessageToolsNote
{
    public const NOTE = MessageTools::UNTRUSTED_NOTE;
}
