<?php

namespace Tests\Feature\HR;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Employee;
use App\Models\HR\Payroll;
use App\Models\HR\AttendanceRecord;
use App\Models\HR\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Carbon\Carbon;
use Spatie\Permission\Models\Permission;

class PayrollControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $shopOwner;
    protected $hrUser;
    protected $payrollManager;
    protected $employee;

    private function payrollPayload(array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $this->employee->id,
            'payrollPeriod' => now()->format('Y-m'),
            'paymentMethod' => 'bank_transfer',
        ], $overrides);
    }

    private function createPayroll(array $overrides = []): Payroll
    {
        return Payroll::create(array_merge([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shopOwner->id,
            'payroll_period' => now()->format('Y-m'),
            'pay_period_start' => now()->startOfMonth()->toDateString(),
            'pay_period_end' => now()->endOfMonth()->toDateString(),
            'base_salary' => 50000,
            'basic_salary' => 50000,
            'gross_salary' => 50000,
            'allowances' => 0,
            'deductions' => 0,
            'total_deductions' => 0,
            'tax_amount' => 0,
            'net_salary' => 50000,
            'status' => 'pending',
            'payment_method' => 'bank_transfer',
            'tax_deductions' => 0,
            'sss_contributions' => 0,
            'philhealth' => 0,
            'pag_ibig' => 0,
            'attendance_days' => 22,
            'leave_days' => 0,
            'absent_days' => 0,
            'overtime_hours' => 0,
        ], $overrides));
    }

    private function seedFinalizedAttendanceForMonth(?Employee $employee = null, ?Carbon $month = null): void
    {
        $employee ??= $this->employee;
        $month ??= now();

        $cursor = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        while ($cursor->lte($end)) {
            if ($cursor->isWeekday()) {
                AttendanceRecord::factory()->create([
                    'employee_id' => $employee->id,
                    'shop_owner_id' => $this->shopOwner->id,
                    'date' => $cursor->copy(),
                    'status' => 'present',
                    'working_hours' => 8,
                    'overtime_hours' => $cursor->day === 1 ? 2 : 0,
                    'minutes_early_departure' => 0,
                    'is_late' => false,
                ]);
            }

            $cursor->addDay();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->shopOwner = ShopOwner::factory()->create();

        $this->hrUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'HR',
        ]);

        $this->payrollManager = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'PAYROLL_MANAGER',
        ]);

        Permission::findOrCreate('access-payslip-generation', 'user');
        $this->hrUser->givePermissionTo('access-payslip-generation');

        $this->employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 50000,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function test_payroll_generates_correctly()
    {
        $period = now()->format('Y-m');

        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll', $this->payrollPayload([
                'payrollPeriod' => $period,
                'salesCommission' => 2000,
            ]));

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'payroll' => ['id', 'employee_id', 'net_salary']
            ]);

        $this->assertDatabaseHas('payrolls', [
            'employee_id' => $this->employee->id,
            'payroll_period' => $period,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function test_tax_calculated_correctly()
    {
        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll', $this->payrollPayload([
                'salesCommission' => 5000,
            ]));

        $response->assertStatus(201);

        $payroll = Payroll::latest()->first();
        $this->assertNotNull($payroll);
        $this->assertGreaterThan(0, (float) $payroll->gross_salary);
        $this->assertGreaterThan(0, (float) $payroll->net_salary);
        $this->assertLessThanOrEqual((float) $payroll->gross_salary, (float) $payroll->net_salary + (float) $payroll->tax_amount + (float) $payroll->total_deductions);
    }

    #[Test]
    public function test_attendance_affects_payroll()
    {
        $daysPresent = 20;
        $daysAbsent = 2;

        // Create attendance records
        for ($i = 0; $i < $daysPresent; $i++) {
            AttendanceRecord::factory()->create([
                'employee_id' => $this->employee->id,
                'date' => now()->subDays($i),
                'status' => 'present',
                'working_hours' => 8,
                'shop_owner_id' => $this->shopOwner->id,
            ]);
        }

        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll', $this->payrollPayload([
                'attendance_days' => $daysPresent,
                'absent_days' => $daysAbsent,
            ]));

        $response->assertStatus(201);

        $payroll = Payroll::latest()->first();
        $this->assertEquals($daysPresent, (int) $payroll->attendance_days);
        $this->assertEquals($daysAbsent, (int) $payroll->absent_days);
    }

    #[Test]
    public function test_single_preview_matches_generated_payroll_totals()
    {
        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();

        $previewPayload = [
            'employee_id' => $this->employee->id,
            'start_date' => $periodStart,
            'end_date' => $periodEnd,
            'attendance_days' => 19,
            'leave_days' => 2,
            'regular_hours' => 176,
            'overtime_hours' => 10,
            'rest_day_hours' => 8,
            'special_holiday_hours' => 0,
            'regular_holiday_hours' => 0,
            'night_differential_hours' => 2,
            'undertime_hours' => 1.5,
            'absent_days' => 1,
            'sales_commission' => 2000,
            'performance_bonus' => 1500,
            'other_allowances' => 750,
        ];

        $previewResponse = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll/calculate-preview', $previewPayload);

        $previewResponse->assertStatus(200);

        $storeResponse = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll', $this->payrollPayload([
                'attendance_days' => 19,
                'leave_days' => 2,
                'absent_days' => 1,
                'overtime_hours' => 10,
                'rest_day_hours' => 8,
                'special_holiday_hours' => 0,
                'regular_holiday_hours' => 0,
                'night_differential_hours' => 2,
                'undertime_hours' => 1.5,
                'salesCommission' => 2000,
                'performanceBonus' => 1500,
                'otherAllowances' => 750,
            ]));

        $storeResponse->assertStatus(201);

        $previewCalculation = $previewResponse->json('calculation');
        $payroll = Payroll::latest()->first();

        $this->assertNotNull($payroll);
    $this->assertEquals(19, (int) $previewCalculation['hours']['attendance_days']);
    $this->assertEquals(2, (int) $previewCalculation['hours']['leave_days']);
        $this->assertEquals((float) $previewCalculation['gross_pay'], (float) $payroll->gross_salary);
        $this->assertEquals((float) $previewCalculation['net_pay'], (float) $payroll->net_salary);

        $savedTotalDeductions = round(
            (float) $payroll->total_deductions
            + (float) $payroll->tax_amount
            + (float) $payroll->sss_contributions
            + (float) $payroll->philhealth
            + (float) $payroll->pag_ibig,
            2
        );

        $this->assertEquals((float) $previewCalculation['deductions']['total_deductions'], $savedTotalDeductions);
    }

    #[Test]
    public function test_single_preview_uses_regular_hours_fallback_when_attendance_days_is_omitted()
    {
        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll/calculate-preview', [
                'employee_id' => $this->employee->id,
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->endOfMonth()->toDateString(),
                'regular_hours' => 80,
                'leave_days' => 1,
                'overtime_hours' => 2,
                'absent_days' => 0,
            ]);

        $response->assertStatus(200);

        $hours = $response->json('calculation.hours');

        $this->assertSame(10, (int) $hours['attendance_days']);
        $this->assertSame(1, (int) $hours['leave_days']);
        $this->assertEquals(80.0, (float) $hours['regular_hours']);
        $this->assertEquals(2.0, (float) $hours['overtime_hours']);
    }

    #[Test]
    public function test_single_preview_returns_expected_breakdown_for_premium_hours_and_extra_earnings()
    {
        $this->employee->update([
            'salary' => 26000,
        ]);

        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll/calculate-preview', [
                'employee_id' => $this->employee->id,
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->endOfMonth()->toDateString(),
                'attendance_days' => 20,
                'leave_days' => 1,
                'regular_hours' => 160,
                'overtime_hours' => 4,
                'rest_day_hours' => 8,
                'special_holiday_hours' => 8,
                'regular_holiday_hours' => 8,
                'night_differential_hours' => 2,
                'undertime_hours' => 1.5,
                'absent_days' => 1,
                'sales_commission' => 1500,
                'performance_bonus' => 1000,
                'other_allowances' => 750,
            ]);

        $response->assertStatus(200);

        $earnings = $response->json('calculation.earnings');
        $deductions = $response->json('calculation.deductions');

        $this->assertEquals(21000.0, (float) $earnings['basic_pay']);
        $this->assertEquals(625.0, (float) $earnings['overtime_pay']);
        $this->assertEquals(1300.0, (float) $earnings['rest_day_pay']);
        $this->assertEquals(1300.0, (float) $earnings['special_holiday_pay']);
        $this->assertEquals(2000.0, (float) $earnings['regular_holiday_pay']);
        $this->assertEquals(25.0, (float) $earnings['night_differential_pay']);
        $this->assertEquals(1500.0, (float) $earnings['sales_commission']);
        $this->assertEquals(1000.0, (float) $earnings['performance_bonus']);
        $this->assertEquals(750.0, (float) $earnings['other_allowances']);
        $this->assertEquals(29500.0, (float) $earnings['total_earnings']);
        $this->assertEquals(0.0, (float) $deductions['absent_deductions']);
        $this->assertEquals(187.5, (float) $deductions['undertime_deductions']);
        $this->assertEquals(900.0, (float) $deductions['sss_contribution']);
        $this->assertEquals(718.75, (float) $deductions['philhealth_contribution']);
        $this->assertEquals(100.0, (float) $deductions['pagibig_contribution']);
        $this->assertEquals(0.0, (float) $deductions['withholding_tax']);
        $this->assertEquals(1906.25, (float) $deductions['total_deductions']);
        $this->assertEquals(29500.0, (float) $response->json('calculation.gross_pay'));
        $this->assertEquals(27593.75, (float) $response->json('calculation.net_pay'));
    }

    #[Test]
    public function test_batch_preview_matches_generated_payroll_and_includes_employee_extra_earnings()
    {
        $period = now()->format('Y-m');

        $this->employee->update([
            'sales_commission_rate' => 0.05,
            'performance_bonus_rate' => 0.03,
            'other_allowances' => 750,
        ]);

        $this->seedFinalizedAttendanceForMonth($this->employee, now());

        $previewResponse = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll/batch/preview', [
                'payrollPeriod' => $period,
                'employeeIds' => [$this->employee->id],
            ]);

        $previewResponse->assertStatus(200)
            ->assertJsonCount(1, 'previews');

        $previewCalculation = $previewResponse->json('previews.0.calculation');

        $this->assertEquals(2500.0, (float) $previewCalculation['sales_commission']);
        $this->assertEquals(1500.0, (float) $previewCalculation['performance_bonus']);
        $this->assertEquals(750.0, (float) $previewCalculation['other_allowances']);

        $generateResponse = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll/batch/generate', [
                'payrollPeriod' => $period,
                'employeeIds' => [$this->employee->id],
                'paymentMethod' => 'bank_transfer',
                'sendNotifications' => false,
            ]);

        $generateResponse->assertStatus(200)
            ->assertJsonPath('created', 1)
            ->assertJsonPath('errors', 0);

        $payroll = Payroll::latest()->with('components')->first();

        $this->assertNotNull($payroll);
        $this->assertEquals((float) $previewCalculation['gross_salary'], (float) $payroll->gross_salary);
        $this->assertEquals((float) $previewCalculation['net_salary'], (float) $payroll->net_salary);
        $this->assertTrue($payroll->components->contains('component_name', 'Sales Commission'));
        $this->assertTrue($payroll->components->contains('component_name', 'Performance Bonus'));
        $this->assertTrue($payroll->components->contains('component_name', 'Other Allowances'));
    }

    #[Test]
    public function test_batch_preview_summary_totals_match_preview_rows_for_multiple_employees()
    {
        $period = now()->format('Y-m');

        $secondEmployee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 30000,
            'status' => 'active',
            'sales_commission_rate' => 0.02,
            'performance_bonus_rate' => 0.01,
            'other_allowances' => 500,
        ]);

        $this->seedFinalizedAttendanceForMonth($this->employee, now());
        $this->seedFinalizedAttendanceForMonth($secondEmployee, now());

        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll/batch/preview', [
                'payrollPeriod' => $period,
                'employeeIds' => [$this->employee->id, $secondEmployee->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.preview_count', 2)
            ->assertJsonPath('summary.error_count', 0)
            ->assertJsonPath('summary.warning_count', 0);

        $previews = $response->json('previews');
        $summary = $response->json('summary');

        $previewGross = round(array_sum(array_map(fn (array $preview) => (float) $preview['calculation']['gross_salary'], $previews)), 2);
        $previewNet = round(array_sum(array_map(fn (array $preview) => (float) $preview['calculation']['net_salary'], $previews)), 2);

        $this->assertSame($previewGross, round((float) $summary['total_gross'], 2));
        $this->assertSame($previewNet, round((float) $summary['total_net'], 2));
        $this->assertArrayHasKey('attendance', $previews[0]);
        $this->assertArrayHasKey('calculation', $previews[0]);
    }

    #[Test]
    public function test_batch_preview_flags_existing_payroll_as_error()
    {
        $period = now()->format('Y-m');

        $this->createPayroll([
            'payroll_period' => $period,
        ]);

        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll/batch/preview', [
                'payrollPeriod' => $period,
                'employeeIds' => [$this->employee->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(0, 'previews')
            ->assertJsonCount(1, 'errors')
            ->assertJsonCount(0, 'warnings')
            ->assertJsonPath('errors.0.message', 'Payroll already exists for this period')
            ->assertJsonPath('summary.preview_count', 0)
            ->assertJsonPath('summary.error_count', 1)
            ->assertJsonPath('summary.warning_count', 0);
    }

    #[Test]
    public function test_batch_preview_flags_non_finalized_attendance_as_error()
    {
        $period = now()->format('Y-m');

        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll/batch/preview', [
                'payrollPeriod' => $period,
                'employeeIds' => [$this->employee->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(0, 'previews')
            ->assertJsonCount(1, 'errors')
            ->assertJsonCount(0, 'warnings')
            ->assertJsonPath('errors.0.message', 'Attendance not finalized for this period')
            ->assertJsonPath('summary.preview_count', 0)
            ->assertJsonPath('summary.error_count', 1)
            ->assertJsonPath('summary.warning_count', 0);
    }

    #[Test]
    public function test_thirteenth_month_release_requires_december_when_release_date_is_not_provided()
    {
        Carbon::setTestNow(Carbon::parse('2026-03-17'));

        try {
            Permission::findOrCreate('access-payslip-approval', 'user');
            $this->hrUser->givePermissionTo('access-payslip-approval');

            $response = $this->actingAs($this->hrUser, 'user')
                ->postJson('/api/hr/payroll/13th-month/release', [
                    'year' => 2025,
                ]);

            $response->assertStatus(422)
                ->assertJsonPath(
                    'error',
                    '13th-month release failed: 13th-month release is restricted to December unless explicitly overridden.'
                );
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function test_thirteenth_month_release_accepts_explicit_december_release_date_in_non_december_runtime()
    {
        Carbon::setTestNow(Carbon::parse('2026-03-17'));

        try {
            Permission::findOrCreate('access-payslip-approval', 'user');
            $this->hrUser->givePermissionTo('access-payslip-approval');

            $response = $this->actingAs($this->hrUser, 'user')
                ->postJson('/api/hr/payroll/13th-month/release', [
                    'year' => 2025,
                    'release_date' => '2025-12-31',
                ]);

            $response->assertStatus(200)
                ->assertJsonPath('result.year', 2025)
                ->assertJsonPath('result.release_date', '2025-12-31');

            $this->assertGreaterThanOrEqual(0, (int) $response->json('result.skipped_count'));
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function test_unpaid_leave_deducted()
    {
        // Create approved unpaid leave
        LeaveRequest::create([
            'employee_id' => $this->employee->id,
            'leave_type' => 'unpaid',
            'start_date' => now()->subDays(5),
            'end_date' => now()->subDays(3),
            'no_of_days' => 3,
            'is_half_day' => false,
            'reason' => 'Test unpaid leave',
            'status' => 'approved',
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll', $this->payrollPayload([
                'leave_days' => 3,
            ]));

        $response->assertStatus(201);

        $payroll = Payroll::latest()->first();
        $this->assertEquals(3, (int) $payroll->leave_days);
    }

    #[Test]
    public function test_can_export_payroll()
    {
        $payroll = $this->createPayroll();

        $response = $this->actingAs($this->hrUser, 'user')
            ->getJson("/api/hr/payroll/{$payroll->id}/export");

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'payroll']);
    }

    #[Test]
    public function test_can_list_employee_payrolls()
    {
        $this->createPayroll(['payroll_period' => now()->subMonths(2)->format('Y-m')]);
        $this->createPayroll(['payroll_period' => now()->subMonth()->format('Y-m')]);
        $this->createPayroll(['payroll_period' => now()->format('Y-m')]);

        $response = $this->actingAs($this->hrUser, 'user')
            ->getJson("/api/hr/payroll?employee_id={$this->employee->id}");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function test_cannot_generate_duplicate_payroll_for_same_period()
    {
        $period = now()->format('Y-m');

        // Create first payroll
        $this->createPayroll(['payroll_period' => $period]);

        // Try to create duplicate
        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll', $this->payrollPayload([
                'payrollPeriod' => $period,
            ]));

        $response->assertStatus(422); // Validation error
    }

    #[Test]
    public function test_payroll_status_workflow()
    {
        $payroll = $this->createPayroll(['status' => 'pending']);

        // Update pending payroll details
        $response = $this->actingAs($this->hrUser, 'user')
            ->putJson("/api/hr/payroll/{$payroll->id}", [
                'allowances' => 1500,
                'deductions' => 500,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('payrolls', [
            'id' => $payroll->id,
            'allowances' => 1500,
            'deductions' => 500,
        ]);
    }

    #[Test]
    public function test_shop_isolation_enforced_for_payroll()
    {
        $otherShopOwner = ShopOwner::factory()->create();
        $otherEmployee = Employee::factory()->create([
            'shop_owner_id' => $otherShopOwner->id,
            'status' => 'active',
        ]);
        $otherPayroll = Payroll::create([
            'employee_id' => $otherEmployee->id,
            'shop_owner_id' => $otherShopOwner->id,
            'payroll_period' => now()->format('Y-m'),
            'pay_period_start' => now()->startOfMonth()->toDateString(),
            'pay_period_end' => now()->endOfMonth()->toDateString(),
            'base_salary' => 45000,
            'basic_salary' => 45000,
            'gross_salary' => 45000,
            'allowances' => 0,
            'deductions' => 0,
            'total_deductions' => 0,
            'tax_amount' => 0,
            'net_salary' => 45000,
            'status' => 'pending',
            'payment_method' => 'bank_transfer',
            'tax_deductions' => 0,
            'sss_contributions' => 0,
            'philhealth' => 0,
            'pag_ibig' => 0,
            'attendance_days' => 22,
            'leave_days' => 0,
            'absent_days' => 0,
            'overtime_hours' => 0,
        ]);

        $response = $this->actingAs($this->hrUser, 'user')
            ->getJson("/api/hr/payroll/{$otherPayroll->id}");

        $response->assertStatus(404);
    }

    #[Test]
    public function test_approved_leave_excludes_days_from_absent_deduction()
    {
        // Setup: Create attendance records for entire March (22 working days total)
        $periodStart = Carbon::parse('2026-03-01');
        $periodEnd = Carbon::parse('2026-03-31');
        
        // Create present records for days 1-14 (before Mar 20), then absent for days 15+ (including Mar 20)
        // This ensures Mar 20 (Fri, workday 15) is marked absent so leave can convert it
        $day = $periodStart->copy();
        $presentDay = 0;
        while ($day->lte($periodEnd)) {
            if ($day->isWeekday()) {
                $status = $presentDay < 14 ? 'present' : 'absent';
                
                AttendanceRecord::create([
                    'employee_id' => $this->employee->id,
                    'shop_owner_id' => $this->shopOwner->id,
                    'date' => $day->toDateString(),
                    'status' => $status,
                    'check_in_time' => $status === 'present' ? '08:00:00' : null,
                    'check_out_time' => $status === 'present' ? '17:00:00' : null,
                    'working_hours' => $status === 'present' ? 8 : 0,
                ]);
                $presentDay++;
            }
            $day->addDay();
        }

        // Create an approved leave request for March 20-22 (Fri-Sun)
        // March 20 is a workday (Fri) and is currently marked as "absent"
        LeaveRequest::create([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shopOwner->id,
            'leave_type' => 'sick',
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-22',
            'no_of_days' => 1,  // Only 1 workday (Friday)
            'reason' => 'Medical appointment',
            'status' => 'approved',
            'approved_by' => $this->hrUser->id,
            'approval_date' => now(),
        ]);

        // Generate payroll
        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/payroll/batch/generate', [
                'employeeIds' => [$this->employee->id],
                'payrollPeriod' => '2026-03',
                'paymentMethod' => 'bank_transfer',
            ]);

        $response->assertStatus(200);
        
        // Debug: Show errors if payroll generation failed
        if ($response->json('created') !== 1) {
            $errors = $response->json('error_details');
            $this->fail('Payroll generation failed: ' . json_encode($errors));
        }
        
        // Verify the payroll was created with correct leave_days and absent_days
        $payroll = Payroll::where('employee_id', $this->employee->id)
            ->where('payroll_period', '2026-03')
            ->first();
        
        $this->assertNotNull($payroll, 'Payroll should be created');
        
        // Leave days should be 1 (March 20 - workday covered by approved leave)
        $this->assertEquals(
            1,
            $payroll->leave_days,
            "Payroll should record 1 leave day (Mar 20) from approved sick leave. Got: {$payroll->leave_days}"
        );
        
        // Absent days should be 6 (Mar 23, 24, 25, 26, 27, 30 or 31) - March 20 moved to leave_days
        $this->assertEquals(
            6,
            $payroll->absent_days,
            "Absent days should exclude March 20. Got: {$payroll->absent_days}"
        );
    }
}
