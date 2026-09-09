<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use PragmaRX\Google2FA\Google2FA;

final class EmployeeMfaService
{
    public function __construct(private readonly Google2FA $google2fa)
    {
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function provisioningUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            (string) config('privileged_security.issuer'),
            (string) $user->email,
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

    public function verifyEnrollment(string $secret, string $code): bool
    {
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return false;
        }

        return $this->google2fa->verifyKey(
            $secret,
            $code,
            (int) config('privileged_security.totp_window', 1),
        );
    }

    public function consumeTotp(User $lockedUser, string $code, int $currentTimestep): bool
    {
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return false;
        }

        $secret = $lockedUser->employee_totp_secret;
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $oldTimestep = $lockedUser->employee_totp_last_used_timestep;
        $acceptedTimestep = $this->google2fa->verifyKeyNewer(
            $secret,
            $code,
            $oldTimestep ?? -1,
            (int) config('privileged_security.totp_window', 1),
            $currentTimestep,
        );

        if (! is_int($acceptedTimestep)
            || ($oldTimestep !== null && $acceptedTimestep <= $oldTimestep)) {
            return false;
        }

        $lockedUser->forceFill([
            'employee_totp_last_used_timestep' => $acceptedTimestep,
        ])->save();

        return true;
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

    public function consumeRecoveryCode(User $lockedUser, string $code): bool
    {
        try {
            $normalizedCode = self::normalizeRecoveryCode($code);
        } catch (InvalidArgumentException) {
            return false;
        }

        $storedCodes = $lockedUser->employee_totp_recovery_codes;
        if (! is_array($storedCodes)) {
            return false;
        }

        foreach ($storedCodes as $index => $storedCode) {
            if (! is_string($storedCode) || ! Hash::check($normalizedCode, $storedCode)) {
                continue;
            }

            unset($storedCodes[$index]);
            $lockedUser->forceFill([
                'employee_totp_recovery_codes' => array_values($storedCodes),
            ])->save();

            return true;
        }

        return false;
    }

    public function consumeSecondFactor(User $lockedUser, string $code, int $currentTimestep): string|false
    {
        if (preg_match('/^[0-9]{6}$/', $code) === 1) {
            return $this->consumeTotp($lockedUser, $code, $currentTimestep) ? 'totp' : false;
        }

        return $this->consumeRecoveryCode($lockedUser, $code) ? 'recovery_code' : false;
    }

    private static function formatRecoveryCode(string $code): string
    {
        return rtrim(chunk_split(strtoupper($code), 4, '-'), '-');
    }

    private static function normalizeRecoveryCode(string $code): string
    {
        $normalizedCode = strtoupper(str_replace(['-', ' ', "	"], '', trim($code)));

        if (preg_match('/^[0-9A-F]{20}$/', $normalizedCode) !== 1) {
            throw new InvalidArgumentException('Invalid recovery code.');
        }

        return $normalizedCode;
    }
}