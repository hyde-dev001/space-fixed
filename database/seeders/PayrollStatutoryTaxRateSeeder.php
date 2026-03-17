<?php

namespace Database\Seeders;

use App\Models\Finance\TaxRate;
use App\Models\ShopOwner;
use Illuminate\Database\Seeder;

class PayrollStatutoryTaxRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $shopIds = ShopOwner::pluck('id');

        $sssBrackets = [
            ['min' => 0, 'max' => 4249.99, 'employee_share' => 180.00],
            ['min' => 4250, 'max' => 4749.99, 'employee_share' => 202.50],
            ['min' => 4750, 'max' => 5249.99, 'employee_share' => 225.00],
            ['min' => 5250, 'max' => 5749.99, 'employee_share' => 247.50],
            ['min' => 5750, 'max' => 6249.99, 'employee_share' => 270.00],
            ['min' => 6250, 'max' => 6749.99, 'employee_share' => 292.50],
            ['min' => 6750, 'max' => 7249.99, 'employee_share' => 315.00],
            ['min' => 7250, 'max' => 7749.99, 'employee_share' => 337.50],
            ['min' => 7750, 'max' => 8249.99, 'employee_share' => 360.00],
            ['min' => 8250, 'max' => 8749.99, 'employee_share' => 382.50],
            ['min' => 8750, 'max' => 9249.99, 'employee_share' => 405.00],
            ['min' => 9250, 'max' => 9749.99, 'employee_share' => 427.50],
            ['min' => 9750, 'max' => 10249.99, 'employee_share' => 450.00],
            ['min' => 10250, 'max' => 10749.99, 'employee_share' => 472.50],
            ['min' => 10750, 'max' => 11249.99, 'employee_share' => 495.00],
            ['min' => 11250, 'max' => 11749.99, 'employee_share' => 517.50],
            ['min' => 11750, 'max' => 12249.99, 'employee_share' => 540.00],
            ['min' => 12250, 'max' => 12749.99, 'employee_share' => 562.50],
            ['min' => 12750, 'max' => 13249.99, 'employee_share' => 585.00],
            ['min' => 13250, 'max' => 13749.99, 'employee_share' => 607.50],
            ['min' => 13750, 'max' => 14249.99, 'employee_share' => 630.00],
            ['min' => 14250, 'max' => 14749.99, 'employee_share' => 652.50],
            ['min' => 14750, 'max' => 15249.99, 'employee_share' => 675.00],
            ['min' => 15250, 'max' => 15749.99, 'employee_share' => 697.50],
            ['min' => 15750, 'max' => 16249.99, 'employee_share' => 720.00],
            ['min' => 16250, 'max' => 16749.99, 'employee_share' => 742.50],
            ['min' => 16750, 'max' => 17249.99, 'employee_share' => 765.00],
            ['min' => 17250, 'max' => 17749.99, 'employee_share' => 787.50],
            ['min' => 17750, 'max' => 18249.99, 'employee_share' => 810.00],
            ['min' => 18250, 'max' => 18749.99, 'employee_share' => 832.50],
            ['min' => 18750, 'max' => 19249.99, 'employee_share' => 855.00],
            ['min' => 19250, 'max' => null, 'employee_share' => 877.50],
        ];

        foreach ($shopIds as $shopId) {
            TaxRate::updateOrCreate(
                ['shop_id' => $shopId, 'code' => 'PAYROLL_SSS_EE'],
                [
                    'name' => 'Payroll SSS Employee Share',
                    'rate' => 0,
                    'type' => 'fixed',
                    'fixed_amount' => 0,
                    'description' => 'SSS employee contribution using salary brackets.',
                    'applies_to' => 'all',
                    'is_default' => false,
                    'is_inclusive' => false,
                    'is_active' => true,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'meta' => [
                        'brackets' => $sssBrackets,
                    ],
                ]
            );

            TaxRate::updateOrCreate(
                ['shop_id' => $shopId, 'code' => 'PAYROLL_PHILHEALTH_EE'],
                [
                    'name' => 'Payroll PhilHealth Employee Share',
                    'rate' => 2.50,
                    'type' => 'percentage',
                    'fixed_amount' => null,
                    'description' => 'PhilHealth employee share with salary floor/ceiling.',
                    'applies_to' => 'all',
                    'is_default' => false,
                    'is_inclusive' => false,
                    'is_active' => true,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'meta' => [
                        'min_salary' => 10000,
                        'max_salary' => 100000,
                    ],
                ]
            );

            TaxRate::updateOrCreate(
                ['shop_id' => $shopId, 'code' => 'PAYROLL_PAGIBIG_EE'],
                [
                    'name' => 'Payroll Pag-IBIG Employee Share',
                    'rate' => 2.00,
                    'type' => 'percentage',
                    'fixed_amount' => null,
                    'description' => 'Pag-IBIG employee share with tiered rates and cap.',
                    'applies_to' => 'all',
                    'is_default' => false,
                    'is_inclusive' => false,
                    'is_active' => true,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'meta' => [
                        'tiers' => [
                            ['max_salary' => 1500, 'rate' => 1.00],
                            ['max_salary' => null, 'rate' => 2.00],
                        ],
                        'max_contribution' => 100,
                    ],
                ]
            );

            TaxRate::updateOrCreate(
                ['shop_id' => $shopId, 'code' => 'PAYROLL_WHT_TRAIN'],
                [
                    'name' => 'Payroll Withholding Tax (TRAIN Monthly)',
                    'rate' => 0,
                    'type' => 'fixed',
                    'fixed_amount' => 0,
                    'description' => 'Monthly withholding tax brackets under TRAIN/BIR.',
                    'applies_to' => 'all',
                    'is_default' => false,
                    'is_inclusive' => false,
                    'is_active' => true,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'meta' => [
                        'monthly_brackets' => [
                            ['min' => 0, 'max' => 20833, 'fixed' => 0, 'rate' => 0],
                            ['min' => 20833, 'max' => 33333, 'fixed' => 0, 'rate' => 15],
                            ['min' => 33333, 'max' => 66667, 'fixed' => 1875, 'rate' => 20],
                            ['min' => 66667, 'max' => 166667, 'fixed' => 8541.80, 'rate' => 25],
                            ['min' => 166667, 'max' => 666667, 'fixed' => 33541.80, 'rate' => 30],
                            ['min' => 666667, 'max' => null, 'fixed' => 183541.80, 'rate' => 35],
                        ],
                    ],
                ]
            );
        }

        $this->command->info('✅ Payroll statutory tax rates seeded with effective-date support (SSS, PhilHealth, Pag-IBIG, TRAIN withholding).');
    }
}
