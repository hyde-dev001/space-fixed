<?php

namespace Tests\Feature\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use Database\Seeders\Test2ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class Test2ProductSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_idempotently_seeds_the_test2_shop_product(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['email' => 'test2@example.com']);

        $this->seed(Test2ProductSeeder::class);
        $this->seed(Test2ProductSeeder::class);

        $product = Product::where('shop_owner_id', $shop->id)
            ->where('sku', 'TEST2-SHOE-001')
            ->sole();

        $this->assertSame('Urban Kicks Test Runner', $product->name);
        $this->assertSame('2499.00', $product->price);
        $this->assertSame(1000, $product->stock_quantity);
        $this->assertTrue($product->is_active);
        $this->assertSame(['7', '8', '9', '10', '11'], $product->sizes_available);
        $this->assertSame(['Black', 'White'], $product->colors_available);

        $variants = ProductVariant::where('product_id', $product->id)->get();

        $this->assertCount(10, $variants);
        $this->assertSame([100], $variants->pluck('quantity')->unique()->values()->all());
        $this->assertTrue($variants->every->is_active);
    }

    public function test_it_explains_when_the_test2_shop_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test2@example.com');

        $this->seed(Test2ProductSeeder::class);
    }
}
