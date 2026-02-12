<?php

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Employee;
use App\Models\User;

class OvertimeRequest extends Model
{
    protected $table = 'hr_overtime_requests';

    protected $fillable = [
        'shop_owner_id',
        'employee_id',
        'shift_schedule_id',
        'overtime_date',
        'start_time',
        'end_time',
        'hours',
        'rate_multiplier',
        'calculated_amount',
        'overtime_type',
        'reason',
        'work_description',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'is_paid',
        'payroll_id',
        'notes',
    ];

    protected $casts = [
        'overtime_date' => 'date',
        'hours' => 'decimal:2',
        'rate_multiplier' => 'decimal:2',
        'calculated_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'is_paid' => 'boolean',
    ];

    /**
     * Get the employee who requested overtime
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Get the user who approved the request
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scope a query to only include records for a specific shop owner.
     */
    public function scopeForShopOwner($query, $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Scope a query to only include records for a specific employee.
     */
    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    /**
     * Scope a query to only include pending requests.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include approved requests.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Approve the overtime request
     */
    public function approve($approverId, $notes = null)
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approved_at' => now(),
            'notes' => $notes,
        ]);
    }

    /**
     * Reject the overtime request
     */
    public function reject($approverId, $reason)
    {
        $this->update([
            'status' => 'rejected',
            'approved_by' => $approverId,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }
}
