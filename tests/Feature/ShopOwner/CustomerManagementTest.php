<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner;

use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_spent_includes_the_stored_vat_and_shipping_amounts(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create();

        Order::create([
            'shop_owner_id' => $shopOwner->getKey(),
            'customer_id' => $customer->getKey(),
            'order_number' => 'ORD-CUSTOMER-METRICS-1',
            'total_amount' => 100.00,
            'shipping_fee' => 10.00,
            'vat_amount' => 12.00,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/customers');

        $response->assertOk()
            ->assertJsonPath('customers.0.id', $customer->getKey())
            ->assertJsonPath('customers.0.totalSpent', 122);
    }
}
