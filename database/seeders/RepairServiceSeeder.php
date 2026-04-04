<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\RepairService;
use App\Models\ShopOwner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class RepairServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $eligibleRegistrationTypes = ['individual', 'company', 'registered'];

        $services = [
            [
                'name' => 'Deep Sole Cleaning',
                'category' => 'Cleaning',
                'price' => 299.00,
                'duration' => '1 day',
                'description' => 'Deep clean for midsoles, outsoles, and uppers.',
                'status' => 'Active',
                'material_templates' => [
                    [
                        'inventory_name' => 'Cleaning Foam Concentrate',
                        'default_quantity' => 0.35,
                        'is_critical' => true,
                        'tolerance_percent' => 10,
                    ],
                    [
                        'inventory_name' => 'Sanding Pad',
                        'default_quantity' => 0.20,
                        'is_critical' => false,
                        'tolerance_percent' => 25,
                    ],
                ],
            ],
            [
                'name' => 'Sneaker Whitening',
                'category' => 'Cleaning',
                'price' => 399.00,
                'duration' => '1-2 days',
                'description' => 'Whitening treatment for yellowed soles and panels.',
                'status' => 'Active',
                'material_templates' => [
                    [
                        'inventory_name' => 'Sole Whitener',
                        'default_quantity' => 0.40,
                        'is_critical' => true,
                        'tolerance_percent' => 10,
                    ],
                    [
                        'inventory_name' => 'Cleaning Foam Concentrate',
                        'default_quantity' => 0.15,
                        'is_critical' => false,
                        'tolerance_percent' => 20,
                    ],
                ],
            ],
            [
                'name' => 'Sole Reglue',
                'category' => 'Restoration',
                'price' => 650.00,
                'duration' => '2-3 days',
                'description' => 'Professional sole reattachment and curing.',
                'status' => 'Active',
                'material_templates' => [
                    [
                        'inventory_name' => 'Industrial Shoe Glue',
                        'default_quantity' => 0.60,
                        'is_critical' => true,
                        'tolerance_percent' => 10,
                    ],
                    [
                        'inventory_name' => 'Sanding Pad',
                        'default_quantity' => 0.30,
                        'is_critical' => false,
                        'tolerance_percent' => 25,
                    ],
                ],
            ],
            [
                'name' => 'Heel Repair',
                'category' => 'Repair',
                'price' => 550.00,
                'duration' => '2 days',
                'description' => 'Heel reinforcement and replacement for worn-out heels.',
                'status' => 'Active',
                'material_templates' => [
                    [
                        'inventory_name' => 'Leather Patch Sheet',
                        'default_quantity' => 0.50,
                        'is_critical' => true,
                        'tolerance_percent' => 10,
                    ],
                    [
                        'inventory_name' => 'Industrial Shoe Glue',
                        'default_quantity' => 0.35,
                        'is_critical' => true,
                        'tolerance_percent' => 10,
                    ],
                    [
                        'inventory_name' => 'Stitching Thread (Nylon)',
                        'default_quantity' => 0.25,
                        'is_critical' => false,
                        'tolerance_percent' => 20,
                    ],
                ],
            ],
            [
                'name' => 'Stitching and Patch Fix',
                'category' => 'Repair',
                'price' => 450.00,
                'duration' => '2-4 days',
                'description' => 'Upper stitching repair and patch application.',
                'status' => 'Active',
                'material_templates' => [
                    [
                        'inventory_name' => 'Stitching Thread (Nylon)',
                        'default_quantity' => 0.40,
                        'is_critical' => true,
                        'tolerance_percent' => 10,
                    ],
                    [
                        'inventory_name' => 'Leather Patch Sheet',
                        'default_quantity' => 0.30,
                        'is_critical' => false,
                        'tolerance_percent' => 20,
                    ],
                ],
            ],
            [
                'name' => 'Color Repaint',
                'category' => 'Customization',
                'price' => 1200.00,
                'duration' => '3-5 days',
                'description' => 'Panel repaint and color restoration service.',
                'status' => 'Active',
                'material_templates' => [
                    [
                        'inventory_name' => 'Sanding Pad',
                        'default_quantity' => 0.40,
                        'is_critical' => true,
                        'tolerance_percent' => 15,
                    ],
                    [
                        'inventory_name' => 'Cleaning Foam Concentrate',
                        'default_quantity' => 0.10,
                        'is_critical' => false,
                        'tolerance_percent' => 25,
                    ],
                ],
            ],
        ];

        $requiredMaterialNames = collect($services)
            ->flatMap(fn (array $service) => collect($service['material_templates'] ?? [])->pluck('inventory_name'))
            ->filter()
            ->unique()
            ->values();

        $eligibleShops = ShopOwner::query()
            ->whereIn('business_type', ['repair', 'both'])
            ->where('status', 'approved')
            ->where(function ($query) use ($eligibleRegistrationTypes) {
                $query->whereNull('registration_type')
                    ->orWhereIn('registration_type', $eligibleRegistrationTypes);
            })
            ->get(['id', 'business_name', 'business_type']);

        $templateLinksSeeded = 0;
        $templateTableExists = Schema::hasTable('repair_material_template_items');

        foreach ($eligibleShops as $shop) {
            $shopMaterials = InventoryItem::query()
                ->where('shop_owner_id', $shop->id)
                ->where('category', 'repair_materials')
                ->whereIn('name', $requiredMaterialNames)
                ->get(['id', 'name'])
                ->keyBy('name');

            foreach ($services as $service) {
                $repairService = RepairService::updateOrCreate(
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

                if ($templateTableExists) {
                    $templateLinksSeeded += $this->syncServiceMaterialTemplates(
                        $repairService,
                        $service['material_templates'] ?? [],
                        $shopMaterials
                    );
                }
            }
        }

        $this->command?->info('Repair services seeded for repair/both shops across individual/company/registered registration types.');
        if ($templateTableExists) {
            $this->command?->info("Repair service material templates seeded: {$templateLinksSeeded} links.");
        } else {
            $this->command?->warn('Skipped service material template linking because repair_material_template_items table does not exist yet.');
        }
    }

    private function syncServiceMaterialTemplates(RepairService $service, array $materialTemplates, Collection $shopMaterials): int
    {
        $service->materialTemplateItems()->delete();

        $created = 0;

        foreach ($materialTemplates as $line) {
            $materialName = (string) ($line['inventory_name'] ?? '');
            $inventoryItem = $shopMaterials->get($materialName);

            if (!$inventoryItem) {
                continue;
            }

            $service->materialTemplateItems()->create([
                'shop_owner_id' => $service->shop_owner_id,
                'inventory_item_id' => $inventoryItem->id,
                'template_type' => 'repair_service',
                'template_id' => $service->id,
                'default_quantity' => (float) ($line['default_quantity'] ?? 1),
                'is_critical' => (bool) ($line['is_critical'] ?? false),
                'tolerance_percent' => (float) ($line['tolerance_percent'] ?? 20),
                'created_by' => null,
            ]);

            $created++;
        }

        return $created;
    }
}
