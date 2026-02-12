<?php

namespace App\Models\HR;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $table = 'leave_requests';

    protected $fillable = [
        'employee_id',
        'shop_owner_id',
        'leave_type',
        'start_date',
        'end_date',
        'no_of_days',
        'is_half_day',
        'reason',
        'supporting_document',
        'status',
        'approval_level',
        'approver_id',
        'approved_by',
        'approval_date',
        'rejection_reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'approval_date' => 'datetime',
        'is_half_day' => 'boolean',
        'approval_level' => 'integer',
    ];

    /**
     * Available leave types
     */
    public const LEAVE_TYPES = [
        'vacation' => 'Vacation',
        'sick' => 'Sick Leave',
        'personal' => 'Personal Leave',
        'maternity' => 'Maternity Leave',
        'paternity' => 'Paternity Leave',
        'unpaid' => 'Unpaid Leave',
    ];

    /**
     * Available statuses
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    /**
     * Get the employee this leave request belongs to
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the shop owner this request belongs to
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Get the user who approved this request
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the approver assigned to this request
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * Scope to filter by shop owner
     */
    public function scopeForShopOwner(Builder $query, $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus(Builder $query, $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get pending requests
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to filter by employee
     */
    public function scopeForEmployee(Builder $query, $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    /**
     * Calculate number of days automatically
     */
    public function calculateDays(): int
    {
        if (!$this->start_date || !$this->end_date) {
            return 0;
        }

        $startDate = \Carbon\Carbon::parse($this->start_date);
        $endDate = \Carbon\Carbon::parse($this->end_date);
        
        // Calculate business days (excluding weekends)
        $days = 0;
        while ($startDate <= $endDate) {
            if (!$startDate->isWeekend()) {
                $days++;
            }
            $startDate->addDay();
        }

        return $days;
    }

    /**
     * Auto-calculate days before saving
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($leaveRequest) {
            if ($leaveRequest->start_date && $leaveRequest->end_date) {
                $leaveRequest->no_of_days = $leaveRequest->calculateDays();
            }
        });
    }

    /**
     * Check if leave request can be approved
     */
    public function canBeApproved(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Approve the leave request
     */
    public function approve($approverId): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->status = 'approved';
        $this->approved_by = $approverId;
        $this->approval_date = now();
        
        return $this->save();
    }

    /**
     * Reject the leave request
     */
    public function reject($reason = null): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->status = 'rejected';
        $this->rejection_reason = $reason;
        
        return $this->save();
    }
}