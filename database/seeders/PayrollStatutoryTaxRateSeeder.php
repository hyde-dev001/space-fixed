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

        $sssBrackets = [];
        for ($msc = 5000; $msc <= 35000; $msc += 500) {
            $regularEmployee = intdiv(min($msc, 20000) * 5, 100);
            $mpfEmployee = intdiv(max($msc - 20000, 0) * 5, 100);
            $regularEmployer = intdiv(min($msc, 20000) * 10, 100);
            $mpfEmployer = intdiv(max($msc - 20000, 0) * 10, 100);

            $sssBrackets[] = [
                'min' => $msc === 5000 ? 0 : $msc - 250,
                'max' => $msc === 35000 ? null : $msc + 249.99,
                'msc' => $msc,
                'employee_share' => $regularEmployee + $mpfEmployee,
                'employer_share' => $regularEmployer + $mpfEmployer + ($msc < 15000 ? 10 : 30),
            ];
        }

        foreach ($shopIds as $shopId) {
            TaxRate::updateOrCreate(
                ['shop_id' => $shopId, 'code' => 'PAYROLL_SSS_EE'],
                [
                    'name' => 'Payroll SSS Employee Share',
                    'rate' => 5.00,
                    'type' => 'percentage',
                    'fixed_amount' => null,
                    'description' => 'SSS employee share using the 2025 MSC schedule; employer share is retained separately.',
                    'applies_to' => 'all',
                    'is_default' => false,
                    'is_inclusive' => false,
                    'is_active' => true,
                    'effective_from' => '2025-01-01',
                    'effective_to' => null,
                    'meta' => [
                        'brackets' => $sssBrackets,
                        'source' => 'https://www.sss.gov.ph/wp-content/uploads/2024/12/CI-2024-006-Publication.pdf',
                        'employee_rate' => 5.00,
                        'employer_rate' => 10.00,
                        'ecp_employer_only' => true,
                        'calculation_base' => 'sss_msc',
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
                    'effective_from' => '2024-01-01',
                    'effective_to' => null,
                    'meta' => [
                        'min_salary' => 10000,
                        'max_salary' => 100000,
                        'employee_rate' => 2.50,
                        'employer_rate' => 2.50,
                        'source' => 'https://www.philhealth.gov.ph/about_us/transparency/PCC_Handbook2024_2ndEdition.pdf',
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
                    'effective_from' => '2023-01-01',
                    'effective_to' => null,
                    'meta' => [
                        'tiers' => [
                            ['max_salary' => 1500, 'rate' => 1.00],
                            ['max_salary' => null, 'rate' => 2.00],
                        ],
                        'max_salary' => 5000,
                        'max_contribution' => 100,
                        'employee_rate' => 2.00,
                        'employer_rate' => 2.00,
                        'source' => 'https://www.pagibigfund.gov.ph/document/pdf/circulars/provident/HDMF%20Circular%20No.%20274%20-%20Revised%20Guidelines%20on%20Pag-IBIG%20Fund%20Membership.pdf',
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
                    'effective_from' => '2023-01-01',
                    'effective_to' => null,
                    'meta' => [
                        'monthly_brackets' => [
                            ['min' => 0, 'max' => 20832.99, 'fixed' => 0, 'rate' => 0],
                            ['min' => 20833, 'max' => 33332.99, 'fixed' => 0, 'rate' => 15],
                            ['min' => 33333, 'max' => 66666.99, 'fixed' => 1875, 'rate' => 20],
                            ['min' => 66667, 'max' => 166666.99, 'fixed' => 8541.80, 'rate' => 25],
                            ['min' => 166667, 'max' => 666666.99, 'fixed' => 33541.80, 'rate' => 30],
                            ['min' => 666667, 'max' => null, 'fixed' => 183541.80, 'rate' => 35],
                        ],
                        'source' => 'https://bir-cdn.bir.gov.ph/local/pdf/Annex%20E%20RR%2011-2018.pdf',
                    ],
                ]
            );
        }

        $this->command->info('✅ Payroll statutory tax rates seeded with effective-date support (SSS, PhilHealth, Pag-IBIG, TRAIN withholding).');
    }
}
