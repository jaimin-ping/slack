<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\Channel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private Channel $general;

    private const SUBSCRIPTION = [
        'endpoint' => 'https://push.example.test/abc',
        'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-secret'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner Person']);
        $this->member = User::factory()->create(['name' => 'Member Person']);

        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Acme']);
        $this->workspace = Workspace::first();
        $this->actingAs($this->member)->post('/workspaces/join', ['code' => $this->workspace->invite_code]);
        $this->general = $this->workspace->channels()->first();
    }

    private function say(User $user, Channel $channel, string $body, ?int $parentId = null): int
    {
        return $this->actingAs($user)
            ->postJson("/w/{$this->workspace->slug}/c/{$channel->id}/messages", ['body' => $body, 'parent_id' => $parentId])
            ->assertCreated()
            ->json('id');
    }

    private function dm(): Channel
    {
        $this->actingAs($this->owner)->post("/w/{$this->workspace->slug}/dm/{$this->member->id}");

        return Channel::where('type', Channel::DM)->first();
    }

    public function test_a_browser_can_subscribe_and_a_new_login_takes_it_over(): void
    {
        $this->actingAs($this->owner)->postJson('/me/push', self::SUBSCRIPTION)->assertNoContent();
        $this->actingAs($this->member)->postJson('/me/push', self::SUBSCRIPTION)->assertNoContent();

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($this->member->id, PushSubscription::first()->user_id);
    }

    public function test_subscription_must_be_https_and_complete(): void
    {
        $this->actingAs($this->owner)->postJson('/me/push', ['endpoint' => 'http://push.example.test/a', 'keys' => self::SUBSCRIPTION['keys']])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson('/me/push', ['endpoint' => self::SUBSCRIPTION['endpoint']])->assertUnprocessable();
    }

    public function test_only_the_owner_of_a_subscription_can_remove_it(): void
    {
        $this->actingAs($this->owner)->postJson('/me/push', self::SUBSCRIPTION);

        $this->actingAs($this->member)->deleteJson('/me/push', ['endpoint' => self::SUBSCRIPTION['endpoint']])->assertNoContent();
        $this->assertSame(1, PushSubscription::count());

        $this->actingAs($this->owner)->deleteJson('/me/push', ['endpoint' => self::SUBSCRIPTION['endpoint']])->assertNoContent();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_logging_out_stops_push_on_that_browser_only(): void
    {
        // A browser sends the same session cookie with every request.
        $this->withCredentials()->withCookie(config('session.cookie'), str_repeat('a', 40));

        $this->actingAs($this->owner)->postJson('/me/push', self::SUBSCRIPTION);
        PushSubscription::create([
            'user_id' => $this->owner->id,
            'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example.test/phone'),
            'endpoint' => 'https://push.example.test/phone',
            'public_key' => 'k',
            'auth_token' => 'a',
            'session_id' => 'another-device',
        ]);

        $this->actingAs($this->owner)->post('/logout');

        $this->assertSame(['another-device'], PushSubscription::pluck('session_id')->all());
    }

    public function test_dm_pushes_to_the_other_person_with_a_link(): void
    {
        Bus::fake([SendPushNotification::class]);
        $dm = $this->dm();

        $id = $this->say($this->owner, $dm, 'hello');

        Bus::assertDispatched(SendPushNotification::class, fn ($job) => $job->userIds === [$this->member->id]
            && $job->payload['title'] === 'Owner Person'
            && $job->payload['body'] === 'hello'
            && $job->payload['tag'] === "message-{$id}"
            && $job->payload['url'] === "/w/{$this->workspace->slug}/c/{$dm->id}");
    }

    public function test_ordinary_channel_message_pushes_nobody(): void
    {
        Bus::fake([SendPushNotification::class]);

        $this->say($this->owner, $this->general, 'hello');

        Bus::assertNotDispatched(SendPushNotification::class);
    }

    public function test_mention_and_thread_reply_push_and_link_to_the_thread(): void
    {
        Bus::fake([SendPushNotification::class]);

        $this->say($this->owner, $this->general, 'hi @Member Person');
        Bus::assertDispatched(SendPushNotification::class, fn ($job) => $job->userIds === [$this->member->id]
            && $job->payload['title'] === 'Owner Person in #general');

        $parent = $this->say($this->member, $this->general, 'parent');
        $this->say($this->owner, $this->general, 'reply', $parent);

        Bus::assertDispatched(SendPushNotification::class, fn ($job) => $job->userIds === [$this->member->id]
            && str_ends_with($job->payload['url'], "?thread={$parent}"));
    }

    public function test_notify_all_channel_pushes_webhook_alerts(): void
    {
        Bus::fake([SendPushNotification::class]);
        $this->actingAs($this->member)->post("/w/{$this->workspace->slug}/c/{$this->general->id}/notify");
        $url = $this->actingAs($this->owner)->postJson("/w/{$this->workspace->slug}/webhooks", [
            'name' => 'Ping Prepay',
            'channel_id' => $this->general->id,
        ])->json('url');

        $this->postJson($url, ['attachments' => [['title' => 'Invoice payment failed', 'color' => 'danger']]])->assertOk();

        Bus::assertDispatched(SendPushNotification::class, fn ($job) => $job->userIds === [$this->member->id]
            && $job->payload['body'] === 'Invoice payment failed');
    }

    public function test_muted_channel_stops_notify_all_but_not_mentions(): void
    {
        Bus::fake([SendPushNotification::class]);
        $base = "/w/{$this->workspace->slug}/c/{$this->general->id}";
        $this->actingAs($this->member)->post("{$base}/notify");
        $this->actingAs($this->member)->post("{$base}/mute");

        $this->say($this->owner, $this->general, 'ordinary message');
        Bus::assertNotDispatched(SendPushNotification::class);

        $this->say($this->owner, $this->general, 'hi @Member Person');
        Bus::assertDispatched(SendPushNotification::class, fn ($job) => $job->userIds === [$this->member->id]);
    }

    public function test_a_failing_push_service_does_not_break_the_job(): void
    {
        $this->mock(PushSender::class)->shouldReceive('send')->andThrow(new RuntimeException('down'));

        (new SendPushNotification([$this->member->id], ['title' => 'x']))->handle(app(PushSender::class));

        $this->assertTrue(true);
    }
}
