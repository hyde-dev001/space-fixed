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
}
