<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactor
{
    private const RECOVERY_CODES = 8;

    /** One 30-second step either side, for phones whose clock is slightly off. */
    private const WINDOW = 1;

    public function __construct(private Google2FA $engine) {}

    public function newSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(config('app.name'), $user->email, $secret);
    }

    public function qrCode(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($url));
    }

    /** A code works once: reusing one seen on screen or over a shoulder is refused. */
    public function verifyCode(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{6}$/', $code) || ! $user->two_factor_secret) {
            return false;
        }

        $step = $this->engine->verifyKeyNewer($user->two_factor_secret, $code, $user->two_factor_last_step ?? 0, self::WINDOW);

        if ($step === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }

    /** A recovery code works once and is then removed. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = strtolower(trim($code));
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    public function passes(User $user, string $code): bool
    {
        return $this->verifyCode($user, $code) || $this->useRecoveryCode($user, $code);
    }

    /** @return array<int, string> The codes, shown once; only their hashes are stored. */
    public function issueRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, self::RECOVERY_CODES))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();

        $user->forceFill(['two_factor_recovery_codes' => array_map(fn (string $code) => Hash::make($code), $codes)])->save();

        return $codes;
    }
}
