<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Channel;
use App\Models\ChannelBookmark;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Invitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlackParityTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->member = User::factory()->create(['name' => 'Member']);
        $this->other = User::factory()->create(['name' => 'Other']);

        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Acme']);
        $this->workspace = Workspace::first();

        foreach ([$this->member, $this->other] as $user) {
            app(Invitations::class)->admit($this->workspace, $user);
        }
    }

    private function url(string $path = ''): string
    {
        return "/w/{$this->workspace->slug}{$path}";
    }

    private function channel(string $name = 'dev-pos'): Channel
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => $name]);

        return Channel::where('name', $name)->firstOrFail();
    }

    public function test_about_tab_shows_description_and_creator(): void
    {
        $channel = $this->channel();

        $this->actingAs($this->owner)->patch($this->url("/c/{$channel->id}"), ['description' => 'Release talk'])->assertRedirect();

        $props = $this->actingAs($this->owner)->get($this->url("/c/{$channel->id}"))->viewData('page')['props']['channel'];
        $this->assertSame('Release talk', $props['description']);
        $this->assertSame('Owner', $props['created_by_name']);
        $this->assertFalse($props['is_default']);
    }

    public function test_a_manager_can_make_a_public_channel_private(): void
    {
        $channel = $this->channel();
        $channel->members()->syncWithoutDetaching([$this->member->id]);

        $this->actingAs($this->member)->patch($this->url("/c/{$channel->id}"), ['make_private' => true])->assertForbidden();
        $this->assertSame(Channel::PUBLIC, $channel->fresh()->type);

        $this->actingAs($this->owner)->patch($this->url("/c/{$channel->id}"), ['make_private' => true])->assertRedirect();
        $this->assertSame(Channel::PRIVATE, $channel->fresh()->type);
    }

    public function test_the_default_channel_cannot_be_made_private(): void
    {
        $general = $this->workspace->channels()->orderBy('id')->first();

        $this->actingAs($this->owner)->patch($this->url("/c/{$general->id}"), ['make_private' => true])->assertForbidden();
    }

    public function test_a_group_dm_is_created_once_for_the_same_people(): void
    {
        $this->actingAs($this->owner)
            ->postJson($this->url('/dm-group'), ['user_ids' => [$this->member->id, $this->other->id]])
            ->assertOk();
        $again = $this->actingAs($this->member)
            ->postJson($this->url('/dm-group'), ['user_ids' => [$this->owner->id, $this->other->id]])
            ->assertOk();

        $group = Channel::findOrFail($again->json('channel_id'));
        $this->assertSame(1, Channel::where('dm_key', 'like', 'g:%')->count());
        $this->assertTrue($group->isGroupDm());
        $this->assertSame(3, $group->members()->count());

        $sidebar = $this->actingAs($this->other)->get($this->url("/c/{$group->id}"))->viewData('page')['props'];
        $this->assertEqualsCanonicalizing([$this->owner->id, $this->member->id], $sidebar['channel']['dm_user_ids']);
        $this->assertNull($sidebar['channel']['dm_user_id']);
    }

    public function test_a_group_dm_with_one_person_is_a_normal_dm(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson($this->url('/dm-group'), ['user_ids' => [$this->member->id]])
            ->assertOk();

        $this->assertFalse(Channel::findOrFail($response->json('channel_id'))->isGroupDm());
    }

    public function test_keyword_notifications_create_activity(): void
    {
        $this->member->update(['notification_keywords' => 'deploy, invoice']);
        $general = $this->workspace->channels()->orderBy('id')->first();

        $this->actingAs($this->owner)->postJson($this->url("/c/{$general->id}/messages"), ['body' => 'We Deploy at 5'])->assertCreated();
        $this->actingAs($this->owner)->postJson($this->url("/c/{$general->id}/messages"), ['body' => 'redeployment is not a match'])->assertCreated();

        $this->assertSame(1, Activity::where(['user_id' => $this->member->id, 'type' => 'keyword'])->count());
        $this->assertSame(0, Activity::where('user_id', $this->other->id)->count());
    }

    public function test_keywords_are_saved_from_preferences(): void
    {
        $this->actingAs($this->member)->putJson('/me/preferences', ['theme' => 'light', 'notification_keywords' => 'deploy'])->assertNoContent();

        $this->assertSame('deploy', $this->member->fresh()->notification_keywords);
    }

    public function test_members_can_add_and_remove_bookmarks(): void
    {
        $channel = $this->channel();
        $channel->members()->syncWithoutDetaching([$this->member->id]);

        $id = $this->actingAs($this->member)
            ->postJson($this->url("/c/{$channel->id}/bookmarks"), ['url' => 'https://docs.example.com/x', 'title' => ''])
            ->assertCreated()
            ->assertJsonPath('title', 'docs.example.com')
            ->json('id');

        $this->actingAs($this->owner)->get($this->url("/c/{$channel->id}"))
            ->assertInertia(fn ($page) => $page->where('channel.bookmarks.0.id', $id));

        $this->actingAs($this->other)->deleteJson($this->url("/c/{$channel->id}/bookmarks/{$id}"))->assertForbidden();
        $this->actingAs($this->member)->deleteJson($this->url("/c/{$channel->id}/bookmarks/{$id}"))->assertNoContent();
        $this->assertSame(0, ChannelBookmark::count());
    }

    public function test_bookmarks_reject_non_web_links(): void
    {
        $channel = $this->channel();

        $this->actingAs($this->owner)
            ->postJson($this->url("/c/{$channel->id}/bookmarks"), ['url' => 'javascript:alert(1)'])
            ->assertUnprocessable();
    }
}
