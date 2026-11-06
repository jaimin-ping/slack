<?php

namespace Tests\Feature;

use App\Events\ActivityCreated;
use App\Events\HuddleReplied;
use App\Events\HuddleStarted;
use App\Events\MessageSent;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Draft;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private Channel $general;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Acme']);
        $this->workspace = Workspace::first();
        app(\App\Services\Invitations::class)->admit($this->workspace, $this->member);
        $this->general = $this->workspace->channels()->where('name', 'general')->first();
    }

    private function url(string $path = ''): string
    {
        return "/w/{$this->workspace->slug}{$path}";
    }

    public function test_workspace_gets_a_general_channel_that_joiners_enter(): void
    {
        $this->assertTrue($this->workspace->hasMember($this->owner));
        $this->assertTrue($this->general->hasMember($this->owner));
        $this->assertTrue($this->general->hasMember($this->member));
    }

    public function test_outsiders_cannot_read_or_post(): void
    {
        $outsider = User::factory()->create();
        $channel = "/c/{$this->general->id}";

        $this->actingAs($outsider)->get($this->url())->assertForbidden();
        $this->actingAs($outsider)->get($this->url($channel))->assertForbidden();
        $this->actingAs($outsider)->getJson($this->url("$channel/messages"))->assertForbidden();
        $this->actingAs($outsider)->postJson($this->url("$channel/messages"), ['body' => 'hi'])->assertForbidden();
    }

    public function test_channel_cannot_be_reached_through_another_workspace(): void
    {
        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Other']);
        $other = Workspace::where('name', 'Other')->first();

        $this->actingAs($this->owner)->get("/w/{$other->slug}/c/{$this->general->id}")->assertNotFound();
    }

    public function test_private_channel_is_only_for_chosen_members(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'Secret Plans', 'is_private' => true]);
        $secret = Channel::where('name', 'secret-plans')->first();

        $this->assertSame(Channel::PRIVATE, $secret->type);
        $this->actingAs($this->owner)->get($this->url("/c/{$secret->id}"))->assertOk();
        $this->actingAs($this->member)->get($this->url("/c/{$secret->id}"))->assertForbidden();
    }

    public function test_private_channel_ignores_people_outside_the_workspace(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->owner)->post($this->url('/channels'), [
            'name' => 'team',
            'is_private' => true,
            'member_ids' => [$this->member->id, $outsider->id],
        ]);
        $team = Channel::where('name', 'team')->first();

        $this->assertTrue($team->hasMember($this->member));
        $this->assertFalse($team->hasMember($outsider));
    }

    public function test_public_channel_includes_every_member(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'random']);

        $this->assertTrue(Channel::where('name', 'random')->first()->hasMember($this->member));
    }

    public function test_duplicate_channel_name_is_rejected(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'General'])->assertSessionHasErrors('name');
    }

    public function test_direct_message_channel_is_reused_from_both_sides(): void
    {
        $this->actingAs($this->owner)->post($this->url("/dm/{$this->member->id}"));
        $this->actingAs($this->member)->post($this->url("/dm/{$this->owner->id}"));

        $dms = Channel::where('type', Channel::DM)->get();

        $this->assertCount(1, $dms);
        $this->assertSame(2, $dms->first()->members()->count());
        $this->assertSame($this->member->id, $dms->first()->dmPartnerId($this->owner->id));
    }

    public function test_direct_message_needs_both_people_in_the_workspace(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->owner)->post($this->url("/dm/{$outsider->id}"))->assertForbidden();
    }

    public function test_sending_a_message_stores_and_broadcasts_it(): void
    {
        Event::fake([MessageSent::class]);

        $this->actingAs($this->owner)
            ->postJson($this->url("/c/{$this->general->id}/messages"), ['body' => '  hello  '])
            ->assertCreated()
            ->assertJsonPath('body', 'hello')
            ->assertJsonPath('user.id', $this->owner->id);

        Event::assertDispatched(MessageSent::class);
    }

    public function test_empty_message_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->postJson($this->url("/c/{$this->general->id}/messages"), ['body' => '   '])
            ->assertUnprocessable();
    }

    public function test_unread_counts_rise_for_others_and_clear_on_open(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'random']);
        $random = Channel::where('name', 'random')->first();

        $this->actingAs($this->owner)->postJson($this->url("/c/{$random->id}/messages"), ['body' => 'one']);
        $this->actingAs($this->owner)->postJson($this->url("/c/{$random->id}/messages"), ['body' => 'two']);

        $unread = function (User $user, Channel $open) use ($random) {
            $sidebar = $this->actingAs($user)->get($this->url("/c/{$open->id}"))->viewData('page')['props']['sidebar'];

            return collect($sidebar)->firstWhere('id', $random->id)['unread'];
        };

        $this->assertSame(2, $unread($this->member, $this->general));
        $this->assertSame(0, $unread($this->owner, $this->general));
        $this->assertSame(0, $unread($this->member, $random));
        $this->assertSame(0, $unread($this->member, $this->general));
    }

    public function test_reaction_toggles_on_and_off(): void
    {
        $message = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'hi']);
        $url = $this->url("/c/{$this->general->id}/messages/{$message->id}/reactions");

        $this->actingAs($this->member)->postJson($url, ['emoji' => '🔥'])
            ->assertOk()
            ->assertJsonPath('reactions.0.emoji', '🔥')
            ->assertJsonPath('reactions.0.user_ids', [$this->member->id]);

        $this->actingAs($this->member)->postJson($url, ['emoji' => '🔥'])->assertJsonPath('reactions', []);
    }

    public function test_reaction_rejects_unknown_emoji_and_outsiders(): void
    {
        $message = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'hi']);
        $url = $this->url("/c/{$this->general->id}/messages/{$message->id}/reactions");

        $this->actingAs($this->member)->postJson($url, ['emoji' => 'x'])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson($url, ['emoji' => '🔥'])->assertForbidden();
    }

    public function test_reaction_cannot_target_a_message_from_another_channel(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'random']);
        $random = Channel::where('name', 'random')->first();
        $message = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'hi']);

        $this->actingAs($this->owner)
            ->postJson($this->url("/c/{$random->id}/messages/{$message->id}/reactions"), ['emoji' => '🔥'])
            ->assertNotFound();
    }

    public function test_star_is_per_user_and_toggles(): void
    {
        $starred = function (User $user) {
            $sidebar = $this->actingAs($user)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props']['sidebar'];

            return collect($sidebar)->firstWhere('id', $this->general->id)['starred'];
        };

        $this->actingAs($this->owner)->post($this->url("/c/{$this->general->id}/star"))->assertNoContent();
        $this->assertTrue($starred($this->owner));
        $this->assertFalse($starred($this->member));

        $this->actingAs($this->owner)->post($this->url("/c/{$this->general->id}/star"));
        $this->assertFalse($starred($this->owner));
    }

    public function test_search_finds_only_messages_the_user_can_see(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'secret', 'is_private' => true]);
        $secret = Channel::where('name', 'secret')->first();
        $secret->messages()->create(['user_id' => $this->owner->id, 'body' => 'Invoice hidden']);
        $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'Invoice open']);

        $this->actingAs($this->member)->getJson($this->url('/search?q=invoice'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.body', 'Invoice open')
            ->assertJsonPath('0.channel_label', '#general');

        $this->actingAs($this->owner)->getJson($this->url('/search?q=invoice'))->assertJsonCount(2);
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'plain text']);

        $this->actingAs($this->owner)->getJson($this->url('/search?q=%25%25'))->assertJsonCount(0);
    }

    public function test_page_reports_last_read_message_for_the_new_line(): void
    {
        $first = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'one']);
        $this->actingAs($this->member)->get($this->url("/c/{$this->general->id}"));
        $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'two']);

        $props = $this->actingAs($this->member)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props'];

        $this->assertSame($first->id, $props['lastReadId']);
    }

    public function test_status_and_away_reach_other_members(): void
    {
        $this->actingAs($this->member)
            ->patch('/me/status', ['status_emoji' => '🌴', 'status_text' => 'On leave'])
            ->assertNoContent();
        $this->actingAs($this->member)->post('/me/away')->assertNoContent();

        $members = $this->actingAs($this->owner)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props']['members'];
        $seen = collect($members)->firstWhere('id', $this->member->id);

        $this->assertSame('🌴', $seen['status_emoji']);
        $this->assertSame('On leave', $seen['status_text']);
        $this->assertTrue($seen['is_away']);

        $this->actingAs($this->member)->post('/me/away');
        $this->actingAs($this->member)->patch('/me/status', []);

        $this->member->refresh();
        $this->assertFalse($this->member->is_away);
        $this->assertNull($this->member->status_text);
    }

    public function test_only_the_owner_can_rename_a_workspace(): void
    {
        $this->actingAs($this->member)->patch($this->url(), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($this->owner)->patch($this->url(), ['name' => 'Ping Holdings'])->assertRedirect();

        $this->assertSame('Ping Holdings', $this->workspace->refresh()->name);
    }

    public function test_replies_stay_in_the_thread_and_count_on_the_parent(): void
    {
        $parent = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'parent']);
        $base = "/c/{$this->general->id}/messages";

        $this->actingAs($this->member)
            ->postJson($this->url($base), ['body' => 'reply', 'parent_id' => $parent->id])
            ->assertCreated()
            ->assertJsonPath('parent_id', $parent->id)
            ->assertJsonPath('parent_reply_count', 1);

        $props = $this->actingAs($this->owner)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props'];
        $this->assertCount(1, $props['messages']);
        $this->assertSame(1, $props['messages'][0]['reply_count']);
        $this->assertNotNull($props['messages'][0]['last_reply_at']);

        $this->actingAs($this->owner)->getJson($this->url("$base/{$parent->id}/thread"))
            ->assertOk()
            ->assertJsonPath('parent.reply_count', 1)
            ->assertJsonCount(1, 'replies')
            ->assertJsonPath('replies.0.body', 'reply');
    }

    public function test_reply_does_not_raise_unread_or_hide_older_unread(): void
    {
        $random = $this->random();

        $unreadOf = function (User $user) use ($random) {
            $sidebar = $this->actingAs($user)->get($this->url("/c/{$random->id}"))->viewData('page')['props']['sidebar'];

            return collect($sidebar)->firstWhere('id', $this->general->id)['unread'];
        };

        $parent = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'one']);
        $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'reply', 'parent_id' => $parent->id]);
        $this->assertSame(1, $unreadOf($this->member));

        $this->actingAs($this->member)
            ->postJson($this->url("/c/{$this->general->id}/messages"), ['body' => 'my reply', 'parent_id' => $parent->id])
            ->assertCreated();
        $this->assertSame(1, $unreadOf($this->member));
    }

    private function random(): Channel
    {
        if (! Channel::where('name', 'random')->exists()) {
            $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'random']);
        }

        return Channel::where('name', 'random')->first();
    }

    public function test_threads_cannot_nest_or_cross_channels(): void
    {
        $parent = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'p']);
        $reply = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'r', 'parent_id' => $parent->id]);
        $base = "/c/{$this->general->id}/messages";

        $this->actingAs($this->owner)->postJson($this->url($base), ['body' => 'x', 'parent_id' => $reply->id])->assertUnprocessable();
        $this->actingAs($this->owner)->getJson($this->url("$base/{$reply->id}/thread"))->assertNotFound();

        $this->actingAs($this->owner)
            ->postJson($this->url("/c/{$this->random()->id}/messages"), ['body' => 'x', 'parent_id' => $parent->id])
            ->assertUnprocessable();
    }

    public function test_file_upload_is_private_to_channel_members(): void
    {
        Storage::fake('local');
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'secret', 'is_private' => true]);
        $secret = Channel::where('name', 'secret')->first();

        $response = $this->actingAs($this->owner)->post($this->url("/c/{$secret->id}/messages"), [
            'files' => [UploadedFile::fake()->image('photo.png')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('body', '')
            ->assertJsonPath('attachments.0.name', 'photo.png')
            ->assertJsonPath('attachments.0.is_image', true);

        $url = $response->json('attachments.0.url');
        $this->actingAs($this->owner)->get($url)->assertOk();
        $this->actingAs($this->member)->get($url)->assertForbidden();
        Storage::disk('local')->assertExists(Attachment::first()->path);
    }

    public function test_non_image_files_always_download(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->owner)->post($this->url("/c/{$this->general->id}/messages"), [
            'files' => [UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('attachments.0.is_image', false);

        $download = $this->actingAs($this->member)->get($response->json('attachments.0.url'));
        $download->assertOk();
        $this->assertStringStartsWith('attachment', $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
    }

    public function test_upload_limits_are_enforced(): void
    {
        Storage::fake('local');
        $url = $this->url("/c/{$this->general->id}/messages");
        $json = ['Accept' => 'application/json'];

        $this->actingAs($this->owner)
            ->post($url, ['files' => [UploadedFile::fake()->create('big.zip', 10241)]], $json)
            ->assertUnprocessable();

        $this->actingAs($this->owner)
            ->post($url, ['files' => array_map(fn ($i) => UploadedFile::fake()->create("f$i.txt", 1), range(1, 4))], $json)
            ->assertUnprocessable();

        $this->assertSame(0, Attachment::count());
    }

    public function test_only_the_author_can_edit(): void
    {
        $message = $this->general->messages()->create(['user_id' => $this->member->id, 'body' => 'old']);
        $url = $this->url("/c/{$this->general->id}/messages/{$message->id}");

        $this->actingAs($this->owner)->patchJson($url, ['body' => 'hack'])->assertForbidden();
        $this->actingAs($this->member)->patchJson($url, ['body' => ' '])->assertUnprocessable();

        $this->actingAs($this->member)->patchJson($url, ['body' => 'new'])
            ->assertOk()
            ->assertJsonPath('body', 'new');

        $this->assertNotNull($message->refresh()->edited_at);
    }

    public function test_delete_permissions(): void
    {
        $mine = $this->general->messages()->create(['user_id' => $this->member->id, 'body' => 'a']);
        $theirs = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'b']);
        $base = "/c/{$this->general->id}/messages";

        $this->actingAs($this->member)->deleteJson($this->url("$base/{$theirs->id}"))->assertForbidden();
        $this->actingAs($this->member)->deleteJson($this->url("$base/{$mine->id}"))->assertNoContent();

        $other = $this->general->messages()->create(['user_id' => $this->member->id, 'body' => 'c']);
        $this->actingAs($this->owner)->deleteJson($this->url("$base/{$other->id}"))->assertNoContent();

        $this->assertSame(1, $this->general->messages()->count());
    }

    public function test_deleting_a_parent_keeps_a_placeholder_until_the_last_reply_goes(): void
    {
        $parent = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'p']);
        $reply = $this->general->messages()->create(['user_id' => $this->member->id, 'body' => 'r', 'parent_id' => $parent->id]);
        $base = "/c/{$this->general->id}/messages";

        $this->actingAs($this->owner)->deleteJson($this->url("$base/{$parent->id}"))->assertNoContent();

        $parent->refresh();
        $this->assertNotNull($parent->removed_at);
        $this->assertSame('', $parent->body);

        $this->actingAs($this->member)->deleteJson($this->url("$base/{$reply->id}"))->assertNoContent();
        $this->assertSame(0, $this->general->messages()->count());
    }

    public function test_deleting_a_message_removes_its_files(): void
    {
        Storage::fake('local');

        $id = $this->actingAs($this->owner)->post($this->url("/c/{$this->general->id}/messages"), [
            'files' => [UploadedFile::fake()->image('a.png')],
        ], ['Accept' => 'application/json'])->json('id');

        $path = Attachment::first()->path;
        $this->actingAs($this->owner)->deleteJson($this->url("/c/{$this->general->id}/messages/{$id}"))->assertNoContent();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, Attachment::count());
    }

    private function makeWebhook(?Channel $channel = null): string
    {
        return $this->actingAs($this->owner)->postJson($this->url('/webhooks'), [
            'name' => 'Ping Prepay',
            'channel_id' => ($channel ?? $this->general)->id,
        ])->assertCreated()->json('url');
    }

    public function test_only_the_owner_manages_webhooks_and_the_token_is_not_stored(): void
    {
        $this->actingAs($this->member)->getJson($this->url('/webhooks'))->assertForbidden();
        $this->actingAs($this->member)
            ->postJson($this->url('/webhooks'), ['name' => 'x', 'channel_id' => $this->general->id])
            ->assertForbidden();

        $url = $this->makeWebhook();
        $token = basename($url);

        $this->assertDatabaseMissing('webhooks', ['token_hash' => $token]);
        $this->assertSame(Webhook::hashToken($token), Webhook::first()->token_hash);
        $this->actingAs($this->owner)->getJson($this->url('/webhooks'))
            ->assertJsonCount(1)
            ->assertJsonMissing(['token_hash' => Webhook::first()->token_hash]);
    }

    public function test_webhook_cannot_target_dms_or_private_channels_the_owner_is_not_in(): void
    {
        $this->actingAs($this->member)->post($this->url('/channels'), ['name' => 'closed', 'is_private' => true]);
        $closed = Channel::where('name', 'closed')->first();
        $this->actingAs($this->owner)->post($this->url("/dm/{$this->member->id}"));
        $dm = Channel::where('type', Channel::DM)->first();

        foreach ([$closed, $dm] as $channel) {
            $this->actingAs($this->owner)
                ->postJson($this->url('/webhooks'), ['name' => 'x', 'channel_id' => $channel->id])
                ->assertStatus(422);
        }
    }

    public function test_webhook_accepts_the_slack_attachment_format(): void
    {
        $url = $this->makeWebhook();

        $this->postJson($url, [
            'username' => 'Billing',
            'attachments' => [[
                'color' => 'danger',
                'title' => 'Invoice payment failed',
                'text' => 'An ACH debit did not go through. <https://x.test/i|Open>',
                'fields' => [['title' => 'Amount Due', 'value' => '$242.00'], ['title' => 'Location ID', 'value' => 10002178]],
            ]],
        ])->assertOk()->assertSee('ok');

        $props = $this->actingAs($this->member)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props'];
        $message = $props['messages'][0];

        $this->assertTrue($message['is_bot']);
        $this->assertSame('Billing', $message['user']['name']);
        $this->assertSame('#e01e5a', $message['cards'][0]['color']);
        $this->assertSame('Invoice payment failed', $message['cards'][0]['title']);
        $this->assertStringContainsString('Open (https://x.test/i)', $message['cards'][0]['text']);
        $this->assertSame('10002178', $message['cards'][0]['fields'][1]['value']);
    }

    public function test_webhook_accepts_text_blocks_and_form_payload(): void
    {
        $url = $this->makeWebhook();

        $this->postJson($url, ['text' => 'plain &amp; simple'])->assertOk();
        $this->postJson($url, ['blocks' => [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => 'Run done']],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'Charged OK: 21']],
        ]])->assertOk();
        $this->post($url, ['payload' => json_encode(['text' => 'legacy'])])->assertOk();

        $this->assertSame(
            ['plain & simple', "*Run done*\nCharged OK: 21", 'legacy'],
            $this->general->messages()->orderBy('id')->pluck('body')->all(),
        );
    }

    public function test_webhook_rejects_bad_requests(): void
    {
        $url = $this->makeWebhook();

        $this->post('/hooks/wrong-token', [])->assertNotFound()->assertSee('no_service');
        $this->call('POST', $url, [], [], [], [], 'not json')->assertStatus(400)->assertSee('invalid_payload');
        $this->postJson($url, ['text' => '   '])->assertStatus(400)->assertSee('no_text');
        $this->postJson($url, ['text' => str_repeat('x', 4001)])->assertStatus(400)->assertSee('msg_too_long');
        $this->postJson($url, ['attachments' => [['color' => 'red;}', 'text' => 'x']]])->assertOk();

        $this->assertSame('#94a3b8', $this->general->messages()->first()->cards[0]['color']);
    }

    public function test_deleted_webhook_stops_working_but_its_messages_stay(): void
    {
        $url = $this->makeWebhook();
        $this->postJson($url, ['text' => 'before'])->assertOk();

        $this->actingAs($this->owner)
            ->deleteJson($this->url('/webhooks/'.Webhook::first()->id))
            ->assertNoContent();

        $this->postJson($url, ['text' => 'after'])->assertNotFound();
        $this->assertSame(1, $this->general->messages()->count());
        $this->assertSame('Ping Prepay', $this->general->messages()->first()->bot_name);
    }

    public function test_webhook_messages_count_as_unread_for_everyone(): void
    {
        $random = $this->random();
        $url = $this->makeWebhook($this->general);
        $this->postJson($url, ['text' => 'alert'])->assertOk();

        $sidebar = $this->actingAs($this->owner)->get($this->url("/c/{$random->id}"))->viewData('page')['props']['sidebar'];

        $this->assertSame(1, collect($sidebar)->firstWhere('id', $this->general->id)['unread']);
    }

    public function test_nobody_can_edit_a_bot_message_but_the_owner_can_delete_it(): void
    {
        $this->postJson($this->makeWebhook(), ['text' => 'alert']);
        $message = $this->general->messages()->first();
        $url = $this->url("/c/{$this->general->id}/messages/{$message->id}");

        $this->actingAs($this->owner)->patchJson($url, ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->member)->deleteJson($url)->assertForbidden();
        $this->actingAs($this->owner)->deleteJson($url)->assertNoContent();
    }

    private function say(User $user, Channel $channel, string $body, ?int $parentId = null): int
    {
        return $this->actingAs($user)
            ->postJson($this->url("/c/{$channel->id}/messages"), ['body' => $body, 'parent_id' => $parentId])
            ->assertCreated()
            ->json('id');
    }

    public function test_mention_creates_activity_and_reading_clears_it(): void
    {
        Event::fake([ActivityCreated::class]);
        $this->say($this->owner, $this->general, "Hello @{$this->member->name}, please check");

        Event::assertDispatched(ActivityCreated::class, fn ($event) => $event->userIds === [$this->member->id]);

        $this->actingAs($this->member)->getJson($this->url('/activity'))
            ->assertJsonCount(1)
            ->assertJsonPath('0.type', 'mention')
            ->assertJsonPath('0.read', false)
            ->assertJsonPath('0.channel_label', '#general');

        $unread = fn () => $this->actingAs($this->member)->get($this->url("/c/{$this->general->id}"))
            ->viewData('page')['props']['activityUnread'];

        $this->assertSame(1, $unread());
        $this->actingAs($this->member)->post($this->url('/activity/read'))->assertNoContent();
        $this->assertSame(0, $unread());
    }

    public function test_mentioning_yourself_or_a_non_member_notifies_nobody(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'secret', 'is_private' => true]);
        $secret = Channel::where('name', 'secret')->first();

        $this->say($this->owner, $this->general, "@{$this->owner->name} note to self");
        $this->say($this->owner, $secret, "@{$this->member->name} cannot see this");

        $this->assertSame(0, Activity::count());
    }

    public function test_thread_replies_reach_participants_but_not_the_sender(): void
    {
        $third = User::factory()->create();
        app(\App\Services\Invitations::class)->admit($this->workspace, $third);

        $parent = $this->say($this->owner, $this->general, 'parent');
        $this->say($this->member, $this->general, 'first', $parent);
        $this->say($third, $this->general, 'second', $parent);

        $this->assertSame(2, Activity::where('user_id', $this->owner->id)->count());
        $this->assertSame(1, Activity::where('user_id', $this->member->id)->count());
        $this->assertSame(0, Activity::where('user_id', $third->id)->count());
    }

    public function test_webhook_can_mention_a_member(): void
    {
        $this->postJson($this->makeWebhook(), ['text' => "@{$this->member->name} billing failed"])->assertOk();

        $this->assertSame('mention', Activity::where('user_id', $this->member->id)->first()->type);
    }

    public function test_threads_page_lists_only_threads_the_user_joined(): void
    {
        $mine = $this->say($this->owner, $this->general, 'mine');
        $this->say($this->member, $this->general, 'reply', $mine);
        $other = $this->say($this->member, $this->general, 'other');
        $this->say($this->member, $this->general, 'self reply', $other);
        $this->say($this->owner, $this->general, 'no replies');

        $this->actingAs($this->owner)->getJson($this->url('/threads'))
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine)
            ->assertJsonPath('0.reply_count', 1)
            ->assertJsonPath('0.channel_label', '#general');
    }

    public function test_draft_saves_clears_on_send_and_stays_private(): void
    {
        $url = $this->url("/c/{$this->general->id}/draft");

        $this->actingAs($this->member)->putJson($url, ['body' => 'half written'])->assertNoContent();
        $this->actingAs($this->member)->putJson($url, ['body' => 'half written twice'])->assertNoContent();

        $props = fn (User $user) => $this->actingAs($user)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props'];

        $this->assertSame('half written twice', $props($this->member)['draft']);
        $this->assertSame('', $props($this->owner)['draft']);
        $this->actingAs($this->member)->getJson($this->url('/drafts'))->assertJsonCount(1)->assertJsonPath('0.channel_label', '#general');
        $this->actingAs($this->owner)->getJson($this->url('/drafts'))->assertJsonCount(0);

        $this->say($this->member, $this->general, 'sent');
        $this->assertSame(0, Draft::count());

        $this->actingAs($this->member)->putJson($url, ['body' => 'again']);
        $this->actingAs($this->member)->putJson($url, ['body' => '  '])->assertNoContent();
        $this->assertSame(0, Draft::count());
    }

    public function test_files_page_hides_private_channel_files(): void
    {
        Storage::fake('local');
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'secret', 'is_private' => true]);
        $secret = Channel::where('name', 'secret')->first();

        foreach ([$secret, $this->general] as $channel) {
            $this->actingAs($this->owner)->post($this->url("/c/{$channel->id}/messages"), [
                'files' => [UploadedFile::fake()->image("{$channel->name}.png")],
            ], ['Accept' => 'application/json'])->assertCreated();
        }

        $this->actingAs($this->owner)->getJson($this->url('/files'))->assertJsonCount(2);
        $this->actingAs($this->member)->getJson($this->url('/files'))
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'general.png');
    }

    public function test_feed_pages_require_membership(): void
    {
        $outsider = User::factory()->create();

        foreach (['/activity', '/threads', '/drafts', '/files'] as $path) {
            $this->actingAs($outsider)->getJson($this->url($path))->assertForbidden();
        }
    }

    public function test_notify_all_is_a_per_user_channel_setting(): void
    {
        $setting = function (User $user) {
            $sidebar = $this->actingAs($user)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props']['sidebar'];

            return collect($sidebar)->firstWhere('id', $this->general->id)['notify_all'];
        };

        $this->actingAs($this->member)->post($this->url("/c/{$this->general->id}/notify"))->assertNoContent();

        $this->assertTrue($setting($this->member));
        $this->assertFalse($setting($this->owner));
    }

    public function test_canvas_is_shared_and_refuses_stale_saves(): void
    {
        $url = $this->url("/c/{$this->general->id}/canvas");

        $this->actingAs($this->member)->getJson($url)->assertOk()->assertJsonPath('version', 0);

        $this->actingAs($this->owner)->putJson($url, ['body' => 'v1', 'version' => 0])
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('updated_by', $this->owner->name);

        $this->actingAs($this->member)->putJson($url, ['body' => 'stale', 'version' => 0])->assertStatus(409);

        $this->actingAs($this->member)->getJson($url)->assertJsonPath('body', 'v1');
    }

    public function test_canvas_is_private_to_channel_members(): void
    {
        $outsider = User::factory()->create();
        $url = $this->url("/c/{$this->general->id}/canvas");

        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $this->actingAs($outsider)->putJson($url, ['body' => 'x', 'version' => 0])->assertForbidden();
    }

    private function privateChannel(): Channel
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'team', 'is_private' => true]);

        return Channel::where('name', 'team')->first();
    }

    public function test_members_can_set_a_topic_but_outsiders_cannot(): void
    {
        $url = $this->url("/c/{$this->general->id}");

        $this->actingAs(User::factory()->create())->patch($url, ['topic' => 'x'])->assertForbidden();
        $this->actingAs($this->member)->patch($url, ['topic' => 'Daily updates'])->assertRedirect();

        $this->assertSame('Daily updates', $this->general->refresh()->topic);
    }

    public function test_people_can_be_added_to_a_private_channel(): void
    {
        $team = $this->privateChannel();
        $outsider = User::factory()->create();
        $url = $this->url("/c/{$team->id}/members");

        $this->actingAs($this->member)->post($url, ['member_ids' => [$this->member->id]])->assertForbidden();

        $this->actingAs($this->owner)->post($url, ['member_ids' => [$this->member->id, $outsider->id]])->assertRedirect();

        $this->assertTrue($team->hasMember($this->member));
        $this->assertFalse($team->hasMember($outsider));
    }

    public function test_leaving_a_private_channel(): void
    {
        $team = $this->privateChannel();
        $url = $this->url("/c/{$team->id}/leave");

        $this->actingAs($this->owner)->post($url)->assertSessionHasErrors('leave');

        $team->members()->attach($this->member->id);
        $this->actingAs($this->member)->post($url)->assertRedirect();
        $this->assertFalse($team->hasMember($this->member));

        $this->actingAs($this->owner)->post($this->url("/c/{$this->general->id}/leave"))->assertForbidden();
    }

    public function test_deleting_a_channel_removes_it_but_never_the_default_one(): void
    {
        Storage::fake('local');
        $team = $this->privateChannel();
        $team->members()->attach($this->member->id);
        $this->actingAs($this->owner)->post($this->url("/c/{$team->id}/messages"), [
            'files' => [UploadedFile::fake()->image('a.png')],
        ], ['Accept' => 'application/json']);
        $path = Attachment::first()->path;

        $this->actingAs($this->member)->delete($this->url("/c/{$team->id}"))->assertForbidden();
        $this->actingAs($this->owner)->delete($this->url("/c/{$this->general->id}"))->assertStatus(422);

        $this->actingAs($this->owner)->delete($this->url("/c/{$team->id}"))->assertRedirect();

        $this->assertNull(Channel::find($team->id));
        Storage::disk('local')->assertMissing($path);
    }

    public function test_workspace_delete_needs_the_owner_and_the_exact_name(): void
    {
        $this->actingAs($this->member)->delete($this->url(), ['confirm_name' => 'Acme'])->assertForbidden();
        $this->actingAs($this->owner)->delete($this->url(), ['confirm_name' => 'acme'])->assertSessionHasErrors('confirm_name');
        $this->assertNotNull(Workspace::find($this->workspace->id));

        $this->actingAs($this->owner)->delete($this->url(), ['confirm_name' => 'Acme'])->assertRedirect('/dashboard');

        $this->assertNull(Workspace::find($this->workspace->id));
        $this->assertSame(0, Channel::count());
    }

    public function test_reply_sent_as_form_data_returns_a_numeric_parent_id(): void
    {
        $parent = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'p']);

        $response = $this->actingAs($this->member)->post($this->url("/c/{$this->general->id}/messages"), [
            'body' => 'reply',
            'parent_id' => (string) $parent->id,
        ], ['Accept' => 'application/json']);

        $this->assertSame($parent->id, $response->assertCreated()->json('parent_id'));
    }

    public function test_profile_photo_is_visible_to_workspace_members_only(): void
    {
        Storage::fake('local');

        $this->actingAs($this->member)
            ->post('/me/avatar', ['photo' => UploadedFile::fake()->image('me.png')], ['Accept' => 'application/json'])
            ->assertNoContent();

        $members = $this->actingAs($this->owner)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props']['members'];
        $url = collect($members)->firstWhere('id', $this->member->id)['avatar_url'];

        $this->assertNotNull($url);
        $this->actingAs($this->owner)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->member)->get($url)->assertOk();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_replacing_and_removing_a_photo_deletes_the_old_file(): void
    {
        Storage::fake('local');
        $upload = fn () => $this->actingAs($this->member)
            ->post('/me/avatar', ['photo' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json']);

        $upload()->assertNoContent();
        $first = $this->member->refresh()->avatar_path;

        $upload()->assertNoContent();
        $second = $this->member->refresh()->avatar_path;

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->actingAs($this->member)->delete('/me/avatar')->assertNoContent();

        Storage::disk('local')->assertMissing($second);
        $this->assertNull($this->member->refresh()->avatar_path);
    }

    public function test_photo_upload_rejects_svg_and_large_files(): void
    {
        Storage::fake('local');
        $json = ['Accept' => 'application/json'];
        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($this->member)->post('/me/avatar', ['photo' => $svg], $json)->assertUnprocessable();
        $this->actingAs($this->member)
            ->post('/me/avatar', ['photo' => UploadedFile::fake()->image('big.png')->size(2049)], $json)
            ->assertUnprocessable();

        $this->assertNull($this->member->refresh()->avatar_path);
    }

    public function test_avatar_path_is_not_exposed_in_shared_props(): void
    {
        $this->member->update(['avatar_path' => 'avatars/secret.png']);

        $props = $this->actingAs($this->member)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props'];

        $this->assertIsArray($props['auth']['user']);
        $this->assertArrayNotHasKey('avatar_path', $props['auth']['user']);
    }

    private function makeChannel(string $name = 'random'): Channel
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => $name]);

        return Channel::where('name', $name)->first();
    }

    private function sidebarOf(User $user, ?Channel $open = null): array
    {
        return $this->actingAs($user)->get($this->url('/c/'.($open ?? $this->general)->id))->viewData('page')['props']['sidebar'];
    }

    public function test_browse_lists_public_channels_and_only_your_private_ones(): void
    {
        $random = $this->makeChannel();
        $this->actingAs($this->member)->post($this->url("/c/{$random->id}/leave"))->assertRedirect();
        $this->privateChannel();

        $this->actingAs($this->member)->getJson($this->url('/browse'))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'general')
            ->assertJsonPath('0.joined', true)
            ->assertJsonPath('1.name', 'random')
            ->assertJsonPath('1.joined', false);

        $this->actingAs($this->owner)->getJson($this->url('/browse'))->assertJsonCount(3);
        $this->actingAs(User::factory()->create())->getJson($this->url('/browse'))->assertForbidden();
    }

    public function test_leaving_and_rejoining_a_public_channel(): void
    {
        $random = $this->makeChannel();

        $this->actingAs($this->member)->post($this->url("/c/{$random->id}/leave"))->assertRedirect();
        $this->assertFalse($random->hasMember($this->member));

        $this->actingAs($this->member)->post($this->url("/c/{$random->id}/join"))->assertRedirect();
        $this->assertTrue($random->hasMember($this->member));
    }

    public function test_private_and_foreign_channels_cannot_be_joined(): void
    {
        $team = $this->privateChannel();

        $this->actingAs($this->member)->post($this->url("/c/{$team->id}/join"))->assertForbidden();
        $this->actingAs(User::factory()->create())->post($this->url("/c/{$this->general->id}/join"))->assertForbidden();
    }

    public function test_the_default_channel_cannot_be_left_or_archived(): void
    {
        $this->actingAs($this->member)->post($this->url("/c/{$this->general->id}/leave"))->assertForbidden();
        $this->actingAs($this->owner)->post($this->url("/c/{$this->general->id}/archive"))->assertStatus(422);
    }

    public function test_only_managers_can_rename_and_names_stay_unique(): void
    {
        $random = $this->makeChannel();
        $this->makeChannel('other');
        $url = $this->url("/c/{$random->id}");

        $this->actingAs($this->member)->patch($url, ['name' => 'hack'])->assertForbidden();
        $this->actingAs($this->owner)->patch($url, ['name' => 'Other'])->assertSessionHasErrors('name');
        $this->actingAs($this->owner)->patch($url, ['name' => 'Team News'])->assertRedirect();

        $this->assertSame('team-news', $random->refresh()->name);
    }

    public function test_archived_channel_leaves_the_sidebar_and_blocks_posting(): void
    {
        $random = $this->makeChannel();
        $url = $this->url("/c/{$random->id}");

        $this->actingAs($this->member)->post("{$url}/archive")->assertForbidden();
        $this->actingAs($this->owner)->post("{$url}/archive")->assertRedirect();

        $this->assertNull(collect($this->sidebarOf($this->member))->firstWhere('id', $random->id));
        $this->actingAs($this->member)->postJson("{$url}/messages", ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->member)->post("{$url}/join")->assertForbidden();

        $page = $this->actingAs($this->member)->get($url)->assertOk()->viewData('page')['props'];
        $this->assertTrue($page['channel']['archived']);

        $this->actingAs($this->member)->post("{$url}/unarchive")->assertForbidden();
        $this->actingAs($this->owner)->post("{$url}/unarchive")->assertRedirect();

        $this->assertNotNull(collect($this->sidebarOf($this->member))->firstWhere('id', $random->id));
        $this->actingAs($this->member)->postJson("{$url}/messages", ['body' => 'back'])->assertCreated();
    }

    public function test_webhook_refuses_an_archived_channel(): void
    {
        $random = $this->makeChannel();
        $hook = $this->makeWebhook($random);
        $this->actingAs($this->owner)->post($this->url("/c/{$random->id}/archive"));

        $this->postJson($hook, ['text' => 'alert'])->assertStatus(410)->assertSee('channel_is_archived');
        $this->assertSame(0, $random->messages()->count());
    }

    public function test_mute_is_per_user(): void
    {
        $this->actingAs($this->member)->post($this->url("/c/{$this->general->id}/mute"))->assertNoContent();

        $this->assertTrue(collect($this->sidebarOf($this->member))->firstWhere('id', $this->general->id)['muted']);
        $this->assertFalse(collect($this->sidebarOf($this->owner))->firstWhere('id', $this->general->id)['muted']);
    }

    public function test_older_messages_load_in_pages(): void
    {
        foreach (range(1, 55) as $i) {
            $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => "m{$i}"]);
        }

        $props = $this->actingAs($this->owner)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props'];
        $this->assertCount(50, $props['messages']);
        $this->assertTrue($props['hasMore']);

        $before = $props['messages'][0]['id'];
        $older = $this->actingAs($this->owner)->getJson($this->url("/c/{$this->general->id}/messages?before={$before}"))->json();
        $this->assertCount(5, $older['messages']);
        $this->assertFalse($older['hasMore']);
    }

    public function test_starting_a_huddle_rings_the_conversation(): void
    {
        Event::fake([HuddleStarted::class]);

        $this->actingAs($this->owner)->post("/w/{$this->workspace->slug}/c/{$this->general->id}/huddle")->assertNoContent();

        Event::assertDispatched(HuddleStarted::class, fn (HuddleStarted $event) => $event->channel->is($this->general)
            && $event->user->is($this->owner)
            && $event->broadcastWith()['by']['name'] === $this->owner->name);
    }

    public function test_only_members_can_ring_a_conversation(): void
    {
        Event::fake([HuddleStarted::class]);

        $this->actingAs(User::factory()->create())->post("/w/{$this->workspace->slug}/c/{$this->general->id}/huddle")->assertForbidden();

        Event::assertNotDispatched(HuddleStarted::class);
    }

    public function test_answering_a_huddle_tells_the_person_who_rang(): void
    {
        Event::fake([HuddleReplied::class]);
        $url = "/w/{$this->workspace->slug}/c/{$this->general->id}/huddle/reply";

        $this->actingAs($this->member)->post($url, ['to' => $this->owner->id, 'status' => 'soon'])->assertNoContent();
        $this->actingAs($this->member)->postJson($url, ['to' => $this->owner->id, 'status' => 'nonsense'])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->post($url, ['to' => $this->owner->id, 'status' => 'declined'])->assertForbidden();

        Event::assertDispatchedTimes(HuddleReplied::class, 1);
        Event::assertDispatched(HuddleReplied::class, fn (HuddleReplied $event) => $event->broadcastWith()['to'] === $this->owner->id
            && $event->broadcastWith()['status'] === 'soon');
    }
}
