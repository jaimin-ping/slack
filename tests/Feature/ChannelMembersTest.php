<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Invitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelMembersTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->other = User::factory()->create();

        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Acme']);
        $this->workspace = Workspace::first();

        foreach ([$this->member, $this->other] as $user) {
            app(Invitations::class)->admit($this->workspace, $user);
        }
    }

    private function publicChannel(): Channel
    {
        $this->actingAs($this->owner)->post("/w/{$this->workspace->slug}/channels", ['name' => 'dev-pos']);

        return Channel::where('name', 'dev-pos')->firstOrFail();
    }

    public function test_a_member_can_add_people_to_a_public_channel(): void
    {
        $channel = $this->publicChannel();
        $channel->members()->syncWithoutDetaching([$this->member->id]);

        $this->actingAs($this->member)
            ->post("/w/{$this->workspace->slug}/c/{$channel->id}/members", ['member_ids' => [$this->other->id]])
            ->assertRedirect();

        $this->assertTrue($channel->hasMember($this->other));
    }

    public function test_a_non_member_cannot_add_people(): void
    {
        $channel = $this->publicChannel();

        $this->actingAs($this->other)
            ->post("/w/{$this->workspace->slug}/c/{$channel->id}/members", ['member_ids' => [$this->member->id]])
            ->assertForbidden();
    }

    public function test_only_a_manager_can_remove_people(): void
    {
        $channel = $this->publicChannel();
        $channel->members()->syncWithoutDetaching([$this->member->id, $this->other->id]);

        $this->actingAs($this->member)
            ->delete("/w/{$this->workspace->slug}/c/{$channel->id}/members/{$this->other->id}")
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->delete("/w/{$this->workspace->slug}/c/{$channel->id}/members/{$this->other->id}")
            ->assertRedirect();

        $this->assertFalse($channel->hasMember($this->other));
    }

    public function test_nobody_is_removed_from_the_default_channel(): void
    {
        $general = $this->workspace->channels()->orderBy('id')->first();

        $this->actingAs($this->owner)
            ->delete("/w/{$this->workspace->slug}/c/{$general->id}/members/{$this->member->id}")
            ->assertStatus(422);
    }
}
