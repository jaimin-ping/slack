<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://idp.example.test';

    private string $key;

    /** Claims the next token will contain, on top of the sensible defaults. */
    private array $claims = [];

    /** The workspace owner every test starts with, who sent the invitations. */
    private const OWNER = 1;

    private ?string $sentVerifier = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = file_get_contents(base_path('tests/fixtures/sso-test-key.pem'));

        config(['services.sso' => [
            'name' => 'Acme ID',
            'issuer' => self::ISSUER,
            'client_id' => 'ping-chat',
            'client_secret' => 'test-secret',
            'allowed_domain' => null,
        ]]);

        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $owner->id]);

        foreach (['asha@example.test', 'asha@acme.test'] as $email) {
            Invitation::create(['workspace_id' => $workspace->id, 'email' => $email]);
        }

        Http::fake(function (ClientRequest $request) {
            return match (true) {
                str_ends_with($request->url(), '/.well-known/openid-configuration') => Http::response([
                    'issuer' => self::ISSUER,
                    'authorization_endpoint' => self::ISSUER.'/authorize',
                    'token_endpoint' => self::ISSUER.'/token',
                    'jwks_uri' => self::ISSUER.'/jwks',
                ]),
                str_ends_with($request->url(), '/jwks') => Http::response(['keys' => [$this->publicKey()]]),
                str_ends_with($request->url(), '/token') => $this->tokenResponse($request),
            };
        });
    }

    private function publicKey(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->key))['rsa'];
        $encode = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return ['kty' => 'RSA', 'kid' => 'test', 'use' => 'sig', 'alg' => 'RS256', 'n' => $encode($details['n']), 'e' => $encode($details['e'])];
    }

    private function tokenResponse(ClientRequest $request)
    {
        $this->sentVerifier = $request->data()['code_verifier'] ?? null;

        $claims = [
            'iss' => self::ISSUER,
            'aud' => 'ping-chat',
            'sub' => 'subject-1',
            'email' => 'asha@example.test',
            'email_verified' => true,
            'name' => 'Asha Person',
            'nonce' => $this->nonce,
            'exp' => time() + 300,
            'iat' => time(),
            ...$this->claims,
        ];

        return Http::response(['id_token' => JWT::encode($claims, $this->key, 'RS256', 'test'), 'access_token' => 'at']);
    }

    private string $nonce = '';

    /** Walks the browser through the provider and back. */
    private function signInWith(array $claims = [], ?string $state = null)
    {
        $this->claims = $claims;
        $location = $this->get('/sso/redirect')->assertRedirect()->headers->get('Location');

        return $this->finish($location, $state);
    }

    private function finish(string $location, ?string $state = null)
    {
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->nonce = $this->claims['nonce'] ?? $query['nonce'];

        return $this->get('/sso/callback?'.http_build_query(['state' => $state ?? $query['state'], 'code' => 'abc']));
    }

    public function test_it_is_off_until_configured(): void
    {
        config(['services.sso.issuer' => null]);

        $this->get('/sso/redirect')->assertNotFound();
        $this->get('/sso/callback')->assertNotFound();
        $this->get('/login')->assertInertia(fn ($page) => $page->where('sso', null));
    }

    public function test_login_page_offers_it_by_name(): void
    {
        $this->get('/login')->assertInertia(fn ($page) => $page->where('sso.name', 'Acme ID'));
    }

    public function test_redirect_uses_state_nonce_and_pkce(): void
    {
        $location = $this->get('/sso/redirect')->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(self::ISSUER.'/authorize?', $location);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['nonce']);

        $this->finish($location);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $this->sentVerifier, true)), '+/', '-_'), '=');
        $this->assertSame($query['code_challenge'], $challenge);
    }

    public function test_a_new_person_gets_an_account_and_is_signed_in(): void
    {
        $this->signInWith()->assertRedirect('/dashboard');

        $user = User::where('email', 'asha@example.test')->first();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Asha Person', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('subject-1', $user->sso_subject);
    }

    public function test_someone_nobody_invited_is_turned_away(): void
    {
        $users = User::count();

        $this->signInWith(['email' => 'stranger@example.test'])->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame($users, User::count());
        $this->assertStringContainsString('not been invited', session('status'));
    }

    public function test_someone_nobody_invited_is_turned_away_on_the_phone_too(): void
    {
        $users = User::count();

        $query = $this->mobileSignIn(str_repeat('v', 64), ['email' => 'stranger@example.test']);

        $this->assertStringContainsString('not been invited', $query['error']);
        $this->assertSame($users, User::count());
    }

    public function test_a_returning_person_is_found_by_subject_even_after_an_email_change(): void
    {
        $this->signInWith();
        auth()->logout();
        $this->flushSession();

        $this->signInWith(['email' => 'new-name@example.test'])->assertRedirect('/dashboard');

        $this->assertSame(1 + self::OWNER, User::count());
        $this->assertAuthenticatedAs(User::latest('id')->first());
    }

    public function test_verified_account_with_the_same_email_is_linked(): void
    {
        $user = User::factory()->create(['email' => 'Asha@Example.test']);

        $this->signInWith()->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('subject-1', $user->fresh()->sso_subject);
    }

    public function test_unconfirmed_account_is_not_merged_silently(): void
    {
        $user = User::factory()->create(['email' => 'asha@example.test', 'email_verified_at' => null]);

        $this->signInWith()->assertRedirect('/login')->assertSessionHas('status');

        $this->assertGuest();
        $this->assertNull($user->fresh()->sso_subject);
    }

    public function test_wrong_state_is_refused(): void
    {
        $this->signInWith([], 'forged')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0 + self::OWNER, User::count());
    }

    public function test_a_callback_nobody_started_is_refused(): void
    {
        $this->get('/sso/callback?state=x&code=y')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_a_state_works_only_once(): void
    {
        $location = $this->get('/sso/redirect')->headers->get('Location');
        $this->finish($location);
        auth()->logout();

        $this->finish($location)->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_tokens_that_cannot_be_trusted_are_refused(): void
    {
        foreach ([
            'wrong nonce' => ['nonce' => 'other'],
            'other app' => ['aud' => 'someone-else'],
            'other issuer' => ['iss' => 'https://evil.example.test'],
            'expired' => ['exp' => time() - 3600],
            'unverified email' => ['email_verified' => false],
            'no email' => ['email' => null],
            'second audience without azp' => ['aud' => ['ping-chat', 'other']],
        ] as $case => $claims) {
            $this->flushSession();
            $this->signInWith($claims)->assertRedirect('/login');

            $this->assertGuest();
            $this->assertSame(self::OWNER, User::count(), $case);
        }
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        $location = $this->get('/sso/redirect')->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $token = JWT::encode([
            'iss' => self::ISSUER, 'aud' => 'ping-chat', 'sub' => 'x', 'email' => 'evil@example.test',
            'email_verified' => true, 'nonce' => $query['nonce'], 'exp' => time() + 300,
        ], 'a-shared-secret-of-at-least-thirty-two-bytes', 'HS256', 'test');

        Http::swap(app(\Illuminate\Http\Client\Factory::class));
        Http::fake([
            '*/.well-known/*' => Http::response(['issuer' => self::ISSUER, 'authorization_endpoint' => 'x', 'token_endpoint' => self::ISSUER.'/token', 'jwks_uri' => self::ISSUER.'/jwks']),
            '*/jwks' => Http::response(['keys' => [$this->publicKey()]]),
            '*/token' => Http::response(['id_token' => $token]),
        ]);

        $this->get('/sso/callback?'.http_build_query(['state' => $query['state'], 'code' => 'abc']))->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_only_the_allowed_domain_gets_in(): void
    {
        config(['services.sso.allowed_domain' => 'acme.test']);

        $this->signInWith()->assertRedirect('/login');
        $this->assertSame(0 + self::OWNER, User::count());

        $this->signInWith(['email' => 'asha@acme.test'])->assertRedirect('/dashboard');
        $this->assertSame(1 + self::OWNER, User::count());
    }

    public function test_two_step_sign_in_still_applies(): void
    {
        $user = User::factory()->create(['email' => 'asha@example.test']);
        $user->forceFill(['sso_issuer' => self::ISSUER, 'sso_subject' => 'subject-1', 'two_factor_confirmed_at' => now()])->save();

        $this->signInWith()->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
    }

    public function test_connecting_needs_the_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/me/sso/connect', ['password' => 'wrong'])->assertJsonValidationErrors('password');
        $this->assertNull($user->fresh()->sso_subject);
    }

    public function test_a_signed_in_person_can_connect_even_with_another_email(): void
    {
        $user = User::factory()->create(['email' => 'personal@example.test', 'email_verified_at' => null]);

        $url = $this->actingAs($user)->postJson('/me/sso/connect', ['password' => 'password'])->assertOk()->json('url');

        $this->finish($url)->assertOk()->assertInertia(fn ($page) => $page->component('Auth/SsoResult'));

        $this->assertSame('subject-1', $user->fresh()->sso_subject);
        $this->actingAs($user->fresh())->get('/login');
    }

    public function test_one_provider_account_cannot_be_connected_twice(): void
    {
        $first = User::factory()->create();
        $first->forceFill(['sso_issuer' => self::ISSUER, 'sso_subject' => 'subject-1'])->save();
        $second = User::factory()->create();

        $url = $this->actingAs($second)->postJson('/me/sso/connect', ['password' => 'password'])->json('url');

        $this->finish($url)->assertInertia(fn ($page) => $page->where('message', 'That single sign-on account already belongs to someone else.'));

        $this->assertNull($second->fresh()->sso_subject);
    }

    public function test_provider_details_never_reach_the_browser(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['sso_issuer' => self::ISSUER, 'sso_subject' => 'subject-1'])->save();

        $this->assertArrayNotHasKey('sso_subject', $user->fresh()->toArray());
    }

    private function mobileStart(string $verifier, string $redirect = 'pingchat://sso')
    {
        return $this->get('/sso/mobile?'.http_build_query(['challenge' => $this->challenge($verifier), 'redirect_uri' => $redirect]));
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** Returns the query the app receives on its own address. */
    private function mobileSignIn(string $verifier, array $claims = []): array
    {
        $this->claims = $claims;
        $back = $this->finish($this->mobileStart($verifier)->assertRedirect()->headers->get('Location'))->headers->get('Location');

        $this->assertStringStartsWith('pingchat://sso?', $back);
        parse_str(parse_url($back, PHP_URL_QUERY), $query);

        return $query;
    }

    private function exchange(string $code, string $verifier, array $extra = [])
    {
        return $this->postJson('/api/v1/sso/exchange', ['code' => $code, 'verifier' => $verifier, 'device_name' => 'Phone', ...$extra]);
    }

    public function test_login_screen_can_ask_if_it_is_on(): void
    {
        $this->getJson('/api/v1/sso')->assertExactJson(['name' => 'Acme ID']);

        config(['services.sso.issuer' => null]);
        $this->getJson('/api/v1/sso')->assertExactJson(['name' => null]);
    }

    public function test_the_app_signs_in_with_a_token_and_the_browser_stays_signed_out(): void
    {
        $verifier = str_repeat('v', 64);
        $query = $this->mobileSignIn($verifier);

        $this->assertGuest();

        $token = $this->exchange($query['code'], $verifier)->assertOk()->assertJsonPath('user.email', 'asha@example.test')->json('token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('name', 'Asha Person');
    }

    public function test_a_stolen_code_is_useless_without_the_verifier_and_burns(): void
    {
        $verifier = str_repeat('v', 64);
        $query = $this->mobileSignIn($verifier);

        $this->exchange($query['code'], str_repeat('x', 64))->assertUnprocessable()->assertJsonMissingPath('token');
        $this->exchange($query['code'], $verifier)->assertUnprocessable();
    }

    public function test_a_code_works_only_once_and_expires(): void
    {
        $verifier = str_repeat('v', 64);
        $query = $this->mobileSignIn($verifier);

        $this->exchange($query['code'], $verifier)->assertOk();
        $this->exchange($query['code'], $verifier)->assertUnprocessable();

        $this->flushSession();
        $late = $this->mobileSignIn($verifier);
        $this->travel(3)->minutes();

        $this->exchange($late['code'], $verifier)->assertUnprocessable();
    }

    public function test_the_app_address_must_be_ours(): void
    {
        $verifier = str_repeat('v', 64);

        foreach (['https://evil.example.test/sso', 'evilapp://sso', 'exp://192.168.1.5:8081/--/sso', 'http://127.0.0.1:5190/sso', 'pingchat://sso?x=1'] as $address) {
            $this->mobileStart($verifier, $address)->assertStatus(422);
        }

        $this->get('/sso/mobile?challenge=short&redirect_uri=pingchat://sso')->assertRedirect();
    }

    public function test_expo_go_address_works_on_a_developer_machine_only(): void
    {
        $this->app['env'] = 'local';

        $this->mobileStart(str_repeat('v', 64), 'exp://192.168.1.5:8081/--/sso')->assertRedirect();
        $this->mobileStart(str_repeat('v', 64), 'exp://evil.example.test:8081/--/sso')->assertRedirect();
        $this->mobileStart(str_repeat('v', 64), 'http://evil.example.test/sso')->assertStatus(422);
    }

    public function test_the_app_hears_why_a_sign_in_failed(): void
    {
        User::factory()->create(['email' => 'asha@example.test', 'email_verified_at' => null]);

        $query = $this->mobileSignIn(str_repeat('v', 64));

        $this->assertArrayNotHasKey('code', $query);
        $this->assertStringStartsWith('This email already has an account', $query['error']);
    }

    public function test_two_step_sign_in_applies_on_the_phone_and_a_wrong_code_can_be_retried(): void
    {
        $user = User::factory()->create(['email' => 'asha@example.test']);
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $verifier = str_repeat('v', 64);
        $query = $this->mobileSignIn($verifier);

        $this->exchange($query['code'], $verifier)->assertUnprocessable()->assertJsonPath('two_factor_required', true);
        $this->exchange($query['code'], $verifier, ['two_factor_code' => '000000'])->assertUnprocessable()->assertJsonPath('two_factor_required', true);

        $code = app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP');
        $this->exchange($query['code'], $verifier, ['two_factor_code' => $code])->assertOk();
    }
}
