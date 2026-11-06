<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TwoFactorController;
use App\Services\LoginCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The email first sign in: type your address, then the code that was sent to it. */
class EmailLoginController extends Controller
{
    public function __construct(private LoginCodes $codes) {}

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = strtolower(trim($data['email']));

        $this->codes->send($email);
        $request->session()->put('login_email', $email);

        return redirect()->route('login.code');
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $email = $request->session()->get('login_email');

        return $email
            ? Inertia::render('Auth/LoginCode', ['email' => $email, 'minutes' => LoginCodes::MINUTES])
            : redirect()->route('login');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $email = $request->session()->get('login_email');

        if (! $email) {
            return redirect()->route('login');
        }

        $code = preg_replace('/\s+/', '', $data['code']);
        $user = $this->codes->check($email, $code) ? $this->codes->accountFor($email) : null;

        if (! $user) {
            throw ValidationException::withMessages(['code' => 'That code is not right, or it has expired.']);
        }

        $this->codes->forget($email);
        $request->session()->forget('login_email');

        if ($user->two_factor_enabled) {
            return TwoFactorController::begin($request, $user, true);
        }

        // People have no password to retype here, so a browser stays signed in until they log out.
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
