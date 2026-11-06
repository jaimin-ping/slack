<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\Channel;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PhonePushSender;
use App\Services\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MobileApiTest extends TestCase
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
        $this->actingAs($this->member)->post('/workspaces/join', ['code' => $this->workspace->invite_code]);
        $this->general = $this->workspace->channels()->first();

        // The set-up used web sessions; the tests below must stand on the token alone.
        $this->app['auth']->forgetGuards();
    }

    private function token(string $email = 'member@example.test'): string
    {
        return $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'password', 'device_name' => 'Pixel'])
            ->assertOk()
            ->json('token');
    }

    private function api(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/w/{$this->workspace->slug}{$path}";
    }

    public function test_login_returns_a_token_and_never_the_password(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'member@example.test',
            'password' => 'password',
            'device_name' => 'Pixel',
        ]);

        $response->assertOk()->assertJsonPath('user.id', $this->member->id)->assertJsonMissingPath('user.password');
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_wrong_password_and_unknown_email_look_the_same(): void
    {
        $wrong = $this->postJson('/api/v1/login', ['email' => 'member@example.test', 'password' => 'nope', 'device_name' => 'x']);
        $unknown = $this->postJson('/api/v1/login', ['email' => 'ghost@example.test', 'password' => 'nope', 'device_name' => 'x']);

        $wrong->assertUnprocessable();
        $unknown->assertUnprocessable();
        $this->assertSame($wrong->json('message'), $unknown->json('message'));
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/login', ['email' => 'member@example.test', 'password' => 'nope', 'device_name' => 'x'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/login', ['email' => 'member@example.test', 'password' => 'nope', 'device_name' => 'x'])->assertTooManyRequests();
    }

    public function test_requests_without_a_token_are_refused(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_logout_revokes_only_this_device(): void
    {
        $phone = $this->token();
        $tablet = $this->token();

        $this->api($phone)->postJson('/api/v1/logout')->assertNoContent();

        $this->api($phone)->getJson('/api/v1/me')->assertUnauthorized();
        $this->api($tablet)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_workspace_bootstrap_lists_channels_and_members(): void
    {
        $token = $this->token();

        $this->api($token)->getJson('/api/v1/workspaces')->assertJsonCount(1)->assertJsonPath('0.slug', $this->workspace->slug);

        $this->api($token)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('workspace.name', 'Acme')
            ->assertJsonPath('channels.0.name', 'general')
            ->assertJsonCount(2, 'members');
    }

    public function test_full_conversation_works_with_a_token(): void
    {
        $token = $this->token();
        $base = "/c/{$this->general->id}/messages";

        $id = $this->api($token)->postJson($this->url($base), ['body' => 'from phone'])
            ->assertCreated()
            ->assertJsonPath('user.id', $this->member->id)
            ->json('id');

        $this->api($token)->getJson($this->url($base))->assertJsonPath('messages.0.body', 'from phone');
        $this->api($token)->postJson($this->url("$base/{$id}/reactions"), ['emoji' => '🔥'])->assertOk();
        $this->api($token)->postJson($this->url($base), ['body' => 'reply', 'parent_id' => $id])->assertCreated();
        $this->api($token)->getJson($this->url("$base/{$id}/thread"))->assertJsonCount(1, 'replies');
        $this->api($token)->patchJson($this->url("$base/{$id}"), ['body' => 'edited'])->assertOk();
        $this->api($token)->postJson($this->url("/c/{$this->general->id}/read"))->assertNoContent();
        $this->api($token)->getJson($this->url('/search?q=edited'))->assertJsonCount(1);
    }

    public function test_direct_message_returns_the_channel_id(): void
    {
        $response = $this->api($this->token())->postJson($this->url("/dm/{$this->owner->id}"));

        $response->assertOk()->assertJsonPath('channel_id', Channel::where('type', Channel::DM)->first()->id);
    }

    public function test_token_cannot_reach_private_channels_or_other_workspaces(): void
    {
        $this->actingAs($this->owner)->post("/w/{$this->workspace->slug}/channels", ['name' => 'secret', 'is_private' => true]);
        $secret = Channel::where('name', 'secret')->first();

        $outsider = User::factory()->create(['email' => 'out@example.test']);

        $member = $this->token();
        $this->api($member)->getJson($this->url("/c/{$secret->id}/messages"))->assertForbidden();

        $token = $this->token('out@example.test');
        $this->api($token)->getJson($this->url())->assertForbidden();
        $this->api($token)->getJson('/api/v1/workspaces')->assertJsonCount(0);
        $this->assertNotNull($outsider);
    }

    public function test_channels_can_be_created_joined_and_left_with_a_token(): void
    {
        $token = $this->token();

        $id = $this->api($token)->postJson($this->url('/channels'), ['name' => 'phone-team'])->assertOk()->json('channel_id');
        $this->assertNotNull(Channel::find($id));

        $this->api($token)->postJson($this->url("/c/{$id}/leave"))->assertNoContent();
        $this->api($token)->getJson($this->url('/browse'))->assertJsonFragment(['id' => $id, 'joined' => false]);
        $this->api($token)->postJson($this->url("/c/{$id}/join"))->assertOk()->assertJsonPath('channel_id', $id);
        $this->api($token)->postJson($this->url("/c/{$id}/mute"))->assertNoContent();
    }

    public function test_personal_actions_and_canvas_work_with_a_token(): void
    {
        $token = $this->token();
        $base = "/c/{$this->general->id}";
        $message = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'keep']);

        $this->api($token)->patchJson('/api/v1/me/status', ['status_emoji' => '🌴', 'status_text' => 'Away'])->assertNoContent();
        $this->api($token)->postJson('/api/v1/me/away')->assertNoContent();
        $this->api($token)->postJson('/api/v1/me/pause', ['minutes' => 60])->assertNoContent();

        $this->api($token)->postJson($this->url("$base/messages/{$message->id}/pin"))->assertJsonPath('pinned', true);
        $this->api($token)->getJson($this->url("$base/pins"))->assertJsonCount(1);
        $this->api($token)->postJson($this->url("$base/messages/{$message->id}/save"))->assertJsonPath('saved', true);
        $this->api($token)->getJson($this->url('/saved'))->assertJsonCount(1);

        $this->api($token)->putJson($this->url("$base/canvas"), ['body' => 'notes', 'version' => 0])->assertJsonPath('version', 1);
        $this->api($token)->getJson($this->url("$base/canvas"))->assertJsonPath('body', 'notes');
        $this->api($token)->postJson($this->url("$base/command"), ['text' => '/help'])->assertOk();
    }

    public function test_phone_token_registers_and_is_removed_on_logout(): void
    {
        $token = $this->token();
        $device = 'ExponentPushToken[abc123]';

        $this->api($token)->postJson('/api/v1/device', ['token' => 'nonsense'])->assertUnprocessable();
        $this->api($token)->postJson('/api/v1/device', ['token' => $device])->assertNoContent();
        $this->assertSame(1, DeviceToken::count());

        $this->api($token)->postJson('/api/v1/logout')->assertNoContent();
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_phone_notification_reaches_expo_and_skips_paused_people(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
        DeviceToken::create(['user_id' => $this->member->id, 'token' => 'ExponentPushToken[a]']);
        DeviceToken::create(['user_id' => $this->owner->id, 'token' => 'ExponentPushToken[b]']);
        $this->owner->update(['dnd_until' => now()->addHour()]);

        $job = new SendPushNotification([$this->member->id, $this->owner->id], [
            'title' => 'Asha',
            'body' => 'hello',
            'slug' => 'acme',
            'channel_id' => 5,
            'thread' => null,
        ]);
        $job->handle(app(PushSender::class), app(PhonePushSender::class));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => count($request->data()) === 1
            && $request->data()[0]['to'] === 'ExponentPushToken[a]'
            && $request->data()[0]['data']['channel_id'] === 5);
    }

    public function test_unregistered_phone_is_forgotten(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [
            ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
        ]])]);
        DeviceToken::create(['user_id' => $this->member->id, 'token' => 'ExponentPushToken[gone]']);

        app(PhonePushSender::class)->send(DeviceToken::all(), ['title' => 'x']);

        $this->assertSame(0, DeviceToken::count());
    }

    public function test_drafts_schedule_and_sections_work_with_a_token(): void
    {
        $token = $this->token();
        $base = "/c/{$this->general->id}";

        $this->api($token)->putJson($this->url("$base/draft"), ['body' => 'half'])->assertNoContent();
        $this->api($token)->getJson($this->url('/drafts'))->assertJsonCount(1)->assertJsonPath('0.body', 'half');

        $id = $this->api($token)->postJson($this->url("$base/schedule"), [
            'body' => 'later',
            'send_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated()->json('id');

        $this->api($token)->getJson($this->url('/drafts'))->assertJsonCount(0);
        $this->api($token)->getJson($this->url('/scheduled'))->assertJsonCount(1);
        $this->api($token)->deleteJson($this->url("/scheduled/{$id}"))->assertNoContent();

        $section = $this->api($token)->postJson($this->url('/sections'), ['name' => 'Work'])->assertCreated()->json('id');
        $this->api($token)->postJson($this->url("$base/section"), ['section_id' => $section])->assertNoContent();

        $this->api($token)->getJson($this->url())
            ->assertJsonPath('channels.0.section_id', $section)
            ->assertJsonPath('extras.sections.0.name', 'Work');
    }

    public function test_files_download_with_a_token(): void
    {
        Storage::fake('local');
        $token = $this->token();

        $file = $this->api($token)->post($this->url("/c/{$this->general->id}/messages"), [
            'files' => [UploadedFile::fake()->image('a.png')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('attachments.0');

        $this->api($token)->get('/api/v1/attachments/'.$file['id'])->assertOk();
    }
}
