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

    public function test_a_message_can_be_marked_unread(): void
    {
        $channel = $this->publicChannel();
        $first = $channel->messages()->create(['user_id' => $this->owner->id, 'body' => 'one']);
        $second = $channel->messages()->create(['user_id' => $this->owner->id, 'body' => 'two']);
        $channel->members()->updateExistingPivot($this->owner->id, ['last_read_message_id' => $second->id]);

        $this->actingAs($this->owner)
            ->post("/w/{$this->workspace->slug}/c/{$channel->id}/unread", ['message_id' => $second->id])
            ->assertNoContent();

        $this->assertSame($first->id, (int) $channel->members()->whereKey($this->owner->id)->first()->pivot->last_read_message_id);
    }

    public function test_the_dashboard_lists_workspaces_with_member_counts(): void
    {
        $workspace = $this->actingAs($this->owner)->get('/dashboard')->viewData('page')['props']['workspaces'][0];

        $this->assertSame(3, $workspace['members_count']);
        $this->assertSame('owner', $workspace['role']);
        $this->assertCount(3, $workspace['preview']);
    }

    public function test_member_list_carries_profile_card_fields(): void
    {
        $general = $this->workspace->channels()->orderBy('id')->first();
        $members = $this->actingAs($this->member)->get("/w/{$this->workspace->slug}/c/{$general->id}")
            ->viewData('page')['props']['members'];

        $owner = collect($members)->firstWhere('id', $this->owner->id);
        $this->assertSame('owner', $owner['role']);
        $this->assertArrayHasKey('timezone', $owner);
    }

    public function test_a_thread_reply_can_also_be_sent_to_the_channel(): void
    {
        $channel = $this->publicChannel();
        $channel->members()->syncWithoutDetaching([$this->member->id]);
        $parent = $channel->messages()->create(['user_id' => $this->owner->id, 'body' => 'Deploy today?']);
        $url = "/w/{$this->workspace->slug}/c/{$channel->id}";

        $this->actingAs($this->member)->postJson("{$url}/messages", ['body' => 'quiet reply', 'parent_id' => $parent->id])->assertCreated();
        $loud = $this->actingAs($this->member)->postJson("{$url}/messages", [
            'body' => 'Yes, at 5', 'parent_id' => $parent->id, 'also_in_channel' => true,
        ])->assertCreated()->assertJsonPath('also_in_channel', true)
            ->assertJsonPath('thread_parent.excerpt', 'Deploy today?');

        $page = $this->actingAs($this->owner)->get($url)->viewData('page')['props'];
        $ids = collect($page['messages'])->pluck('id');

        $this->assertTrue($ids->contains($loud->json('id')));
        $this->assertSame(2, $ids->count()); // The parent and the reply sent to the channel, not the quiet one.
        $this->assertSame(2, collect($page['messages'])->firstWhere('id', $parent->id)['reply_count']);
    }

    public function test_a_top_level_message_ignores_also_in_channel(): void
    {
        $channel = $this->publicChannel();

        $this->actingAs($this->owner)->postJson("/w/{$this->workspace->slug}/c/{$channel->id}/messages", [
            'body' => 'hi', 'also_in_channel' => true,
        ])->assertCreated()->assertJsonPath('also_in_channel', false);
    }

    public function test_nobody_is_removed_from_the_default_channel(): void
    {
        $general = $this->workspace->channels()->orderBy('id')->first();

        $this->actingAs($this->owner)
            ->delete("/w/{$this->workspace->slug}/c/{$general->id}/members/{$this->member->id}")
            ->assertStatus(422);
    }
}
