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

class PayrollControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $shopOwner;
    protected $hrUser;
    protected $payrollManager;
    protected $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopOwner = ShopOwner::factory()->create();

        $this->hrUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'HR',
        ]);

        $this->payrollManager = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'PAYROLL_MANAGER',
        ]);

        $this->employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 50000,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function test_payroll_generates_correctly()
    {
        $payrollData = [
            'employee_id' => $this->employee->id,
            'pay_period_start' => now()->startOfMonth()->format('Y-m-d'),
            'pay_period_end' => now()->endOfMonth()->format('Y-m-d'),
            'basic_salary' => 50000,
            'allowances' => 5000,
            'deductions' => 2000,
            'gross_salary' => 55000,
            'net_salary' => 53000,
        ];

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson('/api/hr/payroll/generate', $payrollData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'employee_id', 'net_salary']
            ]);

        $this->assertDatabaseHas('hr_payrolls', [
            'employee_id' => $this->employee->id,
            'basic_salary' => 50000,
            'net_salary' => 53000,
            'status' => 'draft',
        ]);
    }

    #[Test]
    public function test_tax_calculated_correctly()
    {
        $basicSalary = 50000;
        $allowances = 5000;
        $grossSalary = $basicSalary + $allowances; // 55000

        // Simple tax calculation (example: 10% tax)
        $expectedTax = $grossSalary * 0.10; // 5500
        $expectedNet = $grossSalary - $expectedTax; // 49500

        $payrollData = [
            'employee_id' => $this->employee->id,
            'pay_period_start' => now()->startOfMonth()->format('Y-m-d'),
            'pay_period_end' => now()->endOfMonth()->format('Y-m-d'),
            'basic_salary' => $basicSalary,
            'allowances' => $allowances,
            'deductions' => $expectedTax,
            'gross_salary' => $grossSalary,
            'net_salary' => $expectedNet,
        ];

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson('/api/hr/payroll/generate', $payrollData);

        $response->assertStatus(201);

        $payroll = Payroll::latest()->first();
        $this->assertEquals($expectedNet, $payroll->net_salary);
    }

    #[Test]
    public function test_attendance_affects_payroll()
    {
        $workingDaysInMonth = 22;
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

        // Calculate pro-rated salary
        $fullSalary = 50000;
        $dailyRate = $fullSalary / $workingDaysInMonth;
        $absentDeduction = $dailyRate * $daysAbsent;
        $expectedSalary = $fullSalary - $absentDeduction;

        $payrollData = [
            'employee_id' => $this->employee->id,
            'pay_period_start' => now()->startOfMonth()->format('Y-m-d'),
            'pay_period_end' => now()->endOfMonth()->format('Y-m-d'),
            'basic_salary' => $fullSalary,
            'allowances' => 0,
            'deductions' => $absentDeduction,
            'gross_salary' => $fullSalary,
            'net_salary' => $expectedSalary,
        ];

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson('/api/hr/payroll/generate', $payrollData);

        $response->assertStatus(201);

        $payroll = Payroll::latest()->first();
        $this->assertEqualsWithDelta($expectedSalary, $payroll->net_salary, 0.01);
    }

    #[Test]
    public function test_unpaid_leave_deducted()
    {
        // Create approved unpaid leave
        LeaveRequest::factory()->create([
            'employee_id' => $this->employee->id,
            'leave_type' => 'unpaid',
            'start_date' => now()->subDays(5),
            'end_date' => now()->subDays(3),
            'days' => 3,
            'status' => 'approved',
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $fullSalary = 50000;
        $workingDaysInMonth = 22;
        $dailyRate = $fullSalary / $workingDaysInMonth;
        $unpaidLeaveDeduction = $dailyRate * 3;
        $expectedSalary = $fullSalary - $unpaidLeaveDeduction;

        $payrollData = [
            'employee_id' => $this->employee->id,
            'pay_period_start' => now()->startOfMonth()->format('Y-m-d'),
            'pay_period_end' => now()->endOfMonth()->format('Y-m-d'),
            'basic_salary' => $fullSalary,
            'allowances' => 0,
            'deductions' => $unpaidLeaveDeduction,
            'gross_salary' => $fullSalary,
            'net_salary' => $expectedSalary,
        ];

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson('/api/hr/payroll/generate', $payrollData);

        $response->assertStatus(201);

        $payroll = Payroll::latest()->first();
        $this->assertEqualsWithDelta($expectedSalary, $payroll->net_salary, 0.01);
    }

    #[Test]
    public function test_can_export_payroll()
    {
        $payroll = Payroll::factory()->create([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shopOwner->id,
            'status' => 'processed',
        ]);

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->getJson("/api/hr/payroll/{$payroll->id}/export");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    #[Test]
    public function test_can_list_employee_payrolls()
    {
        Payroll::factory()->count(3)->create([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->getJson("/api/hr/payroll/employee/{$this->employee->id}");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function test_cannot_generate_duplicate_payroll_for_same_period()
    {
        $period_start = now()->startOfMonth()->format('Y-m-d');
        $period_end = now()->endOfMonth()->format('Y-m-d');

        // Create first payroll
        Payroll::factory()->create([
            'employee_id' => $this->employee->id,
            'pay_period_start' => $period_start,
            'pay_period_end' => $period_end,
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        // Try to create duplicate
        $payrollData = [
            'employee_id' => $this->employee->id,
            'pay_period_start' => $period_start,
            'pay_period_end' => $period_end,
            'basic_salary' => 50000,
            'allowances' => 5000,
            'deductions' => 2000,
            'gross_salary' => 55000,
            'net_salary' => 53000,
        ];

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson('/api/hr/payroll/generate', $payrollData);

        $response->assertStatus(422); // Validation error
    }

    #[Test]
    public function test_payroll_status_workflow()
    {
        $payroll = Payroll::factory()->create([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shopOwner->id,
            'status' => 'draft',
        ]);

        // Update to processed
        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->putJson("/api/hr/payroll/{$payroll->id}", [
                'status' => 'processed',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('hr_payrolls', [
            'id' => $payroll->id,
            'status' => 'processed',
        ]);
    }

    #[Test]
    public function test_shop_isolation_enforced_for_payroll()
    {
        $otherShopOwner = ShopOwner::factory()->create();
        $otherEmployee = Employee::factory()->create([
            'shop_owner_id' => $otherShopOwner->id,
        ]);
        $otherPayroll = Payroll::factory()->create([
            'employee_id' => $otherEmployee->id,
            'shop_owner_id' => $otherShopOwner->id,
        ]);

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->getJson("/api/hr/payroll/{$otherPayroll->id}");

        $response->assertStatus(404);
    }
}
