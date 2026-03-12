<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Finance\TaxRate;
use Illuminate\Support\Facades\DB;

class TaxRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('finance_tax_rates')->delete();

        // Philippines VAT 12% (Standard)
        TaxRate::create([
            'name' => 'VAT 12%',
            'code' => 'VAT12',
            'rate' => 12.00,
            'type' => 'percentage',
            'description' => 'Standard Value-Added Tax in the Philippines',
            'applies_to' => 'all',
            'is_default' => true,
            'is_inclusive' => false,
            'is_active' => true,
            'shop_id' => 1,
        ]);

        // VAT 12% Inclusive (for prices that already include VAT)
        TaxRate::create([
            'name' => 'VAT 12% (Inclusive)',
            'code' => 'VAT12_INC',
            'rate' => 12.00,
            'type' => 'percentage',
            'description' => 'Value-Added Tax already included in price',
            'applies_to' => 'all',
            'is_default' => false,
            'is_inclusive' => true,
            'is_active' => true,
            'shop_id' => 1,
        ]);

        // Sales Tax 8% (for regions with different rates)
        TaxRate::create([
            'name' => 'Sales Tax 8%',
            'code' => 'SALES8',
            'rate' => 8.00,
            'type' => 'percentage',
            'description' => 'Regional sales tax',
            'applies_to' => 'all',
            'is_default' => false,
            'is_inclusive' => false,
            'is_active' => true,
            'shop_id' => 1,
        ]);

        // Withholding Tax 5% (for specific expenses)
        TaxRate::create([
            'name' => 'Withholding Tax 5%',
            'code' => 'WHT5',
            'rate' => 5.00,
            'type' => 'percentage',
            'description' => 'Withholding tax on services',
            'applies_to' => 'expenses',
            'is_default' => false,
            'is_inclusive' => false,
            'is_active' => true,
            'shop_id' => 1,
        ]);

        // Zero-Rated (0%)
        TaxRate::create([
            'name' => 'Zero-Rated (0%)',
            'code' => 'ZERO',
            'rate' => 0.00,
            'type' => 'percentage',
            'description' => 'Zero-rated transactions (exports, etc.)',
            'applies_to' => 'all',
            'is_default' => false,
            'is_inclusive' => false,
            'is_active' => true,
            'shop_id' => 1,
        ]);

        // Processing Fee (Fixed)
        TaxRate::create([
            'name' => 'Processing Fee',
            'code' => 'PROC_FEE',
            'rate' => 0,
            'type' => 'fixed',
            'fixed_amount' => 50.00,
            'description' => 'Fixed processing fee per transaction',
            'applies_to' => 'invoices',
            'is_default' => false,
            'is_inclusive' => false,
            'is_active' => true,
            'shop_id' => 1,
        ]);

        $this->command->info('✅ Tax rates seeded successfully!');
        $this->command->info('📊 Created 6 tax rates:');
        $this->command->info('   - VAT 12% (default)');
        $this->command->info('   - VAT 12% (Inclusive)');
        $this->command->info('   - Sales Tax 8%');
        $this->command->info('   - Withholding Tax 5%');
        $this->command->info('   - Zero-Rated (0%)');
        $this->command->info('   - Processing Fee (₱50)');
    }
}
