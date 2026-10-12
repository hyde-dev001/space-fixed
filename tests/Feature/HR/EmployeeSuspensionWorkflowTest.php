<?php

namespace Tests\Feature\HR;

use App\Enums\EmployeeStatus;
use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EmployeeSuspensionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-employee-directory', 'user');
        Role::findOrCreate('HR', 'user');

        $this->shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $this->hr = User::factory()->for($this->shop)->create([
            'role' => 'HR',
            'status' => 'active',
        ]);
        $this->hr->assignRole('HR');
        $this->hr->givePermissionTo('access-employee-directory');
    }

    #[Test]
    public function pending_suspension_request_keeps_employee_and_linked_user_active(): void
    {
        [$employee, $linkedUser] = $this->employeeWithLinkedUser();

        $response = $this->actingAs($this->hr, 'user')
            ->postJson('/api/hr/suspension-requests', [
                'employee_id' => $employee->id,
                'reason' => 'Repeated policy violations',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('suspension_requests', [
            'employee_id' => $employee->id,
            'requested_by' => $this->hr->id,
            'status' => SuspensionStatus::PENDING_MANAGER->value,
        ]);
        $this->assertSame(EmployeeStatus::ACTIVE, $employee->fresh()->status);
        $this->assertSame('active', $linkedUser->fresh()->getRawOriginal('status'));
    }

    #[Test]
    public function hr_suspension_request_list_only_contains_the_current_shop(): void
    {
        [$employee] = $this->employeeWithLinkedUser();
        $otherShop = ShopOwner::factory()->approved()->create();
        $otherEmployee = Employee::factory()->for($otherShop)->active()->create();

        $myRequest = SuspensionRequest::factory()->for($employee)->create([
            'requested_by' => $this->hr->id,
            'status' => SuspensionStatus::PENDING_MANAGER,
        ]);
        $otherRequest = SuspensionRequest::factory()->for($otherEmployee)->create([
            'status' => SuspensionStatus::PENDING_MANAGER,
        ]);

        $response = $this->actingAs($this->hr, 'user')
            ->getJson('/api/hr/suspension-requests');

        $response->assertOk()
            ->assertJsonFragment(['id' => $myRequest->id])
            ->assertJsonMissing(['id' => $otherRequest->id]);
    }

    #[Test]
    public function hr_cannot_create_a_suspension_request_for_another_shop(): void
    {
        $otherShop = ShopOwner::factory()->approved()->create();
        $otherEmployee = Employee::factory()->for($otherShop)->active()->create();

        $this->actingAs($this->hr, 'user')
            ->postJson('/api/hr/suspension-requests', [
                'employee_id' => $otherEmployee->id,
                'reason' => 'Cross-shop attempt',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('suspension_requests', [
            'employee_id' => $otherEmployee->id,
        ]);
    }

    #[Test]
    public function employee_directory_keeps_employee_activation_in_the_shop_scoped_flow(): void
    {
        [$employee, $linkedUser] = $this->employeeWithLinkedUser([
            'employee' => ['status' => EmployeeStatus::SUSPENDED],
            'user' => ['status' => 'suspended'],
        ]);

        $this->actingAs($this->hr, 'user')
            ->postJson("/api/hr/employees/{$employee->id}/suspend", [
                'reason' => 'Direct mutation must be rejected',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUSPENSION_WORKFLOW_REQUIRED');

        $this->actingAs($this->hr, 'user')
            ->postJson("/api/hr/employees/{$employee->id}/activate")
            ->assertOk()
            ->assertJsonPath('employee.status', EmployeeStatus::ACTIVE->value);

        $this->assertSame(EmployeeStatus::ACTIVE, $employee->fresh()->status);
        $this->assertSame('active', $linkedUser->fresh()->getRawOriginal('status'));
    }

    #[Test]
    public function employee_directory_cannot_change_suspension_status_through_general_update(): void
    {
        [$employee] = $this->employeeWithLinkedUser();

        $this->actingAs($this->hr, 'user')
            ->putJson("/api/hr/employees/{$employee->id}", [
                'status' => 'suspended',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUSPENSION_WORKFLOW_REQUIRED');

        $this->assertSame(EmployeeStatus::ACTIVE, $employee->fresh()->status);
    }

    #[Test]
    public function final_owner_approval_suspends_employee_and_its_shop_linked_user(): void
    {
        [$employee, $linkedUser] = $this->employeeWithLinkedUser();
        $request = SuspensionRequest::factory()->for($employee)->create([
            'requested_by' => $this->hr->id,
            'status' => SuspensionStatus::PENDING_OWNER,
            'manager_status' => 'approved',
            'reason' => 'Final approval test',
        ]);

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson("/api/shop-owner/suspension-requests/{$request->id}/review", [
                'action' => 'approve',
                'note' => 'Approved after review',
            ])
            ->assertOk();

        $this->assertSame(EmployeeStatus::SUSPENDED, $employee->fresh()->status);
        $this->assertSame('Final approval test', $employee->fresh()->suspension_reason);
        $this->assertSame('suspended', $linkedUser->fresh()->getRawOriginal('status'));
    }

    #[Test]
    public function owner_rejection_does_not_reactivate_an_existing_employee_suspension(): void
    {
        [$employee, $linkedUser] = $this->employeeWithLinkedUser([
            'employee' => [
                'status' => EmployeeStatus::SUSPENDED,
                'suspension_reason' => 'Existing suspension',
            ],
            'user' => ['status' => 'suspended'],
        ]);
        $request = SuspensionRequest::factory()->for($employee)->create([
            'requested_by' => $this->hr->id,
            'status' => SuspensionStatus::PENDING_OWNER,
            'manager_status' => 'approved',
        ]);

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson("/api/shop-owner/suspension-requests/{$request->id}/review", [
                'action' => 'reject',
                'note' => 'No suspension.',
            ])
            ->assertOk();

        $this->assertSame(EmployeeStatus::SUSPENDED, $employee->fresh()->status);
        $this->assertSame('Existing suspension', $employee->fresh()->suspension_reason);
        $this->assertSame('suspended', $linkedUser->fresh()->getRawOriginal('status'));
    }

    #[Test]
    public function shop_owner_direct_suspension_remains_approval_only_without_touching_platform_customers(): void
    {
        [$employee, $linkedUser] = $this->employeeWithLinkedUser();
        $customer = User::factory()->create([
            'shop_owner_id' => null,
            'status' => 'active',
        ]);

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson("/shop-owner/employees/{$employee->id}/suspend", [
                'suspension_reason' => 'Owner-approved suspension',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUSPENSION_WORKFLOW_REQUIRED');

        $this->assertSame(EmployeeStatus::ACTIVE, $employee->fresh()->status);
        $this->assertSame('active', $linkedUser->fresh()->getRawOriginal('status'));
        $this->assertSame('active', $customer->fresh()->getRawOriginal('status'));
    }

    #[Test]
    public function shop_owner_can_activate_the_employee_account_from_the_company_scoped_directory(): void
    {
        [$employee, $linkedUser] = $this->employeeWithLinkedUser([
            'employee' => ['status' => EmployeeStatus::SUSPENDED],
            'user' => ['status' => 'suspended'],
        ]);

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson("/shop-owner/employees/{$employee->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', EmployeeStatus::ACTIVE->value);

        $this->assertSame(EmployeeStatus::ACTIVE, $employee->fresh()->status);
        $this->assertSame('active', $linkedUser->fresh()->getRawOriginal('status'));
    }

    /** @return array{0: Employee, 1: User} */
    private function employeeWithLinkedUser(array $overrides = []): array
    {
        $email = 'employee-' . fake()->unique()->safeEmail;
        $employee = Employee::factory()->for($this->shop)->create(array_merge([
            'email' => $email,
            'status' => EmployeeStatus::ACTIVE,
        ], $overrides['employee'] ?? []));
        $linkedUser = User::factory()->for($this->shop)->create(array_merge([
            'email' => $email,
            'status' => 'active',
            'role' => 'HR',
        ], $overrides['user'] ?? []));

        return [$employee, $linkedUser];
    }
}
