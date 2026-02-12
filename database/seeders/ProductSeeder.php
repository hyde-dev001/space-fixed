<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\ShopOwner;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get both test shop owners from shop_owners table
        $shopOwner1 = ShopOwner::where('email', 'test@example.com')->first();
        $shopOwner2 = ShopOwner::where('email', 'test2@example.com')->first();
        
        if (!$shopOwner1 || !$shopOwner2) {
            echo "ERROR: Shop owners not found. Please run ShopOwnerSeeder first.\n";
            return;
        }

        echo "Found shop owners:\n";
        echo "1. {$shopOwner1->business_name} ({$shopOwner1->email})\n";
        echo "2. {$shopOwner2->business_name} ({$shopOwner2->email})\n\n";

        // Products for shop owner 1 (Test Business)
        $products1 = [
            [
                'name' => 'Nike Air Max 90',
                'description' => 'Classic comfort meets modern style in the Nike Air Max 90. Max Air cushioning in the heel provides comfort while the Waffle outsole gives you traction.',
                'price' => 1800.00,
                'compare_at_price' => 2200.00,
                'brand' => 'Nike',
                'category' => 'shoes',
                'stock_quantity' => 15,
                'sizes_available' => ['7', '8', '9', '10', '11'],
                'colors_available' => ['Black', 'White', 'Red'],
                'main_image' => '/images/product/product-01.jpg',
            ],
            [
                'name' => 'Adidas Samba',
                'description' => 'A timeless soccer-inspired shoe with a leather upper and gum sole.',
                'price' => 1600.00,
                'brand' => 'Adidas',
                'category' => 'shoes',
                'stock_quantity' => 20,
                'sizes_available' => ['7', '8', '9', '10'],
                'colors_available' => ['Black/White', 'Navy/White'],
                'main_image' => '/images/product/product-02.jpg',
            ],
            [
                'name' => 'New Balance 550',
                'description' => 'Retro basketball style with premium leather construction.',
                'price' => 1700.00,
                'compare_at_price' => 1900.00,
                'brand' => 'New Balance',
                'category' => 'shoes',
                'stock_quantity' => 12,
                'sizes_available' => ['8', '9', '10', '11'],
                'colors_available' => ['White/Green', 'Grey/White'],
                'main_image' => '/images/product/product-03.jpg',
            ],
            [
                'name' => 'Classic Loafers',
                'description' => 'Elegant leather loafers perfect for formal occasions.',
                'price' => 1500.00,
                'brand' => 'Premium Brand',
                'category' => 'shoes',
                'stock_quantity' => 10,
                'sizes_available' => ['7', '8', '9', '10'],
                'colors_available' => ['Brown', 'Black'],
                'main_image' => '/images/product/product-04.jpg',
            ],
            [
                'name' => 'Adidas 450',
                'description' => 'Modern lifestyle sneaker with cloud-white foam cushioning.',
                'price' => 1650.00,
                'brand' => 'Adidas',
                'category' => 'shoes',
                'stock_quantity' => 18,
                'sizes_available' => ['7', '8', '9', '10', '11'],
                'colors_available' => ['White', 'Black', 'Blue'],
                'main_image' => '/images/product/product-05.jpg',
            ],
            [
                'name' => 'New Balance 450',
                'description' => 'Versatile running shoe with fresh foam technology.',
                'price' => 1750.00,
                'brand' => 'New Balance',
                'category' => 'shoes',
                'stock_quantity' => 8,
                'sizes_available' => ['8', '9', '10'],
                'colors_available' => ['Grey', 'Navy'],
                'main_image' => '/images/product/product-06.jpg',
            ],
            [
                'name' => 'Zip Motion Hoodie Sneaker',
                'description' => 'Limited edition collaboration with bold red accents.',
                'price' => 3800.00,
                'brand' => 'Exclusive',
                'category' => 'shoes',
                'stock_quantity' => 5,
                'sizes_available' => ['8', '9', '10'],
                'colors_available' => ['Red/Black'],
                'main_image' => '/images/product/product-07.jpg',
            ],
            [
                'name' => 'Urban Loafers Premium',
                'description' => 'Handcrafted Italian leather loafers for the discerning gentleman.',
                'price' => 2200.00,
                'brand' => 'Premium Brand',
                'category' => 'shoes',
                'stock_quantity' => 7,
                'sizes_available' => ['8', '9', '10', '11'],
                'colors_available' => ['Black', 'Burgundy'],
                'main_image' => '/images/product/product-08.jpg',
            ],
        ];

        // Products for shop owner 2 (Urban Kicks Store)
        $products2 = [
            [
                'name' => 'Jordan 1 Retro High',
                'description' => 'Iconic Air Jordan 1 with premium leather and classic colorway.',
                'price' => 2800.00,
                'compare_at_price' => 3200.00,
                'brand' => 'Nike',
                'category' => 'shoes',
                'stock_quantity' => 10,
                'sizes_available' => ['8', '9', '10', '11'],
                'colors_available' => ['Chicago', 'Bred', 'Royal Blue'],
                'main_image' => '/images/product/product-02.jpg',
            ],
            [
                'name' => 'Yeezy Boost 350 V2',
                'description' => 'Limited edition Yeezy with Primeknit upper and Boost cushioning.',
                'price' => 4500.00,
                'brand' => 'Adidas',
                'category' => 'shoes',
                'stock_quantity' => 5,
                'sizes_available' => ['8', '9', '10'],
                'colors_available' => ['Zebra', 'Cream White'],
                'main_image' => '/images/product/product-05.jpg',
            ],
            [
                'name' => 'Converse Chuck Taylor All Star',
                'description' => 'Timeless canvas sneaker with classic design.',
                'price' => 1200.00,
                'brand' => 'Converse',
                'category' => 'shoes',
                'stock_quantity' => 25,
                'sizes_available' => ['7', '8', '9', '10', '11'],
                'colors_available' => ['Black', 'White', 'Red'],
                'main_image' => '/images/product/product-03.jpg',
            ],
        ];

        // Assign products to shop owner 1
        foreach ($products1 as $productData) {
            $productData['shop_owner_id'] = $shopOwner1->id;
            $productData['is_active'] = true;
            
            Product::create($productData);
        }

        // Assign products to shop owner 2
        foreach ($products2 as $productData) {
            $productData['shop_owner_id'] = $shopOwner2->id;
            $productData['is_active'] = true;
            
            Product::create($productData);
        }

        echo "Created " . count($products1) . " products for {$shopOwner1->business_name}\n";
        echo "Created " . count($products2) . " products for {$shopOwner2->business_name}\n";
        echo "Total: " . (count($products1) + count($products2)) . " products\n";
    }
}
