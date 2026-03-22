<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\PriceChangeRequest;
use App\Models\User;
use App\Enums\ApprovalStatus;

class PriceChangeApprovalService
{
    public function __construct(
        private ApprovalService $approvalService,
        private NotificationService $notificationService
    ) {}

    /**
     * Create a 4-step approval workflow for a price change
     * Finance (1) → Shop Owner (2) → Finance (3) → Finance Final (4)
     */
    public function createPriceChangeApproval(PriceChangeRequest $priceChange, User $shopOwner, User $requestedBy): Approval
    {
        $approvalRoles = [
            '1' => 'finance',           // Finance checks first
            '2' => 'shop_owner',        // Shop owner approves
            '3' => 'finance',           // Finance reviews again
            '4' => 'finance_final'      // Finance Manager final approval
        ];

        // Create polymorphic approval record
        $approval = $this->approvalService->createApproval(
            approvable: $priceChange,
            approvalRoles: $approvalRoles,
            requestedBy: $requestedBy,
            shopOwner: $shopOwner,
            reference: "PCR-{$priceChange->id}",
            description: "Price Change: {$priceChange->product_name} (₱{$priceChange->current_price} → ₱{$priceChange->proposed_price})",
            amount: abs((float)$priceChange->getPriceChangeAmount()),
            metadata: [
                'price_change_id' => $priceChange->id,
                'product_id' => $priceChange->product_id,
                'product_name' => $priceChange->product_name,
                'current_price' => (float)$priceChange->current_price,
                'proposed_price' => (float)$priceChange->proposed_price,
                'change_percentage' => $priceChange->getPriceChangePercentage(),
                'reason' => $priceChange->reason
            ]
        );

        // Link the price change to this approval
        $priceChange->update([
            'approval_id' => $approval->id,
            'current_approval_level' => 1,
            'approval_workflow_version' => 'v4_multi_level'
        ]);

        return $approval;
    }

    /**
     * Approve a price change at current approval level
     */
    public function approvePriceChange(PriceChangeRequest $priceChange, User $approver, ?string $comments = null): array
    {
        if (!$priceChange->approval_id) {
            return [
                'success' => false,
                'message' => 'No approval workflow found for this price change'
            ];
        }

        $approval = Approval::find($priceChange->approval_id);
        if (!$approval) {
            return [
                'success' => false,
                'message' => 'Approval record not found'
            ];
        }

        // Use ApprovalService to transition
        $result = $this->approvalService->approve($approval, $approver, $comments);

        if (!$result['success']) {
            return $result;
        }

        // Keep intermediate states compatible with existing price change enum values
        $statusMapping = [
            1 => 'pending',
            2 => 'finance_approved',
            3 => 'finance_approved',
            4 => 'owner_approved'
        ];

        if ($result['is_final'] ?? false) {
            // Final approval - ready to be applied
            $priceChange->update([
                'status' => 'owner_approved',
                'owner_rejection_reason' => null,  // Clear any previous rejection
                'current_approval_level' => $approval->current_level
            ]);
        } else {
            // Intermediate approval
            $nextLevel = $approval->current_level;
            $priceChange->update([
                'current_approval_level' => $nextLevel,
                'status' => $statusMapping[$nextLevel] ?? 'pending'
            ]);

            // Update appropriate field based on level
            if ($nextLevel === 2) {
                // After Finance level 1
                $priceChange->update([
                    'finance_reviewed_by' => $approver->id,
                    'finance_reviewed_at' => now(),
                    'finance_notes' => $comments
                ]);
            } elseif ($nextLevel === 3) {
                // After Shop Owner level 2
                $priceChange->update([
                    'owner_reviewed_by' => $approver->shop_owner_id,
                    'owner_reviewed_at' => now()
                ]);
            }
        }

        return $result;
    }

    /**
     * Reject a price change at current approval level
     */
    public function rejectPriceChange(PriceChangeRequest $priceChange, User $rejector, string $comments = ''): array
    {
        if (!$priceChange->approval_id) {
            return [
                'success' => false,
                'message' => 'No approval workflow found for this price change'
            ];
        }

        $approval = Approval::find($priceChange->approval_id);
        if (!$approval) {
            return [
                'success' => false,
                'message' => 'Approval record not found'
            ];
        }

        // Use ApprovalService to reject
        $result = $this->approvalService->reject($approval, $rejector, $comments);

        if (!$result['success']) {
            return $result;
        }

        // Update price change to rejected status
        $rejectionStatus = $approval->current_level === 1 ? 'finance_rejected' : 'owner_rejected';
        
        $priceChange->update([
            'status' => $rejectionStatus,
            'current_approval_level' => $approval->current_level
        ]);

        // Update appropriate rejection field
        if ($approval->current_level === 1) {
            $priceChange->update([
                'finance_reviewed_by' => $rejector->id,
                'finance_reviewed_at' => now(),
                'finance_rejection_reason' => $comments
            ]);
        } else {
            $priceChange->update([
                'owner_reviewed_by' => $rejector->shop_owner_id,
                'owner_reviewed_at' => now(),
                'owner_rejection_reason' => $comments
            ]);
        }

        // Send rejection notification to the shop owner
        $shopOwnerId = $priceChange->shop_owner_id;
        $this->notificationService->notifyPriceChangeRejected($shopOwnerId, [
            'product_name' => $priceChange->product?->name ?? 'Product',
            'old_price' => (float)$priceChange->old_price,
            'new_price' => (float)$priceChange->new_price,
            'rejection_reason' => $comments,
        ]);

        return $result;
    }

    /**
     * Get pending price changes for a user based on their role
     */
    public function getPendingPriceChangesForUser(User $shopOwner, User $approver): \Illuminate\Database\Eloquent\Collection
    {
        // Get all pending Approval records for PriceChangeRequest type
        $approvals = Approval::where('shop_owner_id', $shopOwner->id)
            ->where('approvable_type', PriceChangeRequest::class)
            ->where('status', ApprovalStatus::PENDING)
            ->get();

        // Filter by user's ability to approve
        return $approvals->filter(function ($approval) use ($approver) {
            return $approval->canApprove($approver);
        })->map(function ($approval) {
            // Load the associated price change
            return $approval->approvable()->first();
        })->filter()
        ->values();
    }

    /**
     * Get approval summary for a price change
     */
    public function getApprovalSummary(Approval $approval): array
    {
        $priceChange = $approval->approvable;

        $next_info = $approval->current_level < $approval->total_levels
            ? [
                'level' => $approval->current_level + 1,
                'role' => $approval->approval_roles[$approval->current_level + 1] ?? 'Unknown',
            ]
            : null;

        return [
            'approval_id' => $approval->id,
            'price_change_id' => $priceChange->id,
            'product_name' => $priceChange->product_name,
            'current_price' => (float)$priceChange->current_price,
            'proposed_price' => (float)$priceChange->proposed_price,
            'change_amount' => (float)$priceChange->getPriceChangeAmount(),
            'change_percentage' => $priceChange->getPriceChangePercentage(),
            'current_level' => $approval->current_level,
            'total_levels' => $approval->total_levels,
            'current_approver_role' => $approval->current_approver_role,
            'next_approver' => $next_info,
            'status' => $approval->status,
            'approval_progress' => "{$approval->current_level}/{$approval->total_levels}",
            'level_history' => $this->formatLevelHistory($approval)
        ];
    }

    /**
     * Format approval level history for display
     */
    private function formatLevelHistory(Approval $approval): array
    {
        $history = [];
        
        if (!$approval->level_reviewers) {
            return $history;
        }

        foreach ($approval->level_reviewers as $level => $reviewer_data) {
            $history[] = [
                'level' => (int)$level,
                'role' => $approval->approval_roles[$level] ?? 'Unknown',
                'reviewer_id' => $reviewer_data['user_id'] ?? null,
                'action' => $reviewer_data['action'] ?? null,
                'comments' => $reviewer_data['comments'] ?? null,
                'reviewed_at' => $reviewer_data['reviewed_at'] ?? null
            ];
        }

        return $history;
    }

    /**
     * Migrate existing price changes from 2-step to 4-step workflow
     */
    public function migrateToNewWorkflow(PriceChangeRequest $priceChange, User $shopOwner): bool
    {
        // Skip if already migrated
        if ($priceChange->approval_workflow_version === 'v4_multi_level' || $priceChange->approval_id) {
            return false;
        }

        try {
            $requestedBy = User::find($priceChange->requested_by) ?? $shopOwner;
            
            // Create new 4-step approval
            $this->createPriceChangeApproval($priceChange, $shopOwner, $requestedBy);

            return true;
        } catch (\Exception $e) {
            \Log::error('Price change workflow migration failed', [
                'price_change_id' => $priceChange->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
