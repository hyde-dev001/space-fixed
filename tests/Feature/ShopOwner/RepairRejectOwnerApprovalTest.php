<?php

namespace Tests\Feature\ShopOwner;

use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RepairRejectOwnerApprovalTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shopOwner;
    private User $manager;
    private User $repairer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
            'require_two_way_approval' => true,
        ]);

        Permission::findOrCreate('access-repair-reject-review', 'user');
        Role::findOrCreate('Manager', 'user');
        Role::findOrCreate('Repairer', 'user');

        $this->manager = User::factory()
            ->for($this->shopOwner)
            ->create(['role' => 'Manager', 'status' => 'active']);
        $this->manager->assignRole('Manager');
        $this->manager->givePermissionTo('access-repair-reject-review');

        $this->repairer = User::factory()
            ->for($this->shopOwner)
            ->create(['role' => 'Repairer', 'status' => 'active']);
        $this->repairer->assignRole('Repairer');
    }

    public function test_repairer_rejection_ignores_owner_policy_and_manager_is_final_approver(): void
    {
        $repair = $this->createAssignedRepair();

        $this->rejectAsRepairer($repair)
            ->assertOk()
            ->assertJson(['success' => true]);

        $repair->refresh();
        $this->assertSame('repairer_rejected', $repair->status);
        $this->assertFalse((bool) $repair->requires_owner_approval);

        $this->approveAsManager($repair, 'Manager confirms the repairer rejection.')
            ->assertOk();

        $repair->refresh();
        $this->assertSame('rejected', $repair->status);
        $this->assertSame($this->manager->id, $repair->manager_reviewed_by);
    }

    public function test_legacy_policy_off_snapshot_still_reaches_manager_final_decision(): void
    {
        $repair = $this->createAssignedRepair();

        $this->rejectAsRepairer($repair)->assertOk();

        $repair->refresh();
        $this->assertFalse((bool) $repair->requires_owner_approval);

        $this->approveAsManager($repair, 'Manager confirms the rejection.')
            ->assertOk();

        $repair->refresh();
        $this->assertSame('rejected', $repair->status);

        $this->assertDatabaseMissing('notifications', [
            'shop_owner_id' => $this->shopOwner->id,
            'type' => 'repair_rejection_review',
            'requires_action' => true,
        ]);

    }

    public function test_owner_rejection_endpoints_are_removed(): void
    {
        $repair = $this->createRepair([
            'status' => 'owner_approval_pending',
            'requires_owner_approval' => true,
        ]);

        $this->actingAs($this->shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/repairs/rejection-pending')
            ->assertNotFound();

        $this->actingAs($this->shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/approve-rejection")
            ->assertNotFound();

        $this->actingAs($this->shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/reject-rejection", [
                'notes' => 'Owner decisions are no longer part of this workflow.',
            ])
            ->assertNotFound();
    }

    public function test_manager_legacy_finalization_is_direct_and_cannot_be_replayed(): void
    {
        $repair = $this->createRepair([
            'status' => 'repairer_rejected',
            'requires_owner_approval' => false,
        ]);

        $this->finalizeAsManager($repair, 'Final manager decision confirms the rejection.')
            ->assertOk();
        $this->finalizeAsManager($repair, 'A replay must not change the decision.')
            ->assertStatus(400);

        $this->assertSame('rejected', $repair->fresh()->status);
    }

    public function test_high_value_repair_approval_page_is_removed(): void
    {
        $this->actingAs($this->shopOwner, 'shop_owner')
            ->get('/shop-owner/high-value-repairs')
            ->assertNotFound();
    }

    private function createAssignedRepair(): RepairRequest
    {
        return $this->createRepair([
            'status' => 'assigned_to_repairer',
            'assigned_repairer_id' => $this->repairer->id,
            'requires_owner_approval' => false,
        ]);
    }

    private function createRepair(array $attributes = []): RepairRequest
    {
        return RepairRequest::factory()
            ->for($this->shopOwner)
            ->create(array_merge([
                'total' => 1500,
                'customer_name' => 'Repair Customer',
            ], $attributes));
    }

    private function rejectAsRepairer(RepairRequest $repair)
    {
        return $this->actingAs($this->repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/reject", [
                'reason_text' => 'The repair cannot proceed safely with the available materials.',
                'reason_category' => 'parts_unavailable',
            ]);
    }

    private function approveAsManager(RepairRequest $repair, string $notes)
    {
        return $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/repairs/{$repair->id}/approve-rejection", [
                'notes' => $notes,
            ]);
    }

    private function finalizeAsManager(RepairRequest $repair, string $notes)
    {
        return $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/repairs/{$repair->id}/finalize-rejection", [
                'notes' => $notes,
            ]);
    }

}
