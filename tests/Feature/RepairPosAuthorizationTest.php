<?php

namespace Tests\Feature;

use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairPosAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repairer_without_unified_pos_permission_cannot_open_cashier_pos_route(): void
    {
        $repairer = User::factory()->create([
            'role' => 'REPAIRER',
        ]);

        $this->actingAs($repairer, 'user')
            ->get('/erp/cashier/point-of-sale')
            ->assertStatus(403);
    }

    #[Test]
    public function checkout_rejects_user_from_different_shop(): void
    {
        $owningShop = ShopOwner::factory()->create();
        $otherShop = ShopOwner::factory()->create();

        /** @var User $customer */
        $customer = User::factory()->create();

        $repair = RepairRequest::create([
            'request_id' => 'REP-TDD-AUTH-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09171111111',
            'shoe_type' => 'Sneakers',
            'description' => 'Authorization test',
            'shop_owner_id' => $owningShop->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $intruder = User::factory()->create(['shop_owner_id' => $otherShop->id]);

        $this->actingAs($intruder, 'user')
            ->postJson('/api/repair-pos/checkout', [
                'repair_request_id' => $repair->id,
                'due_type' => 'deposit',
                'customer_type' => 'walk_in',
                'walk_in_name' => 'Walk In',
                'payment_lines' => [
                    ['tender_type' => 'cash', 'amount' => 560],
                ],
                'idempotency_key' => 'key-auth-deny-1',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'AUTH_FORBIDDEN_SHOP_SCOPE');
    }
}
