<?php

namespace Database\Seeders;

use App\Models\RepairService;
use App\Models\ShopOwner;
use Illuminate\Database\Seeder;

class RepairServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $services = [
            [
                'name' => 'Deep Sole Cleaning',
                'category' => 'Cleaning',
                'price' => 299.00,
                'duration' => '1 day',
                'description' => 'Deep clean for midsoles, outsoles, and uppers.',
                'status' => 'Active',
            ],
            [
                'name' => 'Sneaker Whitening',
                'category' => 'Cleaning',
                'price' => 399.00,
                'duration' => '1-2 days',
                'description' => 'Whitening treatment for yellowed soles and panels.',
                'status' => 'Active',
            ],
            [
                'name' => 'Sole Reglue',
                'category' => 'Restoration',
                'price' => 650.00,
                'duration' => '2-3 days',
                'description' => 'Professional sole reattachment and curing.',
                'status' => 'Active',
            ],
            [
                'name' => 'Heel Repair',
                'category' => 'Repair',
                'price' => 550.00,
                'duration' => '2 days',
                'description' => 'Heel reinforcement and replacement for worn-out heels.',
                'status' => 'Active',
            ],
            [
                'name' => 'Stitching and Patch Fix',
                'category' => 'Repair',
                'price' => 450.00,
                'duration' => '2-4 days',
                'description' => 'Upper stitching repair and patch application.',
                'status' => 'Active',
            ],
            [
                'name' => 'Color Repaint',
                'category' => 'Customization',
                'price' => 1200.00,
                'duration' => '3-5 days',
                'description' => 'Panel repaint and color restoration service.',
                'status' => 'Active',
            ],
        ];

        $eligibleShops = ShopOwner::query()
            ->whereIn('business_type', ['repair', 'both'])
            ->where('status', 'approved')
            ->get(['id', 'business_name', 'business_type']);

        foreach ($eligibleShops as $shop) {
            foreach ($services as $service) {
                RepairService::updateOrCreate(
                    [
                        'shop_owner_id' => $shop->id,
                        'name' => $service['name'],
                    ],
                    [
                        'category' => $service['category'],
                        'price' => $service['price'],
                        'duration' => $service['duration'],
                        'description' => $service['description'],
                        'status' => $service['status'],
                    ]
                );
            }
        }

        $this->command?->info('Repair services seeded for repair and both business types.');
    }
}
