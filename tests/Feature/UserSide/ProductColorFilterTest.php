<?php

namespace Tests\Feature\UserSide;

use App\Models\Product;
use App\Models\ProductColorVariant;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductColorFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_color_filter_matches_new_variants_combined_colors_and_legacy_colors(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        $redProduct = $this->createProduct($shop, 'Red Runner');
        ProductColorVariant::create([
            'product_id' => $redProduct->id,
            'color_name' => 'Red + White',
            'color_code' => '#ef4444',
            'is_active' => true,
        ]);

        $blueProduct = $this->createProduct($shop, 'Blue Court');
        ProductVariant::create([
            'product_id' => $blueProduct->id,
            'size' => '9',
            'color' => 'Blue',
            'quantity' => 3,
            'is_active' => true,
        ]);

        $yellowProduct = $this->createProduct($shop, 'Yellow Legacy', [
            'colors_available' => ['Yellow'],
        ]);

        $response = $this->getJson('/api/products?filter[color]=rEd&per_page=50');

        $response->assertOk()
            ->assertJsonPath('available_colors.0.name', 'Blue')
            ->assertJsonFragment(['name' => 'Red + White', 'code' => '#ef4444']);

        $ids = collect($response->json('products.data'))->pluck('id');
        $this->assertSame([$redProduct->id], $ids->all());

        $multiple = $this->getJson('/api/products?filter[color]=blue,yellow&per_page=50');
        $multipleIds = collect($multiple->json('products.data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$blueProduct->id, $yellowProduct->id], $multipleIds);
    }

    public function test_color_filter_ignores_inactive_variants_and_unapproved_shops(): void
    {
        $approvedShop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);
        $pendingShop = ShopOwner::factory()->pending()->create([
            'business_type' => 'retail',
        ]);

        $inactiveVariantProduct = $this->createProduct($approvedShop, 'Inactive Red');
        ProductColorVariant::create([
            'product_id' => $inactiveVariantProduct->id,
            'color_name' => 'Red',
            'is_active' => false,
        ]);

        $pendingProduct = $this->createProduct($pendingShop, 'Pending Red');
        ProductVariant::create([
            'product_id' => $pendingProduct->id,
            'size' => '9',
            'color' => 'Red',
            'quantity' => 3,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/products?filter[color]=red&per_page=50');

        $response->assertOk();
        $this->assertSame([], collect($response->json('products.data'))->pluck('id')->all());
    }

    private function createProduct(ShopOwner $shop, string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'shop_owner_id' => $shop->id,
            'name' => $name,
            'slug' => str($name)->slug(),
            'price' => 2500,
            'stock_quantity' => 5,
            'is_active' => true,
            'main_image' => 'products/test.jpg',
        ], $overrides));
    }
}
