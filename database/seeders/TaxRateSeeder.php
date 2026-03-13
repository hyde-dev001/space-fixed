<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Finance\TaxRate;
use App\Models\ShopOwner;

class TaxRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $shopIds = ShopOwner::pluck('id');

        foreach ($shopIds as $shopId) {
            TaxRate::updateOrCreate(
                ['shop_id' => $shopId, 'code' => 'VAT12'],
                [
                    'name' => 'PH VAT 12%',
                    'rate' => 12.00,
                    'type' => 'percentage',
                    'description' => 'Philippines standard VAT (12%)',
                    'applies_to' => 'all',
                    'is_default' => true,
                    'is_inclusive' => false,
                    'is_active' => true,
                ]
            );

            TaxRate::updateOrCreate(
                ['shop_id' => $shopId, 'code' => 'VAT12_INC'],
                [
                    'name' => 'PH VAT 12% (Inclusive)',
                    'rate' => 12.00,
                    'type' => 'percentage',
                    'description' => 'Philippines VAT included in listed price',
                    'applies_to' => 'all',
                    'is_default' => false,
                    'is_inclusive' => true,
                    'is_active' => true,
                ]
            );

            TaxRate::updateOrCreate(
                ['shop_id' => $shopId, 'code' => 'ZERO'],
                [
                    'name' => 'Zero-Rated (0%)',
                    'rate' => 0.00,
                    'type' => 'percentage',
                    'description' => 'Zero-rated transactions',
                    'applies_to' => 'all',
                    'is_default' => false,
                    'is_inclusive' => false,
                    'is_active' => true,
                ]
            );

            TaxRate::updateOrCreate(
                ['shop_id' => $shopId, 'code' => 'EWT2'],
                [
                    'name' => 'PH Expanded Withholding Tax 2%',
                    'rate' => 2.00,
                    'type' => 'percentage',
                    'description' => 'Common PH withholding tax for supplier/service payments',
                    'applies_to' => 'expenses',
                    'is_default' => false,
                    'is_inclusive' => false,
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('✅ Philippine tax rates seeded per shop owner (VAT 12%, VAT Inclusive, Zero-Rated, EWT 2%).');
    }
}
