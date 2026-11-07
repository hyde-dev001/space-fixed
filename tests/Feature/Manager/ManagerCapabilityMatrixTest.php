<?php

namespace Tests\Feature\Manager;

use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManagerCapabilityMatrixTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->shop = ShopOwner::factory()->create(['business_type' => 'repair']);
    }

    public function test_dashboard_read_does_not_authorize_repair_review_mutation(): void
    {
        $manager = $this->userWithPermissions(['access-manager-dashboard']);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'status' => 'manager_reviewing',
        ]);

        $response = $this->actingAs($manager, 'user')
            ->postJson("/api/manager/repairs/{$repair->id}/finalize-rejection", [
                'notes' => 'Final review note',
            ]);

        $response->assertForbidden();
        $this->assertSame('manager_reviewing', $repair->fresh()->status);
    }

    public function test_repair_queue_read_does_not_authorize_repair_review_mutation(): void
    {
        $manager = $this->userWithPermissions(['access-repair-reject-review']);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'status' => 'manager_reviewing',
        ]);

        $this->actingAs($manager, 'user')
            ->getJson('/api/manager/repairs/rejected')
            ->assertOk();

        $this->actingAs($manager, 'user')
            ->postJson("/api/manager/repairs/{$repair->id}/finalize-rejection", [
                'notes' => 'Final review note',
            ])
            ->assertForbidden();
    }

    public function test_dashboard_read_does_not_authorize_suspension_review_mutation(): void
    {
        $manager = $this->userWithPermissions(['access-manager-dashboard']);
        $employee = Employee::factory()->for($this->shop)->create();
        $request = SuspensionRequest::factory()->for($employee)->create([
            'status' => SuspensionStatus::PENDING_MANAGER,
        ]);

        $response = $this->actingAs($manager, 'user')
            ->postJson("/api/manager/suspension-requests/{$request->id}/review", [
                'action' => 'approve',
                'note' => 'Needs owner review',
            ]);

        $response->assertForbidden();
        $this->assertSame(SuspensionStatus::PENDING_MANAGER, $request->fresh()->status);
    }

    public function test_manager_target_lookup_ignores_request_shop_id_and_hides_other_shop(): void
    {
        $manager = $this->userWithPermissions(['access-suspend-account']);
        $otherShop = ShopOwner::factory()->create();
        $otherEmployee = Employee::factory()->for($otherShop)->create();
        $otherRequest = SuspensionRequest::factory()->for($otherEmployee)->create([
            'status' => SuspensionStatus::PENDING_MANAGER,
        ]);

        $response = $this->actingAs($manager, 'user')
            ->getJson('/api/manager/suspension-requests?shop_owner_id=' . $otherShop->id);

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');

        $this->assertFalse($ids->contains($otherRequest->id));
    }

    public function test_permission_administration_is_not_granted_by_manager_dashboard_access(): void
    {
        $manager = $this->userWithPermissions(['access-manager-dashboard']);
        $employee = Employee::factory()->for($this->shop)->create();

        $this->actingAs($manager, 'user')
            ->getJson('/api/hr/employees/' . $employee->id . '/permissions')
            ->assertForbidden();
    }

    public function test_manager_capability_requires_a_valid_shop_relationship(): void
    {
        Permission::findOrCreate('access-manager-dashboard', 'user');
        $manager = User::factory()->create(['role' => 'Staff', 'shop_owner_id' => null]);
        $manager->givePermissionTo('access-manager-dashboard');

        $this->actingAs($manager, 'user')
            ->getJson('/api/manager/dashboard/stats')
            ->assertForbidden();
    }

    private function userWithPermissions(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'user');
        }

        $user = User::factory()->for($this->shop)->create(['role' => 'Staff']);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
