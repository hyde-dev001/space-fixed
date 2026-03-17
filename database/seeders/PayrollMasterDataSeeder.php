<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\HR\BranchPayrollSetting;
use App\Models\HR\HolidayCalendar;
use App\Models\HR\PositionBaseRate;
use App\Models\ShopOwner;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PayrollMasterDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $shopOwners = ShopOwner::query()->get();
        $basePayTable = config('payroll_governance.base_pay_table', []);
        $effectiveFrom = Carbon::now()->startOfMonth()->toDateString();
        $year = (int) Carbon::now()->year;

        foreach ($shopOwners as $shopOwner) {
            $this->seedPositionRates((int) $shopOwner->id, $basePayTable, $effectiveFrom);
            $this->seedHolidayCalendar((int) $shopOwner->id, $year);
            $this->seedBranchSettings((int) $shopOwner->id);
        }

        $this->command?->info('Payroll master data seeded (positions, rates, holidays, branch settings).');
    }

    private function seedPositionRates(int $shopOwnerId, array $basePayTable, string $effectiveFrom): void
    {
        $positions = [
            ['position_name' => 'Store Manager', 'department' => 'Management', 'fallback' => 65000.00, 'config_key' => 'Store Manager'],
            ['position_name' => 'Finance Officer', 'department' => 'Finance', 'fallback' => 45000.00, 'config_key' => 'Finance Officer'],
            ['position_name' => 'HR Specialist', 'department' => 'HR', 'fallback' => 42000.00, 'config_key' => 'HR Specialist'],
            ['position_name' => 'Customer Relations Officer', 'department' => 'CRM', 'fallback' => 38000.00, 'config_key' => 'Customer Relations Officer'],
            ['position_name' => 'Inventory Manager', 'department' => 'Inventory', 'fallback' => 48000.00, 'config_key' => 'Inventory Manager'],
            ['position_name' => 'Procurement Manager', 'department' => 'Procurement', 'fallback' => 50000.00, 'config_key' => 'Procurement Manager'],
            ['position_name' => 'Sales Associate', 'department' => 'Sales', 'fallback' => 28000.00, 'config_key' => 'Sales Associate'],
            ['position_name' => 'Shoe Repair Technician', 'department' => 'Repair', 'fallback' => 35000.00, 'config_key' => 'Shoe Repair Technician'],
            ['position_name' => 'Cashier', 'department' => 'Finance', 'fallback' => 28000.00, 'config_key' => 'Sales Associate'],
            ['position_name' => 'Repairer', 'department' => 'Repair', 'fallback' => 35000.00, 'config_key' => 'Shoe Repair Technician'],
            ['position_name' => 'Inventory Staff', 'department' => 'Inventory', 'fallback' => 32000.00, 'config_key' => 'Inventory Manager'],
        ];

        foreach ($positions as $position) {
            $rate = (float) ($basePayTable[$position['config_key']] ?? $position['fallback']);

            PositionBaseRate::updateOrCreate(
                [
                    'shop_owner_id' => $shopOwnerId,
                    'position_name' => $position['position_name'],
                    'effective_from' => $effectiveFrom,
                ],
                [
                    'position_code' => Str::slug($position['position_name']),
                    'department' => $position['department'],
                    'monthly_rate' => $rate,
                    'is_active' => true,
                    'notes' => 'Phase 2 master data setup',
                ]
            );
        }
    }

    private function seedHolidayCalendar(int $shopOwnerId, int $year): void
    {
        $holidays = [
            ['holiday_date' => "{$year}-01-01", 'holiday_name' => "New Year's Day", 'holiday_type' => 'regular', 'rate_multiplier' => 2.00],
            ['holiday_date' => "{$year}-04-09", 'holiday_name' => 'Araw ng Kagitingan', 'holiday_type' => 'regular', 'rate_multiplier' => 2.00],
            ['holiday_date' => "{$year}-05-01", 'holiday_name' => 'Labor Day', 'holiday_type' => 'regular', 'rate_multiplier' => 2.00],
            ['holiday_date' => "{$year}-06-12", 'holiday_name' => 'Independence Day', 'holiday_type' => 'regular', 'rate_multiplier' => 2.00],
            ['holiday_date' => "{$year}-08-21", 'holiday_name' => 'Ninoy Aquino Day', 'holiday_type' => 'special_non_working', 'rate_multiplier' => 1.30],
            ['holiday_date' => "{$year}-11-01", 'holiday_name' => "All Saints' Day", 'holiday_type' => 'special_non_working', 'rate_multiplier' => 1.30],
            ['holiday_date' => "{$year}-11-30", 'holiday_name' => 'Bonifacio Day', 'holiday_type' => 'regular', 'rate_multiplier' => 2.00],
            ['holiday_date' => "{$year}-12-08", 'holiday_name' => 'Feast of the Immaculate Conception', 'holiday_type' => 'special_non_working', 'rate_multiplier' => 1.30],
            ['holiday_date' => "{$year}-12-25", 'holiday_name' => 'Christmas Day', 'holiday_type' => 'regular', 'rate_multiplier' => 2.00],
            ['holiday_date' => "{$year}-12-30", 'holiday_name' => 'Rizal Day', 'holiday_type' => 'regular', 'rate_multiplier' => 2.00],
            ['holiday_date' => "{$year}-12-31", 'holiday_name' => 'Last Day of the Year', 'holiday_type' => 'special_non_working', 'rate_multiplier' => 1.30],
        ];

        foreach ($holidays as $holiday) {
            HolidayCalendar::updateOrCreate(
                [
                    'shop_owner_id' => $shopOwnerId,
                    'holiday_date' => $holiday['holiday_date'],
                    'holiday_name' => $holiday['holiday_name'],
                ],
                [
                    'holiday_type' => $holiday['holiday_type'],
                    'is_paid' => true,
                    'rate_multiplier' => $holiday['rate_multiplier'],
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedBranchSettings(int $shopOwnerId): void
    {
        $payDays = array_values(config('payroll_governance.cycle.pay_days', [15, 30]));
        $firstPayDay = (int) ($payDays[0] ?? 15);
        $secondPayDay = (int) ($payDays[1] ?? 30);
        $nonBusinessDayRule = (string) config('payroll_governance.cycle.non_business_day_rule', 'move_to_previous_business_day');

        $branches = Employee::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('branch')
            ->pluck('branch')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($branches->isEmpty()) {
            $branches = collect(['Main Branch']);
        }

        foreach ($branches as $branchName) {
            BranchPayrollSetting::updateOrCreate(
                [
                    'shop_owner_id' => $shopOwnerId,
                    'branch_name' => $branchName,
                ],
                [
                    'pay_cycle' => 'semi_monthly',
                    'pay_day_first' => $firstPayDay,
                    'pay_day_second' => $secondPayDay,
                    'standard_work_days_per_month' => 26,
                    'standard_work_hours_per_day' => 8.00,
                    'night_differential_start' => '22:00:00',
                    'night_differential_end' => '06:00:00',
                    'night_differential_rate' => 0.10,
                    'overtime_multiplier' => 1.25,
                    'rest_day_multiplier' => 1.30,
                    'special_holiday_multiplier' => 1.30,
                    'regular_holiday_multiplier' => 2.00,
                    'non_business_day_rule' => $nonBusinessDayRule,
                    'is_active' => true,
                ]
            );
        }
    }
}
