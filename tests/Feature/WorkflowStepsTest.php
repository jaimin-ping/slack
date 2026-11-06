<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkflowStepsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Channel $general;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner Person']);
        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Acme']);
        $this->workspace = Workspace::first();
        $this->general = $this->workspace->channels()->first();
    }

    private function url(string $path): string
    {
        return "/w/{$this->workspace->slug}{$path}";
    }

    private function say(string $text, ?Channel $channel = null): array
    {
        return ['type' => 'message', 'channel_id' => ($channel ?? $this->general)->id, 'text' => $text];
    }

    private function workflow(array $steps, string $trigger = 'member_joined'): Workflow
    {
        return Workflow::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Onboarding',
            'trigger' => $trigger,
            'time' => $trigger === 'daily' ? '00:00' : null,
            'steps' => $steps,
        ]);
    }

    private function join(string $name = 'New Person'): User
    {
        $person = User::factory()->create(['name' => $name]);
        app(\App\Services\Invitations::class)->admit($this->workspace, $person);

        return $person;
    }

    private function bodies(?Channel $channel = null): array
    {
        return ($channel ?? $this->general)->messages()->orderBy('id')->pluck('body')->all();
    }

    private function create(array $body)
    {
        return $this->actingAs($this->owner)->postJson($this->url('/workflows'), [
            'name' => 'Flow', 'trigger' => 'member_joined', ...$body,
        ]);
    }

    public function test_steps_run_in_order_and_wait_between(): void
    {
        $this->workflow([
            $this->say('Hello {name}'),
            ['type' => 'wait', 'minutes' => 60],
            $this->say('An hour later, {name}'),
            ['type' => 'wait', 'minutes' => 1440],
            $this->say('A day later'),
        ]);

        $this->join();
        $this->assertSame(['Hello New Person'], $this->bodies());

        Carbon::setTestNow(now()->addMinutes(59));
        $this->artisan('chat:run-due');
        $this->assertCount(1, $this->bodies());

        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('chat:run-due');
        $this->artisan('chat:run-due');
        $this->assertSame(['Hello New Person', 'An hour later, New Person'], $this->bodies());

        Carbon::setTestNow(now()->addDay());
        $this->artisan('chat:run-due');
        $this->assertCount(3, $this->bodies());
        $this->assertSame(0, WorkflowRun::count());
    }

    public function test_two_members_each_get_their_own_run(): void
    {
        $this->workflow([['type' => 'wait', 'minutes' => 5], $this->say('Hi {name}')]);

        $this->join('First');
        $this->join('Second');
        $this->assertSame(2, WorkflowRun::count());

        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('chat:run-due');

        $this->assertSame(['Hi First', 'Hi Second'], $this->bodies());
    }

    public function test_new_member_gets_a_direct_message_only_they_can_see(): void
    {
        $this->workflow([['type' => 'dm', 'text' => 'Welcome {name}, read the handbook']]);

        $person = $this->join();

        $dm = Channel::where('type', Channel::DM)->first();
        $this->assertSame(['Welcome New Person, read the handbook'], $this->bodies($dm));
        $this->assertTrue($dm->hasMember($person));
        $this->assertFalse($dm->hasMember($this->owner));
    }

    public function test_removed_member_is_skipped_and_later_steps_still_run(): void
    {
        $this->workflow([['type' => 'wait', 'minutes' => 5], ['type' => 'dm', 'text' => 'Secret'], $this->say('Done')]);

        $person = $this->join();
        $this->actingAs($this->owner)->deleteJson($this->url("/members/{$person->id}"))->assertNoContent();

        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('chat:run-due');

        $this->assertNull(Channel::where('type', Channel::DM)->first());
        $this->assertSame(['Done'], $this->bodies());
    }

    public function test_pausing_stops_waiting_runs_and_deleting_clears_them(): void
    {
        $workflow = $this->workflow([['type' => 'wait', 'minutes' => 5], $this->say('Later')]);
        $this->join();

        $this->actingAs($this->owner)->postJson($this->url("/workflows/{$workflow->id}/toggle"));
        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('chat:run-due');

        $this->assertSame([], $this->bodies());
        $this->assertSame(0, WorkflowRun::count());

        $this->actingAs($this->owner)->postJson($this->url("/workflows/{$workflow->id}/toggle"));
        $this->join('Another');
        $this->assertSame(1, WorkflowRun::count());

        $this->actingAs($this->owner)->deleteJson($this->url("/workflows/{$workflow->id}"));
        $this->assertSame(0, WorkflowRun::count());
    }

    public function test_archived_or_deleted_channel_is_skipped_and_the_rest_run(): void
    {
        $archived = $this->workspace->channels()->create(['name' => 'old', 'type' => Channel::PUBLIC, 'archived_at' => now()]);

        $this->workflow([
            $this->say('Skipped', $archived),
            ['type' => 'message', 'channel_id' => 99999, 'text' => 'Gone'],
            $this->say('Still here'),
        ]);

        $this->join();

        $this->assertSame(['Still here'], $this->bodies());
        $this->assertSame([], $this->bodies($archived));
    }

    public function test_daily_workflow_with_a_wait_finishes_later(): void
    {
        Carbon::setTestNow('2026-11-05 10:00:00');

        $this->workflow([$this->say('Morning'), ['type' => 'wait', 'minutes' => 30], $this->say('Reminder')], 'daily');

        $this->artisan('chat:run-due');
        $this->assertSame(['Morning'], $this->bodies());

        Carbon::setTestNow('2026-11-05 10:31:00');
        $this->artisan('chat:run-due');
        $this->assertSame(['Morning', 'Reminder'], $this->bodies());
    }

    public function test_steps_are_validated(): void
    {
        $this->create(['steps' => []])->assertJsonValidationErrors('steps');
        $this->create(['steps' => [$this->say('a'), ['type' => 'wait', 'minutes' => 5]]])->assertJsonValidationErrors('steps');
        $this->create(['steps' => [['type' => 'wait', 'minutes' => 0], $this->say('a')]])->assertJsonValidationErrors('steps.0.minutes');
        $this->create(['steps' => [['type' => 'wait', 'minutes' => 50000], $this->say('a')]])->assertJsonValidationErrors('steps.0.minutes');
        $this->create(['steps' => [['type' => 'shell', 'text' => 'x']]])->assertJsonValidationErrors('steps.0.type');
        $this->create(['steps' => [['type' => 'message', 'text' => 'x']]])->assertJsonValidationErrors('steps.0.channel_id');
        $this->create(['steps' => [['type' => 'dm']]])->assertJsonValidationErrors('steps.0.text');
        $this->create(['steps' => array_fill(0, 11, $this->say('a'))])->assertJsonValidationErrors('steps');
        $this->create(['trigger' => 'daily', 'time' => '09:00', 'steps' => [['type' => 'dm', 'text' => 'x']]])->assertJsonValidationErrors('steps');

        $this->assertSame(0, Workflow::count());
    }

    public function test_a_step_cannot_post_to_a_channel_the_creator_cannot_reach(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)->post('/workspaces', ['name' => 'Other']);
        $foreign = Workspace::where('name', 'Other')->first()->channels()->first();
        $private = $this->workspace->channels()->create(['name' => 'secret', 'type' => Channel::PRIVATE]);

        $this->create(['steps' => [$this->say('x', $foreign)]])->assertJsonValidationErrors('steps');
        $this->create(['steps' => [$this->say('x', $private)]])->assertJsonValidationErrors('steps');
    }

    public function test_saved_steps_keep_only_their_own_fields(): void
    {
        $response = $this->create(['steps' => [
            [...$this->say('Hi'), 'minutes' => 9, 'evil' => 'x'],
            ['type' => 'wait', 'minutes' => 5, 'text' => 'ignored'],
            ['type' => 'dm', 'text' => 'Yo', 'channel_id' => 1],
        ]])->assertCreated();

        $this->assertSame([
            ['type' => 'message', 'channel_id' => $this->general->id, 'text' => 'Hi'],
            ['type' => 'wait', 'minutes' => 5],
            ['type' => 'dm', 'text' => 'Yo'],
        ], Workflow::first()->steps);

        $response->assertJsonPath('steps.0.channel_name', 'general');
    }
}
