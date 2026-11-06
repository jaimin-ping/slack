<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;
use ZipArchive;

class SecurityAndExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'me@example.test']);
    }

    private function code(string $secret, int $offsetSteps = 0): string
    {
        $engine = new Google2FA;

        return $engine->oathTotp($secret, $engine->getTimestamp() + $offsetSteps);
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function enable(): array
    {
        $secret = $this->actingAs($this->user)
            ->postJson('/me/two-factor/setup', ['password' => 'password'])
            ->assertOk()
            ->json('secret');

        $codes = $this->actingAs($this->user)
            ->postJson('/me/two-factor/confirm', ['code' => $this->code($secret)])
            ->assertOk()
            ->json('recovery_codes');

        Auth::logout();
        $this->user->refresh();

        return [$secret, $codes];
    }

    public function test_setup_needs_the_password_and_a_right_code(): void
    {
        $this->actingAs($this->user)->postJson('/me/two-factor/setup', ['password' => 'wrong'])->assertUnprocessable();

        $response = $this->actingAs($this->user)->postJson('/me/two-factor/setup', ['password' => 'password'])->assertOk();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('qr'));

        $this->actingAs($this->user)->postJson('/me/two-factor/confirm', ['code' => '000000'])->assertUnprocessable();
        $this->assertFalse($this->user->refresh()->two_factor_enabled);
    }

    public function test_confirming_gives_recovery_codes_that_are_stored_hashed(): void
    {
        [, $codes] = $this->enable();

        $this->assertCount(8, $codes);
        $this->assertTrue($this->user->two_factor_enabled);
        $this->assertNotContains($codes[0], $this->user->two_factor_recovery_codes);
    }

    public function test_secrets_never_reach_the_page(): void
    {
        $this->enable();

        $auth = $this->user->refresh()->toArray();

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step'] as $field) {
            $this->assertArrayNotHasKey($field, $auth);
        }
        $this->assertTrue($auth['two_factor_enabled']);
    }

    public function test_password_alone_no_longer_signs_in(): void
    {
        $this->enable();

        $this->post('/login', ['email' => 'me@example.test', 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_right_code_finishes_the_sign_in(): void
    {
        [$secret] = $this->enable();

        $this->post('/login', ['email' => 'me@example.test', 'password' => 'password']);

        // The code from confirming was used, so the next one is needed.
        $this->post('/two-factor-challenge', ['code' => $this->code($secret, 1)])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        [$secret] = $this->enable();

        $this->post('/login', ['email' => 'me@example.test', 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $this->code($secret)])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_recovery_code_works_once(): void
    {
        [, $codes] = $this->enable();

        $this->post('/login', ['email' => 'me@example.test', 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => strtoupper($codes[0])])->assertRedirect('/dashboard');
        Auth::logout();

        $this->post('/login', ['email' => 'me@example.test', 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertCount(7, $this->user->refresh()->two_factor_recovery_codes);
    }

    public function test_guessing_is_limited(): void
    {
        [$secret] = $this->enable();
        $this->post('/login', ['email' => 'me@example.test', 'password' => 'password']);

        foreach (range(1, 5) as $i) {
            $this->post('/two-factor-challenge', ['code' => '000000']);
        }

        $this->post('/two-factor-challenge', ['code' => $this->code($secret, 1)])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_challenge_needs_a_pending_sign_in(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect(route('login'));
        $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect(route('login'));
    }

    public function test_disabling_needs_the_password(): void
    {
        $this->enable();

        $this->actingAs($this->user)->deleteJson('/me/two-factor', ['password' => 'wrong'])->assertUnprocessable();
        $this->assertTrue($this->user->refresh()->two_factor_enabled);

        $this->actingAs($this->user)->deleteJson('/me/two-factor', ['password' => 'password'])->assertNoContent();
        $this->assertFalse($this->user->refresh()->two_factor_enabled);
        $this->assertNull($this->user->two_factor_secret);
    }

    public function test_mobile_login_also_needs_the_code(): void
    {
        [$secret] = $this->enable();
        $body = ['email' => 'me@example.test', 'password' => 'password', 'device_name' => 'Pixel'];

        $this->postJson('/api/v1/login', $body)->assertUnprocessable()->assertJsonPath('two_factor_required', true);
        $this->postJson('/api/v1/login', [...$body, 'code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/login', [...$body, 'code' => $this->code($secret, 1)])->assertOk()->assertJsonStructure(['token']);
    }

    /** @return array<string, string> */
    private function unzip(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($path, $content);

        $zip = new ZipArchive;
        $zip->open($path);
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($path);

        return $files;
    }

    private function workspaceWithHistory(): array
    {
        $other = User::factory()->create(['name' => 'Other Person']);

        $this->actingAs($this->user)->post('/workspaces', ['name' => 'Acme']);
        $workspace = Workspace::first();
        $workspace->members()->attach($other->id);

        $general = $workspace->channels()->first();
        $general->members()->attach($other->id);
        $general->messages()->create(['user_id' => $this->user->id, 'body' => 'public from me']);
        $general->messages()->create(['user_id' => $other->id, 'body' => 'public from other']);
        $general->messages()->create(['user_id' => $other->id, 'body' => '', 'removed_at' => now()]);

        $mine = $workspace->channels()->create(['name' => 'mine', 'type' => Channel::PRIVATE]);
        $mine->members()->attach($this->user->id);
        $mine->messages()->create(['user_id' => $this->user->id, 'body' => 'private I can see']);

        $theirs = $workspace->channels()->create(['name' => 'theirs', 'type' => Channel::PRIVATE]);
        $theirs->members()->attach($other->id);
        $theirs->messages()->create(['user_id' => $other->id, 'body' => 'private secret']);

        $dm = $workspace->channels()->create(['type' => Channel::DM, 'dm_key' => "{$this->user->id}-{$other->id}"]);
        $dm->members()->attach([$this->user->id, $other->id]);
        $dm->messages()->create(['user_id' => $other->id, 'body' => 'dm secret']);

        return [$workspace, $other];
    }

    public function test_workspace_export_leaves_out_dms_and_other_private_channels(): void
    {
        [$workspace] = $this->workspaceWithHistory();

        $response = $this->actingAs($this->user)->post("/w/{$workspace->slug}/export", ['password' => 'password'])->assertOk();
        $files = $this->unzip(file_get_contents($response->baseResponse->getFile()->getPathname()));
        $all = implode("\n", $files);

        $this->assertArrayHasKey('channels/general.jsonl', $files);
        $this->assertArrayHasKey('channels/mine.jsonl', $files);
        $this->assertArrayNotHasKey('channels/theirs.jsonl', $files);
        $this->assertStringContainsString('public from other', $all);
        $this->assertStringNotContainsString('private secret', $all);
        $this->assertStringNotContainsString('dm secret', $all);
        $this->assertSame(2, substr_count($files['channels/general.jsonl'], "\n"));
    }

    public function test_workspace_export_is_owner_only_and_needs_the_password(): void
    {
        [$workspace, $other] = $this->workspaceWithHistory();

        $this->actingAs($other)->postJson("/w/{$workspace->slug}/export", ['password' => 'password'])->assertForbidden();
        $this->actingAs($this->user)->postJson("/w/{$workspace->slug}/export", ['password' => 'wrong'])->assertUnprocessable();
    }

    public function test_personal_export_has_only_what_i_wrote(): void
    {
        $this->workspaceWithHistory();

        $response = $this->actingAs($this->user)->post('/me/export', ['password' => 'password'])->assertOk();
        $files = $this->unzip(file_get_contents($response->baseResponse->getFile()->getPathname()));

        $this->assertStringContainsString('me@example.test', $files['profile.json']);
        $this->assertStringContainsString('public from me', $files['messages.jsonl']);
        $this->assertStringNotContainsString('public from other', $files['messages.jsonl']);
        $this->assertStringNotContainsString('dm secret', $files['messages.jsonl']);
    }
}
