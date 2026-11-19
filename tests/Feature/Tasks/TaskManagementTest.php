<?php

namespace Tests\Feature\Tasks;

use App\Events\Tasks\TaskChanged;
use App\Jobs\SendPushNotification;
use App\Models\AiAgent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AgentManager;
use App\Services\Invitations;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $kirit;

    private User $jaimin;

    private Channel $general;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner Person']);
        $this->kirit = User::factory()->create(['name' => 'Kirit', 'email' => 'kirit@example.test']);
        $this->jaimin = User::factory()->create(['name' => 'Jaimin']);

        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Acme']);
        $this->workspace = Workspace::first();
        app(Invitations::class)->admit($this->workspace, $this->kirit);
        app(Invitations::class)->admit($this->workspace, $this->jaimin);
        $this->general = $this->workspace->channels()->where('name', 'general')->first();
    }

    private function url(string $path = '', ?Workspace $workspace = null): string
    {
        return '/w/'.($workspace ?? $this->workspace)->slug.'/tasks'.$path;
    }

    private function task(User $creator, array $data = []): Task
    {
        $id = $this->actingAs($creator)->postJson($this->url(), ['title' => 'Inventory dashboard', ...$data])->assertCreated()->json('id');

        return Task::findOrFail($id);
    }

    private function message(Channel $channel, User $author, string $body): Message
    {
        return $channel->messages()->create(['user_id' => $author->id, 'body' => $body]);
    }

    private function agent(User $owner, string $name = 'Alex'): AiAgent
    {
        return app(AgentManager::class)->create($this->workspace, $owner, ['name' => $name]);
    }

    private function privateChannel(array $members): Channel
    {
        $channel = $this->workspace->channels()->create(['name' => 'leadership', 'type' => Channel::PRIVATE, 'created_by' => $members[0]->id]);
        $channel->members()->attach(collect($members)->pluck('id'));

        return $channel;
    }

    public function test_member_creates_and_assigns_a_task(): void
    {
        Bus::fake([SendPushNotification::class]);

        $response = $this->actingAs($this->owner)->postJson($this->url(), [
            'title' => 'Complete inventory dashboard',
            'description' => 'Before the Friday demo',
            'priority' => 'high',
            'due_at' => now()->addDays(3)->toIso8601String(),
            'assignee_ids' => [$this->kirit->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('priority', 'high')
            ->assertJsonPath('creator.id', $this->owner->id)
            ->assertJsonPath('assignees.0.id', $this->kirit->id)
            ->assertJsonPath('agent', null)
            ->assertJsonPath('source', null)
            ->assertJsonPath('can.manage', true)
            ->assertJsonPath('history.0.type', 'created');

        Bus::assertDispatchedAfterResponse(SendPushNotification::class, fn ($job) => $job->userIds === [$this->kirit->id]
            && str_contains($job->payload['title'], 'Owner Person'));
    }

    public function test_task_input_is_validated(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->owner)->postJson($this->url(), [])->assertJsonValidationErrors('title');
        $this->actingAs($this->owner)->postJson($this->url(), ['title' => 'x', 'priority' => 'whenever'])->assertJsonValidationErrors('priority');
        $this->actingAs($this->owner)->postJson($this->url(), ['title' => 'x', 'assignee_ids' => [$outsider->id]])->assertJsonValidationErrors('assignee_ids');
        $this->actingAs($outsider)->postJson($this->url(), ['title' => 'x'])->assertForbidden();

        $this->assertSame(0, Task::count());
    }

    public function test_task_from_a_message_keeps_its_source_and_limits_assignees(): void
    {
        $message = $this->message($this->general, $this->owner, '@Kirit Please complete the inventory dashboard before Friday.');

        $task = $this->task($this->owner, ['source_message_id' => $message->id, 'assignee_ids' => [$this->kirit->id]]);

        $this->assertSame($this->general->id, $task->source_channel_id);
        $this->assertDatabaseHas('task_message_links', ['task_id' => $task->id, 'message_id' => $message->id, 'relation' => 'source']);

        $this->actingAs($this->kirit)->getJson($this->url("/{$task->id}"))->assertOk()
            ->assertJsonPath('source.available', true)
            ->assertJsonPath('source.channel_label', '#general')
            ->assertJsonPath('source.message_id', $message->id)
            ->assertJsonPath('source.quote', $message->body);

        // A private conversation can only produce tasks for its own members.
        $private = $this->privateChannel([$this->owner, $this->kirit]);
        $secret = $this->message($private, $this->owner, 'Plan the reorg');

        $this->actingAs($this->owner)->postJson($this->url(), ['title' => 'Plan', 'source_message_id' => $secret->id, 'assignee_ids' => [$this->jaimin->id]])
            ->assertJsonValidationErrors('assignee_ids');
        $this->actingAs($this->jaimin)->postJson($this->url(), ['title' => 'Snoop', 'source_message_id' => $secret->id])
            ->assertJsonValidationErrors('source_message_id');
    }

    public function test_source_details_are_hidden_from_people_who_left_the_conversation(): void
    {
        $private = $this->privateChannel([$this->owner, $this->kirit]);
        $task = $this->task($this->owner, ['source_message_id' => $this->message($private, $this->owner, 'Secret plan')->id, 'assignee_ids' => [$this->kirit->id]]);

        $private->members()->detach($this->kirit->id);

        // Still assigned, so the task is visible, but the private conversation behind it is not.
        $this->actingAs($this->kirit)->getJson($this->url("/{$task->id}"))->assertOk()
            ->assertJsonPath('source', ['available' => false, 'state' => null])
            ->assertJsonMissingPath('source.quote');
    }

    public function test_tasks_are_visible_only_to_the_people_involved(): void
    {
        $task = $this->task($this->owner, ['assignee_ids' => [$this->kirit->id]]);
        $outsider = User::factory()->create();

        $this->actingAs($this->kirit)->getJson($this->url("/{$task->id}"))->assertOk();
        $this->actingAs($this->jaimin)->getJson($this->url("/{$task->id}"))->assertNotFound();
        $this->actingAs($outsider)->getJson($this->url("/{$task->id}"))->assertNotFound();
        $this->actingAs($this->jaimin)->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');

        // Members of the source conversation see it as a team task.
        $team = $this->task($this->owner, ['title' => 'Release notes', 'source_message_id' => $this->message($this->general, $this->owner, 'Write release notes')->id]);
        $this->actingAs($this->jaimin)->getJson($this->url('?scope=team'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $team->id);
        $this->actingAs($this->jaimin)->getJson($this->url("/{$team->id}"))->assertOk()->assertJsonPath('can.manage', false)->assertJsonPath('can.work', false);
    }

    public function test_a_task_cannot_be_reached_through_another_workspace(): void
    {
        $task = $this->task($this->owner);

        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Side project']);
        $other = Workspace::where('name', 'Side project')->first();

        $this->actingAs($this->owner)->getJson($this->url("/{$task->id}", $other))->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/status", $other), ['status' => 'completed'])->assertNotFound();
    }

    public function test_assignees_can_move_a_task_but_not_manage_it(): void
    {
        $task = $this->task($this->owner, ['assignee_ids' => [$this->kirit->id]]);

        $this->actingAs($this->kirit)->postJson($this->url("/{$task->id}/status"), ['status' => 'in_progress'])->assertOk()->assertJsonPath('status', 'in_progress');
        $this->actingAs($this->kirit)->postJson($this->url("/{$task->id}/pause"))->assertOk()->assertJsonPath('status', 'paused');
        $this->actingAs($this->kirit)->postJson($this->url("/{$task->id}/resume"))->assertOk()->assertJsonPath('status', 'in_progress');

        $this->actingAs($this->kirit)->patchJson($this->url("/{$task->id}"), ['title' => 'Mine now'])->assertForbidden();
        $this->actingAs($this->kirit)->putJson($this->url("/{$task->id}/assignees"), ['user_ids' => [$this->jaimin->id]])->assertForbidden();
        $this->actingAs($this->kirit)->deleteJson($this->url("/{$task->id}"))->assertForbidden();
        $this->actingAs($this->kirit)->postJson($this->url("/{$task->id}/archive"))->assertForbidden();

        $this->assertSame('Inventory dashboard', $task->fresh()->title);
    }

    public function test_creator_edits_reassigns_and_deletes(): void
    {
        Event::fake([TaskChanged::class]);
        $task = $this->task($this->owner, ['assignee_ids' => [$this->kirit->id]]);

        $this->actingAs($this->owner)->patchJson($this->url("/{$task->id}"), ['title' => 'Inventory dashboard v2', 'priority' => 'urgent', 'due_at' => null])
            ->assertOk()
            ->assertJsonPath('title', 'Inventory dashboard v2')
            ->assertJsonPath('history.1.type', 'updated')
            ->assertJsonPath('history.1.data.from.title', 'Inventory dashboard');

        $this->actingAs($this->owner)->putJson($this->url("/{$task->id}/assignees"), ['user_ids' => [$this->jaimin->id]])
            ->assertOk()
            ->assertJsonCount(1, 'assignees')
            ->assertJsonPath('assignees.0.id', $this->jaimin->id)
            ->assertJsonPath('history.2.data.removed', [$this->kirit->id]);

        // Kirit is told the task left his panel, even though he no longer sees it.
        Event::assertDispatched(TaskChanged::class, fn ($e) => $e->action === 'assigned' && in_array($this->kirit->id, $e->userIds, true));
        $this->actingAs($this->kirit)->getJson($this->url("/{$task->id}"))->assertNotFound();

        $this->actingAs($this->owner)->deleteJson($this->url("/{$task->id}"))->assertNoContent();
        $this->assertNull(Task::find($task->id));
        $this->assertDatabaseMissing('task_events', ['task_id' => $task->id]);
        Event::assertDispatched(TaskChanged::class, fn ($e) => $e->action === 'deleted' && in_array($this->jaimin->id, $e->userIds, true));
    }

    public function test_status_transitions_follow_the_rules(): void
    {
        $task = $this->task($this->owner);
        $status = fn (string $to) => $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/status"), ['status' => $to]);

        $status('blocked')->assertOk();
        $status('completed')->assertOk()->assertJsonPath('status', 'completed');
        $this->assertNotNull($task->fresh()->completed_at);

        $status('blocked')->assertJsonValidationErrors('status');
        $status('suggested')->assertJsonValidationErrors('status');
        $status('paused')->assertJsonValidationErrors('status');

        // Reopening clears the completion date.
        $status('pending')->assertOk();
        $this->assertNull($task->fresh()->completed_at);

        $status('cancelled')->assertOk();
        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/pause"))->assertJsonValidationErrors('status');
    }

    public function test_pause_remembers_the_previous_status(): void
    {
        $task = $this->task($this->owner);
        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/status"), ['status' => 'blocked']);

        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/pause"))->assertOk()->assertJsonPath('paused_from', 'blocked');
        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/status"), ['status' => 'completed'])->assertJsonValidationErrors('status');
        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/resume"))->assertOk()->assertJsonPath('status', 'blocked')->assertJsonPath('paused_from', null);
        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/resume"))->assertJsonValidationErrors('status');
    }

    public function test_suggested_tasks_wait_for_the_owners_approval(): void
    {
        Bus::fake([SendPushNotification::class]);
        $agent = $this->agent($this->owner);
        $message = $this->message($this->general, $this->owner, '@Kirit Please complete the inventory dashboard.');

        $task = app(TaskService::class)->create($this->workspace, $this->owner, [
            'title' => 'Complete inventory dashboard',
            'status' => 'suggested',
            'confidence' => 0.92,
            'source_message_id' => $message->id,
            'assignee_ids' => [$this->kirit->id],
        ], $agent);

        // Nobody else sees a suggestion, and nobody is notified yet.
        $this->actingAs($this->kirit)->getJson($this->url("/{$task->id}"))->assertNotFound();
        $this->actingAs($this->kirit)->getJson($this->url('?scope=team'))->assertJsonCount(0, 'data');
        Bus::assertNotDispatchedAfterResponse(SendPushNotification::class);

        $this->actingAs($this->owner)->getJson($this->url('?scope=pending_approval'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agent.display_name', 'Alex (AI Assistant)')
            ->assertJsonPath('data.0.confidence', 0.92);

        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/status"), ['status' => 'in_progress'])->assertJsonValidationErrors('status');
        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/approve"))->assertOk()->assertJsonPath('status', 'pending');

        $this->actingAs($this->kirit)->getJson($this->url("/{$task->id}"))->assertOk();
        Bus::assertDispatchedAfterResponse(SendPushNotification::class, fn ($job) => str_contains($job->payload['title'], 'Alex (AI Assistant)'));

        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/approve"))->assertJsonValidationErrors('status');
    }

    public function test_rejecting_a_suggestion_cancels_it(): void
    {
        $task = app(TaskService::class)->create($this->workspace, $this->owner, ['title' => 'Maybe', 'status' => 'suggested'], $this->agent($this->owner));

        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/reject"), ['reason' => 'Not a task'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('history.1.data.reason', 'Not a task');
    }

    public function test_the_same_detection_never_creates_two_tasks(): void
    {
        $service = app(TaskService::class);
        $message = $this->message($this->general, $this->owner, 'Coordinate with Jaimin for API integration.');
        $data = ['title' => 'Coordinate API integration', 'source_message_id' => $message->id];

        $first = $service->create($this->workspace, $this->owner, $data, $this->agent($this->owner));
        $retry = $service->create($this->workspace, $this->owner, [...$data, 'title' => '  coordinate API integration! '], $first->agent);
        $other = $service->create($this->workspace, $this->kirit, $data, $this->agent($this->kirit, 'Kiri'));

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($first->id, $other->id);
        $this->assertSame(1, Task::count());

        // A person may still log the same work twice on purpose.
        $this->task($this->owner, $data);
        $this->task($this->owner, $data);
        $this->assertSame(3, Task::count());
    }

    public function test_dependencies_reject_loops_and_hidden_tasks(): void
    {
        $api = $this->task($this->owner, ['title' => 'API integration']);
        $dashboard = $this->task($this->owner, ['title' => 'Dashboard', 'depends_on_ids' => [$api->id]]);

        $this->actingAs($this->owner)->getJson($this->url("/{$dashboard->id}"))->assertJsonPath('dependencies.0.title', 'API integration');

        $this->actingAs($this->owner)->patchJson($this->url("/{$api->id}"), ['depends_on_ids' => [$dashboard->id]])->assertJsonValidationErrors('depends_on_ids');

        $hidden = $this->task($this->jaimin, ['title' => 'Jaimin private']);
        $this->actingAs($this->owner)->patchJson($this->url("/{$dashboard->id}"), ['depends_on_ids' => [$hidden->id]])->assertJsonValidationErrors('depends_on_ids');
    }

    public function test_comments_and_history(): void
    {
        $task = $this->task($this->owner, ['assignee_ids' => [$this->kirit->id]]);

        $this->actingAs($this->kirit)->postJson($this->url("/{$task->id}/comments"), ['body' => 'Waiting for the API'])
            ->assertCreated()
            ->assertJsonPath('type', 'comment')
            ->assertJsonPath('actor.id', $this->kirit->id);

        $this->actingAs($this->jaimin)->postJson($this->url("/{$task->id}/comments"), ['body' => 'Sneaky'])->assertNotFound();
        $this->actingAs($this->kirit)->postJson($this->url("/{$task->id}/comments"), ['body' => ''])->assertJsonValidationErrors('body');

        $history = $this->actingAs($this->owner)->getJson($this->url("/{$task->id}"))->json('history');
        $this->assertSame(['created', 'comment'], array_column($history, 'type'));
        $this->assertSame('Waiting for the API', $history[1]['body']);
    }

    public function test_archived_tasks_leave_the_list_until_asked_for(): void
    {
        $task = $this->task($this->owner);

        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/archive"))->assertOk()->assertJsonPath('archived', true);
        $this->actingAs($this->owner)->getJson($this->url())->assertJsonCount(0, 'data');
        $this->actingAs($this->owner)->getJson($this->url('?archived=1'))->assertJsonCount(1, 'data');

        $this->actingAs($this->owner)->postJson($this->url("/{$task->id}/unarchive"))->assertOk()->assertJsonPath('archived', false);
    }

    public function test_panel_scopes_filters_and_counts(): void
    {
        $mine = $this->task($this->kirit, ['title' => 'Personal note']);
        $toMe = $this->task($this->owner, ['title' => 'Inventory dashboard', 'assignee_ids' => [$this->kirit->id], 'due_at' => now()->subDay()->toIso8601String()]);
        $byMe = $this->task($this->kirit, ['title' => 'API integration', 'assignee_ids' => [$this->jaimin->id], 'priority' => 'urgent']);
        $done = $this->task($this->kirit, ['title' => 'Old work']);
        $this->actingAs($this->kirit)->postJson($this->url("/{$done->id}/status"), ['status' => 'completed']);

        $ids = fn (string $query) => collect($this->actingAs($this->kirit)->getJson($this->url($query))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$mine->id, $toMe->id], $ids('?scope=mine'));
        $this->assertSame([$toMe->id], $ids('?scope=assigned_to_me'));
        $this->assertSame([$byMe->id], $ids('?scope=assigned_by_me'));
        $this->assertSame([$done->id], $ids('?scope=completed'));
        $this->assertSame([$toMe->id], $ids('?scope=mine&due=overdue'));
        $this->assertSame([$toMe->id], $ids('?q=INVENTORY'));
        $this->assertSame([$byMe->id], $ids('?priority[]=urgent'));
        // A literal % in a search is text, not a wildcard.
        $this->assertSame([], $ids('?q=%25'));

        $this->actingAs($this->kirit)->getJson($this->url('/counts'))->assertOk()
            ->assertJsonPath('mine', 2)
            ->assertJsonPath('assigned_to_me', 1)
            ->assertJsonPath('assigned_by_me', 1)
            ->assertJsonPath('completed', 1)
            ->assertJsonPath('pending_approval', 0)
            ->assertJsonPath('overdue', 1);

        $this->actingAs($this->kirit)->getJson($this->url('?scope=nonsense'))->assertJsonValidationErrors('scope');
    }

    public function test_edited_and_removed_source_messages(): void
    {
        $agent = $this->agent($this->owner);
        $service = app(TaskService::class);
        $message = $this->message($this->general, $this->owner, 'Kirit, finish the dashboard. Also draft the summary.');

        $accepted = $service->create($this->workspace, $this->owner, ['title' => 'Finish the dashboard', 'source_message_id' => $message->id], $agent);
        $suggested = $service->create($this->workspace, $this->owner, ['title' => 'Draft the summary', 'status' => 'suggested', 'source_message_id' => $message->id], $agent);

        $service->sourceEdited($message);
        $this->assertSame('edited', $accepted->fresh()->source_state);

        $service->sourceRemoved($message);
        $message->delete();

        $accepted->refresh();
        $this->assertSame('removed', $accepted->source_state);
        $this->assertSame('pending', $accepted->status);
        $this->assertNull($accepted->source_ref);
        $this->assertNull($accepted->source_message_id);
        $this->assertSame('cancelled', $suggested->fresh()->status);

        $this->actingAs($this->owner)->getJson($this->url("/{$accepted->id}"))->assertOk()->assertJsonPath('source.state', 'removed')->assertJsonPath('source.quote', null);
    }

    public function test_tasks_stay_manageable_while_their_agent_is_paused(): void
    {
        $agent = $this->agent($this->owner);
        $task = app(TaskService::class)->create($this->workspace, $this->owner, ['title' => 'Detected', 'assignee_ids' => [$this->kirit->id]], $agent);

        app(AgentManager::class)->setStatus($agent, 'paused', $this->owner);

        $this->actingAs($this->kirit)->postJson($this->url("/{$task->id}/status"), ['status' => 'completed'])->assertOk();
        $this->actingAs($this->owner)->patchJson($this->url("/{$task->id}"), ['title' => 'Detected and done'])->assertOk()->assertJsonPath('agent.name', 'Alex');
    }

    public function test_live_updates_reach_only_the_people_involved(): void
    {
        Event::fake([TaskChanged::class]);

        $task = $this->task($this->owner, ['assignee_ids' => [$this->kirit->id]]);

        Event::assertDispatched(TaskChanged::class, function (TaskChanged $event) use ($task) {
            $channels = collect($event->broadcastOn())->map->name->sort()->values()->all();

            return $event->taskId === $task->id
                && $event->action === 'created'
                && $channels === collect([$this->owner->id, $this->kirit->id])->sort()->map(fn ($id) => "private-App.Models.User.{$id}")->values()->all()
                && array_keys($event->broadcastWith()) === ['workspace_id', 'task_id', 'action'];
        });
    }

    public function test_mobile_app_works_with_tasks(): void
    {
        $task = $this->task($this->owner, ['assignee_ids' => [$this->kirit->id]]);

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/login', ['email' => 'kirit@example.test', 'password' => 'password', 'device_name' => 'Pixel'])->json('token');
        $this->app['auth']->forgetGuards();

        $api = fn () => $this->withHeader('Authorization', "Bearer {$token}");
        $base = "/api/v1/w/{$this->workspace->slug}/tasks";

        $api()->getJson("{$base}?scope=assigned_to_me")->assertOk()->assertJsonPath('data.0.id', $task->id);
        $api()->postJson("{$base}/{$task->id}/status", ['status' => 'in_progress'])->assertOk()->assertJsonPath('status', 'in_progress');
        $api()->postJson($base, ['title' => 'From my phone'])->assertCreated()->assertJsonPath('creator.id', $this->kirit->id);
        $api()->getJson("{$base}/counts")->assertOk()->assertJsonPath('mine', 2);
    }
}
