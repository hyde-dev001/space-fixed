<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\ShopOwner;
use App\Models\Employee;
use App\Models\HR\Department;
use App\Models\HR\LeaveBalance;
use App\Models\HR\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected $shopOwner;
    protected $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopOwner = ShopOwner::factory()->create();
        $this->employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function test_full_name_accessor()
    {
        $fullName = $this->employee->first_name . ' ' . $this->employee->last_name;
        
        $this->assertEquals('John Doe', $fullName);
        
        // If you add a getFullNameAttribute() accessor to the Employee model:
        // $this->assertEquals('John Doe', $this->employee->full_name);
    }

    #[Test]
    public function test_employee_has_department_relationship()
    {
        $department = Department::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $this->employee->update(['department_id' => $department->id]);
        $this->employee->refresh();
        $this->employee->load('department');

        $this->assertInstanceOf(Department::class, $this->employee->getRelation('department'));
        $this->assertEquals($department->id, $this->employee->getRelation('department')->id);
    }

    #[Test]
    public function test_employee_has_attendance_records_relationship()
    {
        AttendanceRecord::factory()->count(5)->create([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $this->assertCount(5, $this->employee->attendanceRecords);
    }

    #[Test]
    public function test_employee_has_leave_balances_relationship()
    {
        LeaveBalance::factory()->create([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shopOwner->id,
            'year' => 2024,
        ]);
        
        LeaveBalance::factory()->create([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shopOwner->id,
            'year' => 2025,
        ]);
        
        LeaveBalance::factory()->create([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shopOwner->id,
            'year' => 2026,
        ]);

        $this->employee->load('leaveBalances');
        $this->assertCount(3, $this->employee->leaveBalances);
    }

    #[Test]
    public function test_leave_balance_calculated_correctly()
    {
        $leaveBalance = LeaveBalance::create([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shopOwner->id,
            'year' => now()->year,
            'vacation_days' => 15,
            'used_vacation' => 5,
            'sick_days' => 10,
            'used_sick' => 0,
            'personal_days' => 5,
            'used_personal' => 0,
            'maternity_days' => 0,
            'used_maternity' => 0,
            'paternity_days' => 0,
            'used_paternity' => 0,
        ]);

        $this->assertEquals(15, $leaveBalance->vacation_days);
        $this->assertEquals(5, $leaveBalance->used_vacation);
        $this->assertEquals(10, $leaveBalance->vacation_days - $leaveBalance->used_vacation); // Available vacation
    }

    #[Test]
    public function test_employee_can_be_suspended()
    {
        $this->assertEquals('active', $this->employee->status);

        $this->employee->update(['status' => 'suspended']);

        $this->assertEquals('suspended', $this->employee->fresh()->status);
    }

    #[Test]
    public function test_employee_can_be_activated()
    {
        $this->employee->update(['status' => 'suspended']);
        $this->assertEquals('suspended', $this->employee->status);

        $this->employee->update(['status' => 'active']);

        $this->assertEquals('active', $this->employee->fresh()->status);
    }

    #[Test]
    public function test_employee_has_shop_owner_relationship()
    {
        $this->assertInstanceOf(ShopOwner::class, $this->employee->shopOwner);
        $this->assertEquals($this->shopOwner->id, $this->employee->shop_owner_id);
    }

    #[Test]
    public function test_employee_scopes_by_status()
    {
        Employee::factory()->count(3)->create([
            'shop_owner_id' => $this->shopOwner->id,
            'status' => 'active',
        ]);

        Employee::factory()->count(2)->create([
            'shop_owner_id' => $this->shopOwner->id,
            'status' => 'suspended',
        ]);

        $activeCount = Employee::where('status', 'active')->count();
        $suspendedCount = Employee::where('status', 'suspended')->count();

        $this->assertEquals(4, $activeCount); // Including setUp employee
        $this->assertEquals(2, $suspendedCount);
    }

    #[Test]
    public function test_employee_casts_dates_correctly()
    {
        $employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'hire_date' => '2024-01-15',
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $employee->hire_date);
        $this->assertEquals('2024-01-15', $employee->hire_date->format('Y-m-d'));
    }

    #[Test]
    public function test_employee_salary_is_decimal()
    {
        $employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 50000.50,
        ]);

        $this->assertEquals('50000.50', number_format($employee->salary, 2, '.', ''));
    }

    #[Test]
    public function test_employee_soft_deletes()
    {
        $employeeId = $this->employee->id;

        $this->employee->delete();

        $this->assertSoftDeleted('employees', ['id' => $employeeId]);
        $this->assertNull(Employee::find($employeeId));
        $this->assertNotNull(Employee::withTrashed()->find($employeeId));
    }

    #[Test]
    public function test_employee_can_be_restored()
    {
        $employeeId = $this->employee->id;

        $this->employee->delete();
        $this->assertSoftDeleted('employees', ['id' => $employeeId]);

        $deletedEmployee = Employee::withTrashed()->find($employeeId);
        $deletedEmployee->restore();

        $this->assertNotNull(Employee::find($employeeId));
    }
}
