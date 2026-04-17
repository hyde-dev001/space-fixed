<?php

namespace Database\Seeders;

use App\Models\RepairPackage;
use App\Models\RepairService;
use App\Models\ShopOwner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class RepairPackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $eligibleRegistrationTypes = ['individual', 'company', 'registered'];
        $templateTableExists = Schema::hasTable('repair_material_template_items');

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
            ->where(function ($query) use ($eligibleRegistrationTypes) {
                $query->whereNull('registration_type')
                    ->orWhereIn('registration_type', $eligibleRegistrationTypes);
            })
            ->get(['id']);

        foreach ($eligibleShops as $shop) {
            $servicesQuery = RepairService::query()
                ->where('shop_owner_id', $shop->id)
                ->where('status', 'Active');

            if ($templateTableExists) {
                $servicesQuery->with(['materialTemplateItems']);
            }

            $services = $servicesQuery
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
                if ($templateTableExists) {
                    $this->syncPackageMaterialTemplates($package, $includedServices);
                }
            }
        }

        $this->command?->info('Repair packages seeded for repair/both shops across individual/company/registered registration types.');
        if (!$templateTableExists) {
            $this->command?->warn('Skipped package material template linking because repair_material_template_items table does not exist yet.');
        }
    }

    private function syncPackageMaterialTemplates(RepairPackage $package, Collection $includedServices): void
    {
        $package->materialTemplateItems()->delete();

        $aggregatedLines = [];

        foreach ($includedServices as $service) {
            foreach ($service->materialTemplateItems as $line) {
                $inventoryItemId = (int) $line->inventory_item_id;

                if (!isset($aggregatedLines[$inventoryItemId])) {
                    $aggregatedLines[$inventoryItemId] = [
                        'default_quantity' => 0.0,
                    ];
                }

                $aggregatedLines[$inventoryItemId]['default_quantity'] += (float) $line->default_quantity;
            }
        }

        foreach ($aggregatedLines as $inventoryItemId => $payload) {
            $package->materialTemplateItems()->create([
                'shop_owner_id' => $package->shop_owner_id,
                'inventory_item_id' => $inventoryItemId,
                'template_type' => 'repair_package',
                'template_id' => $package->id,
                'default_quantity' => $payload['default_quantity'],
                'created_by' => null,
            ]);
        }
    }
}