<?php

namespace Tests\Feature\UserSide;

use App\Models\Product;
use App\Models\PromoCampaign;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductVoucherClaimTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_can_claim_active_voucher_once(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'business_type' => 'retail',
            'status' => 'approved',
        ]);

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Voucher Target Shoe',
            'slug' => 'voucher-target-shoe',
            'price' => 2500,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $campaign = PromoCampaign::create([
            'shop_owner_id' => $shopOwner->id,
            'kind' => 'voucher',
            'scope' => 'shop_wide',
            'name' => 'Weekend Drop',
            'code' => 'WEEKEND10',
            'discount_mode' => 'percentage',
            'value' => 10,
            'min_spend' => 1000,
            'usage_limit' => 100,
            'used_count' => 0,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'status' => 'active',
            'stacking_mode' => 'combinable',
        ]);

        /** @var User $customer */
        $customer = User::factory()->create([
            'shop_owner_id' => null,
        ]);

        $this->actingAs($customer, 'user');

        $this->postJson("/api/products/{$product->id}/vouchers/{$campaign->id}/claim")
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->postJson("/api/products/{$product->id}/vouchers/{$campaign->id}/claim")
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }
}
