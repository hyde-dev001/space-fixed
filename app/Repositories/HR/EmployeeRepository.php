<?php

namespace App\Repositories\HR;

use App\Models\Employee;
use App\Repositories\HR\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

/**
 * EmployeeRepository - Handles all employee-related database queries
 */
class EmployeeRepository extends BaseRepository
{
    /**
     * EmployeeRepository constructor
     *
     * @param Employee $model
     */
    public function __construct(Employee $model)
    {
        $this->model = $model;
    }

    /**
     * Get active employees for a specific shop
     *
     * @param int $shopOwnerId
     * @param array $with
     * @return Collection
     */
    public function findActiveByShop(int $shopOwnerId, array $with = []): Collection
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active');

        if (!empty($with)) {
            $query->with($with);
        }

        return $query->get();
    }

    /**
     * Get all employees for a shop with pagination and filters
     *
     * @param int $shopOwnerId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getShopEmployees(int $shopOwnerId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId);

        // Apply filters
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('first_name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('last_name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('email', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('employee_id', 'like', '%' . $filters['search'] . '%');
            });
        }

        // Default relationships
        $with = $filters['with'] ?? ['department', 'leaveBalances'];
        $query->with($with);

        // Default ordering
        $orderBy = $filters['order_by'] ?? 'created_at';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        return $query->paginate($perPage);
    }

    /**
     * Find employee by employee ID (not database ID)
     *
     * @param string $employeeId
     * @param int $shopOwnerId
     * @return Employee|null
     */
    public function findByEmployeeId(string $employeeId, int $shopOwnerId): ?Employee
    {
        return $this->model->where('employee_id', $employeeId)
            ->where('shop_owner_id', $shopOwnerId)
            ->first();
    }

    /**
     * Find employee by email
     *
     * @param string $email
     * @param int $shopOwnerId
     * @return Employee|null
     */
    public function findByEmail(string $email, int $shopOwnerId): ?Employee
    {
        return $this->model->where('email', $email)
            ->where('shop_owner_id', $shopOwnerId)
            ->first();
    }

    /**
     * Get employees by department
     *
     * @param int $departmentId
     * @param int $shopOwnerId
     * @return Collection
     */
    public function getByDepartment(int $departmentId, int $shopOwnerId): Collection
    {
        return $this->model->where('department_id', $departmentId)
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->get();
    }

    /**
     * Get employees hired within a date range
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int $shopOwnerId
     * @return Collection
     */
    public function getHiredBetween(Carbon $startDate, Carbon $endDate, int $shopOwnerId): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->whereBetween('hire_date', [$startDate, $endDate])
            ->get();
    }

    /**
     * Get suspended employees
     *
     * @param int $shopOwnerId
     * @return Collection
     */
    public function getSuspendedEmployees(int $shopOwnerId): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'suspended')
            ->get();
    }

    /**
     * Get employee count statistics
     *
     * @param int $shopOwnerId
     * @return array
     */
    public function getStatistics(int $shopOwnerId): array
    {
        $total = $this->model->where('shop_owner_id', $shopOwnerId)->count();
        $active = $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->count();
        $suspended = $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'suspended')
            ->count();

        $byDepartment = $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->selectRaw('department_id, COUNT(*) as count')
            ->groupBy('department_id')
            ->with('department:id,name')
            ->get()
            ->map(function ($item) {
                return [
                    'department' => $item->department->name ?? 'Unassigned',
                    'count' => $item->count
                ];
            });

        return [
            'total' => $total,
            'active' => $active,
            'suspended' => $suspended,
            'by_department' => $byDepartment
        ];
    }

    /**
     * Suspend employee
     *
     * @param int $id
     * @param int $shopOwnerId
     * @return bool
     */
    public function suspend(int $id, int $shopOwnerId): bool
    {
        return $this->model->where('id', $id)
            ->where('shop_owner_id', $shopOwnerId)
            ->update(['status' => 'suspended']);
    }

    /**
     * Activate employee
     *
     * @param int $id
     * @param int $shopOwnerId
     * @return bool
     */
    public function activate(int $id, int $shopOwnerId): bool
    {
        return $this->model->where('id', $id)
            ->where('shop_owner_id', $shopOwnerId)
            ->update(['status' => 'active']);
    }

    /**
     * Get employees with upcoming birthdays
     *
     * @param int $shopOwnerId
     * @param int $daysAhead
     * @return Collection
     */
    public function getUpcomingBirthdays(int $shopOwnerId, int $daysAhead = 30): Collection
    {
        $today = Carbon::today();
        $endDate = $today->copy()->addDays($daysAhead);

        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->whereNotNull('date_of_birth')
            ->get()
            ->filter(function ($employee) use ($today, $endDate) {
                $birthday = Carbon::parse($employee->date_of_birth)->year($today->year);
                return $birthday->between($today, $endDate);
            });
    }

    /**
     * Get employees with probation ending soon
     *
     * @param int $shopOwnerId
     * @param int $daysAhead
     * @return Collection
     */
    public function getProbationEnding(int $shopOwnerId, int $daysAhead = 30): Collection
    {
        $endDate = Carbon::today()->addDays($daysAhead);

        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->whereNotNull('probation_end_date')
            ->whereDate('probation_end_date', '<=', $endDate)
            ->whereDate('probation_end_date', '>=', Carbon::today())
            ->get();
    }

    /**
     * Bulk update employees
     *
     * @param array $ids
     * @param array $attributes
     * @param int $shopOwnerId
     * @return int Number of affected rows
     */
    public function bulkUpdate(array $ids, array $attributes, int $shopOwnerId): int
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->whereIn('id', $ids)
            ->update($attributes);
    }

    /**
     * Check if employee ID is unique within shop
     *
     * @param string $employeeId
     * @param int $shopOwnerId
     * @param int|null $excludeId
     * @return bool
     */
    public function isEmployeeIdUnique(string $employeeId, int $shopOwnerId, ?int $excludeId = null): bool
    {
        $query = $this->model->where('employee_id', $employeeId)
            ->where('shop_owner_id', $shopOwnerId);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return !$query->exists();
    }

    /**
     * Check if email is unique within shop
     *
     * @param string $email
     * @param int $shopOwnerId
     * @param int|null $excludeId
     * @return bool
     */
    public function isEmailUnique(string $email, int $shopOwnerId, ?int $excludeId = null): bool
    {
        $query = $this->model->where('email', $email)
            ->where('shop_owner_id', $shopOwnerId);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return !$query->exists();
    }
}
