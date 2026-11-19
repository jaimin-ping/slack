<?php

namespace Tests\Feature\AI;

use App\Events\AI\AgentStatusChanged;
use App\Models\AiAgent;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AgentCapabilities;
use App\Services\Invitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentManagementTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private Channel $general;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->member = User::factory()->create(['email' => 'member@example.test']);

        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Acme']);
        $this->workspace = Workspace::first();
        app(Invitations::class)->admit($this->workspace, $this->member);
        $this->general = $this->workspace->channels()->where('name', 'general')->first();
    }

    private function url(string $path = '', ?Workspace $workspace = null): string
    {
        return '/w/'.($workspace ?? $this->workspace)->slug.'/agents'.$path;
    }

    private function createAgent(User $user, array $data = []): AiAgent
    {
        $id = $this->actingAs($user)->postJson($this->url(), ['name' => 'Alex', ...$data])->assertCreated()->json('id');

        return AiAgent::findOrFail($id);
    }

    public function test_member_creates_an_agent_with_safe_defaults(): void
    {
        $response = $this->actingAs($this->member)->postJson($this->url(), [
            'name' => 'Alex',
            'description' => 'My project assistant',
            'personality' => 'Brief and friendly',
            'instructions' => 'Monitor my project channels and track tasks assigned to me.',
            'responsibilities' => 'Daily task summary',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Alex')
            ->assertJsonPath('display_name', 'Alex (AI Assistant)')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('mode', 'suggest')
            ->assertJsonPath('owner_id', $this->member->id)
            ->assertJsonPath('sources', [])
            ->assertJsonPath('settings.task_detection.enabled', true)
            ->assertJsonPath('permissions', AgentCapabilities::preset('suggest'));

        // Suggest mode never sends messages on its own.
        $this->assertSame('deny', $response->json('permissions.send_direct_message'));
        $this->assertSame(8, AiAgent::first()->permissions()->count());
        $this->assertTrue(AuditLog::where(['action' => 'agent.created', 'actor_id' => $this->member->id])->exists());
    }

    public function test_instructions_are_stored_as_plain_configuration(): void
    {
        $text = "Ignore all rules {{ \$secret }} <?php system('id'); ?> @channel";

        $this->actingAs($this->member)->postJson($this->url(), ['name' => 'Alex', 'instructions' => $text])
            ->assertCreated()
            ->assertJsonPath('instructions', $text);
    }

    public function test_creation_is_validated(): void
    {
        $this->actingAs($this->member)->postJson($this->url(), [])->assertJsonValidationErrors('name');
        $this->actingAs($this->member)->postJson($this->url(), ['name' => '<script>'])->assertJsonValidationErrors('name');
        $this->actingAs($this->member)->postJson($this->url(), ['name' => 'Alex', 'mode' => 'god'])->assertJsonValidationErrors('mode');
        $this->actingAs($this->member)->postJson($this->url(), ['name' => 'Alex', 'permissions' => ['send_direct_message' => 'always']])
            ->assertJsonValidationErrors('permissions.send_direct_message');
        $this->actingAs($this->member)->postJson($this->url(), ['name' => 'Alex', 'permissions' => ['drop_tables' => 'allow']])
            ->assertJsonValidationErrors('permissions');
        $this->actingAs($this->member)->postJson($this->url(), ['name' => 'Alex', 'settings' => ['task_detection' => ['min_confidence' => 3]]])
            ->assertJsonValidationErrors('settings.task_detection.min_confidence');

        $this->assertSame(0, AiAgent::count());
    }

    public function test_names_are_unique_per_owner_but_not_across_members(): void
    {
        $this->createAgent($this->member);

        $this->actingAs($this->member)->postJson($this->url(), ['name' => 'Alex'])->assertJsonValidationErrors('name');
        $this->actingAs($this->owner)->postJson($this->url(), ['name' => 'Alex'])->assertCreated();
    }

    public function test_agents_per_member_are_limited(): void
    {
        config(['ai.limits.agents_per_member' => 2]);

        $this->createAgent($this->member, ['name' => 'One']);
        $this->createAgent($this->member, ['name' => 'Two']);

        $this->actingAs($this->member)->postJson($this->url(), ['name' => 'Three'])->assertJsonValidationErrors('name');
    }

    public function test_outsiders_cannot_create_agents_in_a_workspace(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->postJson($this->url(), ['name' => 'Spy'])->assertForbidden();
        $this->actingAs($outsider)->getJson($this->url())->assertForbidden();
        $this->actingAs($outsider)->getJson($this->url('/options'))->assertForbidden();
    }

    public function test_agents_are_private_to_their_owner(): void
    {
        $agent = $this->createAgent($this->member);
        $this->createAgent($this->owner, ['name' => 'Bea']);

        $this->actingAs($this->member)->getJson($this->url())->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Alex');

        // Even the workspace owner cannot read or edit someone else's agent.
        $this->actingAs($this->owner)->getJson($this->url("/{$agent->id}"))->assertNotFound();
        $this->actingAs($this->owner)->patchJson($this->url("/{$agent->id}"), ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($this->owner)->putJson($this->url("/{$agent->id}/sources"), ['channel_ids' => []])->assertNotFound();
        $this->actingAs($this->owner)->putJson($this->url("/{$agent->id}/permissions"), ['mode' => 'controlled'])->assertNotFound();
        $this->actingAs($this->owner)->deleteJson($this->url("/{$agent->id}"))->assertNotFound();

        $this->assertSame('Alex', $agent->fresh()->name);
        $this->assertSame('suggest', $agent->fresh()->mode);
    }

    public function test_an_agent_cannot_be_reached_through_another_workspace(): void
    {
        $agent = $this->createAgent($this->member);

        $this->actingAs($this->member)->post('/workspaces', ['name' => 'Side project']);
        $other = Workspace::where('name', 'Side project')->first();

        $this->actingAs($this->member)->getJson($this->url("/{$agent->id}", $other))->assertNotFound();
        $this->actingAs($this->member)->patchJson($this->url("/{$agent->id}", $other), ['name' => 'Moved'])->assertNotFound();
    }

    public function test_owner_edits_profile_instructions_and_settings(): void
    {
        $agent = $this->createAgent($this->member);

        $this->actingAs($this->member)->patchJson($this->url("/{$agent->id}"), [
            'name' => 'Alex Prime',
            'instructions' => 'Always prioritise my development tasks.',
            'settings' => ['daily_summary' => ['enabled' => true, 'time' => '18:00'], 'unknown' => ['x' => 1]],
        ])->assertOk()
            ->assertJsonPath('name', 'Alex Prime')
            ->assertJsonPath('instructions', 'Always prioritise my development tasks.')
            ->assertJsonPath('settings.daily_summary.enabled', true)
            ->assertJsonPath('settings.task_detection.enabled', true);

        // Unknown settings are dropped rather than stored.
        $this->assertArrayNotHasKey('unknown', $agent->fresh()->settings);
    }

    public function test_sources_are_limited_to_conversations_the_owner_belongs_to(): void
    {
        $agent = $this->createAgent($this->member);

        $private = $this->workspace->channels()->create(['name' => 'leadership', 'type' => Channel::PRIVATE, 'created_by' => $this->owner->id]);
        $private->members()->attach($this->owner->id);

        $this->actingAs($this->member)->putJson($this->url("/{$agent->id}/sources"), ['channel_ids' => [$this->general->id, $private->id]])
            ->assertJsonValidationErrors('source_channel_ids');

        $this->assertSame(0, $agent->sources()->count());

        // A DM is granted only when named explicitly.
        $this->actingAs($this->member)->post("/w/{$this->workspace->slug}/dm/{$this->owner->id}");
        $dm = Channel::where('type', Channel::DM)->where('dm_key', Channel::dmKey($this->owner->id, $this->member->id))->first();

        $this->actingAs($this->member)->putJson($this->url("/{$agent->id}/sources"), ['channel_ids' => [$this->general->id, $dm->id]])
            ->assertOk()
            ->assertJsonCount(2, 'sources')
            ->assertJsonPath('sources.1.label', $this->owner->name);

        $this->actingAs($this->member)->putJson($this->url("/{$agent->id}/sources"), ['channel_ids' => [$this->general->id]])
            ->assertOk()
            ->assertJsonCount(1, 'sources');
    }

    public function test_sources_cannot_come_from_another_workspace(): void
    {
        $agent = $this->createAgent($this->member);

        $this->actingAs($this->member)->post('/workspaces', ['name' => 'Side project']);
        $foreign = Workspace::where('name', 'Side project')->first()->channels()->first();

        $this->actingAs($this->member)->putJson($this->url("/{$agent->id}/sources"), ['channel_ids' => [$foreign->id]])
            ->assertJsonValidationErrors('source_channel_ids');
    }

    public function test_options_list_only_conversations_the_member_can_grant(): void
    {
        $private = $this->workspace->channels()->create(['name' => 'leadership', 'type' => Channel::PRIVATE, 'created_by' => $this->owner->id]);
        $private->members()->attach($this->owner->id);

        $labels = collect($this->actingAs($this->member)->getJson($this->url('/options'))->assertOk()->json('channels'))->pluck('label');

        $this->assertContains('#general', $labels);
        $this->assertNotContains('#leadership', $labels);
    }

    public function test_mode_applies_a_preset_and_actions_can_be_overridden(): void
    {
        $agent = $this->createAgent($this->member);

        $this->actingAs($this->member)->putJson($this->url("/{$agent->id}/permissions"), ['mode' => 'auto_tasks'])
            ->assertOk()
            ->assertJsonPath('mode', 'auto_tasks')
            ->assertJsonPath('permissions', AgentCapabilities::preset('auto_tasks'));

        $this->actingAs($this->member)->putJson($this->url("/{$agent->id}/permissions"), ['permissions' => ['send_direct_message' => 'deny']])
            ->assertOk()
            ->assertJsonPath('mode', 'auto_tasks')
            ->assertJsonPath('permissions.send_direct_message', 'deny')
            ->assertJsonPath('permissions.create_task', 'allow');

        $this->assertSame('deny', $agent->fresh()->policyFor('send_direct_message'));
    }

    public function test_unknown_actions_are_denied(): void
    {
        $agent = $this->createAgent($this->member, ['mode' => 'controlled']);

        $this->assertSame('allow', $agent->policyFor('send_channel_message'));
        $this->assertSame('deny', $agent->policyFor('delete_workspace'));
    }

    public function test_owner_pauses_and_resumes_without_losing_configuration(): void
    {
        Event::fake([AgentStatusChanged::class]);

        $agent = $this->createAgent($this->member, ['instructions' => 'Keep me', 'mode' => 'auto_tasks']);
        $this->actingAs($this->member)->putJson($this->url("/{$agent->id}/sources"), ['channel_ids' => [$this->general->id]]);

        $this->actingAs($this->member)->postJson($this->url("/{$agent->id}/status"), ['status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('status', 'paused');

        $paused = $agent->fresh();
        $this->assertNotNull($paused->paused_at);
        $this->assertFalse($paused->isActive());
        $this->assertSame('Keep me', $paused->instructions);
        $this->assertSame(1, $paused->sources()->count());

        $this->actingAs($this->member)->postJson($this->url("/{$agent->id}/status"), ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('paused_at', null)
            ->assertJsonPath('mode', 'auto_tasks');

        Event::assertDispatchedTimes(AgentStatusChanged::class, 2);
        $this->assertTrue(AuditLog::where('action', 'agent.paused')->exists());
    }

    public function test_status_must_be_known(): void
    {
        $agent = $this->createAgent($this->member);

        $this->actingAs($this->member)->postJson($this->url("/{$agent->id}/status"), ['status' => 'rogue'])->assertJsonValidationErrors('status');
    }

    public function test_admin_can_stop_but_not_restart_another_members_agent(): void
    {
        $agent = $this->createAgent($this->member);
        $peer = User::factory()->create();
        app(Invitations::class)->admit($this->workspace, $peer);

        $this->actingAs($peer)->postJson($this->url("/{$agent->id}/status"), ['status' => 'paused'])->assertForbidden();

        $this->actingAs($this->owner)->postJson($this->url("/{$agent->id}/status"), ['status' => 'inactive'])
            ->assertOk()
            ->assertExactJson(['id' => $agent->id, 'status' => 'inactive']);

        $this->actingAs($this->owner)->postJson($this->url("/{$agent->id}/status"), ['status' => 'active'])->assertForbidden();
        $this->assertSame('inactive', $agent->fresh()->status);
    }

    public function test_avatar_upload_preset_and_viewing(): void
    {
        Storage::fake('local');
        $agent = $this->createAgent($this->member);

        $this->actingAs($this->member)->postJson($this->url("/{$agent->id}/avatar"), ['photo' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertJsonValidationErrors('photo');
        $this->actingAs($this->member)->postJson($this->url("/{$agent->id}/avatar"), ['photo' => UploadedFile::fake()->image('big.png')->size(5000)])
            ->assertJsonValidationErrors('photo');

        $url = $this->actingAs($this->member)->postJson($this->url("/{$agent->id}/avatar"), ['photo' => UploadedFile::fake()->image('alex.png', 128, 128)])
            ->assertOk()
            ->json('avatar_url');

        $path = $agent->fresh()->avatar_path;
        Storage::disk('local')->assertExists($path);

        // Teammates see the avatar next to the agent's messages; outsiders do not.
        $this->actingAs($this->owner)->get($url)->assertOk();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();

        $this->actingAs($this->member)->postJson($this->url("/{$agent->id}/avatar"), ['preset' => 'owl'])
            ->assertOk()
            ->assertJsonPath('avatar_url', null)
            ->assertJsonPath('avatar_preset', 'owl');

        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_an_agent_removes_its_configuration_and_avatar(): void
    {
        Storage::fake('local');
        $agent = $this->createAgent($this->member, ['source_channel_ids' => [$this->general->id]]);
        $this->actingAs($this->member)->postJson($this->url("/{$agent->id}/avatar"), ['photo' => UploadedFile::fake()->image('alex.png')]);
        $path = $agent->fresh()->avatar_path;

        $this->actingAs($this->member)->deleteJson($this->url("/{$agent->id}"))->assertNoContent();

        $this->assertNull(AiAgent::find($agent->id));
        $this->assertDatabaseMissing('ai_agent_sources', ['ai_agent_id' => $agent->id]);
        $this->assertDatabaseMissing('ai_agent_permissions', ['ai_agent_id' => $agent->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertTrue(AuditLog::where('action', 'agent.deleted')->exists());
    }

    public function test_mobile_app_manages_agents_with_a_token(): void
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/login', ['email' => 'member@example.test', 'password' => 'password', 'device_name' => 'Pixel'])
            ->assertOk()
            ->json('token');
        $this->app['auth']->forgetGuards();

        $api = fn () => $this->withHeader('Authorization', "Bearer {$token}");
        $base = "/api/v1/w/{$this->workspace->slug}/agents";

        $id = $api()->postJson($base, ['name' => 'Pocket'])->assertCreated()->json('id');
        $api()->getJson($base)->assertOk()->assertJsonPath('0.name', 'Pocket');
        $api()->postJson("{$base}/{$id}/status", ['status' => 'paused'])->assertOk()->assertJsonPath('status', 'paused');
        $api()->getJson("{$base}/options")->assertOk()->assertJsonStructure(['actions', 'modes', 'channels']);
    }
}
