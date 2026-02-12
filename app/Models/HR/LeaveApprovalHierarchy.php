<?php

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ShopOwner;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;

class LeaveApprovalHierarchy extends Model
{
    protected $table = 'hr_leave_approval_hierarchy';

    protected $fillable = [
        'shop_owner_id',
        'employee_id',
        'approver_id',
        'approval_level',
        'approver_type',
        'applies_for_days_greater_than',
        'applies_for_leave_types',
        'delegated_to',
        'delegation_start_date',
        'delegation_end_date',
        'delegation_reason',
        'is_active',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'approval_level' => 'integer',
        'applies_for_days_greater_than' => 'integer',
        'applies_for_leave_types' => 'array',
        'delegation_start_date' => 'datetime',
        'delegation_end_date' => 'datetime',
        'is_active' => 'boolean',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_to');
    }

    // ==================== QUERY SCOPES ====================

    public function scopeForShopOwner($query, $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEffectiveNow($query)
    {
        $now = now();
        return $query->where(function ($q) use ($now) {
            $q->whereNull('effective_from')->orWhere('effective_from', '<=', $now);
        })->where(function ($q) use ($now) {
            $q->whereNull('effective_to')->orWhere('effective_to', '>=', $now);
        });
    }

    public function scopeByLevel($query, $level)
    {
        return $query->where('approval_level', $level);
    }

    public function scopeForApprover($query, $approverId)
    {
        return $query->where('approver_id', $approverId);
    }

    public function scopeOrderedByLevel($query)
    {
        return $query->orderBy('approval_level', 'asc');
    }

    // ==================== STATIC HELPER METHODS ====================

    /**
     * Get the next approver for an employee's leave request
     */
    public static function getNextApprover($employeeId, $leaveType = null, $leaveDays = 0, $currentLevel = 0)
    {
        $query = self::forEmployee($employeeId)
            ->active()
            ->effectiveNow()
            ->where('approval_level', '>', $currentLevel)
            ->orderedByLevel();

        // Filter by leave type if applicable
        if ($leaveType) {
            $query->where(function ($q) use ($leaveType) {
                $q->whereNull('applies_for_leave_types')
                  ->orWhereJsonContains('applies_for_leave_types', $leaveType);
            });
        }

        // Filter by leave duration if applicable
        if ($leaveDays > 0) {
            $query->where(function ($q) use ($leaveDays) {
                $q->whereNull('applies_for_days_greater_than')
                  ->orWhere('applies_for_days_greater_than', '<=', $leaveDays);
            });
        }

        $hierarchy = $query->first();

        if (!$hierarchy) {
            return null;
        }

        // Check if there's an active delegation
        if ($hierarchy->hasDelegation()) {
            return [
                'approver_id' => $hierarchy->delegated_to,
                'approver' => $hierarchy->delegate,
                'approval_level' => $hierarchy->approval_level,
                'is_delegated' => true,
                'original_approver_id' => $hierarchy->approver_id,
            ];
        }

        return [
            'approver_id' => $hierarchy->approver_id,
            'approver' => $hierarchy->approver,
            'approval_level' => $hierarchy->approval_level,
            'is_delegated' => false,
        ];
    }

    /**
     * Get all approvers in hierarchy for an employee
     */
    public static function getApprovalChain($employeeId, $leaveType = null, $leaveDays = 0)
    {
        $query = self::forEmployee($employeeId)
            ->active()
            ->effectiveNow()
            ->orderedByLevel();

        // Filter by leave type if applicable
        if ($leaveType) {
            $query->where(function ($q) use ($leaveType) {
                $q->whereNull('applies_for_leave_types')
                  ->orWhereJsonContains('applies_for_leave_types', $leaveType);
            });
        }

        // Filter by leave duration if applicable
        if ($leaveDays > 0) {
            $query->where(function ($q) use ($leaveDays) {
                $q->whereNull('applies_for_days_greater_than')
                  ->orWhere('applies_for_days_greater_than', '<=', $leaveDays);
            });
        }

        return $query->with(['approver', 'delegate'])->get()->map(function ($hierarchy) {
            return [
                'level' => $hierarchy->approval_level,
                'approver_type' => $hierarchy->approver_type,
                'approver_id' => $hierarchy->hasDelegation() ? $hierarchy->delegated_to : $hierarchy->approver_id,
                'approver_name' => $hierarchy->hasDelegation() 
                    ? $hierarchy->delegate->name 
                    : $hierarchy->approver->name,
                'is_delegated' => $hierarchy->hasDelegation(),
                'original_approver' => $hierarchy->hasDelegation() ? $hierarchy->approver->name : null,
            ];
        });
    }

    /**
     * Check if employee has approval hierarchy configured
     */
    public static function hasHierarchy($employeeId): bool
    {
        return self::forEmployee($employeeId)
            ->active()
            ->effectiveNow()
            ->exists();
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Check if this hierarchy entry has an active delegation
     */
    public function hasDelegation(): bool
    {
        if (!$this->delegated_to) {
            return false;
        }

        $now = now();

        // Check if delegation is within the date range
        if ($this->delegation_start_date && $this->delegation_start_date->greaterThan($now)) {
            return false;
        }

        if ($this->delegation_end_date && $this->delegation_end_date->lessThan($now)) {
            return false;
        }

        return true;
    }

    /**
     * Set delegation for this approver
     */
    public function setDelegation($delegateUserId, $startDate, $endDate, $reason = null): bool
    {
        $this->delegated_to = $delegateUserId;
        $this->delegation_start_date = Carbon::parse($startDate);
        $this->delegation_end_date = Carbon::parse($endDate);
        $this->delegation_reason = $reason;
        
        return $this->save();
    }

    /**
     * Clear delegation
     */
    public function clearDelegation(): bool
    {
        $this->delegated_to = null;
        $this->delegation_start_date = null;
        $this->delegation_end_date = null;
        $this->delegation_reason = null;
        
        return $this->save();
    }

    /**
     * Check if hierarchy entry is currently effective
     */
    public function isEffective(): bool
    {
        $now = now();

        if ($this->effective_from && $this->effective_from->greaterThan($now)) {
            return false;
        }

        if ($this->effective_to && $this->effective_to->lessThan($now)) {
            return false;
        }

        return true;
    }
}
