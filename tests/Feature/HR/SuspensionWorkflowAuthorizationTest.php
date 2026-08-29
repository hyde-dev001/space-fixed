<?php

namespace Tests\Feature\HR;

use App\Enums\EmployeeStatus;
use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SuspensionWorkflowAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;
    private User $hr;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'access-employee-directory',
            'access-attendance-records',
            'access-payslip-generation',
        ] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }

        Role::findOrCreate('HR', 'user');

        $this->shop = ShopOwner::factory()->approved()->create();
        $this->hr = User::factory()->for($this->shop)->create([
            'role' => 'HR',
        ]);
        $this->hr->assignRole('HR');
        $this->hr->givePermissionTo('access-employee-directory');

        $this->employee = Employee::factory()->active()->for($this->shop)->create();
    }

    public function test_hr_suspension_requests_are_shop_scoped_and_cross_shop_targets_are_hidden(): void
    {
        $ownRequest = SuspensionRequest::factory()
            ->for($this->employee)
            ->create(['requested_by' => $this->hr->id]);

        $otherShop = ShopOwner::factory()->approved()->create();
        $otherEmployee = Employee::factory()->active()->for($otherShop)->create();
        $otherRequest = SuspensionRequest::factory()
            ->for($otherEmployee)
            ->create(['requested_by' => $this->hr->id]);

        $response = $this->actingAs($this->hr, 'user')
            ->getJson('/api/hr/suspension-requests?per_page=100');

        $response->assertOk();
        $body = $response->json();
        $rows = $body['data']['data'] ?? $body['data'] ?? [];
        $ids = collect($rows)->pluck('id');

        $this->assertTrue($ids->contains($ownRequest->id));
        $this->assertFalse($ids->contains($otherRequest->id));

        $this->actingAs($this->hr, 'user')
            ->postJson('/api/hr/suspension-requests', [
                'employee_id' => $otherEmployee->id,
                'reason' => 'Cross-shop request must be rejected.',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('suspension_requests', 2);
    }

    public function test_hr_can_submit_a_request_and_the_requester_and_audit_are_recorded(): void
    {
        $linkedUser = User::factory()->for($this->shop)->create([
            'email' => $this->employee->email,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->hr, 'user')
            ->postJson('/api/hr/suspension-requests', [
                'employee_id' => $this->employee->id,
                'reason' => 'Documented policy violation.',
                'evidence' => 'Incident report #42',
            ]);

        $response->assertCreated();

        $requestId = (int) $response->json('request.id');

        $this->assertDatabaseHas('suspension_requests', [
            'id' => $requestId,
            'employee_id' => $this->employee->id,
            'requested_by' => $this->hr->id,
            'status' => SuspensionStatus::PENDING_MANAGER->value,
        ]);

        $this->assertSame(
            EmployeeStatus::ACTIVE->value,
            $this->employee->fresh()->status->value,
        );
        $this->assertSame('active', $linkedUser->fresh()->status);

        $this->assertDatabaseHas('hr_audit_logs', [
            'shop_owner_id' => $this->shop->id,
            'user_id' => $this->hr->id,
            'employee_id' => $this->employee->id,
            'action' => AuditLog::ACTION_CREATED,
            'entity_id' => $requestId,
        ]);
    }

    public function test_non_hr_directory_access_cannot_read_or_create_suspension_requests(): void
    {
        $staff = User::factory()->for($this->shop)->create(['role' => 'Staff']);
        $staff->givePermissionTo('access-employee-directory');

        $this->actingAs($staff, 'user')
            ->getJson('/api/hr/suspension-requests')
            ->assertForbidden();

        $this->actingAs($staff, 'user')
            ->postJson('/api/hr/suspension-requests', [
                'employee_id' => $this->employee->id,
                'reason' => 'Staff must not bypass HR workflow.',
            ])
            ->assertForbidden();
    }

    public function test_broad_hr_permissions_cannot_directly_suspend_or_activate_employees(): void
    {
        foreach ([
            'access-employee-directory',
            'access-attendance-records',
            'access-payslip-generation',
        ] as $permission) {
            $actor = User::factory()->for($this->shop)->create(['role' => 'Staff']);
            $actor->givePermissionTo($permission);
            $employee = Employee::factory()->active()->for($this->shop)->create();

            $this->actingAs($actor, 'user')
                ->postJson("/api/hr/employees/{$employee->id}/suspend", [
                    'reason' => 'Direct status mutation must use the workflow.',
                ])
                ->assertForbidden()
                ->assertJsonPath('code', 'SUSPENSION_WORKFLOW_REQUIRED');

            $this->assertSame(EmployeeStatus::ACTIVE->value, $employee->fresh()->status->value);
        }

        $this->employee->forceFill(['status' => EmployeeStatus::SUSPENDED])->save();

        $this->actingAs($this->hr, 'user')
            ->postJson("/api/hr/employees/{$this->employee->id}/activate")
            ->assertForbidden()
            ->assertJsonPath('code', 'SUSPENSION_WORKFLOW_REQUIRED');

        $this->assertSame(EmployeeStatus::SUSPENDED->value, $this->employee->fresh()->status->value);
    }

    public function test_employee_status_update_cannot_bypass_suspension_workflow(): void
    {
        $this->actingAs($this->hr, 'user')
            ->putJson("/api/hr/employees/{$this->employee->id}", [
                'status' => EmployeeStatus::SUSPENDED->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUSPENSION_WORKFLOW_REQUIRED');

        $this->assertSame(EmployeeStatus::ACTIVE->value, $this->employee->fresh()->status->value);
    }

    public function test_legacy_shop_owner_suspend_and_activate_routes_cannot_bypass_the_workflow(): void
    {
        $this->shop->forceFill(['registration_type' => 'company'])->save();

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson("/shop-owner/employees/{$this->employee->id}/suspend", [
                'suspension_reason' => 'Legacy direct status changes must use the workflow.',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUSPENSION_WORKFLOW_REQUIRED');

        $this->assertSame(EmployeeStatus::ACTIVE->value, $this->employee->fresh()->status->value);

        $this->employee->forceFill(['status' => EmployeeStatus::SUSPENDED])->save();

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson("/shop-owner/employees/{$this->employee->id}/activate")
            ->assertForbidden()
            ->assertJsonPath('code', 'SUSPENSION_WORKFLOW_REQUIRED');

        $this->assertSame(EmployeeStatus::SUSPENDED->value, $this->employee->fresh()->status->value);
    }
}
