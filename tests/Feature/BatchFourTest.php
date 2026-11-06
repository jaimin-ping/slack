<?php

namespace Tests\Feature;

use App\Mail\WorkspaceInvite;
use App\Models\Activity;
use App\Models\Channel;
use App\Models\CustomEmoji;
use App\Models\Reminder;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BatchFourTest extends TestCase
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
        $this->actingAs($this->member)->post('/workspaces/join', ['code' => $this->workspace->invite_code]);
        $this->general = $this->workspace->channels()->first();
    }

    private function url(string $path = ''): string
    {
        return "/w/{$this->workspace->slug}{$path}";
    }

    private function command(User $user, string $text, ?Channel $channel = null)
    {
        return $this->actingAs($user)->postJson($this->url('/c/'.($channel ?? $this->general)->id.'/command'), ['text' => $text]);
    }

    public function test_scheduled_message_waits_and_then_sends_once(): void
    {
        $this->actingAs($this->member)->postJson($this->url("/c/{$this->general->id}/schedule"), [
            'body' => 'later',
            'send_at' => now()->addMinutes(10)->toIso8601String(),
        ])->assertCreated();

        $this->artisan('chat:run-due');
        $this->assertSame(0, $this->general->messages()->count());

        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('chat:run-due');
        $this->artisan('chat:run-due');

        $this->assertSame(1, $this->general->messages()->count());
        $this->assertSame($this->member->id, $this->general->messages()->first()->user_id);
    }

    public function test_scheduling_clears_the_saved_draft(): void
    {
        $this->actingAs($this->member)->putJson($this->url("/c/{$this->general->id}/draft"), ['body' => 'later'])->assertNoContent();

        $this->actingAs($this->member)->postJson($this->url("/c/{$this->general->id}/schedule"), [
            'body' => 'later',
            'send_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated();

        $this->actingAs($this->member)->getJson($this->url('/drafts'))->assertJsonCount(0);
    }

    public function test_schedule_rejects_past_times_and_outsiders(): void
    {
        $url = $this->url("/c/{$this->general->id}/schedule");

        $this->actingAs($this->member)->postJson($url, ['body' => 'x', 'send_at' => now()->subMinute()->toIso8601String()])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson($url, ['body' => 'x', 'send_at' => now()->addHour()->toIso8601String()])->assertForbidden();
    }

    public function test_scheduled_message_is_listed_cancelled_and_private(): void
    {
        $id = $this->actingAs($this->member)->postJson($this->url("/c/{$this->general->id}/schedule"), [
            'body' => 'later',
            'send_at' => now()->addHour()->toIso8601String(),
        ])->json('id');

        $this->actingAs($this->member)->getJson($this->url('/scheduled'))->assertJsonCount(1);
        $this->actingAs($this->owner)->getJson($this->url('/scheduled'))->assertJsonCount(0);

        $this->actingAs($this->owner)->deleteJson($this->url("/scheduled/{$id}"))->assertForbidden();
        $this->actingAs($this->member)->deleteJson($this->url("/scheduled/{$id}"))->assertNoContent();
        $this->assertSame(0, ScheduledMessage::count());
    }

    public function test_scheduled_message_fails_safely_after_leaving_the_channel(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'random']);
        $random = Channel::where('name', 'random')->first();
        $this->actingAs($this->member)->postJson($this->url("/c/{$random->id}/schedule"), [
            'body' => 'later',
            'send_at' => now()->addMinute()->toIso8601String(),
        ]);
        $this->actingAs($this->member)->post($this->url("/c/{$random->id}/leave"));

        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('chat:run-due');

        $this->assertSame(0, $random->messages()->count());
        $this->assertTrue(ScheduledMessage::first()->failed);
    }

    public function test_reminder_arrives_in_notes_to_self(): void
    {
        $this->command($this->member, '/remind 30m Call the bank')->assertOk()->assertJsonPath('text', fn ($text) => str_starts_with($text, "Okay, I'll remind you"));

        $this->artisan('chat:run-due');
        $this->assertNull(Channel::where('type', Channel::DM)->first());

        Carbon::setTestNow(now()->addMinutes(31));
        $this->artisan('chat:run-due');

        $dm = Channel::where('type', Channel::DM)->first();
        $this->assertSame("{$this->member->id}-{$this->member->id}", $dm->dm_key);
        $this->assertSame('⏰ Call the bank', $dm->messages()->first()->body);
        $this->assertSame('Reminder', $dm->messages()->first()->bot_name);
        $this->actingAs($this->member)->get($this->url("/c/{$dm->id}"))->assertOk();
        $this->actingAs($this->owner)->get($this->url("/c/{$dm->id}"))->assertForbidden();
    }

    public function test_remind_understands_units_and_rejects_nonsense(): void
    {
        foreach (['2h Stand up' => 120, '1 day Pay rent' => 1440] as $args => $minutes) {
            Carbon::setTestNow('2026-11-05 10:00:00');
            $this->command($this->member, "/remind {$args}");
            $this->assertSame($minutes, (int) now()->diffInMinutes(Reminder::latest('id')->first()->remind_at));
        }

        $this->command($this->member, '/remind soon stuff')->assertJsonPath('text', 'Try: /remind 30m Call the bank');
        $this->command($this->member, '/remind 0m nothing')->assertJsonPath('text', 'Choose a time between 1 minute and 1 year.');
        $this->assertSame(2, Reminder::count());
    }

    public function test_slash_commands_work_and_unknown_ones_explain(): void
    {
        $this->command($this->member, '/me is at lunch')->assertJsonPath('message.body', '_is at lunch_');
        $this->command($this->member, '/topic Daily updates')->assertJsonPath('reload', true);
        $this->assertSame('Daily updates', $this->general->refresh()->topic);

        $this->command($this->member, '/mute')->assertJsonPath('text', 'Channel muted.');
        $this->command($this->member, '/away')->assertJsonPath('text', 'You are now away.');
        $this->command($this->member, '/help')->assertJsonPath('text', fn ($text) => str_contains($text, '/remind'));
        $this->command($this->member, '/nope x')->assertJsonPath('text', '/nope is not a command. Type /help to see what works.');
        $this->command(User::factory()->create(), '/help')->assertForbidden();
    }

    public function test_group_mention_reaches_channel_members_in_the_group(): void
    {
        $third = User::factory()->create();
        $this->workspace->members()->attach($third->id);

        $this->actingAs($this->owner)->postJson($this->url('/groups'), [
            'handle' => 'support',
            'member_ids' => [$this->member->id, $third->id],
        ])->assertCreated();

        $this->actingAs($this->owner)->postJson($this->url("/c/{$this->general->id}/messages"), ['body' => 'ping @support'])->assertCreated();

        $this->assertSame([$this->member->id], Activity::pluck('user_id')->all());
    }

    public function test_groups_are_admin_only_with_safe_handles(): void
    {
        $body = ['handle' => 'dev', 'member_ids' => [$this->member->id]];

        $this->actingAs($this->member)->postJson($this->url('/groups'), $body)->assertForbidden();
        $this->actingAs($this->owner)->postJson($this->url('/groups'), [...$body, 'handle' => 'channel'])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson($this->url('/groups'), [...$body, 'handle' => 'Bad Name'])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson($this->url('/groups'), $body)->assertCreated();
        $this->actingAs($this->owner)->postJson($this->url('/groups'), $body)->assertUnprocessable();

        $group = UserGroup::first();
        $this->actingAs($this->owner)->deleteJson($this->url("/groups/{$group->id}"))->assertNoContent();
    }

    public function test_custom_emoji_upload_and_access(): void
    {
        Storage::fake('local');

        $this->actingAs($this->member)->post($this->url('/emoji'), [
            'name' => 'party-cat',
            'image' => UploadedFile::fake()->image('cat.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $emoji = CustomEmoji::first();
        $extras = $this->actingAs($this->owner)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props']['extras'];

        $this->assertSame('party-cat', $extras['emojis'][0]['name']);
        $this->actingAs($this->owner)->get($extras['emojis'][0]['url'])->assertOk();
        $this->actingAs(User::factory()->create())->get($extras['emojis'][0]['url'])->assertNotFound();

        $this->actingAs($this->owner)->deleteJson($this->url("/emoji/{$emoji->id}"))->assertNoContent();
        Storage::disk('local')->assertMissing($emoji->path);
    }

    public function test_custom_emoji_rejects_svg_big_files_and_bad_names(): void
    {
        Storage::fake('local');
        $json = ['Accept' => 'application/json'];
        $url = $this->url('/emoji');

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->actingAs($this->member)->post($url, ['name' => 'ok', 'image' => $svg], $json)->assertUnprocessable();
        $this->actingAs($this->member)->post($url, ['name' => 'ok', 'image' => UploadedFile::fake()->image('a.png')->size(257)], $json)->assertUnprocessable();
        $this->actingAs($this->member)->post($url, ['name' => 'Bad Name', 'image' => UploadedFile::fake()->image('a.png')], $json)->assertUnprocessable();
    }

    public function test_sidebar_sections_are_personal(): void
    {
        $id = $this->actingAs($this->member)->postJson($this->url('/sections'), ['name' => 'Work'])->assertCreated()->json('id');

        $this->actingAs($this->member)->postJson($this->url("/c/{$this->general->id}/section"), ['section_id' => $id])->assertNoContent();
        $this->actingAs($this->owner)->postJson($this->url("/c/{$this->general->id}/section"), ['section_id' => $id])->assertForbidden();

        $sidebar = fn (User $user) => collect($this->actingAs($user)->get($this->url("/c/{$this->general->id}"))->viewData('page')['props']['sidebar'])->first();

        $this->assertSame($id, $sidebar($this->member)['section_id']);
        $this->assertNull($sidebar($this->owner)['section_id']);

        $this->actingAs($this->member)->deleteJson($this->url("/sections/{$id}"))->assertNoContent();
        $this->assertNull($sidebar($this->member)['section_id']);
    }

    public function test_email_invite_is_admin_only_and_carries_the_link(): void
    {
        Mail::fake();

        $this->actingAs($this->member)->postJson($this->url('/invites'), ['emails' => ['a@example.test']])->assertForbidden();
        $this->actingAs($this->owner)->postJson($this->url('/invites'), ['emails' => ['bad']])->assertUnprocessable();

        $this->actingAs($this->owner)->postJson($this->url('/invites'), ['emails' => ['a@example.test', 'a@example.test', 'b@example.test']])
            ->assertJsonPath('sent', 2);

        Mail::assertSent(WorkspaceInvite::class, 2);
        Mail::assertSent(WorkspaceInvite::class, fn ($mail) => str_contains($mail->render(), $this->workspace->invite_code));
    }

    public function test_daily_workflow_runs_once_a_day_at_its_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 08:00', 'Asia/Kolkata'));

        $this->actingAs($this->owner)->postJson($this->url('/workflows'), [
            'name' => 'Standup',
            'trigger' => 'daily',
            'time' => '09:30',
            'timezone' => 'Asia/Kolkata',
            'steps' => [['type' => 'message', 'channel_id' => $this->general->id, 'text' => 'Time for standup']],
        ])->assertCreated();

        $this->artisan('chat:run-due');
        $this->assertSame(0, $this->general->messages()->count());

        Carbon::setTestNow(Carbon::parse('2026-11-05 09:31', 'Asia/Kolkata'));
        $this->artisan('chat:run-due');
        $this->artisan('chat:run-due');
        $this->assertSame(1, $this->general->messages()->count());
        $this->assertSame('Standup', $this->general->messages()->first()->bot_name);

        Carbon::setTestNow(Carbon::parse('2026-11-06 09:31', 'Asia/Kolkata'));
        $this->artisan('chat:run-due');
        $this->assertSame(2, $this->general->messages()->count());
    }

    public function test_disabled_workflow_stays_quiet_and_workflows_are_admin_only(): void
    {
        $body = ['name' => 'x', 'trigger' => 'daily', 'time' => '00:00', 'steps' => [['type' => 'message', 'channel_id' => $this->general->id, 'text' => 'm']]];

        $this->actingAs($this->member)->postJson($this->url('/workflows'), $body)->assertForbidden();
        $id = $this->actingAs($this->owner)->postJson($this->url('/workflows'), $body)->json('id');
        $this->actingAs($this->owner)->postJson($this->url("/workflows/{$id}/toggle"))->assertJsonPath('enabled', false);

        $this->artisan('chat:run-due');

        $this->assertSame(0, $this->general->messages()->count());
    }

    public function test_welcome_workflow_greets_new_members_only_once(): void
    {
        Workflow::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Welcome',
            'trigger' => 'member_joined',
            'steps' => [['type' => 'message', 'channel_id' => $this->general->id, 'text' => 'Welcome {name}!']],
        ]);

        $person = User::factory()->create(['name' => 'New Person']);
        $this->actingAs($person)->post('/workspaces/join', ['code' => $this->workspace->invite_code]);
        $this->actingAs($person)->post('/workspaces/join', ['code' => $this->workspace->invite_code]);

        $this->assertSame(['Welcome New Person!'], $this->general->messages()->pluck('body')->all());
    }

    public function test_remind_list_shows_numbers_and_cancel_uses_them(): void
    {
        $this->command($this->member, '/remind 30m Call the bank');
        $this->command($this->member, '/remind 1h Water the plants');
        [$first, $second] = Reminder::orderBy('id')->get()->all();

        $this->command($this->member, '/remind list')->assertJsonPath('text', fn ($text) => str_contains($text, "#{$first->id} ") && str_contains($text, 'Call the bank'));

        $this->command($this->member, "/remind cancel {$first->id}")->assertJsonPath('text', 'Reminder cancelled: Call the bank');
        $this->assertNull(Reminder::find($first->id));
        $this->assertNotNull(Reminder::find($second->id));

        Carbon::setTestNow(now()->addMinutes(61));
        $this->artisan('chat:run-due');

        $this->assertDatabaseMissing('messages', ['body' => '⏰ Call the bank']);
    }

    public function test_remind_cancel_explains_mistakes_and_a_reminder_named_cancel_still_works(): void
    {
        $this->command($this->member, '/remind cancel')->assertJsonPath('text', fn ($text) => str_starts_with($text, 'Try: /remind cancel'));
        $this->command($this->member, '/remind cancel 999')->assertJsonPath('text', fn ($text) => str_starts_with($text, 'You have no reminder #999'));

        $this->command($this->member, '/remind 5m cancel 12 gym')->assertJsonPath('text', fn ($text) => str_starts_with($text, "Okay, I'll"));
        $this->assertSame('cancel 12 gym', Reminder::first()->text);
    }

    public function test_nobody_can_cancel_someone_elses_reminder(): void
    {
        $this->command($this->member, '/remind 30m Mine');
        $reminder = Reminder::first();

        $this->command($this->owner, "/remind cancel {$reminder->id}")->assertJsonPath('text', fn ($text) => str_starts_with($text, 'You have no reminder'));
        $this->actingAs($this->owner)->deleteJson($this->url("/reminders/{$reminder->id}"))->assertForbidden();
        $this->assertNotNull(Reminder::find($reminder->id));
    }

    public function test_reminders_list_and_cancel_from_the_screen(): void
    {
        $this->command($this->member, '/remind 30m Call the bank');
        $this->command($this->owner, '/remind 30m Not yours');
        $reminder = Reminder::where('user_id', $this->member->id)->first();

        $this->actingAs($this->member)->getJson($this->url('/reminders'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.text', 'Call the bank');

        $this->actingAs($this->member)->deleteJson($this->url("/reminders/{$reminder->id}"))->assertNoContent();
        $this->actingAs($this->member)->getJson($this->url('/reminders'))->assertJsonCount(0);
    }

    public function test_finished_reminders_are_not_listed_or_cancelled(): void
    {
        $this->command($this->member, '/remind 1m Done already');
        $reminder = Reminder::first();

        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('chat:run-due');

        $this->actingAs($this->member)->getJson($this->url('/reminders'))->assertJsonCount(0);
        $this->command($this->member, "/remind cancel {$reminder->id}")->assertJsonPath('text', fn ($text) => str_starts_with($text, 'You have no reminder'));
    }
}
