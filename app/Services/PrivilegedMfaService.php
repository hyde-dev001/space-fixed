<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SuperAdmin;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use PragmaRX\Google2FA\Google2FA;

final class PrivilegedMfaService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function provisioningUri(SuperAdmin $admin): string
    {
        $secret = $admin->mfa_secret;

        if (! is_string($secret) || $secret === '') {
            throw new InvalidArgumentException('MFA secret is not enrolled.');
        }

        return $this->google2fa->getQRCodeUrl(
            (string) config('privileged_security.issuer'),
            $admin->email,
            $secret,
        );
    }

    public function qrDataUri(string $uri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(256),
            new SvgImageBackEnd(),
        );

        return 'data:image/svg+xml;base64,'.base64_encode((new Writer($renderer))->writeString($uri));
    }

    public function consumeTotp(SuperAdmin $lockedAdmin, string $code, int $currentTimestep): int
    {
        if (preg_match('/\A\d{6}\z/', $code) !== 1) {
            throw new InvalidArgumentException('Invalid MFA code.');
        }

        $secret = $lockedAdmin->mfa_secret;
        if (! is_string($secret) || $secret === '') {
            throw new InvalidArgumentException('Invalid MFA code.');
        }

        $oldTimestep = $lockedAdmin->mfa_last_used_timestep;
        $acceptedTimestep = $this->google2fa->verifyKeyNewer(
            $secret,
            $code,
            $oldTimestep ?? -1,
            (int) config('privileged_security.totp_window', 1),
            $currentTimestep,
        );

        if (! is_int($acceptedTimestep) || ($oldTimestep !== null && $acceptedTimestep <= $oldTimestep)) {
            throw new InvalidArgumentException('Invalid MFA code.');
        }

        $lockedAdmin->forceFill(['mfa_last_used_timestep' => $acceptedTimestep])->save();

        return $acceptedTimestep;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        $count = (int) config('privileged_security.recovery_code_count', 8);

        return array_map(
            static fn (): string => self::formatRecoveryCode(bin2hex(random_bytes(10))),
            range(1, $count),
        );
    }

    /** @param list<string> $codes
     *  @return list<string>
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_values(array_map(
            static fn (string $code): string => Hash::make(self::normalizeRecoveryCode($code)),
            $codes,
        ));
    }

    public function consumeRecoveryCode(SuperAdmin $lockedAdmin, string $code): bool
    {
        try {
            $normalizedCode = self::normalizeRecoveryCode($code);
        } catch (InvalidArgumentException) {
            return false;
        }

        $storedCodes = $lockedAdmin->mfa_recovery_codes;
        if (! is_array($storedCodes)) {
            return false;
        }

        foreach ($storedCodes as $index => $storedCode) {
            if (! is_string($storedCode) || ! Hash::check($normalizedCode, $storedCode)) {
                continue;
            }

            unset($storedCodes[$index]);
            $lockedAdmin->forceFill(['mfa_recovery_codes' => array_values($storedCodes)])->save();

            return true;
        }

        return false;
    }

    private static function formatRecoveryCode(string $code): string
    {
        return rtrim(chunk_split(strtoupper($code), 4, '-'), '-');
    }

    private static function normalizeRecoveryCode(string $code): string
    {
        $normalizedCode = strtoupper(str_replace(['-', ' ', "\t"], '', trim($code)));

        if (preg_match('/\A[0-9A-F]{20}\z/', $normalizedCode) !== 1) {
            throw new InvalidArgumentException('Invalid recovery code.');
        }

        return $normalizedCode;
    }
}
