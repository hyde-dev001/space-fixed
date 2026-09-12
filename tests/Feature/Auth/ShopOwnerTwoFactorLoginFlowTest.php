<?php

namespace Tests\Feature\Auth;

use App\Models\ShopOwner;
use App\Services\EmployeeMfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ShopOwnerTwoFactorLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_login_redirects_to_totp_challenge_without_creating_an_email_otp(): void
    {
        Mail::fake();

        [$shopOwner, $secret] = $this->totpOwner('owner2fa@example.com');

        $response = $this->post('/shop-owner/login', [
            'email' => $shopOwner->email,
            'password' => 'Password1!',
        ]);

        $response->assertRedirect(route('shop-owner.two-factor.challenge'));
        $response->assertSessionHas('shop_owner_2fa_pending_id', $shopOwner->id);
        $response->assertSessionHas('shop_owner_2fa_attempts', 0);
        $response->assertSessionMissing('shop_owner_2fa_entry');
        self::assertSame(6, strlen($this->totpCode($secret)));
        Mail::assertNothingSent();
    }

    #[Test]
    public function shop_owner_totp_challenge_uses_the_shared_mfa_page_and_owner_routes(): void
    {
        [$shopOwner] = $this->totpOwner();

        $this->withSession([
            'shop_owner_2fa_pending_id' => $shopOwner->id,
            'shop_owner_2fa_remember' => false,
            'shop_owner_2fa_pending_at' => now()->timestamp,
            'shop_owner_2fa_attempts' => 0,
        ])->get('/shop-owner/two-factor')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/EmployeeMfaChallenge')
                ->where('verifyRoute', route('shop-owner.two-factor.verify'))
                ->where('loginRoute', route('login')));
    }

    #[Test]
    public function shop_owner_can_verify_totp_code_from_the_authenticator_app(): void
    {
        [$shopOwner, $secret] = $this->totpOwner();

        $response = $this->withSession([
            'shop_owner_2fa_pending_id' => $shopOwner->id,
            'shop_owner_2fa_remember' => false,
            'shop_owner_2fa_pending_at' => now()->timestamp,
            'shop_owner_2fa_attempts' => 0,
        ])->post('/shop-owner/two-factor/verify', [
            'code' => $this->totpCode($secret),
        ]);

        $response->assertRedirect(route('shop-owner.dashboard'));
        $this->assertAuthenticated('shop_owner');
        $response->assertSessionMissing('shop_owner_2fa_pending_id');
        $response->assertSessionMissing('shop_owner_2fa_attempts');
    }

    #[Test]
    public function shop_owner_can_verify_a_recovery_code(): void
    {
        [$shopOwner] = $this->totpOwner();
        $recoveryCode = 'ABCD-1234-EF56-7890-ABCD';
        $shopOwner->forceFill([
            'shop_owner_totp_recovery_codes' => app(EmployeeMfaService::class)->hashRecoveryCodes([$recoveryCode]),
        ])->save();

        $this->withSession([
            'shop_owner_2fa_pending_id' => $shopOwner->id,
            'shop_owner_2fa_remember' => false,
            'shop_owner_2fa_pending_at' => now()->timestamp,
            'shop_owner_2fa_attempts' => 0,
        ])->post('/shop-owner/two-factor/verify', [
            'code' => $recoveryCode,
        ])->assertRedirect(route('shop-owner.dashboard'));

        $this->assertAuthenticatedAs($shopOwner, 'shop_owner');
        self::assertEmpty($shopOwner->fresh()->shop_owner_totp_recovery_codes);
    }

    #[Test]
    public function legacy_email_otp_accounts_are_required_to_enroll_totp_before_login(): void
    {
        Mail::fake();
        $shopOwner = ShopOwner::factory()->approved()->create([
            'password' => Hash::make('Password1!'),
            'two_factor_email_enabled' => true,
        ]);

        $this->post('/shop-owner/login', [
            'email' => $shopOwner->email,
            'password' => 'Password1!',
        ])->assertRedirect(route('shop-owner.two-factor.enroll'));

        $pending = $this->app['session.store']->get('shop_owner_totp_login_enrollment');
        self::assertIsArray($pending);
        self::assertSame($shopOwner->id, (int) $pending['shop_owner_id']);
        self::assertGreaterThan(now()->timestamp, (int) $pending['expires_at']);
        Mail::assertNothingSent();

        $secret = Crypt::decryptString((string) $pending['secret']);
        $response = $this->postJson('/shop-owner/two-factor/enroll/verify', [
            'code' => $this->totpCode($secret),
        ])->assertOk()->assertJsonStructure(['recovery_codes', 'redirect']);

        $this->assertAuthenticatedAs($shopOwner, 'shop_owner');
        $shopOwner->refresh();
        self::assertTrue($shopOwner->hasTotpEnabled());
        self::assertFalse((bool) $shopOwner->two_factor_email_enabled);
        self::assertNotEmpty($response->json('recovery_codes'));
    }

    #[Test]
    public function an_owner_without_totp_can_complete_the_normal_login_flow(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'password' => Hash::make('Password1!'),
        ]);

        $this->post('/shop-owner/login', [
            'email' => $shopOwner->email,
            'password' => 'Password1!',
        ])->assertRedirect(route('shop-owner.dashboard'));

        $this->assertAuthenticatedAs($shopOwner, 'shop_owner');
    }

    /** @return array{0: ShopOwner, 1: string} */
    private function totpOwner(?string $email = null): array
    {
        $attributes = ['password' => Hash::make('Password1!')];
        if ($email !== null) {
            $attributes['email'] = $email;
        }

        $shopOwner = ShopOwner::factory()->approved()->create($attributes);
        $secret = (new Google2FA())->generateSecretKey(32);

        $shopOwner->forceFill([
            'shop_owner_totp_secret' => $secret,
            'shop_owner_totp_enabled_at' => now(),
            'shop_owner_totp_recovery_codes' => app(EmployeeMfaService::class)->hashRecoveryCodes(['ABCD-1234-EF56-7890-ABCD']),
            'shop_owner_totp_last_used_timestep' => null,
        ])->save();

        return [$shopOwner->fresh(), $secret];
    }

    private function totpCode(string $secret): string
    {
        return (new Google2FA())->oathTotp($secret, intdiv(now()->timestamp, 30));
    }
}
