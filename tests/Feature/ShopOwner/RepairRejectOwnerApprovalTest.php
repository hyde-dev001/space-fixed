<?php

namespace Tests\Feature\ShopOwner;

use App\Models\ProcurementSettings;
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

    public function test_on_snapshots_policy_at_repairer_rejection_and_requires_owner_before_manager_final_review(): void
    {
        $this->setRepairRejectPolicy(true);
        $repair = $this->createAssignedRepair();

        $this->rejectAsRepairer($repair)
            ->assertOk()
            ->assertJson(['success' => true]);

        $repair->refresh();
        $this->assertSame('repairer_rejected', $repair->status);
        $this->assertTrue((bool) $repair->requires_owner_approval);

        $this->setRepairRejectPolicy(false);

        $this->approveAsManager($repair, 'Initial review complete; owner decision required.')
            ->assertOk();

        $repair->refresh();
        $this->assertSame('owner_approval_pending', $repair->status);

        $this->approveAsOwner($repair, 'Owner reviewed the repairer rejection.')
            ->assertOk();

        $repair->refresh();
        $this->assertSame('manager_reviewing', $repair->status);

        $this->finalizeAsManager($repair, 'Final manager decision confirms the rejection.')
            ->assertOk();

        $repair->refresh();
        $this->assertSame('rejected', $repair->status);
        $this->assertSame($this->manager->id, $repair->manager_reviewed_by);
    }

    public function test_off_snapshots_policy_and_routes_to_manager_final_review_without_owner_or_terminal_rejection(): void
    {
        $this->setRepairRejectPolicy(false);
        $repair = $this->createAssignedRepair();

        $this->rejectAsRepairer($repair)->assertOk();

        $repair->refresh();
        $this->assertFalse((bool) $repair->requires_owner_approval);

        $this->setRepairRejectPolicy(true);

        $this->approveAsManager($repair, 'Initial review complete; manager final review remains.')
            ->assertOk();

        $repair->refresh();
        $this->assertSame('manager_reviewing', $repair->status);
        $this->assertNotSame('rejected', $repair->status);

        $this->assertDatabaseMissing('notifications', [
            'shop_owner_id' => $this->shopOwner->id,
            'type' => 'repair_rejection_review',
            'requires_action' => true,
        ]);

        $this->approveAsOwner($repair, 'This stale owner action must be denied.')
            ->assertStatus(400);

        $repair->refresh();
        $this->assertSame('manager_reviewing', $repair->status);

        $this->finalizeAsManager($repair, 'Final manager decision confirms the rejection.')
            ->assertOk();

        $this->assertSame('rejected', $repair->fresh()->status);
    }

    public function test_owner_can_reject_the_rejection_request_and_return_repair_to_assigned_flow(): void
    {
        $repair = $this->createRepair([
            'status' => 'owner_approval_pending',
            'requires_owner_approval' => true,
            'repairer_rejection_reason' => 'The assigned repairer cannot safely complete this repair.',
            'repairer_rejected_at' => now()->subHour(),
        ]);

        $this->actingAs($this->shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/reject-rejection", [
                'notes' => 'Return this repair to the assigned workflow for reassignment.',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $repair->refresh();
        $this->assertSame('assigned_to_repairer', $repair->status);
        $this->assertSame('rejected', $repair->owner_decision);
        $this->assertSame($this->shopOwner->id, $repair->owner_reviewed_by);
    }

    public function test_owner_rejection_requires_meaningful_notes(): void
    {
        $repair = $this->createRepair([
            'status' => 'owner_approval_pending',
            'requires_owner_approval' => true,
        ]);

        $this->actingAs($this->shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/reject-rejection", [
                'notes' => 'Too short',
            ])
            ->assertStatus(422);

        $this->assertSame('owner_approval_pending', $repair->fresh()->status);
    }

    public function test_explicitly_off_snapshot_cannot_use_an_owner_stage(): void
    {
        $repair = $this->createRepair([
            'status' => 'owner_approval_pending',
            'requires_owner_approval' => false,
        ]);

        $this->actingAs($this->shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/approve-rejection", [
                'notes' => 'Stale owner approval must be rejected.',
            ])
            ->assertStatus(400);

        $this->assertSame('owner_approval_pending', $repair->fresh()->status);
    }

    public function test_wrong_shop_owner_cannot_approve_rejection(): void
    {
        $repair = $this->createRepair([
            'status' => 'owner_approval_pending',
            'requires_owner_approval' => true,
        ]);
        $otherShopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ]);

        $this->actingAs($otherShopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/approve-rejection", [])
            ->assertNotFound();
    }

    public function test_manager_cannot_finalize_before_the_final_review_stage_or_replay_finalization(): void
    {
        $repair = $this->createRepair([
            'status' => 'repairer_rejected',
            'requires_owner_approval' => false,
        ]);

        $this->finalizeAsManager($repair, 'This action is too early.')
            ->assertStatus(400);

        $this->approveAsManager($repair, 'Initial review complete; final review follows.')
            ->assertOk();
        $this->finalizeAsManager($repair, 'Final manager decision confirms the rejection.')
            ->assertOk();
        $this->finalizeAsManager($repair, 'A replay must not change the decision.')
            ->assertStatus(400);

        $this->assertSame('rejected', $repair->fresh()->status);
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

    private function approveAsOwner(RepairRequest $repair, string $notes)
    {
        return $this->actingAs($this->shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/approve-rejection", [
                'notes' => $notes,
            ]);
    }

    private function setRepairRejectPolicy(bool $enabled): void
    {
        $settings = ProcurementSettings::getForShopOwner($this->shopOwner->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['repair_reject_approval']['enabled'] = $enabled;
        $settings->update(['settings_json' => $settingsJson]);
    }
}
