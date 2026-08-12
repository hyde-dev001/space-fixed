<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SuperAdmin;
use App\Services\PrivilegedMfaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class PrivilegedMfaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_secret_provisioning_uri_and_qr_are_generated_without_network_access(): void
    {
        $service = new PrivilegedMfaService(new Google2FA());
        $admin = SuperAdmin::factory()->mfaEnrolled()->make([
            'email' => 'mfa-admin@example.test',
        ]);
        $secret = $service->generateSecret();
        $admin->forceFill(['mfa_secret' => $secret]);

        $uri = $service->provisioningUri($admin);
        $qrDataUri = $service->qrDataUri($uri);
        $svg = base64_decode(substr($qrDataUri, strlen('data:image/svg+xml;base64,')), true);

        self::assertSame(32, mb_strlen($secret));
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        self::assertStringContainsString('otpauth://totp/', $uri);
        self::assertStringContainsString(rawurlencode((string) config('privileged_security.issuer')), $uri);
        self::assertStringContainsString(rawurlencode($admin->email), $uri);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $qrDataUri);
        self::assertIsString($svg);
        self::assertStringContainsString('<svg', $svg);
    }

    public function test_totp_accepts_one_adjacent_timestep_and_rejects_replay(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 15, 'UTC'));
        $service = new PrivilegedMfaService(new Google2FA());
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $currentTimestep = intdiv(Carbon::now()->timestamp, 30);
        $adjacentCode = (new Google2FA())->oathTotp($admin->mfa_secret, $currentTimestep - 1);

        $admin->forceFill(['mfa_last_used_timestep' => $currentTimestep - 2])->save();
        $acceptedTimestep = $service->consumeTotp($admin->fresh(), $adjacentCode, $currentTimestep);

        self::assertSame($currentTimestep - 1, $acceptedTimestep);
        self::assertSame($acceptedTimestep, $admin->fresh()->mfa_last_used_timestep);

        $this->expectException(\InvalidArgumentException::class);
        $service->consumeTotp($admin->fresh(), $adjacentCode, $currentTimestep);
    }

    public function test_recovery_codes_are_hashed_consumed_once_and_exhaustion_keeps_mfa_complete(): void
    {
        $service = new PrivilegedMfaService(new Google2FA());
        $codes = $service->generateRecoveryCodes();
        $hashes = $service->hashRecoveryCodes($codes);

        self::assertCount(8, $codes);
        self::assertCount(8, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[0-9A-F]{4}(?:-[0-9A-F]{4}){4}$/', $code);
        }
        foreach ($hashes as $index => $hash) {
            self::assertNotSame($codes[$index], $hash);
            self::assertTrue(Hash::check(str_replace('-', '', $codes[$index]), $hash));
        }

        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'mfa_recovery_codes' => $hashes,
        ]);

        self::assertTrue($service->consumeRecoveryCode($admin->fresh(), $codes[0]));
        self::assertFalse($service->consumeRecoveryCode($admin->fresh(), $codes[0]));

        foreach (array_slice($codes, 1) as $code) {
            self::assertTrue($service->consumeRecoveryCode($admin->fresh(), $code));
        }

        $admin = $admin->fresh();
        self::assertSame([], $admin->mfa_recovery_codes);
        self::assertTrue($admin->hasCompletedMfaSetup());
    }

    public function test_regenerating_recovery_codes_replaces_every_previous_hash(): void
    {
        $service = new PrivilegedMfaService(new Google2FA());
        $oldCodes = $service->generateRecoveryCodes();
        $oldHashes = $service->hashRecoveryCodes($oldCodes);
        $newCodes = $service->generateRecoveryCodes();
        $newHashes = $service->hashRecoveryCodes($newCodes);

        foreach ($oldCodes as $oldCode) {
            foreach ($newHashes as $newHash) {
                self::assertFalse(Hash::check(str_replace('-', '', $oldCode), $newHash));
            }
        }
        self::assertNotSame($oldHashes, $newHashes);
    }
}
