<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveBalance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * LeaveService handles all leave-related business logic
 * including leave validation, approval workflows, and balance management
 */
class LeaveService
{
    /**
     * Leave types with their default configurations
     */
    const LEAVE_TYPES = [
        'annual' => ['max_days' => 21, 'requires_approval' => true, 'is_paid' => true],
        'sick' => ['max_days' => 14, 'requires_approval' => false, 'is_paid' => true],
        'casual' => ['max_days' => 7, 'requires_approval' => true, 'is_paid' => true],
        'maternity' => ['max_days' => 90, 'requires_approval' => true, 'is_paid' => true],
        'paternity' => ['max_days' => 7, 'requires_approval' => true, 'is_paid' => true],
        'unpaid' => ['max_days' => null, 'requires_approval' => true, 'is_paid' => false],
        'compassionate' => ['max_days' => 5, 'requires_approval' => true, 'is_paid' => true],
        'study' => ['max_days' => 10, 'requires_approval' => true, 'is_paid' => false]
    ];

    /**
     * Validate if employee can apply for leave
     * 
     * @param Employee $employee
     * @param string $leaveType
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array ['valid' => bool, 'message' => string, 'available_balance' => float]
     */
    public function validateLeaveRequest(Employee $employee, string $leaveType, Carbon $startDate, Carbon $endDate): array
    {
        // Calculate requested days
        $requestedDays = $this->calculateLeaveDays($startDate, $endDate);
        
        // Check leave type validity
        if (!array_key_exists($leaveType, self::LEAVE_TYPES)) {
            return [
                'valid' => false,
                'message' => 'Invalid leave type',
                'available_balance' => 0
            ];
        }
        
        // Check if dates are valid
        if ($startDate->gt($endDate)) {
            return [
                'valid' => false,
                'message' => 'Start date cannot be after end date',
                'available_balance' => 0
            ];
        }
        
        // Check for overlapping leave requests
        $overlapping = $this->hasOverlappingLeave($employee, $startDate, $endDate);
        if ($overlapping) {
            return [
                'valid' => false,
                'message' => 'You already have an approved leave request for these dates',
                'available_balance' => 0
            ];
        }
        
        // Check leave balance
        $balance = $this->getLeaveBalance($employee, $leaveType);
        if ($balance < $requestedDays) {
            return [
                'valid' => false,
                'message' => "Insufficient leave balance. You have {$balance} days remaining",
                'available_balance' => $balance
            ];
        }
        
        // Check maximum allowed days per request (e.g., max 10 consecutive days)
        $maxConsecutiveDays = $this->getMaxConsecutiveDays($leaveType);
        if ($maxConsecutiveDays && $requestedDays > $maxConsecutiveDays) {
            return [
                'valid' => false,
                'message' => "You cannot request more than {$maxConsecutiveDays} consecutive days for {$leaveType} leave",
                'available_balance' => $balance
            ];
        }
        
        return [
            'valid' => true,
            'message' => 'Leave request is valid',
            'available_balance' => $balance,
            'requested_days' => $requestedDays
        ];
    }

    /**
     * Calculate number of leave days excluding weekends
     * 
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return int
     */
    public function calculateLeaveDays(Carbon $startDate, Carbon $endDate): int
    {
        $days = 0;
        $currentDate = $startDate->copy();
        
        while ($currentDate->lte($endDate)) {
            // Count only weekdays (Monday to Friday)
            if (!$currentDate->isWeekend()) {
                $days++;
            }
            $currentDate->addDay();
        }
        
        return $days;
    }

    /**
     * Check if employee has overlapping leave requests
     * 
     * @param Employee $employee
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $excludeLeaveId
     * @return bool
     */
    public function hasOverlappingLeave(Employee $employee, Carbon $startDate, Carbon $endDate, ?int $excludeLeaveId = null): bool
    {
        $query = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                  ->orWhereBetween('end_date', [$startDate, $endDate])
                  ->orWhere(function($q2) use ($startDate, $endDate) {
                      $q2->where('start_date', '<=', $startDate)
                         ->where('end_date', '>=', $endDate);
                  });
            });
        
        if ($excludeLeaveId) {
            $query->where('id', '!=', $excludeLeaveId);
        }
        
        return $query->exists();
    }

    /**
     * Get employee's leave balance for a specific leave type
     * 
     * @param Employee $employee
     * @param string $leaveType
     * @return float
     */
    public function getLeaveBalance(Employee $employee, string $leaveType): float
    {
        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type', $leaveType)
            ->where('year', now()->year)
            ->first();
        
        if (!$balance) {
            // Initialize balance if not exists
            $balance = $this->initializeLeaveBalance($employee, $leaveType);
        }
        
        return $balance->remaining_days ?? 0;
    }

    /**
     * Initialize leave balance for an employee
     * 
     * @param Employee $employee
     * @param string $leaveType
     * @return LeaveBalance
     */
    public function initializeLeaveBalance(Employee $employee, string $leaveType): LeaveBalance
    {
        $config = self::LEAVE_TYPES[$leaveType] ?? ['max_days' => 0];
        $totalDays = $config['max_days'] ?? 0;
        
        // Prorate for new joiners
        if ($employee->hire_date && Carbon::parse($employee->hire_date)->year == now()->year) {
            $totalDays = $this->prorateLeaveBalance($totalDays, Carbon::parse($employee->hire_date));
        }
        
        return LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type' => $leaveType,
            'year' => now()->year,
            'total_days' => $totalDays,
            'used_days' => 0,
            'remaining_days' => $totalDays,
            'carried_forward' => 0,
            'shop_owner_id' => $employee->shop_owner_id
        ]);
    }

    /**
     * Prorate leave balance for employees joining mid-year
     * 
     * @param float $annualDays
     * @param Carbon $hireDate
     * @return float
     */
    protected function prorateLeaveBalance(float $annualDays, Carbon $hireDate): float
    {
        $monthsRemaining = now()->diffInMonths($hireDate) + 1;
        $proratedDays = ($annualDays / 12) * $monthsRemaining;
        
        return round($proratedDays, 1);
    }

    /**
     * Submit leave request
     * 
     * @param Employee $employee
     * @param array $data
     * @return LeaveRequest
     * @throws \Exception
     */
    public function submitLeaveRequest(Employee $employee, array $data): LeaveRequest
    {
        DB::beginTransaction();
        
        try {
            $startDate = Carbon::parse($data['start_date']);
            $endDate = Carbon::parse($data['end_date']);
            
            // Validate leave request
            $validation = $this->validateLeaveRequest($employee, $data['leave_type'], $startDate, $endDate);
            
            if (!$validation['valid']) {
                throw new \Exception($validation['message']);
            }
            
            // Create leave request
            $leaveRequest = LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type' => $data['leave_type'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $validation['requested_days'],
                'reason' => $data['reason'] ?? '',
                'status' => self::requiresApproval($data['leave_type']) ? 'pending' : 'approved',
                'applied_date' => now(),
                'shop_owner_id' => $employee->shop_owner_id
            ]);
            
            // If auto-approved, deduct from balance
            if ($leaveRequest->status === 'approved') {
                $this->deductLeaveBalance($employee, $data['leave_type'], $validation['requested_days']);
            }
            
            DB::commit();
            
            Log::info('Leave request submitted', [
                'employee_id' => $employee->id,
                'leave_request_id' => $leaveRequest->id,
                'leave_type' => $data['leave_type'],
                'days' => $validation['requested_days']
            ]);
            
            // TODO: Send notification to manager
            
            return $leaveRequest;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to submit leave request', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Approve leave request
     * 
     * @param LeaveRequest $leaveRequest
     * @param int $approverId
     * @return bool
     * @throws \Exception
     */
    public function approveLeaveRequest(LeaveRequest $leaveRequest, int $approverId): bool
    {
        DB::beginTransaction();
        
        try {
            // Validate approver has permission
            if (!$this->canApproveLeave($approverId, $leaveRequest)) {
                throw new \Exception('You do not have permission to approve this leave request');
            }
            
            // Check if already approved/rejected
            if ($leaveRequest->status !== 'pending') {
                throw new \Exception('Leave request has already been ' . $leaveRequest->status);
            }
            
            // Update leave request
            $leaveRequest->update([
                'status' => 'approved',
                'approved_by' => $approverId,
                'approved_date' => now()
            ]);
            
            // Deduct from leave balance
            $this->deductLeaveBalance(
                $leaveRequest->employee,
                $leaveRequest->leave_type,
                $leaveRequest->days
            );
            
            DB::commit();
            
            Log::info('Leave request approved', [
                'leave_request_id' => $leaveRequest->id,
                'approved_by' => $approverId
            ]);
            
            // TODO: Send notification to employee
            
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to approve leave request', [
                'leave_request_id' => $leaveRequest->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Reject leave request
     * 
     * @param LeaveRequest $leaveRequest
     * @param int $approverId
     * @param string $reason
     * @return bool
     * @throws \Exception
     */
    public function rejectLeaveRequest(LeaveRequest $leaveRequest, int $approverId, string $reason = ''): bool
    {
        try {
            // Validate approver has permission
            if (!$this->canApproveLeave($approverId, $leaveRequest)) {
                throw new \Exception('You do not have permission to reject this leave request');
            }
            
            // Check if already approved/rejected
            if ($leaveRequest->status !== 'pending') {
                throw new \Exception('Leave request has already been ' . $leaveRequest->status);
            }
            
            $leaveRequest->update([
                'status' => 'rejected',
                'rejected_by' => $approverId,
                'rejected_date' => now(),
                'rejection_reason' => $reason
            ]);
            
            Log::info('Leave request rejected', [
                'leave_request_id' => $leaveRequest->id,
                'rejected_by' => $approverId
            ]);
            
            // TODO: Send notification to employee
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to reject leave request', [
                'leave_request_id' => $leaveRequest->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Deduct days from employee's leave balance
     * 
     * @param Employee $employee
     * @param string $leaveType
     * @param float $days
     * @return bool
     */
    protected function deductLeaveBalance(Employee $employee, string $leaveType, float $days): bool
    {
        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type', $leaveType)
            ->where('year', now()->year)
            ->first();
        
        if (!$balance) {
            $balance = $this->initializeLeaveBalance($employee, $leaveType);
        }
        
        $balance->used_days += $days;
        $balance->remaining_days -= $days;
        $balance->save();
        
        return true;
    }

    /**
     * Check if user can approve leave request
     * 
     * @param int $userId
     * @param LeaveRequest $leaveRequest
     * @return bool
     */
    protected function canApproveLeave(int $userId, LeaveRequest $leaveRequest): bool
    {
        // TODO: Implement proper approval hierarchy
        // For now, check if user is HR or shop owner
        $user = auth()->user();
        
        return $user && (
            $user->hasRole('HR') || 
            $user->hasRole('shop_owner') ||
            $user->shop_owner_id === $leaveRequest->employee->shop_owner_id
        );
    }

    /**
     * Check if leave type requires approval
     * 
     * @param string $leaveType
     * @return bool
     */
    protected static function requiresApproval(string $leaveType): bool
    {
        $config = self::LEAVE_TYPES[$leaveType] ?? ['requires_approval' => true];
        return $config['requires_approval'];
    }

    /**
     * Get maximum consecutive days allowed for leave type
     * 
     * @param string $leaveType
     * @return int|null
     */
    protected function getMaxConsecutiveDays(string $leaveType): ?int
    {
        // Configuration for max consecutive days per leave type
        $maxConsecutive = [
            'casual' => 3,
            'sick' => 5
        ];
        
        return $maxConsecutive[$leaveType] ?? null;
    }

    /**
     * Get comprehensive leave summary for employee
     * 
     * @param Employee $employee
     * @return array
     */
    public function getLeaveSummary(Employee $employee): array
    {
        $balances = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', now()->year)
            ->get()
            ->keyBy('leave_type');
        
        $pendingRequests = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'pending')
            ->count();
        
        $approvedRequests = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('start_date', [now()->startOfYear(), now()->endOfYear()])
            ->get();
        
        $summary = [];
        foreach (self::LEAVE_TYPES as $type => $config) {
            $balance = $balances->get($type);
            $summary[$type] = [
                'total_days' => $balance->total_days ?? $config['max_days'],
                'used_days' => $balance->used_days ?? 0,
                'remaining_days' => $balance->remaining_days ?? ($config['max_days'] ?? 0),
                'is_paid' => $config['is_paid']
            ];
        }
        
        return [
            'balances' => $summary,
            'pending_requests' => $pendingRequests,
            'approved_requests_count' => $approvedRequests->count(),
            'total_days_taken' => $approvedRequests->sum('days')
        ];
    }
}
