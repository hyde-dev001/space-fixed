<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeEmploymentPeriod;
use App\Models\EmployeeLifecycleRequest;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Models\User;
use App\Enums\SuspensionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class EmployeeTerminationAndRehireWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;
    private User $hr;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([
            'access-employee-directory',
            'request-employee-suspensions',
            'request-employee-terminations',
            'request-employee-rehires',
        ] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }

        foreach (['HR', 'Manager', 'Staff'] as $role) {
            Role::findOrCreate($role, 'user');
        }

        $this->shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $this->hr = User::factory()->for($this->shop)->create([
            'role' => 'HR',
            'status' => 'active',
        ]);
        $this->hr->assignRole('HR');
        $this->hr->givePermissionTo([
            'access-employee-directory',
            'request-employee-terminations',
            'request-employee-rehires',
        ]);

        $this->manager = User::factory()->for($this->shop)->create([
            'role' => 'Manager',
            'status' => 'active',
        ]);
        $this->manager->assignRole('Manager');
    }

    #[Test]
    public function termination_stays_pending_until_owner_approval_then_closes_employment_and_disables_account(): void
    {
        [$employee, $linkedUser] = $this->employeeWithLinkedUser();

        $request = $this->actingAs($this->hr, 'user')
            ->postJson('/api/hr/termination-requests', [
                'employee_id' => $employee->id,
                'reason' => 'The employment relationship has ended.',
                'evidence' => 'Signed separation record.',
            ])
            ->assertCreated()
            ->json('request');

        $requestId = (int) $request['id'];

        $this->assertDatabaseHas('employee_lifecycle_requests', [
            'id' => $requestId,
            'employee_id' => $employee->id,
            'request_type' => 'termination',
            'status' => 'pending_manager',
        ]);
        $this->assertSame(EmployeeStatus::ACTIVE, $employee->fresh()->status);
        $this->assertSame('active', $linkedUser->fresh()->getRawOriginal('status'));

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/termination-requests/{$requestId}/review", [
                'action' => 'approve',
                'note' => 'Manager reviewed the separation record.',
            ])
            ->assertOk()
            ->assertJsonPath('request.reason', 'The employment relationship has ended.')
            ->assertJsonPath('request.evidence', 'Signed separation record.')
            ->assertJsonPath('request.manager_note', 'Manager reviewed the separation record.');

        $this->assertSame(EmployeeStatus::ACTIVE, $employee->fresh()->status);

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson("/api/shop-owner/termination-requests/{$requestId}/review", [
                'action' => 'approve',
                'note' => 'Final employment termination approval.',
            ])
            ->assertOk()
            ->assertJsonPath('data.reason', 'The employment relationship has ended.')
            ->assertJsonPath('data.manager_note', 'Manager reviewed the separation record.')
            ->assertJsonPath('data.owner_note', 'Final employment termination approval.');

        $employee->refresh();
        $this->assertSame(EmployeeStatus::TERMINATED, $employee->status);
        $this->assertNotNull($employee->terminated_at);
        $this->assertSame('inactive', $linkedUser->fresh()->getRawOriginal('status'));
        $this->assertDatabaseHas('employee_employment_periods', [
            'employee_id' => $employee->id,
            'end_reason' => 'The employment relationship has ended.',
        ]);
        $this->assertDatabaseHas('employee_lifecycle_requests', [
            'id' => $requestId,
            'status' => 'approved',
        ]);

        $directoryResponse = $this->actingAs($this->hr, 'user')
            ->getJson('/api/hr/employees?status=terminated')
            ->assertOk()
            ->assertJsonPath('data.0.id', $employee->id);

        $this->assertNotNull($directoryResponse->json('data.0.terminated_at'));
        $this->assertSame(
            'The employment relationship has ended.',
            $directoryResponse->json('data.0.employment_periods.0.end_reason'),
        );
    }

    #[Test]
    public function direct_activation_and_status_edit_cannot_reopen_terminated_employment(): void
    {
        [$employee, $linkedUser] = $this->employeeWithLinkedUser([
            'employee' => [
                'status' => EmployeeStatus::TERMINATED,
                'terminated_at' => now()->subMonth(),
            ],
            'user' => ['status' => 'inactive'],
        ]);

        $this->actingAs($this->hr, 'user')
            ->postJson("/api/hr/employees/{$employee->id}/activate")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'EMPLOYEE_REHIRE_REQUIRED');

        $this->actingAs($this->hr, 'user')
            ->putJson("/api/hr/employees/{$employee->id}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'EMPLOYEE_REHIRE_REQUIRED');

        $this->assertSame(EmployeeStatus::TERMINATED, $employee->fresh()->status);
        $this->assertSame('inactive', $linkedUser->fresh()->getRawOriginal('status'));
    }

    #[Test]
    public function terminated_employment_cannot_enter_the_suspension_workflow(): void
    {
        [$employee] = $this->employeeWithLinkedUser([
            'employee' => [
                'status' => EmployeeStatus::TERMINATED,
                'terminated_at' => now()->subMonth(),
            ],
            'user' => ['status' => 'inactive'],
        ]);

        $this->actingAs($this->hr, 'user')
            ->postJson('/api/hr/suspension-requests', [
                'employee_id' => $employee->id,
                'reason' => 'A terminated employee must not be suspended.',
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'EMPLOYEE_REHIRE_REQUIRED');

        $staleRequest = SuspensionRequest::factory()->for($employee)->create([
            'requested_by' => $this->hr->id,
            'status' => SuspensionStatus::PENDING_OWNER,
            'manager_status' => 'approved',
        ]);

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson("/api/shop-owner/suspension-requests/{$staleRequest->id}/review", [
                'action' => 'approve',
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'EMPLOYEE_REHIRE_REQUIRED');

        $this->assertSame(EmployeeStatus::TERMINATED, $employee->fresh()->status);
    }

    #[Test]
    public function rehire_requires_a_new_start_date_and_explicit_role_then_opens_a_new_employment_period(): void
    {
        [$employee, $linkedUser] = $this->employeeWithLinkedUser([
            'employee' => [
                'status' => EmployeeStatus::TERMINATED,
                'terminated_at' => Carbon::parse('2026-08-31 10:00:00'),
            ],
            'user' => ['status' => 'inactive'],
        ]);
        $employee->forceFill(['hire_date' => '2025-01-10'])->save();
        EmployeeEmploymentPeriod::factory()->for($employee)->create([
            'start_date' => '2025-01-10',
            'end_date' => '2026-08-31',
            'end_reason' => 'Previous termination.',
            'role' => 'Manager',
        ]);

        $this->actingAs($this->hr, 'user')
            ->postJson('/api/hr/rehire-requests', [
                'employee_id' => $employee->id,
                'reason' => 'The employee is being considered for a new employment period.',
                'rehire_start_date' => '2027-02-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rehire_role']);

        $request = $this->actingAs($this->hr, 'user')
            ->postJson('/api/hr/rehire-requests', [
                'employee_id' => $employee->id,
                'reason' => 'The employee is being considered for a new employment period.',
                'rehire_start_date' => '2027-02-01',
                'rehire_position' => 'Repair Technician',
                'rehire_department' => 'Repair',
                'rehire_role' => 'Staff',
                'rehire_salary' => 42000,
            ])
            ->assertCreated()
            ->json('request');
        $requestId = (int) $request['id'];

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/rehire-requests/{$requestId}/review", [
                'action' => 'approve',
                'note' => 'The new employment terms were reviewed.',
            ])
            ->assertOk();

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson("/api/shop-owner/rehire-requests/{$requestId}/review", [
                'action' => 'approve',
                'note' => 'Approved as a new employment period.',
            ])
            ->assertOk();

        $employee->refresh();
        $linkedUser->refresh();
        $this->assertSame(EmployeeStatus::ACTIVE, $employee->status);
        $this->assertNull($employee->terminated_at);
        $this->assertSame('2027-02-01', $employee->hire_date->toDateString());
        $this->assertSame('Repair Technician', $employee->position);
        $this->assertSame('42000.00', (string) $employee->salary);
        $this->assertSame('active', $linkedUser->getRawOriginal('status'));
        $this->assertTrue($linkedUser->hasRole('Staff'));
        $this->assertSame(2, EmployeeEmploymentPeriod::where('employee_id', $employee->id)->count());
        $this->assertTrue(EmployeeEmploymentPeriod::query()
            ->where('employee_id', $employee->id)
            ->whereDate('start_date', '2027-02-01')
            ->whereNull('end_date')
            ->where('role', 'Staff')
            ->exists());
    }

    #[Test]
    public function rehire_does_not_restore_previous_direct_permissions(): void
    {
        [$employee, $linkedUser] = $this->employeeWithLinkedUser([
            'employee' => [
                'status' => EmployeeStatus::TERMINATED,
                'terminated_at' => now()->subMonth(),
            ],
            'user' => ['status' => 'inactive'],
        ]);
        $linkedUser->assignRole('Manager');
        Permission::findOrCreate('access-manager-dashboard', 'user');
        $linkedUser->givePermissionTo('access-manager-dashboard');

        $request = EmployeeLifecycleRequest::factory()->for($employee)->create([
            'request_type' => 'rehire',
            'requested_by' => $this->hr->id,
            'status' => 'pending_owner',
            'manager_status' => 'approved',
            'owner_status' => 'pending',
            'rehire_start_date' => '2027-02-01',
            'rehire_role' => 'Staff',
            'rehire_position' => 'Repair Technician',
        ]);

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson("/api/shop-owner/rehire-requests/{$request->id}/review", [
                'action' => 'approve',
                'note' => 'Reviewed new role and permissions.',
            ])
            ->assertOk();

        $linkedUser->refresh();
        $this->assertTrue($linkedUser->hasRole('Staff'));
        $this->assertFalse($linkedUser->hasRole('Manager'));
        $this->assertFalse($linkedUser->getDirectPermissions()->contains('name', 'access-manager-dashboard'));
    }

    #[Test]
    public function manager_can_filter_termination_and_rehire_history_by_rejected_status(): void
    {
        $terminationEmployee = Employee::factory()->for($this->shop)->create([
            'status' => EmployeeStatus::ACTIVE,
        ]);
        $terminationRequest = EmployeeLifecycleRequest::factory()->for($terminationEmployee)->create([
            'requested_by' => $this->hr->id,
            'request_type' => 'termination',
            'status' => 'rejected_manager',
            'manager_status' => 'rejected',
            'manager_id' => $this->manager->id,
        ]);

        $rehireEmployee = Employee::factory()->for($this->shop)->create([
            'status' => EmployeeStatus::TERMINATED,
            'terminated_at' => now()->subMonth(),
        ]);
        $rehireRequest = EmployeeLifecycleRequest::factory()->for($rehireEmployee)->create([
            'requested_by' => $this->hr->id,
            'request_type' => 'rehire',
            'status' => 'rejected_owner',
            'manager_status' => 'approved',
            'owner_status' => 'rejected',
            'manager_id' => $this->manager->id,
            'owner_id' => $this->shop->id,
            'rehire_start_date' => '2027-02-01',
            'rehire_role' => 'Staff',
        ]);

        $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/termination-requests?status=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonFragment(['id' => $terminationRequest->id]);

        $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/rehire-requests?status=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonFragment(['id' => $rehireRequest->id]);
    }

    #[Test]
    public function lifecycle_requests_are_not_available_to_individual_shop_accounts(): void
    {
        $individualShop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'repair',
        ]);
        $individualHr = User::factory()->for($individualShop)->create([
            'role' => 'HR',
            'status' => 'active',
        ]);
        $individualHr->assignRole('HR');
        $individualHr->givePermissionTo([
            'access-employee-directory',
            'request-employee-terminations',
            'request-employee-rehires',
        ]);
        $employee = Employee::factory()->for($individualShop)->create([
            'status' => EmployeeStatus::ACTIVE,
        ]);

        $this->actingAs($individualHr, 'user')
            ->postJson('/api/hr/termination-requests', [
                'employee_id' => $employee->id,
                'reason' => 'This should not be available to an individual account.',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'EMPLOYEE_LIFECYCLE_NOT_AUTHORIZED');

        $this->actingAs($individualShop, 'shop_owner')
            ->getJson('/api/shop-owner/termination-requests')
            ->assertForbidden()
            ->assertJsonPath('code', 'EMPLOYEE_LIFECYCLE_NOT_AUTHORIZED');

        $individualManager = User::factory()->for($individualShop)->create([
            'role' => 'Manager',
            'status' => 'active',
        ]);
        $individualManager->assignRole('Manager');

        $this->actingAs($individualManager, 'user')
            ->getJson('/api/manager/termination-requests')
            ->assertForbidden()
            ->assertJsonPath('code', 'TERMINATION_APPROVALS_READ_FORBIDDEN');
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
            'role' => 'Staff',
        ], $overrides['user'] ?? []));
        $linkedUser->assignRole('Staff');

        return [$employee, $linkedUser];
    }
}
