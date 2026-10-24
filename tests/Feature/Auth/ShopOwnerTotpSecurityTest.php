<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\ShopOwner;
use App\Services\EmployeeMfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class ShopOwnerTotpSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_owner_can_start_and_complete_totp_setup_with_current_password(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'password' => Hash::make('Password1!'),
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/security/totp/setup', [
                'current_password' => 'wrong-password',
            ])
            ->assertStatus(422);

        $setupResponse = $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/security/totp/setup', [
                'current_password' => 'Password1!',
            ])
            ->assertOk()
            ->assertJsonStructure(['qr_code', 'manual_key', 'expires_at']);

        $google2fa = new Google2FA();
        $code = $google2fa->oathTotp(
            (string) $setupResponse->json('manual_key'),
            intdiv(now()->timestamp, 30),
        );

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/security/totp/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonStructure(['message', 'recovery_codes']);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/security/totp/verify', ['code' => $code])
            ->assertStatus(422);

        $owner->refresh();

        self::assertTrue($owner->hasTotpEnabled());
        self::assertNotEmpty($owner->shop_owner_totp_recovery_codes);
        self::assertArrayNotHasKey('shop_owner_totp_secret', $owner->toArray());
        self::assertArrayNotHasKey('shop_owner_totp_recovery_codes', $owner->toArray());
        self::assertNotSame(
            $setupResponse->json('manual_key'),
            $this->app['db']->table('shop_owners')->where('id', $owner->id)->value('shop_owner_totp_secret'),
        );
    }

    public function test_shop_owner_can_regenerate_recovery_codes_only_with_password_and_totp(): void
    {
        [$owner, $secret] = $this->totpOwner();
        $code = $this->totpCode($secret);

        $response = $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/security/totp/recovery-codes/regenerate', [
                'current_password' => 'Password1!',
                'code' => $code,
            ])
            ->assertOk()
            ->assertJsonStructure(['message', 'recovery_codes']);

        self::assertCount(8, $response->json('recovery_codes'));

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/security/totp/recovery-codes/regenerate', [
                'current_password' => 'Password1!',
                'code' => $code,
            ])
            ->assertStatus(422);
    }

    public function test_shop_owner_can_disable_totp_only_with_password_and_totp(): void
    {
        [$owner, $secret] = $this->totpOwner();

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/security/totp/disable', [
                'current_password' => 'Password1!',
                'code' => $this->totpCode($secret),
            ])
            ->assertOk()
            ->assertJson(['message' => 'Two-factor authentication disabled.']);

        self::assertFalse($owner->fresh()->hasTotpEnabled());
    }

    public function test_pending_totp_setup_is_bound_to_the_authenticated_owner(): void
    {
        [$owner, $secret] = $this->totpOwner();
        $owner->forceFill([
            'shop_owner_totp_secret' => null,
            'shop_owner_totp_enabled_at' => null,
            'shop_owner_totp_recovery_codes' => null,
            'shop_owner_totp_last_used_timestep' => null,
        ])->save();

        $otherOwner = ShopOwner::factory()->approved()->create([
            'password' => Hash::make('Password1!'),
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/security/totp/setup', [
                'current_password' => 'Password1!',
            ])
            ->assertOk();

        $this->actingAs($otherOwner, 'shop_owner')
            ->postJson('/shop-owner/security/totp/verify', [
                'code' => $this->totpCode($secret),
            ])
            ->assertStatus(422);

        self::assertFalse($otherOwner->fresh()->hasTotpEnabled());
    }

    public function test_totp_endpoints_require_shop_owner_authentication(): void
    {
        $this->postJson('/shop-owner/security/totp/setup', [
            'current_password' => 'Password1!',
        ])->assertUnauthorized();
    }

    /** @return array{0: ShopOwner, 1: string} */
    private function totpOwner(): array
    {
        $owner = ShopOwner::factory()->approved()->create([
            'password' => Hash::make('Password1!'),
        ]);
        $secret = (new Google2FA())->generateSecretKey(32);
        $mfa = app(EmployeeMfaService::class);

        $owner->forceFill([
            'shop_owner_totp_secret' => $secret,
            'shop_owner_totp_enabled_at' => now(),
            'shop_owner_totp_recovery_codes' => $mfa->hashRecoveryCodes($mfa->generateRecoveryCodes()),
            'shop_owner_totp_last_used_timestep' => null,
        ])->save();

        return [$owner->fresh(), $secret];
    }

    private function totpCode(string $secret): string
    {
        return (new Google2FA())->oathTotp($secret, intdiv(now()->timestamp, 30));
    }
}
