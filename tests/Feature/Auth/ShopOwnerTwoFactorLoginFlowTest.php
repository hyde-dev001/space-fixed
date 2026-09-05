<?php

namespace Tests\Feature\Auth;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShopOwnerTwoFactorLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_login_redirects_to_two_factor_challenge_and_stores_session_entry(): void
    {
        Mail::fake();

        $shopOwner = ShopOwner::factory()->approved()->create([
            'email' => 'owner2fa@example.com',
            'password' => Hash::make('Password1!'),
            'two_factor_email_enabled' => true,
        ]);

        $response = $this->post('/shop-owner/login', [
            'email' => $shopOwner->email,
            'password' => 'Password1!',
        ]);

        $response->assertRedirect(route('shop-owner.two-factor.challenge'));
        $response->assertSessionHas('shop_owner_2fa_pending_id', $shopOwner->id);
        $response->assertSessionHas('shop_owner_2fa_entry', function ($entry) use ($shopOwner) {
            return is_array($entry)
                && (int) ($entry['shop_owner_id'] ?? 0) === (int) $shopOwner->id
                && is_string($entry['otp_hash'] ?? null)
                && (int) ($entry['attempts'] ?? -1) === 0
                && (int) ($entry['expires_at'] ?? 0) > now()->timestamp;
        });
    }

    #[Test]
    public function shop_owner_can_verify_two_factor_code_from_session_entry(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'password' => Hash::make('Password1!'),
            'two_factor_email_enabled' => true,
        ]);

        $response = $this->withSession([
            'shop_owner_2fa_pending_id' => $shopOwner->id,
            'shop_owner_2fa_remember' => false,
            'shop_owner_2fa_pending_at' => now()->timestamp,
            'shop_owner_2fa_entry' => [
                'shop_owner_id' => $shopOwner->id,
                'otp_hash' => Hash::make('123456'),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10)->timestamp,
            ],
        ])->post('/shop-owner/two-factor/verify', [
            'otp' => '123456',
        ]);

        $response->assertRedirect(route('shop-owner.dashboard'));
        $this->assertAuthenticated('shop_owner');
        $response->assertSessionMissing('shop_owner_2fa_pending_id');
        $response->assertSessionMissing('shop_owner_2fa_entry');
    }
}
