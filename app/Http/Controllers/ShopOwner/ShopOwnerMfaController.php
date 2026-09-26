<?php

declare(strict_types=1);

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\ShopOwner;
use App\Services\EmployeeMfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Throwable;

final class ShopOwnerMfaController extends Controller
{
    private const SETUP_SESSION_KEY = 'shop_owner_totp_pending_setup';

    private const SETUP_TTL_MINUTES = 10;

    public function __construct(private readonly EmployeeMfaService $mfa)
    {
    }

    public function setup(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);
        $shopOwner = $this->shopOwner();

        if (! Hash::check((string) $validated['current_password'], (string) $shopOwner->getAuthPassword())) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        if ($shopOwner->hasTotpEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is already enabled.'], 409);
        }

        $secret = $this->mfa->generateSecret();
        $expiresAt = now()->addMinutes(self::SETUP_TTL_MINUTES);

        $request->session()->put(self::SETUP_SESSION_KEY, [
            'shop_owner_id' => (int) $shopOwner->getKey(),
            'secret' => Crypt::encryptString($secret),
            'expires_at' => $expiresAt->timestamp,
        ]);

        return response()->json([
            'qr_code' => $this->mfa->qrDataUri(
                $this->mfa->provisioningUriForEmail((string) $shopOwner->email, $secret),
            ),
            'manual_key' => $secret,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function verifySetup(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);
        $shopOwner = $this->shopOwner();
        $pending = $this->pendingSetup($request, $shopOwner);

        if ($pending === null) {
            return $this->invalidSetup();
        }

        try {
            $secret = Crypt::decryptString((string) $pending['secret']);
        } catch (Throwable) {
            $request->session()->forget(self::SETUP_SESSION_KEY);

            return $this->invalidSetup();
        }

        try {
            $recoveryCodes = DB::transaction(function () use ($shopOwner, $secret, $validated): array {
                $lockedOwner = ShopOwner::query()
                    ->lockForUpdate()
                    ->find($shopOwner->getKey());

                if (! $lockedOwner instanceof ShopOwner
                    || $lockedOwner->hasTotpEnabled()
                    || ! $this->mfa->verifyTotpForSecret($secret, (string) $validated['code'])) {
                    throw new InvalidArgumentException('Invalid TOTP setup verification.');
                }

                $recoveryCodes = $this->mfa->generateRecoveryCodes();
                $lockedOwner->forceFill([
                    'shop_owner_totp_secret' => $secret,
                    'shop_owner_totp_enabled_at' => now(),
                    'shop_owner_totp_recovery_codes' => $this->mfa->hashRecoveryCodes($recoveryCodes),
                    'shop_owner_totp_last_used_timestep' => null,
                    'two_factor_email_enabled' => false,
                ])->save();

                return $recoveryCodes;
            });
        } catch (InvalidArgumentException) {
            return $this->invalidSetup();
        }

        $request->session()->forget(self::SETUP_SESSION_KEY);

        return response()->json([
            'message' => 'Two-factor authentication enabled.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            $recoveryCodes = DB::transaction(function () use ($validated): array {
                $lockedOwner = $this->lockedShopOwner();

                if (! $this->validTotpReauthentication($lockedOwner, $validated)) {
                    throw new InvalidArgumentException('Invalid TOTP reauthentication.');
                }

                $recoveryCodes = $this->mfa->generateRecoveryCodes();
                $lockedOwner->forceFill([
                    'shop_owner_totp_recovery_codes' => $this->mfa->hashRecoveryCodes($recoveryCodes),
                ])->save();

                return $recoveryCodes;
            });
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'The password or authenticator code is invalid.'], 422);
        }

        return response()->json([
            'message' => 'Recovery codes regenerated.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function disable(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            DB::transaction(function () use ($validated): void {
                $lockedOwner = $this->lockedShopOwner();

                if (! $this->validTotpReauthentication($lockedOwner, $validated)) {
                    throw new InvalidArgumentException('Invalid TOTP reauthentication.');
                }

                $lockedOwner->forceFill([
                    'shop_owner_totp_secret' => null,
                    'shop_owner_totp_enabled_at' => null,
                    'shop_owner_totp_recovery_codes' => null,
                    'shop_owner_totp_last_used_timestep' => null,
                    'two_factor_email_enabled' => false,
                ])->save();
            });
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'The password or authenticator code is invalid.'], 422);
        }

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    private function shopOwner(): ShopOwner
    {
        /** @var ShopOwner $shopOwner */
        $shopOwner = Auth::guard('shop_owner')->user();

        return $shopOwner;
    }

    private function lockedShopOwner(): ShopOwner
    {
        $shopOwner = $this->shopOwner();
        $lockedOwner = ShopOwner::query()
            ->lockForUpdate()
            ->find($shopOwner->getKey());

        if (! $lockedOwner instanceof ShopOwner) {
            throw new InvalidArgumentException('Shop Owner account is unavailable.');
        }

        return $lockedOwner;
    }

    /** @return array{shop_owner_id: int, secret: string, expires_at: int}|null */
    private function pendingSetup(Request $request, ShopOwner $shopOwner): ?array
    {
        $pending = $request->session()->get(self::SETUP_SESSION_KEY);

        if (! is_array($pending)
            || (int) ($pending['shop_owner_id'] ?? 0) !== (int) $shopOwner->getKey()
            || ! is_string($pending['secret'] ?? null)
            || ! is_numeric($pending['expires_at'] ?? null)) {
            return null;
        }

        if ((int) $pending['expires_at'] <= now()->timestamp) {
            $request->session()->forget(self::SETUP_SESSION_KEY);

            return null;
        }

        return [
            'shop_owner_id' => (int) $pending['shop_owner_id'],
            'secret' => $pending['secret'],
            'expires_at' => (int) $pending['expires_at'],
        ];
    }

    /** @param array{current_password: string, code: string} $validated */
    private function validTotpReauthentication(ShopOwner $lockedOwner, array $validated): bool
    {
        if (! $lockedOwner->hasTotpEnabled()
            || ! Hash::check((string) $validated['current_password'], (string) $lockedOwner->getAuthPassword())) {
            return false;
        }

        $secret = $lockedOwner->shop_owner_totp_secret;
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $acceptedTimestep = $this->mfa->consumeTotpState(
            $secret,
            $lockedOwner->shop_owner_totp_last_used_timestep,
            (string) $validated['code'],
            intdiv(now()->timestamp, 30),
        );

        if (! is_int($acceptedTimestep)) {
            return false;
        }

        $lockedOwner->shop_owner_totp_last_used_timestep = $acceptedTimestep;

        return true;
    }

    private function invalidSetup()
    {
        return response()->json(['message' => 'The verification code is invalid or the setup has expired.'], 422);
    }
}
