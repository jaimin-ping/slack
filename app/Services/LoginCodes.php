<?php

namespace App\Services;

use App\Mail\LoginCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Signing in with a short code sent to an email address that has an account or an invitation. */
class LoginCodes
{
    public const MINUTES = 10;

    private const MAX_SENDS = 3;

    private const MAX_WRONG_GUESSES = 10;

    private const WRONG_GUESS_WINDOW_SECONDS = 3600;

    public function __construct(private Invitations $invitations) {}

    /** Answers the same way for every address, so nobody can use it to learn who has been invited. */
    public function send(string $email): void
    {
        $limit = 'login-code-send:'.$this->key($email);

        if (RateLimiter::tooManyAttempts($limit, self::MAX_SENDS) || ! $this->canSignIn($email)) {
            return;
        }

        RateLimiter::hit($limit, self::MINUTES * 60);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put('login-code:'.$this->key($email), $this->seal($code), now()->addMinutes(self::MINUTES));

        // Sent after the answer, because a slow reply would give away that the address is known.
        dispatch(fn () => Mail::to($email)->send(new LoginCode($code)))->afterResponse();
    }

    public function check(string $email, string $code): bool
    {
        $limit = 'login-code-wrong:'.$this->key($email);

        if (RateLimiter::tooManyAttempts($limit, self::MAX_WRONG_GUESSES)) {
            return false;
        }

        $sealed = Cache::get('login-code:'.$this->key($email));

        if ($sealed && hash_equals($sealed, $this->seal($code))) {
            return true;
        }

        RateLimiter::hit($limit, self::WRONG_GUESS_WINDOW_SECONDS);

        return false;
    }

    /** The account for an address that has just proved itself, created on the first sign in. */
    public function accountFor(string $email): ?User
    {
        $user = $this->find($email);

        if (! $user && ! $this->invitations->isInvited($email)) {
            return null;
        }

        $user ??= User::create([
            'name' => Str::limit(Str::title(str_replace(['.', '_', '-'], ' ', Str::before($email, '@'))), 255, ''),
            'email' => strtolower($email),
            'password' => Str::password(40),
        ]);

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    public function forget(string $email): void
    {
        Cache::forget('login-code:'.$this->key($email));
        RateLimiter::clear('login-code-wrong:'.$this->key($email));
    }

    private function canSignIn(string $email): bool
    {
        return $this->find($email) || $this->invitations->isInvited($email);
    }

    private function find(string $email): ?User
    {
        return User::whereRaw('lower(email) = ?', [strtolower($email)])->first();
    }

    private function key(string $email): string
    {
        return hash('sha256', strtolower($email));
    }

    private function seal(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
