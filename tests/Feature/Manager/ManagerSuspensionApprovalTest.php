<?php

namespace Tests\Feature\Manager;

use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P5: Manager Suspension Approval Action Tests
 *
 * Tests verify:
 * - Manager can approve pending suspensions
 * - Manager can reject pending suspensions
 * - Status transitions are correct
 * - Cannot approve already-approved requests
 * - Metrics update correctly after actions
 * - Proper validation of input (note length, required fields)
 */
class ManagerSuspensionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private ShopOwner $shop;
    private Employee $employee;
    private SuspensionRequest $suspension;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = ShopOwner::factory()->create();
        $this->manager = User::factory()
            ->for($this->shop)
            ->create(['role' => 'Manager']);

        $this->employee = Employee::factory()
            ->for($this->shop)
            ->create();

        $this->suspension = SuspensionRequest::factory()
            ->for($this->employee)
            ->create(['status' => SuspensionStatus::PENDING_MANAGER]);
    }

    /**
     * Test: Manager can successfully approve a pending suspension
     */
    public function test_manager_can_approve_pending_suspension(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/suspension-requests/{$this->suspension->id}/review",
                [
                    'action' => 'approve',
                    'note' => 'Approved by manager - justified grounds',
                ]
            );

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Suspension request approved']);

        // Verify status changed in database
        $this->suspension->refresh();
        $this->assertEquals(SuspensionStatus::PENDING_OWNER, $this->suspension->status);
        $this->assertNotNull($this->suspension->manager_id);
        $this->assertEquals($this->manager->id, $this->suspension->manager_id);
        $this->assertNotNull($this->suspension->manager_reviewed_at);
    }

    /**
     * Test: Manager can successfully reject a pending suspension
     */
    public function test_manager_can_reject_pending_suspension(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/suspension-requests/{$this->suspension->id}/review",
                [
                    'action' => 'reject',
                    'note' => 'Rejected - insufficient evidence',
                ]
            );

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Suspension request rejected']);

        $this->suspension->refresh();
        $this->assertEquals(SuspensionStatus::REJECTED_MANAGER, $this->suspension->status);
        $this->assertEquals('rejected', $this->suspension->manager_status);
        $this->assertNotNull($this->suspension->manager_note);
    }

    /**
     * Test: Approval note is optional
     */
    public function test_suspension_approval_note_is_optional(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/suspension-requests/{$this->suspension->id}/review",
                ['action' => 'approve']
            );

        $response->assertStatus(200);

        $this->suspension->refresh();
        $this->assertEquals(SuspensionStatus::PENDING_OWNER, $this->suspension->status);
        $this->assertNull($this->suspension->manager_note);
    }

    /**
     * Test: Cannot review already-approved suspension
     */
    public function test_cannot_review_already_approved_suspension(): void
    {
        // First approval
        $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/suspension-requests/{$this->suspension->id}/review",
                ['action' => 'approve', 'note' => 'Approved']
            );

        // Try to review again
        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/suspension-requests/{$this->suspension->id}/review",
                ['action' => 'reject', 'note' => 'Trying to reject already approved']
            );

        $response->assertStatus(422);
        $response->assertJson(['message' => 'This request is not pending manager review.']);
    }

    /**
     * Test: Invalid action parameter is rejected
     */
    public function test_invalid_action_parameter_rejected(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/suspension-requests/{$this->suspension->id}/review",
                ['action' => 'invalid_action']
            );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['action']);
    }

    /**
     * Test: Manager ID is set correctly on approval
     */
    public function test_manager_id_set_correctly_on_approval(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/suspension-requests/{$this->suspension->id}/review",
                ['action' => 'approve']
            );

        $this->suspension->refresh();
        $this->assertEquals($this->manager->id, $this->suspension->manager_id);
    }

    /**
     * Test: Manager cannot approve other shop's suspension
     */
    public function test_manager_cannot_approve_other_shops_suspension(): void
    {
        $otherShop = ShopOwner::factory()->create();
        $otherEmployee = Employee::factory()->for($otherShop)->create();
        $otherSuspension = SuspensionRequest::factory()
            ->for($otherEmployee)
            ->create(['status' => SuspensionStatus::PENDING_MANAGER]);

        $response = $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/suspension-requests/{$otherSuspension->id}/review",
                ['action' => 'approve']
            );

        $response->assertStatus(404);
    }

    /**
     * Test: Timestamp set correctly on review
     */
    public function test_manager_reviewed_at_timestamp_set(): void
    {
        $now = now();

        $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/suspension-requests/{$this->suspension->id}/review",
                ['action' => 'approve']
            );

        $this->suspension->refresh();
        $this->assertNotNull($this->suspension->manager_reviewed_at);
        $this->assertTrue($this->suspension->manager_reviewed_at->isBetween($now->subSecond(), now()->addSecond()));
    }

    /**
     * Test: Rejection reason is stored in note field
     */
    public function test_rejection_reason_stored_in_note(): void
    {
        $rejectionReason = 'Reason for rejection - insufficient documentation';

        $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/suspension-requests/{$this->suspension->id}/review",
                [
                    'action' => 'reject',
                    'note' => $rejectionReason
                ]
            );

        $this->suspension->refresh();
        $this->assertEquals($rejectionReason, $this->suspension->manager_note);
    }

    /**
     * Test: Metrics update after approve action
     */
    public function test_metrics_update_after_suspension_approved(): void
    {
        // Get initial metrics
        $beforeResponse = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?per_page=100');

        $beforeMetrics = $beforeResponse->json('metrics');

        // Approve suspension
        $this->actingAs($this->manager, 'user')
            ->postJson(
                "/api/manager/suspension-requests/{$this->suspension->id}/review",
                ['action' => 'approve']
            );

        // Get updated metrics
        $afterResponse = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?per_page=100');

        $afterMetrics = $afterResponse->json('metrics');

        // Pending should decrease, approved should increase
        $this->assertEquals($beforeMetrics['pending'] - 1, $afterMetrics['pending']);
        $this->assertEquals($beforeMetrics['approved'] + 1, $afterMetrics['approved']);
    }

    /**
     * Test: Can view suspension request details
     */
    public function test_manager_can_view_suspension_details(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson("/api/manager/suspension-requests/{$this->suspension->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'id',
            'name',
            'email',
            'reason',
            'requestedAt',
            'status',
        ]);
    }

    /**
     * Test: Cannot view nonexistent suspension
     */
    public function test_cannot_view_nonexistent_suspension(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests/99999');

        $response->assertStatus(404);
    }
}
