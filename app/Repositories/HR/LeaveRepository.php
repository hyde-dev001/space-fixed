<?php

namespace App\Repositories\HR;

use App\Models\LeaveRequest;
use App\Models\LeaveBalance;
use App\Repositories\HR\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

/**
 * LeaveRepository - Handles all leave-related database queries
 */
class LeaveRepository extends BaseRepository
{
    protected $leaveBalanceModel;

    /**
     * LeaveRepository constructor
     *
     * @param LeaveRequest $model
     * @param LeaveBalance $leaveBalanceModel
     */
    public function __construct(LeaveRequest $model, LeaveBalance $leaveBalanceModel)
    {
        $this->model = $model;
        $this->leaveBalanceModel = $leaveBalanceModel;
    }

    /**
     * Get leave requests for a shop with filters
     *
     * @param int $shopOwnerId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getShopLeaveRequests(int $shopOwnerId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId);

        // Apply filters
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (isset($filters['leave_type'])) {
            $query->where('leave_type', $filters['leave_type']);
        }

        if (isset($filters['start_date'])) {
            $query->whereDate('start_date', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->whereDate('end_date', '<=', $filters['end_date']);
        }

        // Default relationships
        $with = $filters['with'] ?? ['employee', 'approver'];
        $query->with($with);

        // Default ordering
        $orderBy = $filters['order_by'] ?? 'start_date';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        return $query->paginate($perPage);
    }

    /**
     * Get pending leave requests for approval
     *
     * @param int $shopOwnerId
     * @return Collection
     */
    public function getPendingRequests(int $shopOwnerId): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'pending')
            ->with(['employee'])
            ->orderBy('start_date', 'asc')
            ->get();
    }

    /**
     * Get employee's leave requests
     *
     * @param int $employeeId
     * @param int|null $year
     * @return Collection
     */
    public function getEmployeeLeaveRequests(int $employeeId, ?int $year = null): Collection
    {
        $query = $this->model->where('employee_id', $employeeId);

        if ($year) {
            $query->whereYear('start_date', $year);
        }

        return $query->orderBy('start_date', 'desc')->get();
    }

    /**
     * Get approved leave requests for employee in a year
     *
     * @param int $employeeId
     * @param int $year
     * @return Collection
     */
    public function getApprovedRequests(int $employeeId, int $year): Collection
    {
        return $this->model->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereYear('start_date', $year)
            ->get();
    }

    /**
     * Check for overlapping leave requests
     *
     * @param int $employeeId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $excludeId
     * @return Collection
     */
    public function getOverlappingLeaves(int $employeeId, Carbon $startDate, Carbon $endDate, ?int $excludeId = null): Collection
    {
        $query = $this->model->where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                  ->orWhereBetween('end_date', [$startDate, $endDate])
                  ->orWhere(function ($q2) use ($startDate, $endDate) {
                      $q2->where('start_date', '<=', $startDate)
                         ->where('end_date', '>=', $endDate);
                  });
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->get();
    }

    /**
     * Get leaves for a specific date range
     *
     * @param int $shopOwnerId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return Collection
     */
    public function getLeavesInDateRange(int $shopOwnerId, Carbon $startDate, Carbon $endDate): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'approved')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                  ->orWhereBetween('end_date', [$startDate, $endDate])
                  ->orWhere(function ($q2) use ($startDate, $endDate) {
                      $q2->where('start_date', '<=', $startDate)
                         ->where('end_date', '>=', $endDate);
                  });
            })
            ->with('employee')
            ->get();
    }

    /**
     * Get leave statistics for shop
     *
     * @param int $shopOwnerId
     * @param int|null $year
     * @return array
     */
    public function getLeaveStatistics(int $shopOwnerId, ?int $year = null): array
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId);

        if ($year) {
            $query->whereYear('start_date', $year);
        }

        $total = $query->count();
        $pending = (clone $query)->where('status', 'pending')->count();
        $approved = (clone $query)->where('status', 'approved')->count();
        $rejected = (clone $query)->where('status', 'rejected')->count();

        $byType = (clone $query)->selectRaw('leave_type, COUNT(*) as count')
            ->groupBy('leave_type')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->leave_type => $item->count];
            });

        return [
            'total' => $total,
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'by_type' => $byType
        ];
    }

    /**
     * Approve leave request
     *
     * @param int $id
     * @param int $approverId
     * @param string|null $approverRemarks
     * @return bool
     */
    public function approveLeave(int $id, int $approverId, ?string $approverRemarks = null): bool
    {
        return $this->model->where('id', $id)->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approved_at' => Carbon::now(),
            'approver_remarks' => $approverRemarks
        ]);
    }

    /**
     * Reject leave request
     *
     * @param int $id
     * @param int $approverId
     * @param string|null $approverRemarks
     * @return bool
     */
    public function rejectLeave(int $id, int $approverId, ?string $approverRemarks = null): bool
    {
        return $this->model->where('id', $id)->update([
            'status' => 'rejected',
            'approved_by' => $approverId,
            'approved_at' => Carbon::now(),
            'approver_remarks' => $approverRemarks
        ]);
    }

    /**
     * Cancel leave request
     *
     * @param int $id
     * @param string|null $cancellationReason
     * @return bool
     */
    public function cancelLeave(int $id, ?string $cancellationReason = null): bool
    {
        return $this->model->where('id', $id)->update([
            'status' => 'cancelled',
            'cancellation_reason' => $cancellationReason,
            'cancelled_at' => Carbon::now()
        ]);
    }

    // ==================== Leave Balance Methods ====================

    /**
     * Get leave balance for employee
     *
     * @param int $employeeId
     * @param string $leaveType
     * @param int $year
     * @return LeaveBalance|null
     */
    public function getLeaveBalance(int $employeeId, string $leaveType, int $year): ?LeaveBalance
    {
        return $this->leaveBalanceModel->where('employee_id', $employeeId)
            ->where('leave_type', $leaveType)
            ->where('year', $year)
            ->first();
    }

    /**
     * Get all leave balances for employee
     *
     * @param int $employeeId
     * @param int $year
     * @return Collection
     */
    public function getEmployeeLeaveBalances(int $employeeId, int $year): Collection
    {
        return $this->leaveBalanceModel->where('employee_id', $employeeId)
            ->where('year', $year)
            ->get();
    }

    /**
     * Create or update leave balance
     *
     * @param int $employeeId
     * @param string $leaveType
     * @param int $year
     * @param array $data
     * @return LeaveBalance
     */
    public function upsertLeaveBalance(int $employeeId, string $leaveType, int $year, array $data): LeaveBalance
    {
        return $this->leaveBalanceModel->updateOrCreate(
            [
                'employee_id' => $employeeId,
                'leave_type' => $leaveType,
                'year' => $year
            ],
            $data
        );
    }

    /**
     * Update leave balance used days
     *
     * @param int $employeeId
     * @param string $leaveType
     * @param int $year
     * @param float $days
     * @return bool
     */
    public function incrementUsedDays(int $employeeId, string $leaveType, int $year, float $days): bool
    {
        return $this->leaveBalanceModel->where('employee_id', $employeeId)
            ->where('leave_type', $leaveType)
            ->where('year', $year)
            ->increment('used_days', $days);
    }

    /**
     * Decrement leave balance used days
     *
     * @param int $employeeId
     * @param string $leaveType
     * @param int $year
     * @param float $days
     * @return bool
     */
    public function decrementUsedDays(int $employeeId, string $leaveType, int $year, float $days): bool
    {
        return $this->leaveBalanceModel->where('employee_id', $employeeId)
            ->where('leave_type', $leaveType)
            ->where('year', $year)
            ->decrement('used_days', $days);
    }

    /**
     * Get employees with low leave balance
     *
     * @param int $shopOwnerId
     * @param int $year
     * @param float $threshold
     * @return Collection
     */
    public function getEmployeesWithLowBalance(int $shopOwnerId, int $year, float $threshold = 3.0): Collection
    {
        return $this->leaveBalanceModel->where('year', $year)
            ->whereHas('employee', function ($q) use ($shopOwnerId) {
                $q->where('shop_owner_id', $shopOwnerId)
                  ->where('status', 'active');
            })
            ->whereRaw('(allocated_days - used_days) <= ?', [$threshold])
            ->with('employee')
            ->get();
    }

    /**
     * Initialize leave balances for new employee
     *
     * @param int $employeeId
     * @param int $shopOwnerId
     * @param int $year
     * @return Collection
     */
    public function initializeEmployeeBalances(int $employeeId, int $shopOwnerId, int $year): Collection
    {
        // Get leave types from settings or use default
        $leaveTypes = [
            ['type' => 'annual', 'days' => 15],
            ['type' => 'sick', 'days' => 10],
            ['type' => 'casual', 'days' => 5],
            ['type' => 'maternity', 'days' => 90],
            ['type' => 'paternity', 'days' => 5]
        ];

        $balances = collect();

        foreach ($leaveTypes as $leaveType) {
            $balance = $this->leaveBalanceModel->create([
                'employee_id' => $employeeId,
                'leave_type' => $leaveType['type'],
                'year' => $year,
                'allocated_days' => $leaveType['days'],
                'used_days' => 0,
                'shop_owner_id' => $shopOwnerId
            ]);

            $balances->push($balance);
        }

        return $balances;
    }
}
