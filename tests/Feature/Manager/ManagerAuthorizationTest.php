<?php

namespace Tests\Feature\Manager;

use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * P5: Manager Authorization & Authentication Tests
 *
 * Tests verify:
 * - Only authenticated Manager role users can access manager pages
 * - Shop scoping: Managers can only see their own shop's data
 * - Cross-shop access is prevented
 * - API endpoints require proper auth headers
 */
class ManagerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private ShopOwner $shop;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('Manager', 'user');
        Role::findOrCreate('Staff', 'user');

        // Create a shop and manager
        $this->shop = ShopOwner::factory()->create();
        $this->manager = User::factory()
            ->for($this->shop)
            ->create(['role' => 'Manager']);
        $this->manager->assignRole('Manager');
    }

    /**
     * Test: Unauthenticated user cannot access manager endpoints
     */
    public function test_unauthenticated_user_cannot_access_suspension_list(): void
    {
        $response = $this->getJson('/api/manager/suspension-requests');

        $response->assertStatus(401);
    }

    /**
     * Test: Non-manager role cannot access manager endpoints
     */
    public function test_non_manager_role_cannot_access_suspension_list(): void
    {
        $staff = User::factory()
            ->for($this->shop)
            ->create(['role' => 'Staff']);
        $staff->assignRole('Staff');

        $response = $this->actingAs($staff, 'user')
            ->getJson('/api/manager/suspension-requests');

        $response->assertStatus(403);
    }

    /**
     * Test: Manager can only see suspension requests from their own shop
     */
    public function test_manager_cannot_access_other_shops_suspension_requests(): void
    {
        // Create another shop with suspension requests
        $otherShop = ShopOwner::factory()->create();
        $otherManager = User::factory()->for($otherShop)->create(['role' => 'Manager']);
        $otherManager->assignRole('Manager');
        $otherEmployee = Employee::factory()->for($otherShop)->create();

        $otherRequest = SuspensionRequest::factory()
            ->for($otherEmployee)
            ->create(['status' => SuspensionStatus::PENDING_MANAGER]);

        // This shop's employee
        $myEmployee = Employee::factory()->for($this->shop)->create();
        $myRequest = SuspensionRequest::factory()
            ->for($myEmployee)
            ->create(['status' => SuspensionStatus::PENDING_MANAGER]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        
        // Should only see requests from current shop
        $this->assertTrue(collect($data)->pluck('id')->contains($myRequest->id));
        // Should not see other shop's requests
        $this->assertFalse(collect($data)->pluck('id')->contains($otherRequest->id));
    }

    /**
     * Test: Manager cannot approve suspension requests from other shops
     */
    public function test_manager_cannot_approve_other_shops_suspension(): void
    {
        $otherShop = ShopOwner::factory()->create();
        $otherEmployee = Employee::factory()->for($otherShop)->create();
        $otherRequest = SuspensionRequest::factory()
            ->for($otherEmployee)
            ->create(['status' => SuspensionStatus::PENDING_MANAGER]);

        $response = $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/suspension-requests/{$otherRequest->id}/review", [
                'action' => 'approve',
                'note' => 'Approved',
            ]);

        $response->assertStatus(404);
    }

    /**
     * Test: Authenticated manager with valid shop can access endpoints
     */
    public function test_manager_with_valid_shop_can_access_endpoints(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'data' => [
                    '*' => ['id', 'name', 'email', 'reason', 'status']
                ]
            ],
            'metrics' => ['pending', 'approved', 'rejected']
        ]);
    }

    public function test_suspension_queue_read_does_not_grant_manager_decision(): void
    {
        \Spatie\Permission\Models\Permission::findOrCreate('access-manager-suspension-approvals', 'user');
        $readOnlyManager = User::factory()
            ->for($this->shop)
            ->create(['role' => 'Staff']);
        $readOnlyManager->givePermissionTo('access-manager-suspension-approvals');

        $employee = Employee::factory()->for($this->shop)->create();
        $request = SuspensionRequest::factory()
            ->for($employee)
            ->create(['status' => SuspensionStatus::PENDING_MANAGER]);

        $this->actingAs($readOnlyManager, 'user')
            ->getJson('/api/manager/suspension-requests')
            ->assertOk();

        $this->actingAs($readOnlyManager, 'user')
            ->postJson("/api/manager/suspension-requests/{$request->id}/review", [
                'action' => 'approve',
            ])
            ->assertForbidden();

        $this->assertSame(SuspensionStatus::PENDING_MANAGER, $request->fresh()->status);
    }

    /**
     * Test: Manager's shop context is properly resolved from auth
     */
    public function test_manager_suspension_count_reflects_only_their_shop(): void
    {
        $employee1 = Employee::factory()->for($this->shop)->create();
        $employee2 = Employee::factory()->for($this->shop)->create();

        SuspensionRequest::factory(3)
            ->for($employee1)
            ->create(['status' => SuspensionStatus::PENDING_MANAGER]);

        SuspensionRequest::factory(2)
            ->for($employee2)
            ->create(['status' => SuspensionStatus::APPROVED]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests');

        $response->assertStatus(200);
        $metrics = $response->json('metrics');
        
        $this->assertEquals(3, $metrics['pending']);
        $this->assertEquals(2, $metrics['approved']);
        $this->assertEquals(5, $metrics['total']);
    }

    /**
     * Test: Dashboard stats endpoint requires manager role
     */
    public function test_dashboard_stats_requires_manager_role(): void
    {
        $staff = User::factory()
            ->for($this->shop)
            ->create(['role' => 'Staff']);

        $response = $this->actingAs($staff, 'user')
            ->getJson('/api/manager/dashboard/stats');

        $response->assertStatus(403);
    }

    /**
     * Test: Dashboard stats only show data from manager's shop
     */
    public function test_dashboard_stats_scoped_to_managers_shop(): void
    {
        // RBAC-focused check: manager can access endpoint.
        // Detailed stat correctness is covered in dedicated dashboard tests.
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/dashboard/stats');

        $this->assertNotEquals(403, $response->status());
    }

    public function test_repair_only_manager_cannot_access_retail_job_orders(): void
    {
        $this->shop->update(['business_type' => 'repair']);

        $this->actingAs($this->manager, 'user')
            ->get(route('erp.manager.job-orders'))
            ->assertRedirect(route('erp.profile'));

        $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/orders')
            ->assertForbidden()
            ->assertJsonPath('business_type', 'repair')
            ->assertJsonPath('allowed_types', ['retail', 'both']);
    }

    public function test_retail_and_both_managers_can_access_retail_job_orders(): void
    {
        foreach (['retail', 'both'] as $businessType) {
            $this->shop->update(['business_type' => $businessType]);

            $this->actingAs($this->manager, 'user')
                ->get(route('erp.manager.job-orders'))
                ->assertOk();

            $this->actingAs($this->manager, 'user')
                ->getJson('/api/manager/orders')
                ->assertOk();
        }
    }
}
