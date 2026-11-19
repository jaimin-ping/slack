<?php

namespace Tests\Feature\AI;

use App\Ai\Providers\FakeLanguageModel;
use App\Models\AiAgent;
use App\Models\AiAgentApproval;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AI\Concerns\BuildsAgents;
use Tests\TestCase;

/**
 * The mobile app's view of tasks and agents: the same features and rules as the web app, reached only
 * through /api/v1 with a personal access token, never a browser session.
 */
class MobileApiTest extends TestCase
{
    use BuildsAgents;
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkspace();
    }

    /** Sends one request as the user's phone: their token only, with no session left over from setup. */
    private function phone(User $user, string $method, string $path, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->tokens[$user->id] ??= $user->createToken('Phone')->plainTextToken;

        return $this->withToken($this->tokens[$user->id])->json($method, "/api/v1/w/{$this->workspace->slug}{$path}", $data);
    }

    private function agentId(User $owner, array $data = []): int
    {
        return $this->phone($owner, 'POST', '/agents', ['name' => 'Pocket', 'source_channel_ids' => [$this->general->id], ...$data])
            ->assertCreated()->json('id');
    }

    public function test_every_task_and_agent_endpoint_needs_a_token(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $base = "/api/v1/w/{$this->workspace->slug}";

        foreach (['/tasks', '/tasks/counts', '/agents', '/agents/options', '/agents/usage', '/approvals'] as $path) {
            $this->getJson($base.$path)->assertUnauthorized();
        }

        $this->postJson("{$base}/tasks", ['title' => 'Sneaky'])->assertUnauthorized();
        $this->assertSame(0, Task::count());
    }

    public function test_the_task_workflow_works_from_a_phone(): void
    {
        $id = $this->phone($this->kirit, 'POST', '/tasks', [
            'title' => 'Ship the mobile build', 'priority' => 'high', 'assignee_ids' => [$this->jaimin->id], 'due_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('id');

        $this->phone($this->jaimin, 'GET', '/tasks?scope=mine')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->phone($this->jaimin, 'GET', '/tasks/counts')->assertOk();
        $this->phone($this->jaimin, 'GET', "/tasks/{$id}")->assertOk()->assertJsonPath('title', 'Ship the mobile build')->assertJsonPath('can.work', true);
        $this->phone($this->jaimin, 'POST', "/tasks/{$id}/status", ['status' => 'in_progress'])->assertOk();
        $this->phone($this->jaimin, 'POST', "/tasks/{$id}/comments", ['body' => 'Started on it'])->assertSuccessful();
        $this->phone($this->kirit, 'PATCH', "/tasks/{$id}", ['title' => 'Ship the mobile build v2'])->assertOk();
        $this->phone($this->kirit, 'POST', "/tasks/{$id}/status", ['status' => 'completed'])->assertOk();

        $task = Task::findOrFail($id);
        $this->assertSame(Task::COMPLETED, $task->status);
        $this->assertSame('Ship the mobile build v2', $task->title);

        // Someone outside the task cannot see or change it from their phone either.
        $this->phone($this->boss, 'GET', "/tasks/{$id}")->assertNotFound();
        $this->phone($this->boss, 'POST', "/tasks/{$id}/status", ['status' => 'pending'])->assertNotFound();
    }

    public function test_an_agent_is_managed_and_chatted_with_from_a_phone(): void
    {
        $id = $this->agentId($this->kirit);

        $this->phone($this->kirit, 'GET', '/agents')->assertOk()->assertJsonPath('0.id', $id)->assertJsonPath('0.display_name', 'Pocket (AI Assistant)');
        $this->phone($this->kirit, 'GET', '/agents/options')->assertOk();
        $this->phone($this->kirit, 'PATCH', "/agents/{$id}", ['personality' => 'Brief'])->assertOk();
        $this->phone($this->kirit, 'PUT', "/agents/{$id}/sources", ['channel_ids' => [$this->general->id]])->assertOk();
        $this->phone($this->kirit, 'PUT', "/agents/{$id}/permissions", ['mode' => 'auto_tasks'])->assertOk();
        $this->phone($this->kirit, 'POST', "/agents/{$id}/status", ['status' => 'paused'])->assertOk()->assertJsonPath('status', 'paused');
        $this->phone($this->kirit, 'POST', "/agents/{$id}/status", ['status' => 'active'])->assertOk();

        $this->ai->push(FakeLanguageModel::text('You have no open tasks.'));
        $this->phone($this->kirit, 'POST', "/agents/{$id}/chat", ['message' => 'Show me my tasks'])->assertStatus(202);
        $this->phone($this->kirit, 'GET', "/agents/{$id}/chat")->assertOk()->assertJsonPath('1.content', 'You have no open tasks.');

        $memory = $this->phone($this->kirit, 'POST', "/agents/{$id}/memories", ['kind' => 'preference', 'content' => 'Keep it short'])->assertCreated()->json('id');
        $this->phone($this->kirit, 'PATCH', "/agents/{$id}/memories/{$memory}", ['content' => 'Keep it very short'])->assertOk();
        $this->phone($this->kirit, 'GET', "/agents/{$id}/memories")->assertOk()->assertJsonPath('0.content', 'Keep it very short');
        $this->phone($this->kirit, 'DELETE', "/agents/{$id}/memories/{$memory}")->assertNoContent();

        $run = $this->phone($this->kirit, 'GET', "/agents/{$id}/runs")->assertOk()->json('data.0.id');
        $this->phone($this->kirit, 'GET', "/agents/{$id}/runs/{$run}")->assertOk();
        $this->phone($this->kirit, 'GET', '/agents/usage')->assertOk();

        $this->phone($this->kirit, 'DELETE', "/agents/{$id}")->assertNoContent();
        $this->assertNull(AiAgent::find($id));
    }

    public function test_approvals_are_handled_from_a_phone_and_agent_messages_carry_the_ai_label(): void
    {
        $id = $this->agentId($this->kirit, ['mode' => 'controlled', 'permissions' => ['send_direct_message' => 'approve']]);
        $this->ai->push(
            FakeLanguageModel::tools(['send_direct_message', ['user_id' => $this->jaimin->id, 'text' => 'Kirit asked me to say the build is ready.']]),
            FakeLanguageModel::text('It needs your approval.'),
        );
        $this->phone($this->kirit, 'POST', "/agents/{$id}/chat", ['message' => 'Tell Jaimin the build is ready'])->assertStatus(202);

        $approval = $this->phone($this->kirit, 'GET', '/approvals')->assertOk()->assertJsonCount(1)->json('0.id');
        $this->phone($this->jaimin, 'POST', "/approvals/{$approval}/approve")->assertNotFound();
        $this->assertSame(0, Message::whereNotNull('ai_agent_id')->count());

        $this->phone($this->kirit, 'POST', "/approvals/{$approval}/approve")->assertOk()->assertJsonPath('status', 'executed');
        $sent = Message::whereNotNull('ai_agent_id')->firstOrFail();

        // Jaimin's phone shows the message as the agent's, marked as AI, with its owner named.
        $payload = collect($this->phone($this->jaimin, 'GET', "/c/{$sent->channel_id}/messages")->assertOk()->json('messages'))->firstWhere('id', $sent->id);
        $this->assertTrue($payload['is_bot']);
        $this->assertSame('Pocket (AI Assistant)', $payload['user']['name']);
        $this->assertSame($id, $payload['agent']['id']);
        $this->assertSame('Kirit', $payload['agent']['owner_name']);
        $this->assertSame('executed', AiAgentApproval::findOrFail($approval)->status);
    }

    public function test_a_phone_cannot_reach_someone_elses_agent(): void
    {
        $id = $this->agentId($this->kirit);

        foreach ([['GET', ''], ['PATCH', ''], ['GET', '/chat'], ['POST', '/chat'], ['GET', '/memories'], ['GET', '/runs'], ['POST', '/status'], ['DELETE', '']] as [$method, $path]) {
            $this->phone($this->jaimin, $method, "/agents/{$id}{$path}", ['message' => 'hi', 'status' => 'paused', 'name' => 'Mine'])
                // Status answers 403, not 404, because workspace admins may switch off any agent (same as the web app).
                ->assertStatus($path === '/status' ? 403 : 404);
        }

        $this->phone($this->jaimin, 'GET', '/agents')->assertOk()->assertJsonCount(0);
        $this->assertSame('Pocket', AiAgent::findOrFail($id)->name);
    }

    public function test_provider_credentials_never_reach_the_phone(): void
    {
        config(['ai.providers.anthropic.api_key' => 'sk-ant-must-stay-on-the-server']);
        $id = $this->agentId($this->kirit);

        foreach (['/agents', '/agents/options', "/agents/{$id}", '/agents/usage', "/agents/{$id}/runs"] as $path) {
            $body = $this->phone($this->kirit, 'GET', $path)->assertOk()->getContent();
            $this->assertStringNotContainsString('sk-ant', $body);
            $this->assertStringNotContainsStringIgnoringCase('api_key', $body);
        }
    }
}
