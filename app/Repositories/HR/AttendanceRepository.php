<?php

namespace App\Repositories\HR;

use App\Models\Attendance;
use App\Repositories\HR\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AttendanceRepository - Handles all attendance-related database queries
 */
class AttendanceRepository extends BaseRepository
{
    /**
     * AttendanceRepository constructor
     *
     * @param Attendance $model
     */
    public function __construct(Attendance $model)
    {
        $this->model = $model;
    }

    /**
     * Get attendance records for a shop with filters
     *
     * @param int $shopOwnerId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getShopAttendance(int $shopOwnerId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId);

        // Apply filters
        if (isset($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date'])) {
            $query->whereDate('date', $filters['date']);
        }

        if (isset($filters['start_date'])) {
            $query->whereDate('date', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->whereDate('date', '<=', $filters['end_date']);
        }

        // Default relationships
        $with = $filters['with'] ?? ['employee'];
        $query->with($with);

        // Default ordering
        $orderBy = $filters['order_by'] ?? 'date';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        return $query->paginate($perPage);
    }

    /**
     * Get today's attendance for shop
     *
     * @param int $shopOwnerId
     * @return Collection
     */
    public function getTodaysAttendance(int $shopOwnerId): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->whereDate('date', Carbon::today())
            ->with('employee')
            ->orderBy('check_in', 'asc')
            ->get();
    }

    /**
     * Get employee's attendance for a date range
     *
     * @param int $employeeId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return Collection
     */
    public function getEmployeeAttendance(int $employeeId, Carbon $startDate, Carbon $endDate): Collection
    {
        return $this->model->where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * Get employee's attendance for a specific month
     *
     * @param int $employeeId
     * @param int $year
     * @param int $month
     * @return Collection
     */
    public function getMonthlyAttendance(int $employeeId, int $year, int $month): Collection
    {
        return $this->model->where('employee_id', $employeeId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->orderBy('date', 'asc')
            ->get();
    }

    /**
     * Get attendance record for employee on specific date
     *
     * @param int $employeeId
     * @param Carbon $date
     * @return Attendance|null
     */
    public function getAttendanceByDate(int $employeeId, Carbon $date): ?Attendance
    {
        return $this->model->where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->first();
    }

    /**
     * Check if employee has checked in today
     *
     * @param int $employeeId
     * @return bool
     */
    public function hasCheckedInToday(int $employeeId): bool
    {
        return $this->model->where('employee_id', $employeeId)
            ->whereDate('date', Carbon::today())
            ->whereNotNull('check_in')
            ->exists();
    }

    /**
     * Get late arrivals for a shop on a specific date
     *
     * @param int $shopOwnerId
     * @param Carbon $date
     * @param string $lateThreshold (e.g., '09:00:00')
     * @return Collection
     */
    public function getLateArrivals(int $shopOwnerId, Carbon $date, string $lateThreshold = '09:00:00'): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->whereDate('date', $date)
            ->whereNotNull('check_in')
            ->whereTime('check_in', '>', $lateThreshold)
            ->with('employee')
            ->get();
    }

    /**
     * Get early departures for a shop on a specific date
     *
     * @param int $shopOwnerId
     * @param Carbon $date
     * @param string $earlyThreshold (e.g., '17:00:00')
     * @return Collection
     */
    public function getEarlyDepartures(int $shopOwnerId, Carbon $date, string $earlyThreshold = '17:00:00'): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->whereDate('date', $date)
            ->whereNotNull('check_out')
            ->whereTime('check_out', '<', $earlyThreshold)
            ->with('employee')
            ->get();
    }

    /**
     * Get absent employees for a specific date
     *
     * @param int $shopOwnerId
     * @param Carbon $date
     * @return Collection
     */
    public function getAbsentEmployees(int $shopOwnerId, Carbon $date): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->whereDate('date', $date)
            ->where('status', 'absent')
            ->with('employee')
            ->get();
    }

    /**
     * Get employees currently checked in (no check out)
     *
     * @param int $shopOwnerId
     * @return Collection
     */
    public function getCurrentlyCheckedIn(int $shopOwnerId): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->whereDate('date', Carbon::today())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->with('employee')
            ->get();
    }

    /**
     * Get attendance statistics for a shop
     *
     * @param int $shopOwnerId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getAttendanceStatistics(int $shopOwnerId, Carbon $startDate, Carbon $endDate): array
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId)
            ->whereBetween('date', [$startDate, $endDate]);

        $total = $query->count();
        $present = (clone $query)->where('status', 'present')->count();
        $absent = (clone $query)->where('status', 'absent')->count();
        $late = (clone $query)->where('status', 'late')->count();
        $onLeave = (clone $query)->where('status', 'on_leave')->count();

        $attendanceRate = $total > 0 ? ($present / $total) * 100 : 0;

        return [
            'total_records' => $total,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'on_leave' => $onLeave,
            'attendance_rate' => round($attendanceRate, 2)
        ];
    }

    /**
     * Get employee attendance summary for a period
     *
     * @param int $employeeId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getEmployeeAttendanceSummary(int $employeeId, Carbon $startDate, Carbon $endDate): array
    {
        $records = $this->model->where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $totalDays = $records->count();
        $presentDays = $records->where('status', 'present')->count();
        $absentDays = $records->where('status', 'absent')->count();
        $lateDays = $records->where('status', 'late')->count();
        $leaveDays = $records->where('status', 'on_leave')->count();

        $totalWorkingHours = $records->sum('working_hours');
        $averageWorkingHours = $totalDays > 0 ? $totalWorkingHours / $totalDays : 0;

        return [
            'total_days' => $totalDays,
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'late_days' => $lateDays,
            'leave_days' => $leaveDays,
            'total_working_hours' => round($totalWorkingHours, 2),
            'average_working_hours' => round($averageWorkingHours, 2),
            'attendance_rate' => $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 2) : 0
        ];
    }

    /**
     * Mark attendance for employee
     *
     * @param int $employeeId
     * @param int $shopOwnerId
     * @param Carbon $date
     * @param string $status
     * @param array $additionalData
     * @return Attendance
     */
    public function markAttendance(int $employeeId, int $shopOwnerId, Carbon $date, string $status, array $additionalData = []): Attendance
    {
        return $this->model->updateOrCreate(
            [
                'employee_id' => $employeeId,
                'date' => $date->format('Y-m-d')
            ],
            array_merge([
                'shop_owner_id' => $shopOwnerId,
                'status' => $status
            ], $additionalData)
        );
    }

    /**
     * Record check-in
     *
     * @param int $employeeId
     * @param int $shopOwnerId
     * @param Carbon|null $checkInTime
     * @return Attendance
     */
    public function checkIn(int $employeeId, int $shopOwnerId, ?Carbon $checkInTime = null): Attendance
    {
        $checkInTime = $checkInTime ?? Carbon::now();

        return $this->model->updateOrCreate(
            [
                'employee_id' => $employeeId,
                'date' => $checkInTime->format('Y-m-d')
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'check_in' => $checkInTime,
                'status' => 'present'
            ]
        );
    }

    /**
     * Record check-out
     *
     * @param int $employeeId
     * @param Carbon|null $checkOutTime
     * @return bool
     */
    public function checkOut(int $employeeId, ?Carbon $checkOutTime = null): bool
    {
        $checkOutTime = $checkOutTime ?? Carbon::now();

        $attendance = $this->model->where('employee_id', $employeeId)
            ->whereDate('date', $checkOutTime->format('Y-m-d'))
            ->first();

        if (!$attendance) {
            return false;
        }

        // Calculate working hours
        $workingHours = 0;
        if ($attendance->check_in) {
            $checkIn = Carbon::parse($attendance->check_in);
            $workingHours = $checkOutTime->diffInHours($checkIn, true);
        }

        return $attendance->update([
            'check_out' => $checkOutTime,
            'working_hours' => $workingHours
        ]);
    }

    /**
     * Get employees with perfect attendance
     *
     * @param int $shopOwnerId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return Collection
     */
    public function getEmployeesWithPerfectAttendance(int $shopOwnerId, Carbon $startDate, Carbon $endDate): Collection
    {
        $totalDays = $startDate->diffInDays($endDate) + 1;

        return $this->model->select('employee_id')
            ->where('shop_owner_id', $shopOwnerId)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('status', 'present')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) = ?', [$totalDays])
            ->with('employee')
            ->get()
            ->pluck('employee');
    }

    /**
     * Get attendance trends by day of week
     *
     * @param int $shopOwnerId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getAttendanceTrendsByDayOfWeek(int $shopOwnerId, Carbon $startDate, Carbon $endDate): array
    {
        return $this->model->select(
            DB::raw('DAYNAME(date) as day_of_week'),
            DB::raw('COUNT(*) as total'),
            DB::raw('SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present'),
            DB::raw('SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as absent')
        )
            ->where('shop_owner_id', $shopOwnerId)
            ->whereBetween('date', [$startDate, $endDate])
            ->groupBy('day_of_week')
            ->get()
            ->toArray();
    }

    /**
     * Bulk create attendance records
     *
     * @param array $records
     * @return bool
     */
    public function bulkCreateAttendance(array $records): bool
    {
        return $this->model->insert($records);
    }

    /**
     * Update multiple attendance records
     *
     * @param array $ids
     * @param array $data
     * @return int
     */
    public function bulkUpdateAttendance(array $ids, array $data): int
    {
        return $this->model->whereIn('id', $ids)->update($data);
    }
}
