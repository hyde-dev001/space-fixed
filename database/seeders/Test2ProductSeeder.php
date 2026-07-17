<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use Illuminate\Database\Seeder;
use RuntimeException;

class Test2ProductSeeder extends Seeder
{
    public function run(): void
    {
        $shop = ShopOwner::where('email', 'test2@example.com')->first()
            ?? throw new RuntimeException('Urban Kicks Store (test2@example.com) was not found. Run ShopOwnerSeeder first.');

        $sizes = ['7', '8', '9', '10', '11'];
        $colors = ['Black', 'White'];

        $product = Product::updateOrCreate([
            'shop_owner_id' => $shop->id,
            'sku' => 'TEST2-SHOE-001',
        ], [
            'name' => 'Urban Kicks Test Runner',
            'description' => 'Reusable test shoe for checkout, order, and logistics flows.',
            'price' => 2499.00,
            'brand' => 'Urban Kicks',
            'category' => 'shoes',
            'stock_quantity' => 1000,
            'is_active' => true,
            'sizes_available' => $sizes,
            'colors_available' => $colors,
        ]);

        foreach ($sizes as $size) {
            foreach ($colors as $color) {
                ProductVariant::updateOrCreate([
                    'product_id' => $product->id,
                    'size' => $size,
                    'color' => $color,
                ], [
                    'quantity' => 100,
                    'sku' => "TEST2-SHOE-001-{$size}-" . strtoupper($color),
                    'is_active' => true,
                ]);
            }
        }
    }
}
