<?php

namespace App\Services\HR;

use App\Models\HR\AttendanceRecord;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LatenessTrackingService
{
    /**
     * Get lateness statistics for a shop owner
     */
    public function getLatenessStats(int $shopOwnerId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = AttendanceRecord::forShopOwner($shopOwnerId);

        if ($startDate && $endDate) {
            $query->betweenDates($startDate, $endDate);
        } else {
            // Default to current month
            $query->betweenDates(
                Carbon::now()->startOfMonth()->toDateString(),
                Carbon::now()->endOfMonth()->toDateString()
            );
        }

        $totalRecords = $query->count();
        $lateRecords = (clone $query)->where('is_late', true)->count();
        $earlyDepartureRecords = (clone $query)->where('is_early_departure', true)->count();
        
        $averageLateMinutes = (clone $query)
            ->where('is_late', true)
            ->avg('minutes_late') ?? 0;

        return [
            'total_attendance_records' => $totalRecords,
            'late_records' => $lateRecords,
            'early_departure_records' => $earlyDepartureRecords,
            'lateness_rate' => $totalRecords > 0 ? round(($lateRecords / $totalRecords) * 100, 2) : 0,
            'average_late_minutes' => round($averageLateMinutes, 2),
        ];
    }

    /**
     * Get employees with most lateness
     */
    public function getMostLateEmployees(int $shopOwnerId, int $limit = 10, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $query = AttendanceRecord::forShopOwner($shopOwnerId)
            ->where('is_late', true)
            ->with('employee:id,first_name,last_name,position,department');

        if ($startDate && $endDate) {
            $query->betweenDates($startDate, $endDate);
        } else {
            // Default to current month
            $query->betweenDates(
                Carbon::now()->startOfMonth()->toDateString(),
                Carbon::now()->endOfMonth()->toDateString()
            );
        }

        return $query->selectRaw('employee_id, COUNT(*) as late_count, SUM(minutes_late) as total_minutes_late, AVG(minutes_late) as avg_minutes_late')
            ->groupBy('employee_id')
            ->orderByDesc('late_count')
            ->limit($limit)
            ->get()
            ->map(function ($record) {
                return [
                    'employee' => $record->employee,
                    'late_count' => $record->late_count,
                    'total_minutes_late' => $record->total_minutes_late,
                    'average_minutes_late' => round($record->avg_minutes_late, 2),
                    'total_hours_late' => round($record->total_minutes_late / 60, 2),
                ];
            });
    }

    /**
     * Get lateness report for a specific employee
     */
    public function getEmployeeLatenessReport(int $employeeId, int $shopOwnerId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = AttendanceRecord::forShopOwner($shopOwnerId)
            ->forEmployee($employeeId);

        if ($startDate && $endDate) {
            $query->betweenDates($startDate, $endDate);
        } else {
            // Default to current month
            $query->betweenDates(
                Carbon::now()->startOfMonth()->toDateString(),
                Carbon::now()->endOfMonth()->toDateString()
            );
        }

        $totalDays = $query->count();
        $lateDays = (clone $query)->where('is_late', true)->count();
        $totalLateMinutes = (clone $query)->where('is_late', true)->sum('minutes_late');
        $averageLateMinutes = $lateDays > 0 ? $totalLateMinutes / $lateDays : 0;

        $lateRecords = (clone $query)
            ->where('is_late', true)
            ->orderByDesc('date')
            ->get(['date', 'check_in_time', 'expected_check_in', 'minutes_late', 'lateness_reason']);

        return [
            'total_attendance_days' => $totalDays,
            'late_days' => $lateDays,
            'on_time_days' => $totalDays - $lateDays,
            'lateness_rate' => $totalDays > 0 ? round(($lateDays / $totalDays) * 100, 2) : 0,
            'total_minutes_late' => $totalLateMinutes,
            'total_hours_late' => round($totalLateMinutes / 60, 2),
            'average_minutes_late' => round($averageLateMinutes, 2),
            'late_records' => $lateRecords,
        ];
    }

    /**
     * Get daily lateness summary
     */
    public function getDailyLatenessSummary(int $shopOwnerId, string $date): Collection
    {
        return AttendanceRecord::forShopOwner($shopOwnerId)
            ->where('date', $date)
            ->where('is_late', true)
            ->with('employee:id,first_name,last_name,position,department')
            ->orderByDesc('minutes_late')
            ->get()
            ->map(function ($record) {
                return [
                    'employee' => $record->employee,
                    'check_in_time' => $record->check_in_time,
                    'expected_check_in' => $record->expected_check_in,
                    'minutes_late' => $record->minutes_late,
                    'lateness_reason' => $record->lateness_reason,
                ];
            });
    }

    /**
     * Get lateness trends by day of week
     */
    public function getLatenessTrendsByDayOfWeek(int $shopOwnerId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = AttendanceRecord::forShopOwner($shopOwnerId)
            ->where('is_late', true);

        if ($startDate && $endDate) {
            $query->betweenDates($startDate, $endDate);
        } else {
            // Default to last 30 days
            $query->betweenDates(
                Carbon::now()->subDays(30)->toDateString(),
                Carbon::now()->toDateString()
            );
        }

        $records = $query->get(['date', 'minutes_late']);

        $dayTotals = [];
        foreach ($records as $record) {
            $dayOfWeek = Carbon::parse($record->date)->format('l'); // Monday, Tuesday, etc.
            if (!isset($dayTotals[$dayOfWeek])) {
                $dayTotals[$dayOfWeek] = ['count' => 0, 'total_minutes' => 0];
            }
            $dayTotals[$dayOfWeek]['count']++;
            $dayTotals[$dayOfWeek]['total_minutes'] += $record->minutes_late;
        }

        $result = [];
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            $result[$day] = [
                'late_count' => $dayTotals[$day]['count'] ?? 0,
                'average_minutes_late' => isset($dayTotals[$day]) && $dayTotals[$day]['count'] > 0
                    ? round($dayTotals[$day]['total_minutes'] / $dayTotals[$day]['count'], 2)
                    : 0,
            ];
        }

        return $result;
    }
}
