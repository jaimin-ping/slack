<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Invitations;
use App\Services\SsoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Signing in through the server's single sign-on provider. */
class SsoController extends Controller
{
    private const FAILED = 'Single sign-on did not work. Try again, or use your password.';

    private const UNCONFIRMED = 'This email already has an account. Log in with your password, then connect single sign-on in Preferences.';

    private const NOT_INVITED = 'This email has not been invited. Ask a workspace admin for an invitation.';

    private const MOBILE_CODE_SECONDS = 120;

    public function __construct(private SsoClient $sso, private Invitations $invitations) {}

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($this->sso->enabled(), 404);

        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        try {
            return redirect()->away($this->begin($request, null));
        } catch (RuntimeException $e) {
            report($e);

            return $this->backToLogin(self::FAILED);
        }
    }

    /** The phone opens this in its system browser. The browser itself never gets signed in. */
    public function mobile(Request $request): RedirectResponse
    {
        abort_unless($this->sso->enabled(), 404);

        $data = $request->validate([
            'challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'redirect_uri' => ['required', 'string', 'max:200'],
        ]);

        abort_unless($this->isAppAddress($data['redirect_uri']), 422);

        try {
            return redirect()->away($this->begin($request, null, $data));
        } catch (RuntimeException $e) {
            report($e);

            return $this->toApp($data['redirect_uri'], ['error' => self::FAILED]);
        }
    }

    /** Lets someone who signed up with a password use single sign-on from now on. */
    public function connect(Request $request): JsonResponse
    {
        abort_unless($this->sso->enabled(), 404);

        $request->validate(['password' => ['required', 'current_password']]);

        return response()->json(['url' => $this->begin($request, $request->user()->id)]);
    }

    public function callback(Request $request): RedirectResponse|Response
    {
        abort_unless($this->sso->enabled(), 404);

        $pending = $request->session()->pull('sso');

        if (! $pending || ! hash_equals($pending['state'], (string) $request->query('state'))) {
            return $this->fail($pending, 'That sign in has expired. Please start again.');
        }

        if (! $request->filled('code')) {
            return $this->fail($pending, self::FAILED);
        }

        try {
            $identity = $this->sso->identity(
                (string) $request->query('code'),
                $pending['verifier'],
                $pending['nonce'],
                route('sso.callback'),
            );
        } catch (RuntimeException $e) {
            report($e);

            return $this->fail($pending, self::FAILED);
        }

        $domain = strtolower((string) config('services.sso.allowed_domain'));

        if ($domain && Str::afterLast($identity['email'], '@') !== $domain) {
            return $this->fail($pending, "Only {$domain} accounts can sign in here.");
        }

        $linked = User::where(['sso_issuer' => $this->sso->issuer(), 'sso_subject' => $identity['sub']])->first();

        if ($pending['mobile']) {
            return $this->finishMobile($pending, $linked, $identity);
        }

        return $pending['link']
            ? $this->finishConnect($request, $linked, $identity, $pending['link'])
            : $this->signIn($request, $linked, $identity);
    }

    private function begin(Request $request, ?int $link, ?array $mobile = null): string
    {
        $pending = [
            'state' => $this->sso->randomToken(),
            'nonce' => $this->sso->randomToken(),
            'verifier' => $this->sso->randomToken(),
            'link' => $link,
            'mobile' => $mobile,
        ];

        $url = $this->sso->authorizeUrl($pending['state'], $pending['nonce'], $pending['verifier'], route('sso.callback'));
        $request->session()->put('sso', $pending);

        return $url;
    }

    private function finishConnect(Request $request, ?User $linked, array $identity, int $userId): Response
    {
        if ($request->user()?->id !== $userId) {
            return $this->result('Sign in again, then connect single sign-on.');
        }

        if ($linked && $linked->id !== $userId) {
            return $this->result('That single sign-on account already belongs to someone else.');
        }

        $this->link($request->user(), $identity);

        return $this->result('Single sign-on is connected. You can use it the next time you sign in.');
    }

    /** Hands the app a short-lived code that only the holder of the matching verifier can use. */
    private function finishMobile(array $pending, ?User $linked, array $identity): RedirectResponse
    {
        $user = $linked ?? $this->accountFor($identity);

        if (! $user) {
            return $this->fail($pending, $this->refusal($identity));
        }

        $code = $this->sso->randomToken();

        Cache::put('sso.mobile.'.hash('sha256', $code), [
            'user' => $user->id,
            'challenge' => $pending['mobile']['challenge'],
        ], self::MOBILE_CODE_SECONDS);

        return $this->toApp($pending['mobile']['redirect_uri'], ['code' => $code]);
    }

    /** An account for this person, or null when they are not invited or one must not be merged silently. */
    private function accountFor(array $identity): ?User
    {
        $existing = $this->existing($identity);

        if (! $existing && ! $this->invitations->isInvited($identity['email'])) {
            return null;
        }

        // An unconfirmed address could have been registered by anyone, so that account is not handed over.
        if ($existing && ! $existing->email_verified_at) {
            return null;
        }

        $user = $existing ?? $this->createUser($identity);
        $this->link($user, $identity);

        return $user;
    }

    private function existing(array $identity): ?User
    {
        return User::whereRaw('lower(email) = ?', [$identity['email']])->first();
    }

    private function refusal(array $identity): string
    {
        return $this->existing($identity) ? self::UNCONFIRMED : self::NOT_INVITED;
    }

    private function signIn(Request $request, ?User $user, array $identity): RedirectResponse
    {
        $user ??= $this->accountFor($identity);

        if (! $user) {
            return $this->backToLogin($this->refusal($identity));
        }

        if ($user->two_factor_enabled) {
            return TwoFactorController::begin($request, $user, false);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function createUser(array $identity): User
    {
        $user = User::create([
            'name' => Str::limit($identity['name'], 255, ''),
            'email' => $identity['email'],
            'password' => Str::password(40),
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function link(User $user, array $identity): void
    {
        $user->forceFill(['sso_issuer' => $this->sso->issuer(), 'sso_subject' => $identity['sub']])->save();
    }

    private function fail(?array $pending, string $message): RedirectResponse|Response
    {
        if ($pending['mobile'] ?? null) {
            return $this->toApp($pending['mobile']['redirect_uri'], ['error' => $message]);
        }

        return ($pending['link'] ?? null) ? $this->result($message) : $this->backToLogin($message);
    }

    /** Expo Go and the browser test build only exist on a developer's machine. */
    private function isAppAddress(string $uri): bool
    {
        return preg_match('#^pingchat://[\w/-]*$#', $uri)
            || (app()->isLocal() && preg_match('#^(exp://[\w.:-]+(/--/[\w/-]*)?|http://(127\.0\.0\.1|localhost)(:\d+)?/[\w/-]*)$#', $uri));
    }

    private function toApp(string $uri, array $query): RedirectResponse
    {
        return redirect()->away($uri.'?'.http_build_query($query));
    }

    private function backToLogin(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('status', $message);
    }

    private function result(string $message): Response
    {
        return Inertia::render('Auth/SsoResult', ['message' => $message]);
    }
}
