<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ShopOwner;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $shopOwner = ShopOwner::where('email', 'test@example.com')->first() ?? ShopOwner::first();

        if (!$shopOwner) {
            $this->command->error('No shop owner found. Please run ShopOwnerSeeder first.');
            return;
        }

        $appUrl = rtrim((string) config('app.url', 'http://localhost'), '/');

        $product = Product::updateOrCreate(
            [
                'shop_owner_id' => $shopOwner->id,
                'sku' => 'SEED-PRD-001',
            ],
            [
                'name' => 'Seeded Nike Air Sample',
                'description' => 'Sample seeded product for local development and QA checks.',
                'price' => 3499.00,
                'compare_at_price' => 3999.00,
                'brand' => 'Nike',
                'category' => 'shoes',
                'stock_quantity' => 15,
                'is_active' => true,
                'is_featured' => false,
                'main_image' => $appUrl . '/images/product/product-01.jpg',
                'additional_images' => [
                    $appUrl . '/images/product/product-02.jpg',
                    $appUrl . '/images/product/product-03.jpg',
                ],
                'sizes_available' => ['8', '9', '10'],
                'colors_available' => ['Black', 'White'],
                'weight' => 0.90,
            ]
        );

        $this->command->info('Product seeded: #' . $product->id . ' (' . $product->name . ')');
    }
}