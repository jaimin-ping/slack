<?php

namespace Tests\Feature;

use App\Mail\LoginCode;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InviteOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->owner = User::factory()->create();
        $this->actingAs($this->owner)->post('/workspaces', ['name' => 'Acme']);
        $this->workspace = Workspace::firstOrFail();
        $this->app['auth']->forgetGuards();
    }

    private function invite(string $email): void
    {
        $this->actingAs($this->owner)->postJson("/w/{$this->workspace->slug}/invites", ['emails' => [$email]])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    private function sentCode(string $email): string
    {
        $code = null;
        Mail::assertSent(LoginCode::class, function (LoginCode $mail) use ($email, &$code) {
            $code = $mail->hasTo($email) ? $mail->code : $code;

            return $mail->hasTo($email);
        });

        return $code;
    }

    public function test_nobody_can_sign_up_or_join_with_a_link(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'A', 'email' => 'a@example.test', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertNotFound();
        $this->actingAs($this->owner)->post('/workspaces/join', ['code' => 'x'])->assertStatus(404);
        $this->assertSame(1, User::count());
    }

    public function test_an_invited_address_gets_an_account_and_joins_the_workspace(): void
    {
        $this->invite('New.Person@Example.test');

        $this->post('/login/email', ['email' => 'new.person@example.test'])->assertRedirect('/login/code');
        $this->get('/login/code')->assertOk();

        $this->post('/login/code', ['code' => $this->sentCode('new.person@example.test')])->assertRedirect('/dashboard');
        $this->assertAuthenticated();

        $this->get('/dashboard')->assertOk();

        $person = User::where('email', 'new.person@example.test')->firstOrFail();
        $this->assertSame('New Person', $person->name);
        $this->assertNotNull($person->email_verified_at);
        $this->assertTrue($this->workspace->hasMember($person));
        $this->assertTrue($this->workspace->channels()->first()->hasMember($person));
        $this->assertNotNull(Invitation::firstOrFail()->accepted_at);
    }

    public function test_an_address_nobody_invited_gets_no_code_and_no_hint(): void
    {
        $this->post('/login/email', ['email' => 'stranger@example.test'])->assertRedirect('/login/code');
        $this->get('/login/code')->assertOk();

        Mail::assertNotSent(LoginCode::class);

        $this->post('/login/code', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    public function test_a_wrong_code_fails_and_a_used_code_cannot_be_reused(): void
    {
        $this->invite('person@example.test');
        $this->post('/login/email', ['email' => 'person@example.test']);
        $code = $this->sentCode('person@example.test');

        $this->post('/login/code', ['code' => $code === '000000' ? '000001' : '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/login/code', ['code' => $code])->assertRedirect('/dashboard');
        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        $this->withSession(['login_email' => 'person@example.test'])->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_too_many_wrong_guesses_lock_out_even_the_right_code(): void
    {
        $this->invite('person@example.test');
        $this->post('/login/email', ['email' => 'person@example.test']);
        $code = $this->sentCode('person@example.test');
        $wrong = $code === '000000' ? '000001' : '000000';
        $codes = app(\App\Services\LoginCodes::class);

        foreach (range(1, 10) as $guess) {
            $this->assertFalse($codes->check('person@example.test', $wrong));
        }

        $this->assertFalse($codes->check('person@example.test', $code));
    }

    public function test_sending_is_limited_per_address(): void
    {
        $this->invite('person@example.test');

        foreach (range(1, 5) as $try) {
            $this->post('/login/email', ['email' => 'person@example.test']);
        }

        // A test reuses one app, so earlier after-response jobs run again. Each real send has its own code.
        $this->assertLessThanOrEqual(3, Mail::sent(LoginCode::class)->map(fn (LoginCode $mail) => $mail->code)->unique()->count());
        $this->assertFalse(RateLimiter::remaining('login-code-send:'.hash('sha256', 'person@example.test'), 3) > 0);
    }

    public function test_a_cancelled_invitation_stops_working(): void
    {
        $this->invite('person@example.test');
        $invitation = Invitation::firstOrFail();

        $this->post('/login/email', ['email' => 'person@example.test']);
        $code = $this->sentCode('person@example.test');

        $this->actingAs($this->owner)->deleteJson("/w/{$this->workspace->slug}/invites/{$invitation->id}")->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    public function test_only_admins_see_and_cancel_invitations_of_their_own_workspace(): void
    {
        $this->invite('person@example.test');
        $invitation = Invitation::firstOrFail();
        $member = User::factory()->create();
        app(\App\Services\Invitations::class)->admit($this->workspace, $member);

        $this->actingAs($member)->getJson("/w/{$this->workspace->slug}/invites")->assertForbidden();
        $this->actingAs($member)->deleteJson("/w/{$this->workspace->slug}/invites/{$invitation->id}")->assertForbidden();

        $otherOwner = User::factory()->create();
        $this->actingAs($otherOwner)->post('/workspaces', ['name' => 'Other']);
        $other = Workspace::where('name', 'Other')->firstOrFail();

        $this->actingAs($otherOwner)->deleteJson("/w/{$other->slug}/invites/{$invitation->id}")->assertNotFound();

        $this->actingAs($this->owner)->getJson("/w/{$this->workspace->slug}/invites")
            ->assertJsonCount(1)->assertJsonPath('0.email', 'person@example.test');
    }

    public function test_someone_with_an_account_joins_on_their_next_visit(): void
    {
        $person = User::factory()->create(['email' => 'person@example.test']);
        $this->invite('person@example.test');

        $this->actingAs($person)->get('/dashboard')->assertOk();

        $this->assertTrue($this->workspace->hasMember($person));
    }

    public function test_typing_an_invited_address_into_a_profile_does_not_join(): void
    {
        $this->invite('boss@example.test');
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->patch('/profile', ['name' => 'Intruder', 'email' => 'boss@example.test']);
        $this->actingAs($intruder->fresh())->get('/dashboard')->assertOk();

        $this->assertFalse($this->workspace->hasMember($intruder));
    }

    public function test_inviting_a_member_sends_nothing(): void
    {
        $this->actingAs($this->owner)->postJson("/w/{$this->workspace->slug}/invites", ['emails' => [$this->owner->email]])
            ->assertJsonPath('sent', 0);

        $this->assertSame(0, Invitation::count());
    }

    public function test_the_code_sign_in_still_asks_for_the_authenticator(): void
    {
        $person = User::factory()->create(['email' => 'person@example.test']);
        $person->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        $this->post('/login/email', ['email' => 'person@example.test']);

        $this->post('/login/code', ['code' => $this->sentCode('person@example.test')])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_the_phone_signs_in_with_a_code(): void
    {
        $this->invite('person@example.test');

        $this->postJson('/api/v1/login/email', ['email' => 'stranger@example.test'])->assertNoContent();
        $this->postJson('/api/v1/login/email', ['email' => 'person@example.test'])->assertNoContent();
        $code = $this->sentCode('person@example.test');

        $this->postJson('/api/v1/login/code', ['email' => 'person@example.test', 'code' => '12', 'device_name' => 'Phone'])
            ->assertUnprocessable();

        $token = $this->postJson('/api/v1/login/code', ['email' => 'person@example.test', 'code' => $code, 'device_name' => 'Phone'])
            ->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/workspaces')->assertJsonPath('0.name', 'Acme');
    }

    public function test_the_phone_can_retry_a_mistyped_authenticator_code(): void
    {
        $person = User::factory()->create(['email' => 'person@example.test']);
        $person->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->mock(TwoFactor::class)->shouldReceive('passes')->andReturn(false, true);

        $this->postJson('/api/v1/login/email', ['email' => 'person@example.test']);
        $body = ['email' => 'person@example.test', 'code' => $this->sentCode('person@example.test'), 'device_name' => 'Phone'];

        $this->postJson('/api/v1/login/code', $body)->assertJsonPath('two_factor_required', true);
        $this->postJson('/api/v1/login/code', $body + ['two_factor_code' => '000000'])->assertJsonPath('two_factor_required', true);
        $this->postJson('/api/v1/login/code', $body + ['two_factor_code' => '111111'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_an_expired_page_comes_back_instead_of_an_error_screen(): void
    {
        Route::post('/expired-test', fn () => throw new TokenMismatchException)->middleware('web');

        $this->from('/login')->post('/expired-test')->assertRedirect('/login')->assertSessionHas('status');
        $this->from('/login')->postJson('/expired-test')->assertStatus(419);
    }

    public function test_first_user_command_makes_a_verified_account_once(): void
    {
        $this->artisan('chat:first-user', ['email' => 'Boss@Example.test', 'name' => 'Boss'])->assertSuccessful();
        $this->assertNotNull(User::where('email', 'boss@example.test')->value('email_verified_at'));

        $this->artisan('chat:first-user', ['email' => 'boss@example.test', 'name' => 'Boss'])->assertFailed();
    }
}
