<?php

namespace Tests\Feature;

use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairPosTenderVerificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function non_cash_tender_is_created_as_pending_authorization(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $actor = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $customer = User::factory()->create();

        $repair = RepairRequest::create([
            'request_id' => 'REP-TDD-VERIFY-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000999',
            'shoe_type' => 'Sneakers',
            'description' => 'Tender verification test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'full',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Wallet Pending',
            'idempotency_key' => 'idem-wallet-001',
            'payment_lines' => [
                [
                    'tender_type' => 'paymongo_wallet',
                    'amount' => 1120,
                    'provider_reference' => 'PM-REF-001',
                ],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('pos_payment_lines', [
            'tender_type' => 'paymongo_wallet',
            'status' => 'pending_authorization',
        ]);
    }
}
