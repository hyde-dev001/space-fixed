<?php

namespace Tests\Feature\HR;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Employee;
use App\Models\HR\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class EmployeeControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $shopOwner;
    protected $hrUser;
    protected $staffUser;
    protected $department;

    protected function setUp(): void
    {
        parent::setUp();

        // Create shop owner
        $this->shopOwner = ShopOwner::factory()->create();

        // Create HR user
        $this->hrUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'HR',
        ]);

        // Create staff user (no HR privileges)
        $this->staffUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'staff',
        ]);

        // Create department
        $this->department = Department::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);
    }

    #[Test]
    public function test_can_create_employee_as_hr()
    {
        $employeeData = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '1234567890',
            'department_id' => $this->department->id,
            'position' => 'Software Engineer',
            'hire_date' => now()->format('Y-m-d'),
            'salary' => 50000,
            'status' => 'active',
        ];

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson('/api/hr/employees', $employeeData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'first_name', 'last_name', 'email']
            ]);

        $this->assertDatabaseHas('hr_employees', [
            'email' => 'john.doe@example.com',
            'shop_owner_id' => $this->shopOwner->id,
        ]);
    }

    #[Test]
    public function test_cannot_create_employee_as_staff()
    {
        $employeeData = [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'phone' => '0987654321',
            'department_id' => $this->department->id,
            'position' => 'Designer',
            'hire_date' => now()->format('Y-m-d'),
            'salary' => 45000,
        ];

        $response = $this->actingAs($this->staffUser, 'sanctum')
            ->postJson('/api/hr/employees', $employeeData);

        $response->assertStatus(403); // Forbidden

        $this->assertDatabaseMissing('hr_employees', [
            'email' => 'jane.smith@example.com',
        ]);
    }

    #[Test]
    public function test_shop_isolation_enforced()
    {
        // Create another shop owner with their own employee
        $otherShopOwner = ShopOwner::factory()->create();
        $otherEmployee = Employee::factory()->create([
            'shop_owner_id' => $otherShopOwner->id,
        ]);

        // Try to access other shop's employee
        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->getJson("/api/hr/employees/{$otherEmployee->id}");

        $response->assertStatus(404); // Should not find employee from other shop

        // Verify HR user can only see their shop's employees
        $myEmployee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->getJson('/api/hr/employees');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $myEmployee->id])
            ->assertJsonMissing(['id' => $otherEmployee->id]);
    }

    #[Test]
    public function test_employee_suspension_workflow()
    {
        $employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'status' => 'active',
        ]);

        // Suspend employee
        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson("/api/hr/employees/{$employee->id}/suspend");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Employee suspended successfully']);

        $this->assertDatabaseHas('hr_employees', [
            'id' => $employee->id,
            'status' => 'suspended',
        ]);

        // Activate employee
        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson("/api/hr/employees/{$employee->id}/activate");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Employee activated successfully']);

        $this->assertDatabaseHas('hr_employees', [
            'id' => $employee->id,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function test_can_update_employee_as_hr()
    {
        $employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'first_name' => 'Old',
        ]);

        $updateData = [
            'first_name' => 'Updated',
            'last_name' => $employee->last_name,
            'email' => $employee->email,
        ];

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->putJson("/api/hr/employees/{$employee->id}", $updateData);

        $response->assertStatus(200);

        $this->assertDatabaseHas('hr_employees', [
            'id' => $employee->id,
            'first_name' => 'Updated',
        ]);
    }

    #[Test]
    public function test_can_delete_employee_as_shop_owner()
    {
        $shopOwnerUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'shop_owner',
        ]);

        $employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->actingAs($shopOwnerUser, 'sanctum')
            ->deleteJson("/api/hr/employees/{$employee->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('hr_employees', [
            'id' => $employee->id,
        ]);
    }

    #[Test]
    public function test_validation_errors_on_invalid_data()
    {
        $invalidData = [
            'first_name' => '', // Required
            'email' => 'invalid-email', // Invalid format
            'salary' => 'not-a-number', // Should be numeric
        ];

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson('/api/hr/employees', $invalidData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'email', 'salary']);
    }

    #[Test]
    public function test_can_get_employee_statistics()
    {
        Employee::factory()->count(10)->create([
            'shop_owner_id' => $this->shopOwner->id,
            'status' => 'active',
        ]);

        Employee::factory()->count(3)->create([
            'shop_owner_id' => $this->shopOwner->id,
            'status' => 'suspended',
        ]);

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->getJson('/api/hr/employees/statistics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_employees',
                'active_employees',
                'suspended_employees',
            ]);
    }
}
