<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProductPriceFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_products_apply_inclusive_minimum_and_maximum_price_filters(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        $below = $this->productFor($shop, 'price-below', 1999);
        $minimum = $this->productFor($shop, 'price-minimum', 2000);
        $maximum = $this->productFor($shop, 'price-maximum', 3000);
        $above = $this->productFor($shop, 'price-above', 3001);

        $response = $this->getJson('/api/products?filter[price_min]=2000&filter[price_max]=3000');

        $response->assertOk();
        $ids = collect($response->json('products.data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$minimum->id, $maximum->id], $ids);
        $this->assertNotContains($below->id, $ids);
        $this->assertNotContains($above->id, $ids);
    }

    public function test_public_products_reject_a_minimum_price_above_the_maximum_price(): void
    {
        $this->getJson('/api/products?filter[price_min]=3000&filter[price_max]=2000')
            ->assertStatus(422)
            ->assertJson([
                'errors' => [
                    'filter.price_min' => [
                        'The minimum price must be less than or equal to the maximum price.',
                    ],
                ],
            ]);
    }

    private function productFor(ShopOwner $shop, string $slug, float $price): Product
    {
        return Product::create([
            'shop_owner_id' => $shop->id,
            'name' => str_replace('-', ' ', $slug),
            'slug' => $slug,
            'price' => $price,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
    }
}
