<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RetailPosHistoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function history_returns_only_retail_pos_orders_for_actor_shop(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        $otherShopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        /** @var User $user */
        $user = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        Order::create([
            'shop_owner_id' => $shopOwner->id,
            'order_number' => 'RPOS-20260408-0001',
            'total_amount' => 1000,
            'status' => 'pending',
            'payment_status' => 'paid',
            'customer_name' => 'Walk-in 1',
            'payment_method' => 'cash',
            'paid_at' => now(),
        ]);

        Order::create([
            'shop_owner_id' => $shopOwner->id,
            'order_number' => 'ORD-20260408-0002',
            'total_amount' => 800,
            'status' => 'pending',
            'payment_status' => 'paid',
            'customer_name' => 'Online 1',
            'payment_method' => 'paymongo',
            'paid_at' => now(),
        ]);

        Order::create([
            'shop_owner_id' => $otherShopOwner->id,
            'order_number' => 'RPOS-20260408-9999',
            'total_amount' => 1200,
            'status' => 'pending',
            'payment_status' => 'paid',
            'customer_name' => 'Other Shop',
            'payment_method' => 'cash',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($user, 'user')
            ->getJson('/api/retail-pos/history');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', 'RPOS-20260408-0001');
    }
}
