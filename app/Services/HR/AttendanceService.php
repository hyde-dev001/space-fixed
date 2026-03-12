<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\AttendanceRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AttendanceService handles all attendance-related business logic
 * including check-in/out, working hours calculation, and overtime management
 */
class AttendanceService
{
    /**
     * Standard work hours per day
     */
    const STANDARD_WORK_HOURS = 8;
    
    /**
     * Standard work hours per week
     */
    const STANDARD_WORK_HOURS_WEEK = 40;

    /**
     * Process employee check-in
     * 
     * @param Employee $employee
     * @param Carbon|null $checkInTime
     * @param string|null $location
     * @param array $additionalData
     * @return AttendanceRecord
     * @throws \Exception
     */
    public function checkIn(Employee $employee, ?Carbon $checkInTime = null, ?string $location = null, array $additionalData = []): AttendanceRecord
    {
        try {
            $checkInTime = $checkInTime ?? Carbon::now();
            
            // Check if already checked in today
            $existingRecord = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('date', $checkInTime->toDateString())
                ->whereNull('check_out_time')
                ->first();
            
            if ($existingRecord) {
                throw new \Exception('Employee has already checked in today');
            }
            
            // Check for shift timing if applicable
            $expectedCheckIn = $this->getExpectedCheckInTime($employee);
            $isLate = $expectedCheckIn && $checkInTime->greaterThan($expectedCheckIn);
            
            $record = AttendanceRecord::create([
                'employee_id' => $employee->id,
                'date' => $checkInTime->toDateString(),
                'check_in_time' => $checkInTime,
                'check_in_location' => $location,
                'is_late' => $isLate,
                'late_minutes' => $isLate ? $checkInTime->diffInMinutes($expectedCheckIn) : 0,
                'status' => 'present',
                'shop_owner_id' => $employee->shop_owner_id,
                ...$additionalData
            ]);
            
            Log::info('Employee checked in', [
                'employee_id' => $employee->id,
                'attendance_id' => $record->id,
                'check_in_time' => $checkInTime->toDateTimeString(),
                'is_late' => $isLate
            ]);
            
            return $record;
            
        } catch (\Exception $e) {
            Log::error('Failed to process check-in', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Process employee check-out
     * 
     * @param Employee $employee
     * @param Carbon|null $checkOutTime
     * @param string|null $location
     * @return AttendanceRecord
     * @throws \Exception
     */
    public function checkOut(Employee $employee, ?Carbon $checkOutTime = null, ?string $location = null): AttendanceRecord
    {
        try {
            $checkOutTime = $checkOutTime ?? Carbon::now();
            
            // Find today's check-in record
            $record = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('date', $checkOutTime->toDateString())
                ->whereNull('check_out_time')
                ->first();
            
            if (!$record) {
                throw new \Exception('No check-in record found for today');
            }
            
            // Calculate working hours
            $workingHours = $this->calculateWorkingHours($record->check_in_time, $checkOutTime);
            
            // Calculate overtime
            $overtime = $this->calculateOvertime($workingHours);
            
            // Check for early departure
            $expectedCheckOut = $this->getExpectedCheckOutTime($employee);
            $isEarlyDeparture = $expectedCheckOut && $checkOutTime->lessThan($expectedCheckOut);
            
            $record->update([
                'check_out_time' => $checkOutTime,
                'check_out_location' => $location,
                'working_hours' => $workingHours,
                'overtime_hours' => $overtime,
                'is_early_departure' => $isEarlyDeparture,
                'early_departure_minutes' => $isEarlyDeparture ? $expectedCheckOut->diffInMinutes($checkOutTime) : 0
            ]);
            
            Log::info('Employee checked out', [
                'employee_id' => $employee->id,
                'attendance_id' => $record->id,
                'check_out_time' => $checkOutTime->toDateTimeString(),
                'working_hours' => $workingHours,
                'overtime_hours' => $overtime
            ]);
            
            return $record->fresh();
            
        } catch (\Exception $e) {
            Log::error('Failed to process check-out', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Calculate working hours excluding break time
     * 
     * @param Carbon $checkIn
     * @param Carbon $checkOut
     * @param float $breakHours Default 1 hour lunch break
     * @return float
     */
    public function calculateWorkingHours(Carbon $checkIn, Carbon $checkOut, float $breakHours = 1.0): float
    {
        $totalHours = $checkOut->diffInMinutes($checkIn) / 60;
        
        // Deduct break time if work hours exceed break threshold
        if ($totalHours > 4) {
            $totalHours -= $breakHours;
        }
        
        // Ensure non-negative
        return max(0, round($totalHours, 2));
    }

    /**
     * Calculate overtime hours
     * 
     * @param float $workingHours
     * @return float
     */
    public function calculateOvertime(float $workingHours): float
    {
        if ($workingHours > self::STANDARD_WORK_HOURS) {
            return round($workingHours - self::STANDARD_WORK_HOURS, 2);
        }
        
        return 0;
    }

    /**
     * Mark employee as absent for a specific date
     * 
     * @param Employee $employee
     * @param Carbon $date
     * @param string $reason
     * @return AttendanceRecord
     */
    public function markAbsent(Employee $employee, Carbon $date, string $reason = ''): AttendanceRecord
    {
        try {
            // Check if record already exists
            $existing = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('date', $date->toDateString())
                ->first();
            
            if ($existing) {
                $existing->update([
                    'status' => 'absent',
                    'notes' => $reason
                ]);
                return $existing;
            }
            
            $record = AttendanceRecord::create([
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'status' => 'absent',
                'notes' => $reason,
                'shop_owner_id' => $employee->shop_owner_id
            ]);
            
            Log::info('Employee marked absent', [
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'reason' => $reason
            ]);
            
            return $record;
            
        } catch (\Exception $e) {
            Log::error('Failed to mark absent', [
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Get attendance summary for employee in a period
     * 
     * @param Employee $employee
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getAttendanceSummary(Employee $employee, Carbon $startDate, Carbon $endDate): array
    {
        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();
        
        $workingDays = $this->getWorkingDaysBetween($startDate, $endDate);
        
        $presentDays = $records->where('status', 'present')->count();
        $absentDays = $records->where('status', 'absent')->count();
        $lateDays = $records->where('is_late', true)->count();
        $earlyDepartures = $records->where('is_early_departure', true)->count();
        
        $totalWorkingHours = $records->sum('working_hours');
        $totalOvertimeHours = $records->sum('overtime_hours');
        
        $attendanceRate = $workingDays > 0 ? round(($presentDays / $workingDays) * 100, 2) : 0;
        
        return [
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'working_days' => $workingDays
            ],
            'attendance' => [
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'late_days' => $lateDays,
                'early_departures' => $earlyDepartures,
                'attendance_rate' => $attendanceRate
            ],
            'hours' => [
                'total_working_hours' => $totalWorkingHours,
                'total_overtime_hours' => $totalOvertimeHours,
                'average_hours_per_day' => $presentDays > 0 ? round($totalWorkingHours / $presentDays, 2) : 0
            ]
        ];
    }

    /**
     * Get working days between two dates (excluding weekends)
     * 
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return int
     */
    protected function getWorkingDaysBetween(Carbon $startDate, Carbon $endDate): int
    {
        $workingDays = 0;
        $currentDate = $startDate->copy();
        
        while ($currentDate->lte($endDate)) {
            if (!$currentDate->isWeekend()) {
                $workingDays++;
            }
            $currentDate->addDay();
        }
        
        return $workingDays;
    }

    /**
     * Get expected check-in time for employee (based on shift)
     * 
     * @param Employee $employee
     * @return Carbon|null
     */
    protected function getExpectedCheckInTime(Employee $employee): ?Carbon
    {
        // TODO: Implement shift-based check-in time
        // For now, return standard 9:00 AM
        return Carbon::today()->setTime(9, 0);
    }

    /**
     * Get expected check-out time for employee (based on shift)
     * 
     * @param Employee $employee
     * @return Carbon|null
     */
    protected function getExpectedCheckOutTime(Employee $employee): ?Carbon
    {
        // TODO: Implement shift-based check-out time
        // For now, return standard 6:00 PM (9 hours including 1 hour break)
        return Carbon::today()->setTime(18, 0);
    }

    /**
     * Calculate attendance-based salary adjustments
     * 
     * @param Employee $employee
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function calculateAttendanceDeductions(Employee $employee, Carbon $startDate, Carbon $endDate): array
    {
        $summary = $this->getAttendanceSummary($employee, $startDate, $endDate);
        
        $workingDays = $summary['period']['working_days'];
        $presentDays = $summary['attendance']['present_days'];
        $absentDays = $workingDays - $presentDays;
        
        $perDaySalary = ($employee->salary ?? 0) / $workingDays;
        $deduction = $absentDays * $perDaySalary;
        
        return [
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'per_day_salary' => round($perDaySalary, 2),
            'total_deduction' => round($deduction, 2)
        ];
    }

    /**
     * Get team attendance overview for a manager
     * 
     * @param int $shopOwnerId
     * @param Carbon $date
     * @return array
     */
    public function getTeamAttendanceOverview(int $shopOwnerId, Carbon $date): array
    {
        $employees = Employee::where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->get();
        
        $totalEmployees = $employees->count();
        
        $attendance = AttendanceRecord::where('shop_owner_id', $shopOwnerId)
            ->whereDate('date', $date)
            ->get();
        
        $present = $attendance->where('status', 'present')->count();
        $absent = $totalEmployees - $present;
        $late = $attendance->where('is_late', true)->count();
        
        return [
            'date' => $date->toDateString(),
            'total_employees' => $totalEmployees,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'attendance_rate' => $totalEmployees > 0 ? round(($present / $totalEmployees) * 100, 2) : 0,
            'checked_in' => $attendance->whereNotNull('check_in_time')->count(),
            'checked_out' => $attendance->whereNotNull('check_out_time')->count()
        ];
    }

    /**
     * Identify attendance patterns and anomalies
     * 
     * @param Employee $employee
     * @param int $days Number of days to analyze
     * @return array
     */
    public function analyzeAttendancePatterns(Employee $employee, int $days = 30): array
    {
        $endDate = Carbon::today();
        $startDate = Carbon::today()->subDays($days);
        
        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get();
        
        // Calculate patterns
        $latePattern = $records->where('is_late', true)->count();
        $earlyDeparturePattern = $records->where('is_early_departure', true)->count();
        $averageCheckInTime = $this->calculateAverageCheckInTime($records);
        $averageWorkHours = $records->avg('working_hours') ?? 0;
        
        // Identify anomalies
        $anomalies = [];
        
        if ($latePattern > ($days * 0.3)) {
            $anomalies[] = 'Frequent late arrivals';
        }
        
        if ($earlyDeparturePattern > ($days * 0.2)) {
            $anomalies[] = 'Frequent early departures';
        }
        
        if ($averageWorkHours < (self::STANDARD_WORK_HOURS * 0.8)) {
            $anomalies[] = 'Below standard working hours';
        }
        
        return [
            'period' => [
                'days_analyzed' => $days,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString()
            ],
            'patterns' => [
                'late_days' => $latePattern,
                'early_departure_days' => $earlyDeparturePattern,
                'average_check_in_time' => $averageCheckInTime,
                'average_work_hours' => round($averageWorkHours, 2)
            ],
            'anomalies' => $anomalies,
            'reliability_score' => $this->calculateReliabilityScore($records, $days)
        ];
    }

    /**
     * Calculate average check-in time
     * 
     * @param \Illuminate\Database\Eloquent\Collection $records
     * @return string
     */
    protected function calculateAverageCheckInTime($records): string
    {
        $checkIns = $records->whereNotNull('check_in_time');
        
        if ($checkIns->isEmpty()) {
            return 'N/A';
        }
        
        $totalMinutes = 0;
        foreach ($checkIns as $record) {
            $time = Carbon::parse($record->check_in_time);
            $totalMinutes += ($time->hour * 60) + $time->minute;
        }
        
        $averageMinutes = $totalMinutes / $checkIns->count();
        $hours = floor($averageMinutes / 60);
        $minutes = $averageMinutes % 60;
        
        return sprintf('%02d:%02d', $hours, $minutes);
    }

    /**
     * Calculate employee reliability score based on attendance
     * 
     * @param \Illuminate\Database\Eloquent\Collection $records
     * @param int $totalDays
     * @return float Score out of 100
     */
    protected function calculateReliabilityScore($records, int $totalDays): float
    {
        $presentDays = $records->where('status', 'present')->count();
        $lateDays = $records->where('is_late', true)->count();
        $earlyDepartures = $records->where('is_early_departure', true)->count();
        
        // Base score from attendance rate
        $attendanceScore = ($presentDays / $totalDays) * 70;
        
        // Deduct points for late arrivals and early departures
        $punctualityScore = 30 - (($lateDays + $earlyDepartures) * 2);
        $punctualityScore = max(0, $punctualityScore);
        
        return round($attendanceScore + $punctualityScore, 2);
    }
}
