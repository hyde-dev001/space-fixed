<?php

declare(strict_types=1);

namespace Tests\Feature\UserSide;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerIdentityAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_pending_customer_can_browse_products(): void
    {
        $user = $this->customer(User::IDENTITY_PENDING_REVIEW);

        $this->actingAs($user, 'user')
            ->get('/products')
            ->assertOk();
    }

    public function test_pending_customer_is_blocked_before_checkout_validation(): void
    {
        $user = $this->customer(User::IDENTITY_PENDING_REVIEW);

        $this->actingAs($user, 'user')
            ->postJson('/api/checkout/create-order', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'IDENTITY_VERIFICATION_REQUIRED');
    }

    public function test_rejected_customer_is_blocked_before_checkout_validation(): void
    {
        $user = $this->customer(User::IDENTITY_REJECTED);

        $this->actingAs($user, 'user')
            ->postJson('/api/checkout/create-order', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'IDENTITY_VERIFICATION_REQUIRED');
    }

    public function test_approved_customer_reaches_checkout_validation(): void
    {
        $user = $this->customer(User::IDENTITY_APPROVED);

        $response = $this->actingAs($user, 'user')
            ->postJson('/api/checkout/create-order', []);

        $this->assertNotSame(403, $response->status());
        $this->assertNotSame('IDENTITY_VERIFICATION_REQUIRED', $response->json('code'));
    }

    private function customer(string $identityStatus): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'identity_verification_status' => $identityStatus,
            'status' => 'active',
            'shop_owner_id' => null,
            'role' => null,
        ]);
    }
}
