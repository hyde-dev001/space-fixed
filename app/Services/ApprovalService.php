<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\User;
use App\Enums\ApprovalStatus;

class ApprovalService
{
    /**
     * Create a new approval with role-based workflow
     */
    public function createApproval(
        $approvable,
        array $approvalRoles,
        User $requestedBy,
        User $shopOwner,
        string $reference = '',
        string $description = '',
        float $amount = 0,
        ?array $metadata = null
    ): Approval {
        $first_role = $approvalRoles['1'] ?? null;
        
        $approval = Approval::create([
            'approvable_type' => get_class($approvable),
            'approvable_id' => $approvable->id,
            'shop_owner_id' => $shopOwner->id,
            'reference' => $reference,
            'description' => $description,
            'amount' => $amount,
            'requested_by' => $requestedBy->id,
            'current_level' => 1,
            'total_levels' => count($approvalRoles),
            'status' => ApprovalStatus::PENDING,
            'approval_roles' => $approvalRoles,
            'current_approver_role' => $first_role,
            'level_reviewers' => [],
            'metadata' => $metadata
        ]);

        return $approval;
    }

    /**
     * Get pending approvals for a user based on their roles
     */
    public function getPendingApprovalsForUser(User $user): \Illuminate\Database\Eloquent\Collection
    {
        $shopOwnerId = (int) ($user->shop_owner_id ?? $user->id);
        $approvals = Approval::pending()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', ApprovalStatus::PENDING);

        // Filter by user's roles and permissions
        return $approvals->get()->filter(function ($approval) use ($user) {
            return $approval->canApprove($user);
        });
    }

    /**
     * Approve an approval at current level and move to next
     */
    public function approve(Approval $approval, object $user, ?string $comments = null): array
    {
        if (!$approval->canApprove($user)) {
            return [
                'success' => false,
                'message' => 'You do not have permission to approve this item at the current level.'
            ];
        }

        try {
            $approval->recordLevelReview($user, 'approved', $comments);

            if ($approval->current_level >= $approval->total_levels) {
                // Final approval
                $approval->status = ApprovalStatus::APPROVED;
                $approval->reviewed_by = $user->id;
                $approval->reviewed_at = now();
                $approval->save();

                return [
                    'success' => true,
                    'message' => 'Approval completed successfully.',
                    'approval' => $approval,
                    'is_final' => true
                ];
            } else {
                // Move to next level
                $approval->current_level++;
                $approval->current_approver_role = $approval->getApproverRoleForLevel($approval->current_level);
                $approval->reviewed_by = $user->id;
                $approval->reviewed_at = now();
                $approval->save();

                return [
                    'success' => true,
                    'message' => "Approval passed to next level: {$approval->current_approver_role}",
                    'approval' => $approval,
                    'is_final' => false,
                    'next_level' => $approval->current_level,
                    'next_approver_role' => $approval->current_approver_role
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Error during approval: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Reject an approval at current level
     */
    public function reject(Approval $approval, object $user, string $comments = ''): array
    {
        if (!$approval->canApprove($user)) {
            return [
                'success' => false,
                'message' => 'You do not have permission to reject this item.'
            ];
        }

        try {
            $approval->recordLevelReview($user, 'rejected', $comments);
            $approval->status = ApprovalStatus::REJECTED;
            $approval->reviewed_by = $user->id;
            $approval->reviewed_at = now();
            $approval->save();

            return [
                'success' => true,
                'message' => 'Item rejected successfully.',
                'approval' => $approval
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Error during rejection: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Get approval summary for display
     */
    public function getApprovalSummary(Approval $approval): array
    {
        return [
            'approval_id' => $approval->id,
            'reference' => $approval->reference,
            'description' => $approval->description,
            'amount' => $approval->amount,
            'current_level' => $approval->current_level,
            'total_levels' => $approval->total_levels,
            'current_approver_role' => $approval->current_approver_role,
            'status' => $approval->status,
            'requested_by' => $approval->requestedBy ? $approval->requestedBy->name : null,
            'approval_progress' => "{$approval->current_level}/{$approval->total_levels}",
            'level_history' => $this->formatLevelHistory($approval),
            'can_approve' => $approval->approval_roles
        ];
    }

    /**
     * Format level history for display
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
     * Check approval readiness for various statuses
     */
    public function isApprovalComplete(Approval $approval): bool
    {
        return $approval->status !== ApprovalStatus::PENDING;
    }

    /**
     * Get the count of pending approvals by role
     */
    public function getPendingCountByRole(string $role, ?User $shopOwner = null): int
    {
        $query = Approval::pending();
        
        if ($shopOwner) {
            $query->where('shop_owner_id', $shopOwner->id);
        }

        return $query->get()
            ->filter(function ($approval) use ($role) {
                return $approval->current_approver_role === $role;
            })
            ->count();
    }
}
