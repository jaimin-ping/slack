<?php

namespace Tests\Feature;

use App\Jobs\FetchLinkPreview;
use App\Models\Activity;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LinkPreviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessageFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private Channel $general;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner Person']);
        $this->member = User::factory()->create(['name' => 'Member Person']);

        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Acme']);
        $this->workspace = Workspace::first();
        app(\App\Services\Invitations::class)->admit($this->workspace, $this->member);
        $this->general = $this->workspace->channels()->first();
    }

    private function url(string $path = ''): string
    {
        return "/w/{$this->workspace->slug}{$path}";
    }

    private function message(string $body = 'hello', ?User $user = null): Message
    {
        return $this->general->messages()->create(['user_id' => ($user ?? $this->owner)->id, 'body' => $body]);
    }

    private function say(User $user, string $body): int
    {
        return $this->actingAs($user)
            ->postJson($this->url("/c/{$this->general->id}/messages"), ['body' => $body])
            ->assertCreated()
            ->json('id');
    }

    public function test_any_member_can_pin_and_unpin(): void
    {
        $message = $this->message();
        $url = $this->url("/c/{$this->general->id}/messages/{$message->id}/pin");

        $this->actingAs($this->member)->postJson($url)->assertOk()->assertJsonPath('pinned', true);
        $this->actingAs($this->owner)->getJson($this->url("/c/{$this->general->id}/pins"))->assertJsonCount(1)->assertJsonPath('0.id', $message->id);

        $this->actingAs($this->owner)->postJson($url)->assertJsonPath('pinned', false);
        $this->actingAs($this->owner)->getJson($this->url("/c/{$this->general->id}/pins"))->assertJsonCount(0);

        $this->actingAs(User::factory()->create())->postJson($url)->assertForbidden();
    }

    public function test_saved_items_are_private_to_each_person(): void
    {
        $message = $this->message();
        $url = $this->url("/c/{$this->general->id}/messages/{$message->id}/save");

        $this->actingAs($this->member)->postJson($url)->assertJsonPath('saved', true);

        $this->actingAs($this->member)->getJson($this->url('/saved'))->assertJsonCount(1)->assertJsonPath('0.channel_label', '#general');
        $this->actingAs($this->owner)->getJson($this->url('/saved'))->assertJsonCount(0);

        $this->actingAs($this->member)->postJson($url)->assertJsonPath('saved', false);
        $this->actingAs($this->member)->getJson($this->url('/saved'))->assertJsonCount(0);
    }

    public function test_saved_item_disappears_after_leaving_a_private_channel(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'team', 'is_private' => true]);
        $team = Channel::where('name', 'team')->first();
        $team->members()->attach($this->member->id);
        $message = $team->messages()->create(['user_id' => $this->owner->id, 'body' => 'secret']);

        $this->actingAs($this->member)->postJson($this->url("/c/{$team->id}/messages/{$message->id}/save"))->assertOk();
        $this->actingAs($this->member)->post($this->url("/c/{$team->id}/leave"));

        $this->actingAs($this->member)->getJson($this->url('/saved'))->assertJsonCount(0);
    }

    public function test_forward_copies_the_text_with_its_source(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'random']);
        $random = Channel::where('name', 'random')->first();
        $message = $this->message('original text');

        $this->actingAs($this->member)
            ->postJson($this->url("/c/{$this->general->id}/messages/{$message->id}/forward"), ['channel_id' => $random->id, 'comment' => 'look'])
            ->assertCreated()
            ->assertJsonPath('body', 'look')
            ->assertJsonPath('user.id', $this->member->id)
            ->assertJsonPath('cards.0.title', 'Forwarded from Owner Person in #general')
            ->assertJsonPath('cards.0.text', 'original text');

        $this->assertSame(1, $random->messages()->count());
    }

    public function test_forward_needs_access_to_both_channels(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'team', 'is_private' => true]);
        $team = Channel::where('name', 'team')->first();
        $message = $this->message();
        $secret = $team->messages()->create(['user_id' => $this->owner->id, 'body' => 'secret']);

        $this->actingAs($this->member)
            ->postJson($this->url("/c/{$this->general->id}/messages/{$message->id}/forward"), ['channel_id' => $team->id])
            ->assertForbidden();

        $this->actingAs($this->member)
            ->postJson($this->url("/c/{$team->id}/messages/{$secret->id}/forward"), ['channel_id' => $this->general->id])
            ->assertForbidden();
    }

    public function test_any_emoji_can_react_but_text_cannot(): void
    {
        $url = $this->url("/c/{$this->general->id}/messages/{$this->message()->id}/reactions");

        foreach (['🦄', '👍🏽', '🇮🇳', '1️⃣'] as $emoji) {
            $this->actingAs($this->member)->postJson($url, ['emoji' => $emoji])->assertOk();
        }

        foreach (['x', '<b>', '12', str_repeat('🔥', 17)] as $bad) {
            $this->actingAs($this->member)->postJson($url, ['emoji' => $bad])->assertUnprocessable();
        }
    }

    public function test_at_channel_reaches_everyone_but_the_sender(): void
    {
        $this->say($this->owner, 'hello @channel');

        $this->assertSame([$this->member->id], Activity::pluck('user_id')->all());
    }

    public function test_at_here_skips_people_marked_away(): void
    {
        $this->member->update(['is_away' => true]);

        $this->say($this->owner, '@here quick question');

        $this->assertSame(0, Activity::count());
    }

    public function test_words_that_only_look_like_mentions_notify_nobody(): void
    {
        $this->say($this->owner, 'mail me at a@channel.test or @channels');

        $this->assertSame(0, Activity::count());
    }

    public function test_message_with_a_link_schedules_a_preview(): void
    {
        Bus::fake([FetchLinkPreview::class]);

        $this->say($this->owner, 'no link here');
        Bus::assertNotDispatched(FetchLinkPreview::class);

        $id = $this->say($this->owner, 'see https://example.com/a.');
        Bus::assertDispatched(FetchLinkPreview::class, fn ($job) => $job->messageId === $id);
    }

    public function test_preview_reads_open_graph_tags_and_saves_them(): void
    {
        Http::fake(['*' => Http::response(
            '<html><head><title>Plain</title><meta property="og:title" content="Rich title">'
            .'<meta property="og:description" content="About it"><meta property="og:site_name" content="Site"></head></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        )]);

        $message = $this->message('https://93.184.216.34/page');

        (new FetchLinkPreview($message->id))->handle(app(LinkPreviewer::class));

        $preview = $message->refresh()->link_preview;
        $this->assertSame('Rich title', $preview['title']);
        $this->assertSame('About it', $preview['description']);
        $this->assertSame('Site', $preview['site']);
    }

    public function test_preview_refuses_private_addresses_without_any_request(): void
    {
        Http::fake();
        $previewer = app(LinkPreviewer::class);

        $blocked = [
            'http://127.0.0.1/',
            'http://localhost/',
            'http://10.0.0.5/',
            'http://192.168.1.1/admin',
            'http://172.16.0.1/',
            'http://169.254.169.254/latest/meta-data/',
            'http://100.64.0.1/',
            'http://0.0.0.0/',
            'http://[::1]/',
            'http://93.184.216.34:5432/',
            'http://user:pass@93.184.216.34/',
            'ftp://93.184.216.34/',
            'file:///etc/passwd',
        ];

        foreach ($blocked as $url) {
            $this->assertNull($previewer->fetch($url), $url);
        }

        Http::assertNothingSent();
    }

    public function test_preview_rechecks_every_redirect(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

        $this->assertNull(app(LinkPreviewer::class)->fetch('https://93.184.216.34/start'));

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254'));
    }

    public function test_preview_ignores_files_and_failed_pages(): void
    {
        Http::fakeSequence()
            ->push('binary', 200, ['Content-Type' => 'application/pdf'])
            ->push('<title>Oops</title>', 500, ['Content-Type' => 'text/html']);

        $previewer = app(LinkPreviewer::class);

        $this->assertNull($previewer->fetch('https://93.184.216.34/a.pdf'));
        $this->assertNull($previewer->fetch('https://93.184.216.34/broken'));
    }
}
