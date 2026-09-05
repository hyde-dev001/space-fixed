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

        $catalog = [
            ['sku' => 'UKS-MEN-001', 'name' => 'Urban Kicks Court Low', 'category' => 'men', 'price' => 3299, 'image' => 'product-01.jpg'],
            ['sku' => 'UKS-MEN-002', 'name' => 'Urban Kicks Street Runner', 'category' => 'men', 'price' => 3899, 'image' => 'product-02.jpg'],
            ['sku' => 'UKS-MEN-003', 'name' => 'Urban Kicks Daily High', 'category' => 'men', 'price' => 4299, 'image' => 'product-03.jpg'],
            ['sku' => 'UKS-MEN-004', 'name' => 'Urban Kicks Canvas One', 'category' => 'men', 'price' => 2499, 'image' => 'product-04.jpg'],
            ['sku' => 'UKS-MEN-005', 'name' => 'Urban Kicks Trail Motion', 'category' => 'men', 'price' => 4599, 'image' => 'product-05.jpg'],
            ['sku' => 'UKS-WOMEN-001', 'name' => 'Urban Kicks Nova Court', 'category' => 'women', 'price' => 3499, 'image' => 'product-06.jpg'],
            ['sku' => 'UKS-WOMEN-002', 'name' => 'Urban Kicks Bloom Runner', 'category' => 'women', 'price' => 3799, 'image' => 'product-07.jpg'],
            ['sku' => 'UKS-WOMEN-003', 'name' => 'Urban Kicks Cloud Walk', 'category' => 'women', 'price' => 2999, 'image' => 'product-08.jpg'],
            ['sku' => 'UKS-WOMEN-004', 'name' => 'Urban Kicks Studio Low', 'category' => 'women', 'price' => 3199, 'image' => 'product-01.jpg'],
            ['sku' => 'UKS-WOMEN-005', 'name' => 'Urban Kicks Metro Slip', 'category' => 'women', 'price' => 2799, 'image' => 'product-02.jpg'],
            ['sku' => 'UKS-KIDS-001', 'name' => 'Urban Kicks Mini Dash', 'category' => 'kids', 'price' => 1899, 'image' => 'product-03.jpg'],
            ['sku' => 'UKS-KIDS-002', 'name' => 'Urban Kicks School Court', 'category' => 'kids', 'price' => 2099, 'image' => 'product-04.jpg'],
            ['sku' => 'UKS-KIDS-003', 'name' => 'Urban Kicks Play Street', 'category' => 'kids', 'price' => 1799, 'image' => 'product-05.jpg'],
            ['sku' => 'UKS-KIDS-004', 'name' => 'Urban Kicks Junior High', 'category' => 'kids', 'price' => 2299, 'image' => 'product-06.jpg'],
            ['sku' => 'UKS-KIDS-005', 'name' => 'Urban Kicks Color Pop', 'category' => 'kids', 'price' => 1999, 'image' => 'product-07.jpg'],
            ['sku' => 'UKS-SPORTS-001', 'name' => 'Urban Kicks Sprint Pro', 'category' => 'sports', 'price' => 4999, 'image' => 'product-08.jpg'],
            ['sku' => 'UKS-SPORTS-002', 'name' => 'Urban Kicks Hoops Elite', 'category' => 'sports', 'price' => 5299, 'image' => 'product-01.jpg'],
            ['sku' => 'UKS-SPORTS-003', 'name' => 'Urban Kicks Cross Train', 'category' => 'sports', 'price' => 4399, 'image' => 'product-02.jpg'],
            ['sku' => 'UKS-SPORTS-004', 'name' => 'Urban Kicks Football X', 'category' => 'sports', 'price' => 4799, 'image' => 'product-03.jpg'],
            ['sku' => 'UKS-SPORTS-005', 'name' => 'Urban Kicks Game Day', 'category' => 'sports', 'price' => 5599, 'image' => 'product-04.jpg'],
        ];

        foreach ($catalog as $index => $definition) {
            $sizes = $definition['category'] === 'kids' ? ['1', '2', '3', '4', '5'] : ['7', '8', '9', '10', '11'];
            $colors = $index % 2 === 0 ? ['Black', 'White', 'Red'] : ['White', 'Gray', 'Blue'];
            $product = Product::updateOrCreate(
                ['shop_owner_id' => $shop->id, 'sku' => $definition['sku']],
                [
                    'name' => $definition['name'],
                    'slug' => strtolower(str_replace(' ', '-', $definition['name'])),
                    'description' => "{$definition['name']} from the Urban Kicks Store everyday collection.",
                    'price' => $definition['price'],
                    'brand' => 'Urban Kicks',
                    'category' => $definition['category'],
                    'stock_quantity' => 450,
                    'is_active' => true,
                    'is_featured' => $index < 8,
                    'main_image' => "products/{$definition['image']}",
                    'sizes_available' => $sizes,
                    'colors_available' => $colors,
                ],
            );

            foreach ($sizes as $size) {
                foreach ($colors as $color) {
                    ProductVariant::updateOrCreate(
                        ['product_id' => $product->id, 'size' => $size, 'color' => $color],
                        ['quantity' => 30, 'sku' => "{$definition['sku']}-{$size}-".strtoupper($color), 'is_active' => true],
                    );
                }
            }
        }

        $fixture = Product::updateOrCreate(
            ['shop_owner_id' => $shop->id, 'sku' => 'TEST2-SHOE-001'],
            [
                'name' => 'Urban Kicks Test Runner',
                'description' => 'Reusable test shoe for checkout, order, and logistics flows.',
                'price' => 2499.00,
                'brand' => 'Urban Kicks',
                'category' => 'shoes',
                'stock_quantity' => 1000,
                'is_active' => true,
                'sizes_available' => ['7', '8', '9', '10', '11'],
                'colors_available' => ['Black', 'White'],
            ],
        );

        foreach ($fixture->sizes_available as $size) {
            foreach ($fixture->colors_available as $color) {
                ProductVariant::updateOrCreate(
                    ['product_id' => $fixture->id, 'size' => $size, 'color' => $color],
                    ['quantity' => 100, 'sku' => "TEST2-SHOE-001-{$size}-".strtoupper($color), 'is_active' => true],
                );
            }
        }
    }
}
