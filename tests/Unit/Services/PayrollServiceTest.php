<?php

namespace Tests\Unit\Services;

use App\Models\Employee;
use App\Models\HR\PayrollComponent;
use App\Models\ShopOwner;
use App\Services\HR\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PayrollService $service;
    protected ShopOwner $shopOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PayrollService();
        $this->shopOwner = ShopOwner::factory()->create();
    }

    /** @test */
    public function it_resolves_employee_default_additional_earnings()
    {
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 2000,
            'sales_commission_rate' => 0.05,
            'performance_bonus_rate' => 0.03,
            'other_allowances' => 750,
        ]);

        $result = $this->service->resolveAdditionalEarnings($employee, '2026-03');

        $this->assertSame(2600.0, $result['sales_commission']);
        $this->assertSame(1560.0, $result['performance_bonus']);
        $this->assertSame(750.0, $result['other_allowances']);
        $this->assertSame(
            ['Sales Commission', 'Performance Bonus', 'Other Allowances'],
            array_column($result['components'], 'name')
        );
    }

    /** @test */
    public function it_prefers_explicit_additional_earnings_over_employee_defaults()
    {
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 50000,
            'sales_commission_rate' => 0.05,
            'performance_bonus_rate' => 0.03,
            'other_allowances' => 750,
        ]);

        $result = $this->service->resolveAdditionalEarnings($employee, '2026-03', [
            'sales_commission' => 1200,
            'performance_bonus' => 0,
            'other_allowances' => 500,
        ]);

        $this->assertSame(1200.0, $result['sales_commission']);
        $this->assertSame(0.0, $result['performance_bonus']);
        $this->assertSame(500.0, $result['other_allowances']);
        $this->assertSame(
            ['Sales Commission', 'Other Allowances'],
            array_column($result['components'], 'name')
        );
    }

    /** @test */
    public function it_builds_shared_payroll_calculation_breakdown_for_premium_hours()
    {
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 1000,
        ]);

        $extraEarnings = $this->service->resolveAdditionalEarnings($employee, '2026-03', [
            'sales_commission' => 1500,
            'performance_bonus' => 1000,
            'other_allowances' => 750,
        ]);

        $calculation = $this->service->buildPayrollCalculation(
            $employee,
            $extraEarnings['components'],
            [
                'attendance_days' => 20,
                'leave_days' => 1,
                'absent_days' => 1,
                'overtime_hours' => 4,
                'rest_day_hours' => 8,
                'special_holiday_hours' => 8,
                'regular_holiday_hours' => 8,
                'night_differential_hours' => 2,
                'undertime_hours' => 1.5,
            ],
            '2026-03-31'
        );

        $this->assertSame(1000.0, (float) $calculation['rules']['daily_rate']);
        $this->assertSame(125.0, (float) $calculation['rules']['hourly_rate']);
        $this->assertSame(21000.0, (float) $calculation['breakdown']['basic_pay']);
        $this->assertSame(625.0, (float) $calculation['breakdown']['overtime_pay']);
        $this->assertArrayNotHasKey('rest_day_pay', $calculation['breakdown']);
        $this->assertSame(1300.0, (float) $calculation['breakdown']['special_holiday_pay']);
        $this->assertSame(2000.0, (float) $calculation['breakdown']['regular_holiday_pay']);
        $this->assertArrayNotHasKey('night_differential_pay', $calculation['breakdown']);
        $this->assertSame(1500.0, (float) $calculation['breakdown']['sales_commission']);
        $this->assertSame(1000.0, (float) $calculation['breakdown']['performance_bonus']);
        $this->assertSame(750.0, (float) $calculation['breakdown']['other_allowances']);
        $this->assertSame(0.0, (float) $calculation['breakdown']['absent_deductions']);
        $this->assertSame(187.5, (float) $calculation['breakdown']['undertime_deductions']);
        $this->assertSame(900.0, (float) $calculation['statutory']['sss_contribution']);
        $this->assertSame(685.63, (float) $calculation['statutory']['philhealth_contribution']);
        $this->assertSame(100.0, (float) $calculation['statutory']['pagibig_contribution']);
        $this->assertSame(0.0, (float) $calculation['statutory']['withholding_tax']);
        $this->assertSame(28175.0, (float) $calculation['gross_salary']);
        $this->assertSame(187.5, (float) $calculation['component_deductions']);
        $this->assertSame(1873.13, (float) $calculation['total_deductions']);
        $this->assertSame(26301.87, (float) $calculation['net_salary']);
        $this->assertCount(9, $calculation['components']);
    }

    /** @test */
    public function it_generates_payroll_without_total_drift_from_shared_calculation()
    {
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 1000,
        ]);

        $overrides = [
            'attendance_days' => 20,
            'leave_days' => 1,
            'absent_days' => 1,
            'overtime_hours' => 4,
            'rest_day_hours' => 8,
            'special_holiday_hours' => 8,
            'regular_holiday_hours' => 8,
            'night_differential_hours' => 2,
            'undertime_hours' => 1.5,
            'payment_method' => 'bank_transfer',
        ];

        $extraEarnings = $this->service->resolveAdditionalEarnings($employee, '2026-03', [
            'sales_commission' => 1500,
            'performance_bonus' => 1000,
            'other_allowances' => 750,
        ]);

        $calculation = $this->service->buildPayrollCalculation(
            $employee,
            $extraEarnings['components'],
            $overrides,
            '2026-03-31'
        );

        $payroll = $this->service->generatePayroll(
            $employee,
            '2026-03',
            $extraEarnings['components'],
            $overrides
        );

        $payroll->load('components');

        $this->assertSame((float) $calculation['gross_salary'], (float) $payroll->gross_salary);
        $this->assertSame((float) $calculation['net_salary'], (float) $payroll->net_salary);
        $this->assertSame((float) $calculation['total_deductions'], (float) $payroll->total_deductions);
        $this->assertSame((float) $calculation['statutory']['withholding_tax'], (float) $payroll->tax_amount);
        $this->assertSame((float) $calculation['statutory']['sss_contribution'], (float) $payroll->sss_contributions);
        $this->assertSame((float) $calculation['statutory']['philhealth_contribution'], (float) $payroll->philhealth);
        $this->assertSame((float) $calculation['statutory']['pagibig_contribution'], (float) $payroll->pag_ibig);
        $this->assertSame((float) $calculation['total_deductions'], (float) $payroll->deductions);
        $this->assertSame('processed', $payroll->status);
        $this->assertFalse($payroll->components->contains('component_name', 'Rest Day Pay'));
        $this->assertTrue($payroll->components->contains('component_name', 'Special Holiday Pay'));
        $this->assertTrue($payroll->components->contains('component_name', 'Regular Holiday Pay'));
        $this->assertFalse($payroll->components->contains('component_name', 'Night Differential Pay'));
        $this->assertTrue($payroll->components->contains('component_name', 'Sales Commission'));
        $this->assertTrue($payroll->components->contains('component_name', 'Performance Bonus'));
        $this->assertTrue($payroll->components->contains('component_name', 'Other Allowances'));
        $this->assertTrue($payroll->components->contains('component_code', PayrollComponent::CODE_13TH_ACCRUAL));
    }
}
