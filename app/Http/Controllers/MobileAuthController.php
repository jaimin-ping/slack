<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Invitations;
use App\Services\LoginCodes;
use App\Services\SsoClient;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Sign-in for the mobile app, which uses a token instead of a browser session. */
class MobileAuthController extends Controller
{
    private const SSO_RETRY_SECONDS = 120;

    public function login(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if ($user->two_factor_enabled && ! (isset($data['code']) && $twoFactor->passes($user, $data['code']))) {
            return response()->json([
                'message' => isset($data['code']) ? 'That code is not right.' : 'Enter the code from your authenticator app.',
                'two_factor_required' => true,
            ], 422);
        }

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    /** Emails a sign in code. The answer is the same whether or not the address is known. */
    public function sendCode(Request $request, LoginCodes $codes): Response
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $codes->send(strtolower(trim($data['email'])));

        return response()->noContent();
    }

    public function loginWithCode(Request $request, LoginCodes $codes, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:10'],
            'device_name' => ['required', 'string', 'max:100'],
            'two_factor_code' => ['nullable', 'string', 'max:20'],
        ]);

        $email = strtolower(trim($data['email']));
        $code = preg_replace('/\s+/', '', $data['code']);
        $user = $codes->check($email, $code) ? $codes->accountFor($email) : null;

        if (! $user) {
            throw ValidationException::withMessages(['code' => 'That code is not right, or it has expired.']);
        }

        // The emailed code stays usable here, so the person can retry a mistyped authenticator code.
        if ($user->two_factor_enabled && ! (isset($data['two_factor_code']) && $twoFactor->passes($user, $data['two_factor_code']))) {
            return response()->json([
                'message' => isset($data['two_factor_code']) ? 'That code is not right.' : 'Enter the code from your authenticator app.',
                'two_factor_required' => true,
            ], 422);
        }

        $codes->forget($email);

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    /** Tells the login screen whether to offer single sign-on. */
    public function sso(SsoClient $sso): JsonResponse
    {
        return response()->json(['name' => $sso->enabled() ? config('services.sso.name') : null]);
    }

    /** Swaps the code from the browser for a token. The code works once, and only with its verifier. */
    public function ssoExchange(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'verifier' => ['required', 'string', 'min:43', 'max:128'],
            'device_name' => ['required', 'string', 'max:100'],
            'two_factor_code' => ['nullable', 'string', 'max:20'],
        ]);

        $key = 'sso.mobile.'.hash('sha256', $data['code']);
        $grant = Cache::pull($key);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $data['verifier'], true)), '+/', '-_'), '=');
        $user = $grant && hash_equals($grant['challenge'], $challenge) ? User::find($grant['user']) : null;

        if (! $user) {
            return response()->json(['message' => 'That sign in has expired. Please start again.'], 422);
        }

        if ($user->two_factor_enabled && ! (isset($data['two_factor_code']) && $twoFactor->passes($user, $data['two_factor_code']))) {
            Cache::put($key, $grant, self::SSO_RETRY_SECONDS);

            return response()->json([
                'message' => isset($data['two_factor_code']) ? 'That code is not right.' : 'Enter the code from your authenticator app.',
                'two_factor_required' => true,
            ], 422);
        }

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): Response
    {
        // This phone must stop showing the person's messages once they sign out.
        DeviceToken::where('access_token_id', $request->user()->currentAccessToken()->id)->delete();
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /** Remembers this phone's push address. A new sign-in on the same phone takes it over. */
    public function device(Request $request): Response
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[.+\]$/']]);

        DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'access_token_id' => $request->user()->currentAccessToken()->id],
        );

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    /** The public values the app needs to reach the WebSocket server. */
    public function config(): JsonResponse
    {
        return response()->json([
            'key' => config('broadcasting.connections.reverb.key'),
            'port' => (int) config('broadcasting.connections.reverb.options.port'),
            'scheme' => config('broadcasting.connections.reverb.options.scheme'),
        ]);
    }

    public function workspaces(Request $request, Invitations $invitations): JsonResponse
    {
        $invitations->acceptFor($request->user());

        return response()->json(
            $request->user()->workspaces()->orderBy('name')->get(['workspaces.id', 'name', 'slug'])
                ->map(fn ($workspace) => $workspace->only('id', 'name', 'slug')),
        );
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatarUrl(),
        ];
    }
}
