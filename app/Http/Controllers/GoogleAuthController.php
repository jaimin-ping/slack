<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Invitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/** Signing in through Google OAuth. */
class GoogleAuthController extends Controller
{
    private const FAILED = 'Google sign-in did not work. Try again, or use your password.';

    private const UNCONFIRMED = 'This email already has an account. Log in with your password first.';

    private const NOT_INVITED = 'This email has not been invited. Ask a workspace admin for an invitation.';

    private const MOBILE_CODE_SECONDS = 120;

    public function __construct(private Invitations $invitations) {}

    public function redirect(Request $request): RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        return Socialite::driver('google')->redirect();
    }

    /** The mobile/desktop app opens this in its system browser. */
    public function mobile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'redirect_uri' => ['required', 'string', 'max:200'],
        ]);

        if (!$this->isAppAddress($data['redirect_uri'])) {
            abort(422, 'Invalid redirect URI');
        }

        // Store the mobile context in session and save immediately
        $request->session()->put('google_mobile', $data);
        $request->session()->save();

        // Use Socialite redirect (NOT stateless - we need session)
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse|InertiaResponse
    {
        $mobile = $request->session()->pull('google_mobile');

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);

            if ($mobile) {
                return $this->toApp($mobile['redirect_uri'], ['error' => self::FAILED]);
            }

            return $this->backToLogin(self::FAILED);
        }

        $email = strtolower($googleUser->getEmail());
        $existing = User::whereRaw('lower(email) = ?', [$email])->first();

        // Check if user is linked with Google
        $linked = User::where('google_id', $googleUser->getId())->first();

        // Handle mobile flow
        if ($mobile) {
            $user = $linked ?? $this->accountFor($googleUser, $existing);

            if (! $user) {
                $message = $existing ? self::UNCONFIRMED : self::NOT_INVITED;
                return $this->toApp($mobile['redirect_uri'], ['error' => $message]);
            }

            return $this->finishMobile($mobile, $user);
        }

        // Web flow
        if ($linked) {
            return $this->signIn($request, $linked);
        }

        // If existing user with same email but not linked to Google
        if ($existing) {
            if (! $existing->email_verified_at) {
                return $this->backToLogin(self::UNCONFIRMED);
            }

            // Link Google to existing account and sign in
            $existing->forceFill(['google_id' => $googleUser->getId()])->save();

            return $this->signIn($request, $existing);
        }

        // New user - check if invited
        if (! $this->invitations->isInvited($email)) {
            return $this->backToLogin(self::NOT_INVITED);
        }

        // Create new user
        $user = User::create([
            'name' => Str::limit($googleUser->getName(), 255, ''),
            'email' => $email,
            'password' => Str::password(40),
            'google_id' => $googleUser->getId(),
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $this->signIn($request, $user);
    }

    /** Handle user account for mobile flow */
    private function accountFor($googleUser, ?User $existing): ?User
    {
        $email = strtolower($googleUser->getEmail());

        // Unconfirmed email cannot be handed over
        if ($existing && ! $existing->email_verified_at) {
            return null;
        }

        // Check invitation for new users
        if (! $existing && ! $this->invitations->isInvited($email)) {
            return null;
        }

        if ($existing) {
            // Link Google to existing account
            $existing->forceFill(['google_id' => $googleUser->getId()])->save();
            return $existing;
        }

        // Create new user
        $user = User::create([
            'name' => Str::limit($googleUser->getName(), 255, ''),
            'email' => $email,
            'password' => Str::password(40),
            'google_id' => $googleUser->getId(),
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** Hands the app a short-lived code that only the holder of the matching verifier can use. */
    private function finishMobile(array $mobile, User $user): RedirectResponse
    {
        $code = Str::random(64);

        Cache::put('google.mobile.'.hash('sha256', $code), [
            'user' => $user->id,
            'challenge' => $mobile['challenge'],
        ], self::MOBILE_CODE_SECONDS);

        // For deep links (pingchat://), always redirect directly
        $uri = $mobile['redirect_uri'];
        $query = http_build_query(['code' => $code]);

        return redirect()->away("{$uri}?{$query}");
    }

    private function signIn(Request $request, User $user): RedirectResponse
    {
        if ($user->two_factor_enabled) {
            return TwoFactorController::begin($request, $user, false);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /** Validates app redirect URIs */
    private function isAppAddress(string $uri): bool
    {
        // Allow deep links
        if (preg_match('#^pingchat://[\w/-]*$#', $uri)) {
            return true;
        }

        // Allow web redirect to our own domain for code display
        $appUrl = rtrim(config('app.url'), '/');
        if (str_starts_with($uri, $appUrl . '/auth/google/code')) {
            return true;
        }

        // Development: allow localhost
        if (app()->isLocal() && preg_match('#^(exp://[\w.:-]+(/--/[\w/-]*)?|http://(127\.0\.0\.1|localhost)(:\d+)?/[\w/-]*)$#', $uri)) {
            return true;
        }

        return false;
    }

    private function toApp(string $uri, array $query): RedirectResponse|InertiaResponse
    {
        // For deep links, redirect
        if (str_starts_with($uri, 'pingchat://') || str_starts_with($uri, 'exp://')) {
            return redirect()->away($uri.'?'.http_build_query($query));
        }

        // For web/localhost, show code page
        return Inertia::render('Auth/GoogleCode', $query);
    }

    private function backToLogin(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('status', $message);
    }
}
