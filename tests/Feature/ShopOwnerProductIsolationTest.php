<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopOwnerProductIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(ShopOwner $shopOwner, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Test Shoe ' . uniqid(),
            'description' => 'Product isolation test item',
            'price' => 2500,
            'brand' => 'SoleSpace',
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ], $overrides));
    }

    /** @test */
    public function shop_owner_product_routes_use_the_shop_owner_session_even_when_a_staff_session_is_also_active(): void
    {
        $individualShopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'individual',
        ]);

        $companyShopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'company',
        ]);

        /** @var User $staffUser */
        $staffUser = User::factory()->create([
            'shop_owner_id' => $companyShopOwner->id,
        ]);

        $individualProduct = $this->createProduct($individualShopOwner, [
            'name' => 'Individual Account Shoe',
        ]);

        $companyProduct = $this->createProduct($companyShopOwner, [
            'name' => 'Company Account Shoe',
        ]);

        $this->actingAs($staffUser, 'user');
        $this->actingAs($individualShopOwner, 'shop_owner');

        $listResponse = $this->getJson('/api/shop-owner/products');

        $listResponse->assertOk()
            ->assertJsonPath('success', true);

        $productIds = collect($listResponse->json('products'))->pluck('id')->all();

        $this->assertContains($individualProduct->id, $productIds);
        $this->assertNotContains($companyProduct->id, $productIds);

        $createResponse = $this->postJson('/api/shop-owner/products', [
            'name' => 'Created From Individual Session',
            'description' => 'Should stay on the individual account.',
            'price' => 1999.99,
            'brand' => 'Isolation',
            'category' => 'shoes',
            'stock_quantity' => 7,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('product.shop_owner_id', $individualShopOwner->id);

        $this->assertDatabaseHas('products', [
            'name' => 'Created From Individual Session',
            'shop_owner_id' => $individualShopOwner->id,
        ]);

        $this->assertDatabaseMissing('products', [
            'name' => 'Created From Individual Session',
            'shop_owner_id' => $companyShopOwner->id,
        ]);
    }

    /** @test */
    public function company_shop_owner_cannot_create_a_standalone_product(): void
    {
        $companyShopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'company',
        ]);

        $payload = [
            'name' => 'Company Standalone Shoe',
            'description' => 'Company products must be linked to inventory.',
            'price' => 1999.99,
            'brand' => 'Inventory Boundary',
            'category' => 'shoes',
            'stock_quantity' => 7,
        ];

        $this->actingAs($companyShopOwner, 'shop_owner')
            ->postJson('/api/shop-owner/products', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'ERP_ROUTE_NOT_ALLOWED');

        $this->assertDatabaseMissing('products', [
            'name' => $payload['name'],
            'shop_owner_id' => $companyShopOwner->id,
        ]);
    }

    /** @test */
    public function company_shop_owner_catalog_exposes_uploaded_and_inventory_backed_products(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $companyShopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'company',
        ]);

        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $companyShopOwner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $standaloneProduct = $this->createProduct($companyShopOwner, [
            'name' => 'Legacy Standalone Shoe',
        ]);
        $inventoryBackedProduct = $this->createProduct($companyShopOwner, [
            'name' => 'Inventory Backed Shoe',
        ]);

        InventoryItem::factory()->create([
            'shop_owner_id' => $companyShopOwner->id,
            'product_id' => $inventoryBackedProduct->id,
            'category' => 'shoes',
        ]);

        $response = $this->actingAs($companyShopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/products');

        $response->assertOk();

        $productIds = collect($response->json('products'))->pluck('id')->all();

        $this->assertContains($inventoryBackedProduct->id, $productIds);
        $this->assertContains($standaloneProduct->id, $productIds);
    }

    /** @test */
    public function individual_shop_owner_can_create_a_product_when_route_enforcement_is_enabled(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'individual',
        ]);

        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $payload = [
            'name' => 'Enforced Individual Shoe',
            'description' => 'Individual owner product under the canonical route contract.',
            'price' => 2199.99,
            'brand' => 'SoleSpace',
            'category' => 'shoes',
            'stock_quantity' => 5,
        ];

        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson('/api/shop-owner/products', $payload)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('product.shop_owner_id', $shopOwner->id);

        $this->assertDatabaseHas('products', [
            'name' => $payload['name'],
            'shop_owner_id' => $shopOwner->id,
        ]);
    }
}
