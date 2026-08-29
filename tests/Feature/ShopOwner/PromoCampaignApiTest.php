<?php

namespace Tests\Feature\ShopOwner;

use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromoCampaignApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_promo_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('promo_campaigns'));
        $this->assertTrue(Schema::hasTable('promo_campaign_products'));
        $this->assertTrue(Schema::hasTable('voucher_claims'));
        $this->assertTrue(Schema::hasColumn('promo_campaigns', 'discount_target'));
    }

    public function test_retail_shop_owner_can_read_products_and_create_product_scoped_voucher(): void
    {
        $owner = ShopOwner::factory()->create([
            'business_type' => 'retail',
            'status' => 'approved',
        ]);
        $otherOwner = ShopOwner::factory()->create([
            'business_type' => 'retail',
            'status' => 'approved',
        ]);
        $product = Product::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Weekend Runner',
            'price' => 2500,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $otherProduct = Product::create([
            'shop_owner_id' => $otherOwner->id,
            'name' => 'Other Runner',
            'price' => 3000,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($owner, 'shop_owner');

        $this->getJson('/api/shop-owner/promos/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id);

        $payload = [
            'kind' => 'voucher',
            'scope' => 'product_specific',
            'name' => 'Weekend Drop',
            'code' => 'SAVE10',
            'discount_mode' => 'percentage',
            'value' => 10,
            'min_spend' => 2000,
            'usage_limit' => 100,
            'start_at' => now()->subHour()->toISOString(),
            'end_at' => now()->addDays(7)->toISOString(),
            'product_ids' => [$product->id, $otherProduct->id],
        ];

        $this->postJson('/api/shop-owner/promos', $payload)
            ->assertCreated()
            ->assertJsonPath('data.shop_owner_id', $owner->id)
            ->assertJsonPath('data.products.0.id', $product->id)
            ->assertJsonMissing(['id' => $otherProduct->id]);

        $this->getJson('/api/shop-owner/promos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.products.0.id', $product->id);

        $this->assertDatabaseHas('promo_campaigns', [
            'shop_owner_id' => $owner->id,
            'code' => 'SAVE10',
        ]);
    }

    public function test_non_retail_shop_owner_cannot_use_promo_api(): void
    {
        $owner = ShopOwner::factory()->create([
            'business_type' => 'repair',
            'status' => 'approved',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/promos/products')
            ->assertForbidden();
    }

    public function test_company_shop_with_logistics_can_create_shipping_voucher(): void
    {
        $owner = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
            'status' => 'approved',
        ]);
        ShopOwnerModule::create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);

        $response = $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/promos', [
                'kind' => 'voucher',
                'discount_target' => 'shipping',
                'scope' => 'shop_wide',
                'name' => 'Flexible Shipping Saver',
                'code' => 'SHIP50',
                'discount_mode' => 'percentage',
                'value' => 50,
                'min_spend' => 1234,
                'usage_limit' => 100,
                'start_at' => now()->subHour()->toISOString(),
                'end_at' => now()->addDays(7)->toISOString(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.discount_target', 'shipping')
            ->assertJsonPath('data.scope', 'shop_wide');

        $this->getJson('/api/shop-owner/promos')
            ->assertOk()
            ->assertJsonPath('logistics.accessible', true);
    }

    public function test_shipping_voucher_requires_accessible_logistics(): void
    {
        $owner = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
            'status' => 'approved',
        ]);
        ShopOwnerModule::create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'logistics',
            'enabled' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/promos', [
                'kind' => 'voucher',
                'discount_target' => 'shipping',
                'scope' => 'shop_wide',
                'name' => 'Unavailable Shipping Saver',
                'code' => 'SHIPOFF',
                'discount_mode' => 'fixed',
                'value' => 75,
                'min_spend' => 0,
                'usage_limit' => 100,
                'start_at' => now()->subHour()->toISOString(),
                'end_at' => now()->addDays(7)->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'Shipping vouchers require accessible Shop-owned Logistics.']);
    }

    public function test_shipping_voucher_is_always_shop_wide(): void
    {
        $owner = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
            'status' => 'approved',
        ]);
        ShopOwnerModule::create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);
        $product = Product::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Shipping Voucher Sneaker',
            'slug' => 'shipping-voucher-sneaker-' . random_int(1000, 9999),
            'description' => 'Shipping voucher scope test product',
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/promos', [
                'kind' => 'voucher',
                'discount_target' => 'shipping',
                'scope' => 'product_specific',
                'product_ids' => [$product->id],
                'name' => 'Product Shipping Saver',
                'code' => 'SHIPITEM',
                'discount_mode' => 'percentage',
                'value' => 20,
                'min_spend' => 0,
                'usage_limit' => 100,
                'start_at' => now()->subHour()->toISOString(),
                'end_at' => now()->addDays(7)->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'Shipping vouchers must be shop-wide.']);
    }
}
