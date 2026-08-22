<?php

namespace Tests\Feature\Manager;

use App\Models\ProcurementSettings;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P5: Manager Repair Rejection Review Action Tests
 *
 * Tests verify:
 * - Manager can approve repair rejections
 * - Manager can override (reject) repair rejections
 * - Status transitions are correct for rejection workflows
 * - Only managers with repair business type can access
 * - Approval/override notes are stored
 * - Cannot re-review already-decided rejections
 */
class ManagerRepairRejectionTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $replacementRepairer;
    private ShopOwner $repairShop;
    private RepairRequest $rejectedRepair;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a repair-type shop
        $this->repairShop = ShopOwner::factory()->create([
            'business_type' => 'repair' // or 'both'
        ]);

        $this->manager = User::factory()
            ->for($this->repairShop)
            ->create(['role' => 'Manager']);
        Role::findOrCreate('Manager', 'user');
        $this->manager->assignRole('Manager');

        Role::findOrCreate('Repairer', 'user');
        $this->replacementRepairer = User::factory()
            ->for($this->repairShop)
            ->create(['role' => 'Repairer', 'status' => 'active']);
        $this->replacementRepairer->assignRole('Repairer');

        // Create a repair request that has been rejected by repairer
        $this->rejectedRepair = RepairRequest::factory()
            ->for($this->repairShop)
            ->create([
                'status' => 'repairer_rejected',
                'requires_owner_approval' => true,
                'repairer_rejection_reason' => 'Cannot repair - parts unavailable',
                'repairer_rejected_at' => now()->subHours(2),
                'manager_decision' => null, // Not yet reviewed by manager
                'manager_reviewed_at' => null,
            ]);
    }

    /**
     * Test: Non-repair manager cannot access repair rejection review page
     */
    public function test_retail_only_manager_cannot_access_repair_rejections(): void
    {
        $retailShop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $retailManager = User::factory()
            ->for($retailShop)
            ->create(['role' => 'Manager']);
        $retailManager->assignRole('Manager');

        $response = $this->actingAs($retailManager, 'user')
            ->getJson('/api/manager/repairs/rejected');

        $response->assertForbidden();
    }

    /**
     * Test: Repair manager can access rejection list
     */
    public function test_repair_manager_can_access_rejection_list(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/repairs/rejected');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'request_id', 'status', 'repairer_rejection_reason']
            ]
        ]);
    }

    /**
     * Test: Manager can approve a repair rejection
     */
    public function test_manager_can_approve_repair_rejection(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/repairs/{$this->rejectedRepair->id}/approve-rejection",
                ['notes' => 'Approved - valid reason, parts unavailable']
            );

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->rejectedRepair->refresh();
        $this->assertEquals('approve_rejection', $this->rejectedRepair->manager_decision);
        $this->assertNotNull($this->rejectedRepair->manager_reviewed_at);
    }

    /**
     * Test: OFF skips only the owner stage and leaves the final manager decision pending
     */
    public function test_manager_approval_routes_an_off_snapshot_to_manager_final_review(): void
    {
        $this->setRepairRejectPolicy(false);
        $this->rejectedRepair->update(['requires_owner_approval' => false]);

        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/repairs/{$this->rejectedRepair->id}/approve-rejection",
                ['notes' => 'Initial review complete; final manager review remains.']
            );

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->rejectedRepair->refresh();
        $this->assertSame('manager_reviewing', $this->rejectedRepair->status);
        $this->assertNotSame('rejected', $this->rejectedRepair->status);
    }

    /**
     * Test: Manager can override repair rejection (reassign)
     */
    public function test_manager_can_override_repair_rejection(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/repairs/{$this->rejectedRepair->id}/override-rejection",
                [
                    'notes' => 'Overriding - will contact supplier for parts',
                    'repairer_id' => $this->replacementRepairer->id,
                ]
            );

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->rejectedRepair->refresh();
        $this->assertEquals('override_accept', $this->rejectedRepair->manager_decision);
        $this->assertNotNull($this->rejectedRepair->manager_reviewed_by);
        $this->assertNotNull($this->rejectedRepair->manager_reviewed_at);
    }

    /**
     * Test: Cannot approve already-decided rejection
     */
    public function test_cannot_approve_already_decided_rejection(): void
    {
        // First approval
        $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/repairs/{$this->rejectedRepair->id}/approve-rejection",
                ['notes' => 'Approved']
            );

        // Try to override already-approved
        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/repairs/{$this->rejectedRepair->id}/override-rejection",
                [
                    'notes' => 'Trying to override',
                    'repairer_id' => $this->replacementRepairer->id,
                ]
            );

        $this->assertContains($response->status(), [400, 409, 422]);
    }

    /**
     * Test: Override requires minimum note length
     */
    public function test_override_requires_minimum_note_length(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/repairs/{$this->rejectedRepair->id}/override-rejection",
                ['notes' => 'Short'] // Too short
            );

        // Should either fail validation or be rejected
        $response->assertStatus(422);
    }

    /**
     * Test: Manager ID is set on decision
     */
    public function test_manager_id_set_on_repair_rejection_decision(): void
    {
        $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/repairs/{$this->rejectedRepair->id}/approve-rejection",
                ['notes' => 'Approved']
            );

        $this->rejectedRepair->refresh();
        $this->assertEquals($this->manager->id, $this->rejectedRepair->manager_reviewed_by);
    }

    /**
     * Test: Rejection list only shows repairer-rejected items
     */
    public function test_rejection_list_only_shows_repairer_rejected(): void
    {
        // Create a completed repair (not rejected)
        RepairRequest::factory()
            ->for($this->repairShop)
            ->create([
                'status' => 'completed',
                'manager_decision' => null,
            ]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/repairs/rejected');

        $data = $response->json('data');

        // All items should have repairer_rejected status
        foreach ($data as $repair) {
            $this->assertEquals('repairer_rejected', $repair['status']);
        }
    }

    /**
     * Test: Cannot access other shop's repair rejection
     */
    public function test_manager_cannot_access_other_shops_repair_rejection(): void
    {
        $otherShop = ShopOwner::factory()->create(['business_type' => 'repair']);
        $otherRepair = RepairRequest::factory()
            ->for($otherShop)
            ->create(['status' => 'repairer_rejected']);

        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/repairs/{$otherRepair->id}/approve-rejection",
                ['notes' => 'Should fail']
            );

        $response->assertForbidden();
    }

    /**
     * Test: Approval timestamp is set correctly
     */
    public function test_approval_timestamp_set_correctly(): void
    {
        $now = now();

        $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/repairs/{$this->rejectedRepair->id}/approve-rejection",
                []
            );

        $this->rejectedRepair->refresh();
        $this->assertNotNull($this->rejectedRepair->manager_reviewed_at);
        $this->assertTrue($this->rejectedRepair->manager_reviewed_at->isBetween(
            $now->subSecond(),
            now()->addSecond()
        ));
    }

    /**
     * Test: Cannot review nonexistent repair rejection
     */
    public function test_cannot_review_nonexistent_repair(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/repairs/99999/approve-rejection');

        $response->assertStatus(404);
    }

    /**
     * Test: Rejection list pagination works
     */
    public function test_repair_rejection_list_pagination_works(): void
    {
        // Create multiple rejected repairs
        RepairRequest::factory(5)
            ->for($this->repairShop)
            ->create([
                'status' => 'repairer_rejected',
                'repairer_rejected_at' => now(),
            ]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/repairs/rejected');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertCount(6, $data);
    }

    public function test_repairer_rejection_notifies_permission_based_manager_reviewer(): void
    {
        Permission::findOrCreate('access-repair-reject-review', 'user');

        $permissionBasedReviewer = User::factory()
            ->for($this->repairShop)
            ->create(['role' => 'STAFF']);
        $permissionBasedReviewer->givePermissionTo('access-repair-reject-review');

        $repairer = User::factory()
            ->for($this->repairShop)
            ->create(['role' => 'STAFF']);

        $assignedRepair = RepairRequest::factory()
            ->for($this->repairShop)
            ->create([
                'request_id' => 'REP-NOTIF-001',
                'status' => 'assigned_to_repairer',
                'assigned_repairer_id' => $repairer->id,
            ]);

        $response = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$assignedRepair->id}/reject", [
                'reason_text' => 'Cannot proceed due to severe structural damage.',
                'reason_category' => 'safety_risk',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $permissionBasedReviewer->id,
            'type' => 'repair_rejection_review',
            'action_url' => '/erp/manager/repair-rejection-review',
        ]);
    }

    private function setRepairRejectPolicy(bool $enabled): void
    {
        $settings = ProcurementSettings::getForShopOwner($this->repairShop->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['repair_reject_approval']['enabled'] = $enabled;
        $settings->update(['settings_json' => $settingsJson]);
    }
}
