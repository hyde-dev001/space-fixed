<?php

namespace Tests\Feature\Repair;

use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LegacyRepairOwnerApprovalReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_owner_stage_rows_are_reconciled_without_erasing_history(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $reviewer = User::factory()->for($shopOwner)->create();

        $managerApproved = RepairRequest::factory()->for($shopOwner)->create([
            'status' => 'owner_approval_pending',
            'requires_owner_approval' => true,
            'repairer_rejected_at' => now()->subHour(),
            'manager_decision' => 'approve_rejection',
            'manager_reviewed_at' => now()->subMinutes(30),
            'manager_reviewed_by' => $reviewer->id,
            'owner_approval_notes' => 'Legacy approval context.',
        ]);
        $unresolvedRejection = RepairRequest::factory()->for($shopOwner)->create([
            'status' => 'pending_owner_approval',
            'requires_owner_approval' => true,
            'repairer_rejected_at' => now()->subHour(),
            'manager_decision' => null,
            'owner_decision' => null,
        ]);
        $nonRejection = RepairRequest::factory()->for($shopOwner)->create([
            'status' => 'owner_approval_pending',
            'requires_owner_approval' => true,
            'is_high_value' => true,
            'repairer_rejected_at' => null,
            'manager_decision' => null,
            'owner_decision' => null,
        ]);
        $ownerDecided = RepairRequest::factory()->for($shopOwner)->create([
            'status' => 'owner_approval_pending',
            'requires_owner_approval' => true,
            'owner_decision' => 'approved',
        ]);

        $migration = require base_path(
            'database/migrations/2026_09_09_000001_reconcile_legacy_repair_owner_approval.php',
        );
        $migration->up();

        $this->assertSame('rejected', $managerApproved->fresh()->status);
        $this->assertFalse((bool) $managerApproved->fresh()->requires_owner_approval);
        $this->assertSame('Legacy approval context.', $managerApproved->fresh()->owner_approval_notes);

        $this->assertSame('repairer_rejected', $unresolvedRejection->fresh()->status);
        $this->assertFalse((bool) $unresolvedRejection->fresh()->requires_owner_approval);

        $this->assertSame('pending', $nonRejection->fresh()->status);
        $this->assertFalse((bool) $nonRejection->fresh()->requires_owner_approval);
        $this->assertTrue((bool) $nonRejection->fresh()->is_high_value);

        $this->assertSame('owner_approval_pending', $ownerDecided->fresh()->status);
        $this->assertTrue((bool) $ownerDecided->fresh()->requires_owner_approval);
        $this->assertSame('approved', $ownerDecided->fresh()->owner_decision);

        $migration->up();

        $this->assertSame('rejected', $managerApproved->fresh()->status);
        $this->assertSame('repairer_rejected', $unresolvedRejection->fresh()->status);
        $this->assertSame('pending', $nonRejection->fresh()->status);
    }
}
