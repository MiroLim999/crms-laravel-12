<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Codes from an authenticator app (Google Authenticator, Microsoft
 * Authenticator, Authy, ...): TOTP, RFC 6238, 6 digits every 30 seconds.
 *
 * Works offline. The phone and CRMS each work out the code from the shared key
 * and the time, so nothing is sent anywhere. The computer's clock must be
 * within about a minute of the phone's.
 */
class TwoFactor
{
    /** Codes one step either side of now are accepted: a clock up to ~30 s off. */
    private const WINDOW = 1;

    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
        $this->engine->setWindow(self::WINDOW);
    }

    /** A new shared key: 32 base32 characters (160 bits). */
    public function newSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    /** The key as people type it into an app by hand: groups of four. */
    public function formatSecret(string $secret): string
    {
        return implode(' ', str_split($secret, 4));
    }

    /**
     * The QR code the app scans, as inline SVG (made here, offline). It holds
     * only otpauth://totp/CRMS:<email>?secret=...&issuer=CRMS.
     */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $url = $this->engine->getQRCodeUrl('CRMS', $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);

        // Inline in the page: drop the XML declaration.
        return trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', $svg));
    }

    /**
     * The time step of `code` when it is right for `secret` and newer than
     * `lastStep` (a code is used once), or null.
     */
    public function verify(string $secret, string $code, ?int $lastStep = null): ?int
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return null;
        }

        // 0 rather than null: with null the library answers true, not the step.
        $step = $this->engine->verifyKeyNewer($secret, $code, $lastStep ?? 0);

        return is_int($step) ? $step : null;
    }

    /** The code the app shows now (for the tests). */
    public function currentCode(string $secret): string
    {
        return $this->engine->getCurrentOtp($secret);
    }
}
