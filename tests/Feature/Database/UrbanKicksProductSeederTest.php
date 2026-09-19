<?php

namespace Tests\Feature\Database;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use Database\Seeders\ShopOwnerSeeder;
use Database\Seeders\Test2ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrbanKicksProductSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_urban_kicks_gets_a_broad_catalog_with_variants_and_safe_reruns(): void
    {
        $this->seed(ShopOwnerSeeder::class);
        $this->seed(Test2ProductSeeder::class);

        $shop = ShopOwner::where('email', 'test2@example.com')->firstOrFail();
        $products = Product::where('shop_owner_id', $shop->id)->get();

        $this->assertGreaterThanOrEqual(16, $products->count());
        $this->assertEqualsCanonicalizing(
            ['men', 'women', 'kids', 'sports'],
            $products->whereIn('category', ['men', 'women', 'kids', 'sports'])->pluck('category')->unique()->values()->all(),
        );
        $this->assertGreaterThan($products->count(), ProductVariant::whereIn('product_id', $products->pluck('id'))->count());

        $productCount = $products->count();
        $variantCount = ProductVariant::whereIn('product_id', $products->pluck('id'))->count();

        $this->seed(Test2ProductSeeder::class);

        $this->assertSame($productCount, Product::where('shop_owner_id', $shop->id)->count());
        $this->assertSame($variantCount, ProductVariant::whereIn('product_id', $products->pluck('id'))->count());
    }
}
