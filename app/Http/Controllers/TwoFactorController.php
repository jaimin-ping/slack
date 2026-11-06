<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    private const CHALLENGE_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    public function setup(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();
        abort_if($user->two_factor_enabled, 409);

        $secret = $twoFactor->newSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_last_step' => null])->save();

        return response()->json([
            'secret' => $secret,
            'qr' => $twoFactor->qrCode($twoFactor->otpauthUrl($user, $secret)),
        ]);
    }

    public function confirm(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        $user = $request->user();
        abort_if($user->two_factor_enabled || ! $user->two_factor_secret, 409);

        if (! $twoFactor->verifyCode($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Check your app and try again.']);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return response()->json(['recovery_codes' => $twoFactor->issueRecoveryCodes($user)]);
    }

    public function disable(Request $request): HttpResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return response()->noContent();
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        return $this->pendingUser($request)
            ? Inertia::render('Auth/TwoFactorChallenge')
            : redirect()->route('login');
    }

    public function verify(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $key = 'two-factor:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['code' => 'Too many tries. Wait a minute and try again.']);
        }

        if (! $twoFactor->passes($user, $data['code'])) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['code' => 'That code is not right.']);
        }

        RateLimiter::clear($key);

        $remember = (bool) $request->session()->get('two_factor.remember');
        $request->session()->forget('two_factor');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('two_factor');

        if (! $pending || now()->timestamp > $pending['expires']) {
            return null;
        }

        return User::find($pending['user']);
    }

    /** Password was right: holds the sign-in until the second step passes. */
    public static function begin(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->regenerate();
        $request->session()->put('two_factor', [
            'user' => $user->id,
            'remember' => $remember,
            'expires' => now()->addMinutes(self::CHALLENGE_MINUTES)->timestamp,
        ]);

        return redirect()->route('two-factor.challenge');
    }
}
