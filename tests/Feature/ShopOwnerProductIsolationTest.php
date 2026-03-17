<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShopOwner;
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
}