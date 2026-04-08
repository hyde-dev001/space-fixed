<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use Illuminate\Database\Seeder;

class RepairMaterialSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $eligibleRegistrationTypes = ['individual', 'company', 'registered'];

        $materialBlueprints = [
            [
                'name' => 'Industrial Shoe Glue',
                'sku_suffix' => 'GLUE-001',
                'description' => 'High-strength adhesive used for sole and upper bonding.',
                'unit' => 'tube',
                'available_quantity' => 40,
                'reorder_level' => 10,
                'reorder_quantity' => 25,
                'price' => 220.00,
                'cost_price' => 140.00,
            ],
            [
                'name' => 'Stitching Thread (Nylon)',
                'sku_suffix' => 'THRD-001',
                'description' => 'Durable nylon thread for shoe upper and panel stitching.',
                'unit' => 'roll',
                'available_quantity' => 30,
                'reorder_level' => 8,
                'reorder_quantity' => 20,
                'price' => 180.00,
                'cost_price' => 110.00,
            ],
            [
                'name' => 'Sanding Pad',
                'sku_suffix' => 'SAND-001',
                'description' => 'Abrasive pad for prep and finishing in restoration work.',
                'unit' => 'pcs',
                'available_quantity' => 80,
                'reorder_level' => 20,
                'reorder_quantity' => 40,
                'price' => 45.00,
                'cost_price' => 25.00,
            ],
            [
                'name' => 'Leather Patch Sheet',
                'sku_suffix' => 'LPCH-001',
                'description' => 'Patch sheet for heel and panel reinforcement repairs.',
                'unit' => 'sheet',
                'available_quantity' => 50,
                'reorder_level' => 12,
                'reorder_quantity' => 25,
                'price' => 120.00,
                'cost_price' => 70.00,
            ],
            [
                'name' => 'Sole Whitener',
                'sku_suffix' => 'WHTN-001',
                'description' => 'Whitening solution for yellowed midsoles and outsoles.',
                'unit' => 'bottle',
                'available_quantity' => 35,
                'reorder_level' => 10,
                'reorder_quantity' => 20,
                'price' => 260.00,
                'cost_price' => 160.00,
            ],
            [
                'name' => 'Cleaning Foam Concentrate',
                'sku_suffix' => 'CLNF-001',
                'description' => 'Concentrated cleaning solution for routine shoe restoration.',
                'unit' => 'bottle',
                'available_quantity' => 45,
                'reorder_level' => 12,
                'reorder_quantity' => 24,
                'price' => 190.00,
                'cost_price' => 115.00,
            ],
        ];

        $eligibleShops = ShopOwner::query()
            ->whereIn('business_type', ['repair', 'both'])
            ->where('status', 'approved')
            ->where(function ($query) use ($eligibleRegistrationTypes) {
                $query->whereNull('registration_type')
                    ->orWhereIn('registration_type', $eligibleRegistrationTypes);
            })
            ->get(['id', 'business_name', 'registration_type', 'business_type']);

        $seededCount = 0;

        foreach ($eligibleShops as $shop) {
            foreach ($materialBlueprints as $blueprint) {
                $sku = $this->buildShopScopedSku((int) $shop->id, (string) $blueprint['sku_suffix']);

                // Include soft-deleted rows so rerunning the seeder does not violate unique SKU constraints.
                $item = InventoryItem::withTrashed()->firstOrNew(['sku' => $sku]);
                $item->fill([
                    'shop_owner_id' => $shop->id,
                    'name' => $blueprint['name'],
                    'sku' => $sku,
                    'category' => 'repair_materials',
                    'description' => $blueprint['description'],
                    'unit' => $blueprint['unit'],
                    'available_quantity' => $blueprint['available_quantity'],
                    'reserved_quantity' => 0,
                    'reorder_level' => $blueprint['reorder_level'],
                    'reorder_quantity' => $blueprint['reorder_quantity'],
                    'price' => $blueprint['price'],
                    'cost_price' => $blueprint['cost_price'],
                    'is_active' => true,
                ]);
                $item->deleted_at = null;
                $item->save();

                $seededCount++;
            }
        }

        $this->command?->info("Repair materials seeded/updated: {$seededCount} records across {$eligibleShops->count()} repair-capable shops.");
    }

    private function buildShopScopedSku(int $shopOwnerId, string $suffix): string
    {
        return sprintf('RM-%04d-%s', $shopOwnerId, strtoupper($suffix));
    }
}
