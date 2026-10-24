<?php

namespace Tests\Unit\Services;

use App\Models\Employee;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Models\HR\SalaryChange;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\HR\PayrollService;
use App\Support\PayrollMoney;
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
        $this->assertSame(1400.0, (float) $calculation['statutory']['sss_contribution']);
        $this->assertSame(650.0, (float) $calculation['statutory']['philhealth_contribution']);
        $this->assertSame(100.0, (float) $calculation['statutory']['pagibig_contribution']);
        $this->assertSame(666.3, (float) $calculation['statutory']['withholding_tax']);
        $this->assertSame(2830.0, (float) $calculation['statutory']['employer_contributions']['sss_contribution']);
        $this->assertSame(650.0, (float) $calculation['statutory']['employer_contributions']['philhealth_contribution']);
        $this->assertSame(100.0, (float) $calculation['statutory']['employer_contributions']['pagibig_contribution']);
        $this->assertSame(28175.0, (float) $calculation['gross_salary']);
        $this->assertSame(187.5, (float) $calculation['component_deductions']);
        $this->assertSame(3003.8, (float) $calculation['total_deductions']);
        $this->assertSame(25171.2, (float) $calculation['net_salary']);
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

    /** @test */
    public function it_uses_the_rate_effective_for_the_pay_period_without_mutating_employee_salary()
    {
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 1000,
        ]);
        $approver = User::factory()->create(['shop_owner_id' => $this->shopOwner->id]);

        SalaryChange::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $this->shopOwner->id,
            'proposed_by' => $approver->id,
            'approved_by' => $approver->id,
            'previous_salary' => 1000,
            'new_salary' => 2000,
            'change_percent' => 100,
            'change_type' => SalaryChange::TYPE_MAJOR,
            'effective_date' => '2026-04-01',
            'reason' => 'Approved rate change',
            'status' => SalaryChange::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $oldPeriod = $this->service->previewPayroll($employee, '2026-03', [], [
            'attendance_days' => 26,
            'run_date' => '2026-03-31',
        ]);

        $this->assertSame(1000.0, (float) $oldPeriod['calculation']['rules']['daily_rate']);
        $this->assertSame(1000.0, (float) $employee->fresh()->salary);

        $payroll = $this->service->generatePayroll($employee, '2026-03', [], [
            'attendance_days' => 26,
            'run_date' => '2026-03-31',
        ]);

        $this->assertNotEmpty($payroll->calculation_snapshot);
        $this->assertSame(1000.0, (float) data_get($payroll->calculation_snapshot, 'rules.daily_rate'));

        $newPeriod = $this->service->previewPayroll($employee, '2026-04', [], [
            'attendance_days' => 26,
            'run_date' => '2026-04-30',
        ]);

        $this->assertSame(2000.0, (float) $newPeriod['calculation']['rules']['daily_rate']);
        $this->assertSame(1000.0, (float) $employee->fresh()->salary);
    }

    /** @test */
    public function it_does_not_double_count_stored_statutory_deductions()
    {
        $payroll = new Payroll([
            'gross_salary' => '1000.00',
            'basic_salary' => '1000.00',
            'total_deductions' => '100.00',
            'deductions' => '100.00',
            'tax_amount' => '10.00',
            'sss_contributions' => '20.00',
            'philhealth' => '15.00',
            'pag_ibig' => '5.00',
            'net_salary' => '900.00',
        ]);

        $this->assertEqualsWithDelta(900.0, (float) $payroll->calculateNetSalary(), 0.001);
        $this->assertSame([], $payroll->reconciliationIssues());
    }

    /** @test */
    public function it_locks_approved_financial_values_but_allows_rejected_correction()
    {
        $approved = new Payroll([
            'status' => 'approved',
            'approval_status' => 'approved',
        ]);
        $rejected = new Payroll([
            'status' => 'pending',
            'approval_status' => 'rejected',
            'approval_id' => 10,
        ]);

        $this->assertTrue($approved->financialMutationLocked());
        $this->assertFalse($rejected->financialMutationLocked());
    }

    /** @test */
    public function it_applies_effective_statutory_rules_to_their_own_bases_and_keeps_employer_shares_out_of_net_pay()
    {
        $current = $this->service->calculateStatutoryDeductions(
            (int) $this->shopOwner->id,
            '40000.00',
            '2026-03-31',
            [
                'sss' => '28175.00',
                'philhealth' => '26000.00',
                'pagibig' => '28175.00',
            ]
        );

        $this->assertSame('1400.00', $current['sss_contribution']);
        $this->assertSame('650.00', $current['philhealth_contribution']);
        $this->assertSame('100.00', $current['pagibig_contribution']);
        $this->assertSame('2778.40', $current['withholding_tax']);
        $this->assertSame('2830.00', $current['employer_contributions']['sss_contribution']);
        $this->assertSame('650.00', $current['employer_contributions']['philhealth_contribution']);
        $this->assertSame('100.00', $current['employer_contributions']['pagibig_contribution']);

        $historical = $this->service->calculateStatutoryDeductions(
            (int) $this->shopOwner->id,
            '28175.00',
            '2023-03-31',
            [
                'sss' => '28175.00',
                'philhealth' => '26000.00',
                'pagibig' => '28175.00',
            ]
        );

        $this->assertSame('1260.00', $historical['sss_contribution']);
        $this->assertSame('520.00', $historical['philhealth_contribution']);
        $this->assertSame('2690.00', $historical['employer_contributions']['sss_contribution']);
        $this->assertSame('520.00', $historical['employer_contributions']['philhealth_contribution']);
    }

    /** @test */
    public function it_prorates_a_half_day_once_in_basic_pay()
    {
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 1000,
        ]);

        $calculation = $this->service->buildPayrollCalculation(
            $employee,
            [],
            [
                'attendance_days' => 20,
                'half_day_days' => 1,
                'leave_days' => 0,
            ],
            '2026-03-31'
        );

        $this->assertSame('19500.00', $calculation['breakdown']['basic_pay']);
        $this->assertSame('19500.00', $calculation['gross_salary']);
    }

    /** @test */
    public function it_converts_attendance_late_minutes_to_one_hourly_deduction()
    {
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 1000,
        ]);

        $calculation = $this->service->buildPayrollCalculation(
            $employee,
            [],
            [
                'attendance_days' => 26,
                'late_hours' => 0.5,
            ],
            '2026-03-31'
        );

        $this->assertSame('62.50', $calculation['rules']['late_deduction']);
        $this->assertSame('62.50', $calculation['breakdown']['late_deductions']);
        $this->assertTrue($calculation['components']->contains('component_name', 'Late Deduction'));
    }

    /** @test */
    public function it_rounds_payroll_money_without_binary_float_drift()
    {
        $this->assertSame('0.30', PayrollMoney::add('0.10', '0.20'));
        $this->assertSame('100.01', PayrollMoney::round('100.005'));
        $this->assertSame('-100.01', PayrollMoney::round('-100.005'));
    }
}
