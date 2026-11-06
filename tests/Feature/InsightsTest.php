<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InsightsTest extends TestCase
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

    private function actions(): array
    {
        return AuditLog::orderBy('id')->pluck('action')->all();
    }

    public function test_analytics_is_admin_only(): void
    {
        $this->actingAs($this->member)->getJson($this->url('/analytics'))->assertForbidden();
        $this->actingAs($this->owner)->getJson($this->url('/analytics'))->assertOk();
        $this->actingAs($this->owner)->getJson($this->url('/analytics?days=12'))->assertUnprocessable();
    }

    public function test_analytics_counts_messages_by_day_and_fills_empty_days(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');

        $old = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'old']);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'a']);
        $this->general->messages()->create(['user_id' => $this->member->id, 'body' => 'b']);
        $this->general->messages()->create(['bot_name' => 'Alert', 'body' => 'c']);
        $this->general->messages()->create(['user_id' => $this->member->id, 'body' => '', 'removed_at' => now()]);

        $data = $this->actingAs($this->owner)->getJson($this->url('/analytics?days=7'))->assertOk()->json();

        $this->assertCount(7, $data['daily']);
        $this->assertSame(['day' => '2026-11-05', 'messages' => 3], $data['daily'][6]);
        $this->assertSame(0, $data['daily'][5]['messages']);
        $this->assertSame(1, $data['daily'][4]['messages']);
        $this->assertSame(4, $data['messages']);
        $this->assertSame(1, $data['bot_messages']);
        $this->assertSame(2, $data['active_members']);
        $this->assertSame(2, $data['members']);
    }

    public function test_period_leaves_out_older_messages(): void
    {
        $old = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'old']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $this->actingAs($this->owner)->getJson($this->url('/analytics?days=30'))->assertJsonPath('messages', 0);
        $this->actingAs($this->owner)->getJson($this->url('/analytics?days=90'))->assertJsonPath('messages', 1);
    }

    public function test_rankings_never_show_private_or_direct_activity(): void
    {
        $private = $this->workspace->channels()->create(['name' => 'secret-project', 'type' => Channel::PRIVATE]);
        $dm = $this->workspace->channels()->create(['type' => Channel::DM, 'dm_key' => '1-2']);

        foreach (range(1, 3) as $i) {
            $private->messages()->create(['user_id' => $this->member->id, 'body' => 'x']);
            $dm->messages()->create(['user_id' => $this->member->id, 'body' => 'x']);
        }
        $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'x']);

        $response = $this->actingAs($this->owner)->getJson($this->url('/analytics'))->assertOk();

        $this->assertSame(7, $response->json('messages'));
        $this->assertSame([['name' => 'general', 'total' => 1]], $response->json('top_channels'));
        $this->assertSame([['name' => 'Owner Person', 'total' => 1]], $response->json('top_members'));
        $this->assertStringNotContainsString('secret-project', $response->getContent());
    }

    public function test_audit_log_records_admin_actions_without_message_text(): void
    {
        $this->actingAs($this->owner)->post($this->url('/channels'), ['name' => 'random']);
        $random = Channel::where('name', 'random')->first();
        $this->actingAs($this->owner)->patch($this->url("/c/{$random->id}"), ['name' => 'news']);
        $this->actingAs($this->owner)->post($this->url("/c/{$random->id}/archive"));
        $this->actingAs($this->owner)->post($this->url("/c/{$random->id}/unarchive"));
        $this->actingAs($this->owner)->patchJson($this->url("/members/{$this->member->id}"), ['role' => 'admin']);
        $hook = $this->actingAs($this->owner)->postJson($this->url('/webhooks'), ['name' => 'Bot', 'channel_id' => $this->general->id])->json('webhook.id');
        $this->actingAs($this->owner)->deleteJson($this->url("/webhooks/{$hook}"));
        $this->actingAs($this->owner)->patch($this->url(), ['name' => 'Renamed']);

        $message = $this->general->messages()->create(['user_id' => $this->member->id, 'body' => 'very private words']);
        $this->actingAs($this->owner)->deleteJson($this->url("/c/{$this->general->id}/messages/{$message->id}"));

        $this->actingAs($this->owner)->deleteJson($this->url("/c/{$random->id}"));
        $this->actingAs($this->owner)->delete($this->url("/c/{$random->id}"));
        $this->actingAs($this->owner)->deleteJson($this->url("/members/{$this->member->id}"));

        $actions = $this->actions();

        foreach ([
            'member.joined', 'channel.created', 'channel.renamed', 'channel.archived', 'channel.restored',
            'member.role_changed', 'webhook.created', 'webhook.deleted', 'workspace.renamed',
            'message.moderated', 'channel.deleted', 'member.removed',
        ] as $action) {
            $this->assertContains($action, $actions);
        }

        $body = $this->actingAs($this->owner)->getJson($this->url('/audit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('very private words', $body);
    }

    public function test_deleting_your_own_message_is_not_moderation(): void
    {
        $message = $this->general->messages()->create(['user_id' => $this->owner->id, 'body' => 'x']);

        $this->actingAs($this->owner)->deleteJson($this->url("/c/{$this->general->id}/messages/{$message->id}"));

        $this->assertNotContains('message.moderated', $this->actions());
    }

    public function test_audit_log_is_admin_only_and_filters_and_pages(): void
    {
        $this->actingAs($this->member)->getJson($this->url('/audit'))->assertForbidden();

        foreach (range(1, 105) as $i) {
            AuditLog::record($this->workspace, $this->owner, 'test.action', "n{$i}");
        }

        $first = $this->actingAs($this->owner)->getJson($this->url('/audit?action=test.action'))->assertOk();
        $first->assertJsonCount(100, 'entries')->assertJsonPath('hasMore', true)->assertJsonPath('entries.0.detail', 'n105');

        $before = $first->json('entries.99.id');
        $this->actingAs($this->owner)->getJson($this->url("/audit?action=test.action&before={$before}"))
            ->assertJsonCount(5, 'entries')
            ->assertJsonPath('hasMore', false);
    }

    public function test_audit_keeps_the_actor_name_after_their_account_is_gone(): void
    {
        AuditLog::record($this->workspace, $this->member, 'test.action');

        $this->member->delete();

        $this->actingAs($this->owner)->getJson($this->url('/audit?action=test.action'))->assertJsonPath('entries.0.actor', 'Member Person');
    }

    public function test_creating_a_workspace_is_recorded(): void
    {
        $this->assertSame('workspace.created', AuditLog::orderBy('id')->value('action'));
    }

    public function test_exports_are_recorded(): void
    {
        $this->actingAs($this->owner)->post($this->url('/export'), ['password' => 'password'])->assertOk();

        $this->assertContains('workspace.exported', $this->actions());
    }
}
