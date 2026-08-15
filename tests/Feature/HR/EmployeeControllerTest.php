<?php

namespace Tests\Feature\HR;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class EmployeeControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $shopOwner;
    protected $hrUser;
    protected $staffUser;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-employee-directory', 'user');
        Role::findOrCreate('HR', 'user');
        Role::findOrCreate('Shop Owner', 'user');

        $this->shopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        // Create HR user
        $this->hrUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'HR',
        ]);
        $this->hrUser->assignRole('HR');
        $this->hrUser->givePermissionTo('access-employee-directory');

        // Create staff user (no HR privileges)
        $this->staffUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'staff',
        ]);

    }

    #[Test]
    public function test_can_create_employee_as_hr()
    {
        $employeeData = [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '09171234567',
            'department' => 'Engineering',
            'position' => 'Software Engineer',
            'hireDate' => now()->format('Y-m-d'),
            'salary' => 50000,
            'role' => 'HR',
        ];

        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/employees', $employeeData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'employee' => ['id', 'first_name', 'last_name', 'email']
            ]);

        $this->assertDatabaseHas('employees', [
            'email' => 'john.doe@example.com',
            'shop_owner_id' => $this->shopOwner->id,
        ]);
    }

    #[Test]
    public function test_cannot_create_employee_as_staff()
    {
        $employeeData = [
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'email' => 'jane.smith@example.com',
            'phone' => '09179876543',
            'department' => 'Design',
            'position' => 'Designer',
            'hireDate' => now()->format('Y-m-d'),
            'salary' => 45000,
            'role' => 'HR',
        ];

        $response = $this->actingAs($this->staffUser, 'user')
            ->postJson('/api/hr/employees', $employeeData);

        $response->assertStatus(403); // Forbidden

        $this->assertDatabaseMissing('employees', [
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
        $response = $this->actingAs($this->hrUser, 'user')
            ->getJson("/api/hr/employees/{$otherEmployee->id}");

        $response->assertStatus(404); // Should not find employee from other shop

        // Verify HR user can only see their shop's employees
        $myEmployee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->actingAs($this->hrUser, 'user')
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
        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson("/api/hr/employees/{$employee->id}/suspend", ['reason' => 'Policy violation']);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Employee suspended successfully']);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status' => 'suspended',
        ]);

        // Activate employee
        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson("/api/hr/employees/{$employee->id}/activate");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Employee account reactivated successfully']);

        $this->assertDatabaseHas('employees', [
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
            'firstName' => 'Updated',
            'lastName' => $employee->last_name,
            'email' => $employee->email,
        ];

        $response = $this->actingAs($this->hrUser, 'user')
            ->putJson("/api/hr/employees/{$employee->id}", $updateData);

        $response->assertStatus(200);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'first_name' => 'Updated',
        ]);
    }

    #[Test]
    public function test_canonical_employee_status_changes_sync_linked_user_state(): void
    {
        $linkedUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'email' => 'linked.employee@example.com',
            'status' => 'active',
        ]);
        $employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'email' => $linkedUser->email,
            'status' => 'active',
        ]);

        $this->actingAs($this->hrUser, 'user')
            ->putJson("/api/hr/employees/{$employee->id}", ['status' => 'inactive'])
            ->assertOk();

        $this->assertSame('inactive', $linkedUser->fresh()->status);

        $this->actingAs($this->hrUser, 'user')
            ->putJson("/api/hr/employees/{$employee->id}", ['status' => 'suspended'])
            ->assertOk();

        $this->assertSame('suspended', $linkedUser->fresh()->status);

        $this->actingAs($this->hrUser, 'user')
            ->putJson("/api/hr/employees/{$employee->id}", ['status' => 'terminated'])
            ->assertOk();

        $this->assertSame('inactive', $linkedUser->fresh()->status);
    }

    #[Test]
    public function terminated_employee_cannot_be_reactivated_through_hr_api(): void
    {
        $employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'status' => 'terminated',
        ]);

        $this->actingAs($this->hrUser, 'user')
            ->putJson("/api/hr/employees/{$employee->id}", ['status' => 'active'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMPLOYEE_TERMINATED');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status' => 'terminated',
        ]);

        $this->actingAs($this->hrUser, 'user')
            ->postJson("/api/hr/employees/{$employee->id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMPLOYEE_TERMINATED');
    }

    #[Test]
    public function test_can_delete_employee_as_shop_owner()
    {
        $shopOwnerUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'shop_owner',
        ]);
        $shopOwnerUser->assignRole('Shop Owner');
        $shopOwnerUser->givePermissionTo('access-employee-directory');

        $employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->actingAs($shopOwnerUser, 'user')
            ->deleteJson("/api/hr/employees/{$employee->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('employees', [
            'id' => $employee->id,
        ]);
    }

    #[Test]
    public function test_validation_errors_on_invalid_data()
    {
        $invalidData = [
            'firstName' => '', // Required
            'email' => 'invalid-email', // Invalid format
            'salary' => 'not-a-number', // Should be numeric
        ];

        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/hr/employees', $invalidData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['firstName', 'email', 'salary']);
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

        $response = $this->actingAs($this->hrUser, 'user')
            ->getJson('/api/hr/employees/statistics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'totalEmployees',
                'activeEmployees',
                'suspendedEmployees',
            ]);
    }
}
