<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\PriceChangeRequest;
use App\Models\User;
use App\Enums\ApprovalStatus;
use App\Enums\NotificationType;

class PriceChangeApprovalService
{
    public function __construct(
        private ApprovalService $approvalService,
        private NotificationService $notificationService
    ) {}

    /**
     * Create approval workflow for a price change
        * 1-step if owner approval not required: Finance (1) applies price
     * 3-step if owner approval required: Finance (1) → Shop Owner (2) → Finance Final (3)
     */
    public function createPriceChangeApproval(PriceChangeRequest $priceChange, User $shopOwner, User $requestedBy, bool $requiresOwnerApproval = true): Approval
    {
        $approvalRoles = $requiresOwnerApproval
            ? [
                '1' => 'finance',           // Finance initial review
                '2' => 'shop_owner',        // Shop owner approves
                '3' => 'finance'            // Finance final approval (applies price)
            ]
            : [
                '1' => 'finance'            // Finance final approval (applies price)
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

        $previousLevel = (int) $approval->current_level;

        // Use ApprovalService to transition
        $result = $this->approvalService->approve($approval, $approver, $comments);

        if (!$result['success']) {
            return $result;
        }

        // Keep intermediate states compatible with existing price change enum values
        // Status='finance_approved' is used for intermediate approvals (levels 1-2)
        // Only after Finance Final approval (level 3) does status change to 'owner_approved'
        // This 3-step workflow: Finance → Owner → Finance Final
        $statusMapping = [
            1 => 'pending',
            2 => 'finance_approved',   // After Finance level 1 approval
            3 => 'finance_approved'    // After Owner level 2 approval (awaits Finance level 3)
        ];

        if ($result['is_final'] ?? false) {
            // Final approval (level 4) - ready to be applied
            $priceChange->update([
                'status' => 'owner_approved',
                'owner_rejection_reason' => null,  // Clear any previous rejection
                'current_approval_level' => $approval->current_level
            ]);
        } else {
            // Intermediate approval at levels 1, 2, 3
            $nextLevel = $approval->current_level;
            $newStatus = $statusMapping[$nextLevel] ?? 'pending';
            
            $priceChange->update([
                'current_approval_level' => $nextLevel,
                'status' => $newStatus
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

        $this->dispatchPriceChangeApprovalNotifications(
            priceChange: $priceChange,
            approval: $approval,
            approver: $approver,
            previousLevel: $previousLevel,
            result: $result
        );

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

        $this->dispatchPriceChangeRejectionNotifications($priceChange, $approval, $comments);

        return $result;
    }

    public function notifyPriceChangeApprovalRequested(PriceChangeRequest $priceChange): void
    {
        $payload = $this->buildPriceChangeNotificationData($priceChange);

        $this->notificationService->sendToErpRole(
            roleName: 'Finance',
            shopId: (int) $priceChange->shop_owner_id,
            type: NotificationType::PRICE_CHANGE_REQUEST,
            title: 'New Price Change Request',
            message: "{$payload['product_name']}: ₱{$payload['old_price']} → ₱{$payload['new_price']} needs Finance review.",
            data: $payload,
            actionUrl: $this->financePriceChangeActionUrl($priceChange->id),
            priority: 'medium',
            requiresAction: true,
        );
    }

    private function dispatchPriceChangeApprovalNotifications(
        PriceChangeRequest $priceChange,
        Approval $approval,
        User $approver,
        int $previousLevel,
        array $result
    ): void {
        $payload = $this->buildPriceChangeNotificationData($priceChange, $approver, null);
        $shopOwnerId = (int) $priceChange->shop_owner_id;

        if ($result['is_final'] ?? false) {
            $requesterId = $this->resolveRequesterId($priceChange, $approval);
            if ($requesterId) {
                $this->notificationService->sendToUser(
                    userId: $requesterId,
                    type: NotificationType::PRICE_CHANGE_REQUEST,
                    title: 'Price Change Approved and Applied',
                    message: "{$payload['product_name']} price was updated from ₱{$payload['old_price']} to ₱{$payload['new_price']}.",
                    data: $payload,
                    actionUrl: $this->financePriceChangeActionUrl($priceChange->id),
                    shopId: $shopOwnerId
                );
            }

            return;
        }

        if ($previousLevel === 1) {
            $this->notificationService->sendToShopOwner(
                shopOwnerId: $shopOwnerId,
                type: NotificationType::PRICE_CHANGE_REQUEST,
                title: 'Price Change Awaiting Your Approval',
                message: "{$payload['product_name']}: ₱{$payload['old_price']} → ₱{$payload['new_price']} now needs shop owner approval.",
                data: $payload,
                actionUrl: $this->notificationService->ownerApprovalActionUrl('product_price_change', $priceChange->id),
                priority: 'medium',
                requiresAction: true,
            );

            return;
        }

        if ($previousLevel === 2) {
            $this->notificationService->sendToErpRole(
                roleName: 'Finance',
                shopId: $shopOwnerId,
                type: NotificationType::PRICE_CHANGE_REQUEST,
                title: 'Price Change Returned to Finance',
                message: "{$payload['product_name']} was approved by shop owner and now needs final Finance approval.",
                data: $payload,
                actionUrl: $this->financePriceChangeActionUrl($priceChange->id),
                priority: 'medium',
                requiresAction: true,
            );
        }
    }

    private function dispatchPriceChangeRejectionNotifications(PriceChangeRequest $priceChange, Approval $approval, string $comments): void
    {
        $payload = $this->buildPriceChangeNotificationData($priceChange, null, $comments);
        $shopOwnerId = (int) $priceChange->shop_owner_id;

        // Keep shop owner informed on any rejection outcome.
        $this->notificationService->notifyPriceChangeRejected($shopOwnerId, $payload);

        $requesterId = $this->resolveRequesterId($priceChange, $approval);
        if ($requesterId) {
            $this->notificationService->sendToUser(
                userId: $requesterId,
                type: NotificationType::PRICE_CHANGE_REJECTED,
                title: 'Price Change Request Rejected',
                message: "{$payload['product_name']} price change was rejected. Reason: {$comments}",
                data: $payload,
                actionUrl: $this->financePriceChangeActionUrl($priceChange->id),
                shopId: $shopOwnerId
            );
        }
    }

    private function financePriceChangeActionUrl(int|string $priceChangeId): string
    {
        return "/finance?section=shoe-pricing&price_change=" . urlencode((string) $priceChangeId);
    }

    private function buildPriceChangeNotificationData(
        PriceChangeRequest $priceChange,
        ?User $actor = null,
        ?string $reason = null
    ): array {
        return [
            'price_change_id' => $priceChange->id,
            'product_id' => $priceChange->product_id,
            'product_name' => $priceChange->product_name,
            'old_price' => number_format((float) $priceChange->current_price, 2),
            'new_price' => number_format((float) $priceChange->proposed_price, 2),
            'change_percentage' => $priceChange->getPriceChangePercentage(),
            'submitted_by' => $actor?->name ?? ($priceChange->requester?->name ?? 'Staff'),
            'reason' => $priceChange->reason,
            'rejection_reason' => $reason,
        ];
    }

    private function resolveRequesterId(PriceChangeRequest $priceChange, Approval $approval): ?int
    {
        $candidates = [
            $priceChange->requested_by ?? null,
            $approval->requested_by ?? null,
        ];

        foreach ($candidates as $candidate) {
            $userId = (int) $candidate;
            if ($userId > 0) {
                return $userId;
            }
        }

        return null;
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
