<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/** OpenID Connect sign in with the one provider the server's operator configured. */
class SsoClient
{
    private const CACHE_SECONDS = 3600;

    private const TIMEOUT_SECONDS = 10;

    public function enabled(): bool
    {
        return (bool) ($this->issuer() && config('services.sso.client_id') && config('services.sso.client_secret'));
    }

    public function issuer(): string
    {
        return rtrim((string) config('services.sso.issuer'), '/');
    }

    public function authorizeUrl(string $state, string $nonce, string $verifier, string $redirectUri): string
    {
        return $this->discovery()['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.sso.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function randomToken(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    /**
     * @return array{sub: string, email: string, name: string}
     *
     * @throws RuntimeException when the provider's answer cannot be trusted.
     */
    public function identity(string $code, string $verifier, string $nonce, string $redirectUri): array
    {
        try {
            $tokens = Http::asForm()->timeout(self::TIMEOUT_SECONDS)->post($this->discovery()['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => config('services.sso.client_id'),
                'client_secret' => config('services.sso.client_secret'),
                'code_verifier' => $verifier,
            ])->throw()->json();
        } catch (ConnectionException|RequestException $e) {
            throw new RuntimeException('The token request failed.', 0, $e);
        }

        $claims = $this->verifiedClaims((string) ($tokens['id_token'] ?? ''), $nonce);

        if (! isset($claims['email']) && isset($tokens['access_token'], $this->discovery()['userinfo_endpoint'])) {
            $claims = [...$this->userInfo($tokens['access_token'], $claims['sub']), ...$claims];
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($claims['email_verified'] ?? false, [true, 'true'], true)) {
            throw new RuntimeException('The provider did not confirm a verified email.');
        }

        return ['sub' => $claims['sub'], 'email' => $email, 'name' => trim((string) ($claims['name'] ?? '')) ?: strstr($email, '@', true)];
    }

    /** @return array<string, mixed> */
    private function verifiedClaims(string $idToken, string $nonce): array
    {
        try {
            $claims = (array) $this->decode($idToken);
        } catch (Throwable $e) {
            throw new RuntimeException('The ID token could not be verified.', 0, $e);
        }

        $audiences = (array) ($claims['aud'] ?? []);
        $clientId = config('services.sso.client_id');

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $this->issuer()
            || ! in_array($clientId, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $clientId)
            || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))
            || empty($claims['sub'])) {
            throw new RuntimeException('The ID token was not meant for this sign in.');
        }

        return $claims;
    }

    private function decode(string $idToken): object
    {
        try {
            return JWT::decode($idToken, JWK::parseKeySet($this->keys(), 'RS256'));
        } catch (UnexpectedValueException $e) {
            // The provider may have rotated its keys since they were cached.
            Cache::forget($this->cacheKey('keys'));

            return JWT::decode($idToken, JWK::parseKeySet($this->keys(), 'RS256'));
        }
    }

    /** @return array<string, mixed> */
    private function userInfo(string $accessToken, string $subject): array
    {
        try {
            $info = Http::withToken($accessToken)->timeout(self::TIMEOUT_SECONDS)
                ->get($this->discovery()['userinfo_endpoint'])->throw()->json();
        } catch (ConnectionException|RequestException $e) {
            throw new RuntimeException('The profile request failed.', 0, $e);
        }

        if (($info['sub'] ?? null) !== $subject) {
            throw new RuntimeException('The profile belongs to someone else.');
        }

        return $info;
    }

    /** @return array<string, mixed> */
    private function discovery(): array
    {
        return Cache::remember($this->cacheKey('discovery'), self::CACHE_SECONDS, function () {
            try {
                $document = Http::timeout(self::TIMEOUT_SECONDS)
                    ->get($this->issuer().'/.well-known/openid-configuration')->throw()->json();
            } catch (ConnectionException|RequestException $e) {
                throw new RuntimeException('The provider could not be reached.', 0, $e);
            }

            if (rtrim((string) ($document['issuer'] ?? ''), '/') !== $this->issuer()) {
                throw new RuntimeException('The provider names a different issuer.');
            }

            return $document;
        });
    }

    /** @return array<string, mixed> */
    private function keys(): array
    {
        return Cache::remember($this->cacheKey('keys'), self::CACHE_SECONDS, fn () => Http::timeout(self::TIMEOUT_SECONDS)
            ->get($this->discovery()['jwks_uri'])->throw()->json());
    }

    private function cacheKey(string $name): string
    {
        return "sso.{$name}.".md5($this->issuer());
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
