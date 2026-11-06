<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Channel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Jobs\SendPushNotification;
use App\Services\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class AdminAndPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $member;

    private Channel $general;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->member = User::factory()->create(['name' => 'Member Person']);

        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Acme']);
        $this->workspace = Workspace::first();

        foreach ([$this->admin, $this->member] as $user) {
            $this->actingAs($user)->post('/workspaces/join', ['code' => $this->workspace->invite_code]);
        }

        $this->workspace->members()->updateExistingPivot($this->admin->id, ['role' => 'admin']);
        $this->general = $this->workspace->channels()->first();
    }

    private function url(string $path = ''): string
    {
        return "/w/{$this->workspace->slug}{$path}";
    }

    private function memberProps(User $viewer, User $about): array
    {
        $members = $this->actingAs($viewer)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props']['members'];

        return collect($members)->firstWhere('id', $about->id);
    }

    public function test_only_admins_can_list_members(): void
    {
        $this->actingAs($this->member)->getJson($this->url('/members'))->assertForbidden();

        $this->actingAs($this->admin)->getJson($this->url('/members'))
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonFragment(['id' => $this->owner->id, 'role' => 'owner'])
            ->assertJsonFragment(['id' => $this->admin->id, 'role' => 'admin']);
    }

    public function test_only_the_owner_changes_roles_and_never_their_own(): void
    {
        $url = $this->url("/members/{$this->member->id}");

        $this->actingAs($this->admin)->patchJson($url, ['role' => 'admin'])->assertForbidden();
        $this->actingAs($this->owner)->patchJson($url, ['role' => 'owner'])->assertUnprocessable();
        $this->actingAs($this->owner)->patchJson($url, ['role' => 'admin'])->assertNoContent();
        $this->assertTrue($this->workspace->isAdmin($this->member));

        $this->actingAs($this->owner)->patchJson($this->url("/members/{$this->owner->id}"), ['role' => 'member'])->assertStatus(422);
    }

    public function test_admin_powers_cover_channels_webhooks_and_moderation(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'random']);
        $random = Channel::where('name', 'random')->first();
        $message = $this->general->messages()->create(['user_id' => $this->member->id, 'body' => 'x']);

        $this->actingAs($this->admin)->patch($this->url("/c/{$random->id}"), ['name' => 'renamed'])->assertRedirect();
        $this->actingAs($this->admin)->getJson($this->url('/webhooks'))->assertOk();
        $this->actingAs($this->admin)->deleteJson($this->url("/c/{$this->general->id}/messages/{$message->id}"))->assertNoContent();
        $this->actingAs($this->admin)->patch($this->url(), ['name' => 'New name'])->assertRedirect();
    }

    public function test_only_the_owner_deletes_the_workspace(): void
    {
        $this->actingAs($this->admin)->delete($this->url(), ['confirm_name' => 'Acme'])->assertForbidden();
    }

    public function test_removing_a_member_takes_away_all_access(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'team', 'is_private' => true]);
        $team = Channel::where('name', 'team')->first();
        $team->members()->attach($this->member->id);
        $message = $this->general->messages()->create(['user_id' => $this->member->id, 'body' => 'stays']);
        Activity::create(['user_id' => $this->member->id, 'workspace_id' => $this->workspace->id, 'message_id' => $message->id, 'type' => 'mention']);

        $this->actingAs($this->admin)->deleteJson($this->url("/members/{$this->member->id}"))->assertNoContent();

        $this->assertFalse($this->workspace->hasMember($this->member));
        $this->assertFalse($this->general->hasMember($this->member));
        $this->assertFalse($team->hasMember($this->member));
        $this->assertSame(0, Activity::count());
        $this->assertNotNull($message->fresh());

        $this->actingAs($this->member)->get($this->url("/c/{$this->general->id}"))->assertForbidden();
    }

    public function test_removal_rules_protect_owner_admins_and_yourself(): void
    {
        $other = User::factory()->create();
        $this->workspace->members()->attach($other->id, ['role' => 'admin']);

        $this->actingAs($this->member)->deleteJson($this->url("/members/{$this->admin->id}"))->assertForbidden();
        $this->actingAs($this->admin)->deleteJson($this->url("/members/{$other->id}"))->assertForbidden();
        $this->actingAs($this->admin)->deleteJson($this->url("/members/{$this->owner->id}"))->assertStatus(422);
        $this->actingAs($this->admin)->deleteJson($this->url("/members/{$this->admin->id}"))->assertStatus(422);

        $this->actingAs($this->owner)->deleteJson($this->url("/members/{$other->id}"))->assertNoContent();
    }

    public function test_preferences_save_and_validate(): void
    {
        $this->actingAs($this->member)->putJson('/me/preferences', [
            'theme' => 'dark',
            'timezone' => 'Asia/Kolkata',
            'title' => 'Developer',
            'phone' => '+91 98765',
            'dnd_from' => '22:00',
            'dnd_to' => '07:00',
        ])->assertNoContent();

        $this->assertSame('dark', $this->member->refresh()->theme);
        $this->assertSame('Developer', $this->memberProps($this->owner, $this->member)['title']);

        $this->actingAs($this->member)->putJson('/me/preferences', ['theme' => 'neon'])->assertUnprocessable();
        $this->actingAs($this->member)->putJson('/me/preferences', ['theme' => 'dark', 'timezone' => 'Mars/Base'])->assertUnprocessable();
        $this->actingAs($this->member)->putJson('/me/preferences', ['theme' => 'dark', 'dnd_from' => '22:00'])->assertUnprocessable();
    }

    public function test_preferences_accept_the_older_timezone_names_browsers_send(): void
    {
        $this->actingAs($this->member)
            ->putJson('/me/preferences', ['theme' => 'dark', 'timezone' => 'Asia/Calcutta'])
            ->assertNoContent();

        $this->member->update(['dnd_from' => '22:00', 'dnd_to' => '07:00']);
        Carbon::setTestNow(Carbon::parse('2026-11-05 23:30', 'Asia/Kolkata'));

        $this->assertTrue($this->member->refresh()->isDnd());
    }

    public function test_pause_sets_and_clears_do_not_disturb(): void
    {
        $this->actingAs($this->member)->postJson('/me/pause', ['minutes' => 60])->assertNoContent();
        $this->assertTrue($this->member->refresh()->isDnd());
        $this->assertTrue($this->memberProps($this->owner, $this->member)['in_dnd']);

        $this->actingAs($this->member)->postJson('/me/pause', ['minutes' => 0])->assertNoContent();
        $this->assertFalse($this->member->refresh()->isDnd());

        $this->actingAs($this->member)->postJson('/me/pause', ['minutes' => 7])->assertUnprocessable();
    }

    public function test_pause_ends_by_itself(): void
    {
        $this->member->update(['dnd_until' => now()->addMinutes(30)]);

        Carbon::setTestNow(now()->addMinutes(31));

        $this->assertFalse($this->member->refresh()->isDnd());
    }

    public function test_daily_schedule_uses_the_persons_timezone_and_crosses_midnight(): void
    {
        $this->member->update(['timezone' => 'Asia/Kolkata', 'dnd_from' => '22:00', 'dnd_to' => '07:00']);

        Carbon::setTestNow(Carbon::parse('2026-11-05 23:30', 'Asia/Kolkata'));
        $this->assertTrue($this->member->refresh()->isDnd());

        Carbon::setTestNow(Carbon::parse('2026-11-06 06:59', 'Asia/Kolkata'));
        $this->assertTrue($this->member->refresh()->isDnd());

        Carbon::setTestNow(Carbon::parse('2026-11-06 07:00', 'Asia/Kolkata'));
        $this->assertFalse($this->member->refresh()->isDnd());

        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00', 'Asia/Kolkata'));
        $this->assertFalse($this->member->refresh()->isDnd());
    }

    public function test_paused_people_get_no_notification_but_keep_their_activity(): void
    {
        $this->member->update(['dnd_until' => now()->addHour()]);
        PushSubscription::create([
            'user_id' => $this->member->id,
            'endpoint_hash' => 'a',
            'endpoint' => 'https://push.example.test/a',
            'public_key' => 'k',
            'auth_token' => 't',
        ]);

        $sender = Mockery::mock(PushSender::class);
        $sender->shouldReceive('send')->once()->withArgs(fn (Collection $subs) => $subs->isEmpty())->andReturn(['sent' => 0, 'failed' => 0]);

        (new SendPushNotification([$this->member->id], ['title' => 'x']))->handle($sender);

        $this->actingAs($this->owner)
            ->postJson($this->url("/c/{$this->general->id}/messages"), ['body' => 'hi @Member Person'])
            ->assertCreated();

        $this->assertSame(1, Activity::where('user_id', $this->member->id)->count());
    }

    public function test_status_clears_itself_after_its_time(): void
    {
        $this->actingAs($this->member)
            ->patchJson('/me/status', ['status_emoji' => '🍽️', 'status_text' => 'Lunch', 'expires_in' => 30])
            ->assertNoContent();

        $this->assertSame('Lunch', $this->memberProps($this->owner, $this->member)['status_text']);

        Carbon::setTestNow(now()->addMinutes(31));

        $seen = $this->memberProps($this->owner, $this->member);
        $this->assertNull($seen['status_text']);
        $this->assertNull($seen['status_emoji']);
    }

    public function test_status_without_a_time_stays_and_bad_times_are_refused(): void
    {
        $this->actingAs($this->member)->patchJson('/me/status', ['status_text' => 'Remote'])->assertNoContent();

        Carbon::setTestNow(now()->addDays(30));
        $this->assertSame('Remote', $this->memberProps($this->owner, $this->member)['status_text']);

        $this->actingAs($this->member)->patchJson('/me/status', ['status_text' => 'x', 'expires_in' => 5])->assertUnprocessable();
    }
}
