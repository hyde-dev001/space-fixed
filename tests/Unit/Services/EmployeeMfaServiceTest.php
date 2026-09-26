<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EmployeeMfaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class EmployeeMfaServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_builds_a_provisioning_uri_without_requiring_a_user_model(): void
    {
        $service = new EmployeeMfaService(new Google2FA());

        $uri = $service->provisioningUriForEmail(
            'shop-owner@example.test',
            'JBSWY3DPEHPK3PXP',
        );

        self::assertStringContainsString('otpauth://totp/', $uri);
        self::assertStringContainsString(rawurlencode((string) config('privileged_security.issuer')), $uri);
        self::assertStringContainsString(rawurlencode('shop-owner@example.test'), $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
    }

    public function test_it_consumes_a_totp_timestep_once_for_any_account_state(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 10, 12, 0, 15, 'UTC'));
        $google2fa = new Google2FA();
        $service = new EmployeeMfaService($google2fa);
        $secret = $google2fa->generateSecretKey(32);
        $timestep = intdiv(Carbon::now()->timestamp, 30);
        $code = $google2fa->oathTotp($secret, $timestep);

        self::assertSame(
            $timestep,
            $service->consumeTotpState($secret, null, $code, $timestep),
        );
        self::assertFalse(
            $service->consumeTotpState($secret, $timestep, $code, $timestep),
        );
    }

    public function test_it_removes_one_matching_recovery_hash_and_rejects_reuse(): void
    {
        $service = new EmployeeMfaService(new Google2FA());
        $code = 'ABCD-EF12-3456-7890-ABCD';
        $hashes = $service->hashRecoveryCodes([$code]);

        $remaining = $service->consumeRecoveryCodeFromHashes($hashes, $code);

        self::assertSame([], $remaining);
        self::assertFalse($service->consumeRecoveryCodeFromHashes($remaining, $code));
        self::assertTrue(Hash::check(str_replace('-', '', $code), $hashes[0]));
    }
}
