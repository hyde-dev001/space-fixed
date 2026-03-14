<?php

namespace Database\Seeders;

use App\Models\RepairPackage;
use App\Models\RepairService;
use App\Models\ShopOwner;
use Illuminate\Database\Seeder;

class RepairPackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $packageBlueprints = [
            [
                'name' => 'Starter Clean Package',
                'description' => 'Best for routine restoration and whitening.',
                'status' => 'active',
                'service_names' => ['Deep Sole Cleaning', 'Sneaker Whitening'],
                'discount' => 99,
            ],
            [
                'name' => 'Repair Restore Package',
                'description' => 'Great for structural shoe repairs and patch work.',
                'status' => 'active',
                'service_names' => ['Sole Reglue', 'Stitching and Patch Fix'],
                'discount' => 150,
            ],
        ];

        $eligibleShops = ShopOwner::query()
            ->whereIn('business_type', ['repair', 'both'])
            ->where('status', 'approved')
            ->get(['id']);

        foreach ($eligibleShops as $shop) {
            $services = RepairService::query()
                ->where('shop_owner_id', $shop->id)
                ->where('status', 'Active')
                ->get()
                ->keyBy('name');

            foreach ($packageBlueprints as $blueprint) {
                $includedServices = collect($blueprint['service_names'])
                    ->map(fn (string $serviceName) => $services->get($serviceName))
                    ->filter();

                if ($includedServices->count() < 2) {
                    continue;
                }

                $servicesTotal = (float) $includedServices->sum(fn (RepairService $service) => (float) $service->price);
                $packagePrice = max($servicesTotal - (float) $blueprint['discount'], 0);

                $package = RepairPackage::updateOrCreate(
                    [
                        'shop_owner_id' => $shop->id,
                        'name' => $blueprint['name'],
                    ],
                    [
                        'description' => $blueprint['description'],
                        'package_price' => $packagePrice,
                        'status' => $blueprint['status'],
                    ]
                );

                $package->syncIncludedServices($includedServices->pluck('id')->all());
            }
        }

        $this->command?->info('Repair packages seeded for repair and both business types.');
    }
}