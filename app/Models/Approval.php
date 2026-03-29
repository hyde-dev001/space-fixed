<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\ApprovalStatus;

class Approval extends Model
{
    use HasFactory;

    protected $table = 'approvals';

    protected $fillable = [
        'shop_owner_id',
        'approvable_type',
        'approvable_id',
        'reference',
        'description',
        'amount',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'current_level',
        'total_levels',
        'status',
        'comments',
        'metadata',
        'approval_roles',
        'current_approver_role',
        'level_reviewers'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'current_level' => 'integer',
        'total_levels' => 'integer',
        'status' => ApprovalStatus::class,
        'metadata' => 'array',
        'approval_roles' => 'json',
        'level_reviewers' => 'json'
    ];

    /**
     * Get the user who requested this approval
     */
    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the user who reviewed this approval
     */
    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get the shop owner
     */
    public function shopOwner()
    {
        return $this->belongsTo(User::class, 'shop_owner_id');
    }

    /**
     * Get the approvable model (polymorphic relation)
     */
    public function approvable()
    {
        return $this->morphTo();
    }

    /**
     * Get the approvable type for reference
     */
    public function approvableType()
    {
        return $this->morphTo('approvable');
    }

    /**
     * Get the approval history
     */
    public function history()
    {
        return $this->hasMany(ApprovalHistory::class);
    }

    /**
     * Scope to get pending approvals
     */
    public function scopePending($query)
    {
        return $query->where('status', ApprovalStatus::PENDING);
    }

    /**
     * Scope to get approved approvals
     */
    public function scopeApproved($query)
    {
        return $query->where('status', ApprovalStatus::APPROVED);
    }

    /**
     * Scope to get rejected approvals
     */
    public function scopeRejected($query)
    {
        return $query->where('status', ApprovalStatus::REJECTED);
    }

    /**
     * Get the role required for approval at a specific level
     */
    public function getApproverRoleForLevel(?int $level = null): ?string
    {
        $level = $level ?? $this->current_level;
        if (!$this->approval_roles) {
            return null;
        }
        return $this->approval_roles[(string)$level] ?? null;
    }

    /**
     * Check if a user can approve this approval at the current level
     */
    public function canApprove($user): bool
    {
        // Cannot approve if already approved or rejected
        if ($this->status !== ApprovalStatus::PENDING) {
            return false;
        }

        $requiredRole = $this->getApproverRoleForLevel();
        if (!$requiredRole) {
            return false;
        }

        // Check if user has the required role
        return $this->userHasApprovalRole($user, $requiredRole);
    }

    /**
     * Check if a user has a specific approval role
     */
    public function userHasApprovalRole($user, string $role): bool
    {
        $normalizedRole = str_replace([' ', '-'], '_', strtolower(trim($role)));

        return match ($normalizedRole) {
            'finance' => $this->userHasAnyRoleSafe($user, [
                    'finance',
                    'Finance',
                    'Finance Manager',
                    'finance-manager',
                ])
                || $this->userHasAnyPermissionSafe($user, [
                    'access-shoe-price-approval',
                    'access-repair-price-approval',
                    'approve-expenses',
                    'access-approval-workflow',
                    'access-shoe-pricing',
                ]),
            'shop_owner' => $this->isExactShopOwnerIdentity($user)
                || (
                    $this->isLinkedToApprovalShop($user)
                    && (
                        $this->userHasRoleSafe($user, 'shop-owner')
                        || $this->userHasRoleSafe($user, 'Shop Owner')
                    )
                ),
            'finance_final' => (
                $this->userHasAnyRoleSafe($user, [
                    'Finance Manager',
                    'finance-manager',
                    'Finance',
                    'finance',
                ])
                || $this->userHasPermissionSafe($user, 'access-approval-workflow')
            ) && $this->userHasAnyPermissionSafe($user, [
                'access-shoe-price-approval',
                'access-repair-price-approval',
                'approve-expenses',
                'access-approval-workflow',
                'access-shoe-pricing',
            ]),
            default => false
        };
    }

    private function userHasPermissionSafe($user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (\Throwable) {
            return false;
        }
    }

    private function userHasAnyPermissionSafe($user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->userHasPermissionSafe($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function userHasRoleSafe($user, string $role): bool
    {
        try {
            return method_exists($user, 'hasRole') ? (bool) $user->hasRole($role) : false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function userHasAnyRoleSafe($user, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->userHasRoleSafe($user, $role)) {
                return true;
            }
        }

        return false;
    }

    private function isExactShopOwnerIdentity($user): bool
    {
        $userId = (int) ($user->id ?? 0);
        $approvalShopOwnerId = (int) $this->shop_owner_id;

        return $userId > 0 && $userId === $approvalShopOwnerId;
    }

    private function isLinkedToApprovalShop($user): bool
    {
        $linkedShopOwnerId = (int) ($user->shop_owner_id ?? 0);
        $approvalShopOwnerId = (int) $this->shop_owner_id;

        return $linkedShopOwnerId > 0 && $linkedShopOwnerId === $approvalShopOwnerId;
    }

    /**
     * Get the next approver role after current level
     */
    public function getNextApproverRole(): ?string
    {
        $nextLevel = $this->current_level + 1;
        if ($nextLevel > $this->total_levels) {
            return null;
        }
        return $this->getApproverRoleForLevel($nextLevel);
    }

    /**
     * Transition approval to next level
     */
    public function transitionToNextLevel($user, string $action = 'approved', ?string $comments = null): bool
    {
        if (!$this->canApprove($user)) {
            return false;
        }

        // Record this level's review
        $this->recordLevelReview($user, $action, $comments);

        if ($action === 'rejected') {
            $this->status = ApprovalStatus::REJECTED;
            $this->reviewed_by = $user->id;
            $this->reviewed_at = now();
            return $this->save();
        }

        // If approved, move to next level
        if ($this->current_level >= $this->total_levels) {
            // All levels approved
            $this->status = ApprovalStatus::APPROVED;
            $this->reviewed_by = $user->id;
            $this->reviewed_at = now();
            return $this->save();
        }

        // Move to next level
        $this->current_level++;
        $this->current_approver_role = $this->getNextApproverRole();
        $this->reviewed_by = $user->id;
        $this->reviewed_at = now();
        return $this->save();
    }

    /**
     * Record a reviewer's action at a specific level
     */
    public function recordLevelReview($user, string $action, ?string $comments = null): void
    {
        $levelReviewers = $this->level_reviewers ?? [];
        
        $levelReviewers[(string)$this->current_level] = [
            'user_id' => $user->id,
            'action' => $action,
            'comments' => $comments,
            'reviewed_at' => now()->toIso8601String()
        ];

        $this->level_reviewers = $levelReviewers;
        
        // Also create an audit trail entry
        if ($this->relationLoaded('history')) {
            $this->history()->create([
                'level' => $this->current_level,
                'reviewer_id' => $user->id,
                'action' => $action,
                'comments' => $comments
            ]);
        }
    }
}
