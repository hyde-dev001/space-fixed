<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\HR\LeaveRequest;
use App\Models\HR\Payroll;
use App\Models\HR\PerformanceReview;
use App\Models\HR\Department;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * HR Analytics Controller
 * 
 * Provides comprehensive analytics and reporting for HR operations
 * including headcount, turnover, attendance, payroll, and performance metrics.
 */
class HRAnalyticsController extends Controller
{
    /**
     * Main analytics dashboard - aggregates all key metrics
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get date range from request or default to last 12 months
        $startDate = $request->input('start_date', Carbon::now()->subYear());
        $endDate = $request->input('end_date', Carbon::now());

        return response()->json([
            'headcount' => $this->getHeadcountMetrics($user->shop_owner_id, $startDate, $endDate),
            'turnover' => $this->getTurnoverRate($user->shop_owner_id, $startDate, $endDate),
            'attendance' => $this->getAttendanceStats($user->shop_owner_id, $startDate, $endDate),
            'payroll' => $this->getPayrollCosts($user->shop_owner_id, $startDate, $endDate),
            'performance' => $this->getPerformanceDistribution($user->shop_owner_id, $startDate, $endDate),
            'summary' => $this->getSummaryMetrics($user->shop_owner_id),
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Get headcount metrics by department, location, and time trends
     */
    public function getHeadcountMetrics($shopOwnerId, $startDate = null, $endDate = null): array
    {
        $query = Employee::where('shop_owner_id', $shopOwnerId);

        // Current headcount
        $currentHeadcount = (clone $query)->where('status', 'active')->count();
        
        // By department
        $byDepartment = (clone $query)
            ->where('status', 'active')
            ->select('department', DB::raw('COUNT(*) as count'))
            ->groupBy('department')
            ->orderByDesc('count')
            ->get()
            ->map(fn($item) => [
                'department' => $item->department ?? 'Unassigned',
                'count' => $item->count,
                'percentage' => $currentHeadcount > 0 ? round(($item->count / $currentHeadcount) * 100, 2) : 0,
            ]);

        // By location (using branch field)
        $byLocation = (clone $query)
            ->where('status', 'active')
            ->select('branch', DB::raw('COUNT(*) as count'))
            ->groupBy('branch')
            ->orderByDesc('count')
            ->get()
            ->map(fn($item) => [
                'location' => $item->branch ?? 'Unknown',
                'count' => $item->count,
                'percentage' => $currentHeadcount > 0 ? round(($item->count / $currentHeadcount) * 100, 2) : 0,
            ]);

        // By status
        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn($item) => [
                'status' => $item->status,
                'count' => $item->count,
            ]);

        // Monthly trend (last 12 months)
        $monthlyTrend = [];
        $start = Carbon::parse($startDate ?? Carbon::now()->subYear());
        $end = Carbon::parse($endDate ?? Carbon::now());
        
        $currentMonth = $start->copy();
        while ($currentMonth->lte($end)) {
            $monthStart = $currentMonth->copy()->startOfMonth();
            $monthEnd = $currentMonth->copy()->endOfMonth();
            
            $hired = Employee::where('shop_owner_id', $shopOwnerId)
                ->whereBetween('hire_date', [$monthStart, $monthEnd])
                ->count();
            
            // Calculate inactive employees in this month (simplified without termination_date)
            $terminated = Employee::where('shop_owner_id', $shopOwnerId)
                ->where('status', 'inactive')
                ->where('updated_at', '<=', $monthEnd)
                ->count();
            
            // Calculate headcount at end of month (active employees)
            $headcountAtMonth = Employee::where('shop_owner_id', $shopOwnerId)
                ->where('hire_date', '<=', $monthEnd)
                ->whereIn('status', ['active', 'on_leave'])
                ->count();
            
            $monthlyTrend[] = [
                'month' => $currentMonth->format('Y-m'),
                'month_name' => $currentMonth->format('M Y'),
                'headcount' => $headcountAtMonth,
                'hired' => $hired,
                'terminated' => $terminated,
                'net_change' => $hired - $terminated,
            ];
            
            $currentMonth->addMonth();
        }

        return [
            'current_headcount' => $currentHeadcount,
            'by_department' => $byDepartment,
            'by_location' => $byLocation,
            'by_status' => $byStatus,
            'monthly_trend' => $monthlyTrend,
        ];
    }

    /**
     * Get turnover rate and analysis (simplified - no termination_date column)
     */
    public function getTurnoverRate($shopOwnerId, $startDate = null, $endDate = null): array
    {
        $start = Carbon::parse($startDate ?? Carbon::now()->subYear());
        $end = Carbon::parse($endDate ?? Carbon::now());

        // Average headcount during period
        $avgHeadcount = Employee::where('shop_owner_id', $shopOwnerId)
            ->where('hire_date', '<=', $end)
            ->whereIn('status', ['active', 'on_leave'])
            ->count();

        // Inactive employees (simplified turnover)
        $inactiveEmployees = Employee::where('shop_owner_id', $shopOwnerId)
            ->where('status', 'inactive')
            ->get();

        $inactiveCount = $inactiveEmployees->count();
        
        // Simplified turnover rate
        $turnoverRate = $avgHeadcount > 0 
            ? round(($inactiveCount / ($avgHeadcount + $inactiveCount)) * 100, 2)
            : 0;

        // By department
        $byDepartment = $inactiveEmployees->groupBy('department')->map(fn($group) => [
            'department' => $group->first()->department ?? 'Unassigned',
            'count' => $group->count(),
        ])->values();

        return [
            'turnover_rate' => $turnoverRate,
            'terminated_count' => $inactiveCount,
            'average_headcount' => $avgHeadcount,
            'by_reason' => [],
            'by_department' => $byDepartment,
            'tenure_analysis' => [],
        ];
    }

    /**
     * Get attendance statistics and patterns
     */
    public function getAttendanceStats($shopOwnerId, $startDate = null, $endDate = null): array
    {
        $start = Carbon::parse($startDate ?? Carbon::now()->subMonth());
        $end = Carbon::parse($endDate ?? Carbon::now());

        // Total attendance records
        $attendanceQuery = AttendanceRecord::whereHas('employee', function($q) use ($shopOwnerId) {
            $q->where('shop_owner_id', $shopOwnerId);
        })->whereBetween('date', [$start, $end]);

        $totalRecords = (clone $attendanceQuery)->count();
        
        // Average working hours
        $avgWorkingHours = (clone $attendanceQuery)
            ->whereNotNull('working_hours')
            ->avg('working_hours');

        // Overtime hours
        $totalOvertimeHours = (clone $attendanceQuery)
            ->sum('overtime_hours');

        // Late arrivals
        $lateArrivals = (clone $attendanceQuery)
            ->where('status', 'late')
            ->count();

        // Absenteeism
        $workingDays = $start->diffInDays($end);
        $activeEmployees = Employee::where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->count();
        
        $expectedAttendance = $workingDays * $activeEmployees;
        $absenteeismRate = $expectedAttendance > 0 
            ? round((($expectedAttendance - $totalRecords) / $expectedAttendance) * 100, 2)
            : 0;

        // By department
        $byDepartment = AttendanceRecord::select('employees.department', DB::raw('COUNT(*) as count'))
            ->join('employees', 'attendance_records.employee_id', '=', 'employees.id')
            ->where('employees.shop_owner_id', $shopOwnerId)
            ->whereBetween('attendance_records.date', [$start, $end])
            ->groupBy('employees.department')
            ->get()
            ->map(fn($item) => [
                'department' => $item->department ?? 'Unassigned',
                'attendance_count' => $item->count,
            ]);

        // Daily trend
        $dailyTrend = AttendanceRecord::select(
                DB::raw('DATE(date) as date'),
                DB::raw('COUNT(*) as count'),
                DB::raw('AVG(working_hours) as avg_hours')
            )
            ->whereHas('employee', function($q) use ($shopOwnerId) {
                $q->where('shop_owner_id', $shopOwnerId);
            })
            ->whereBetween('date', [$start, $end])
            ->groupBy(DB::raw('DATE(date)'))
            ->orderBy('date')
            ->get()
            ->map(fn($item) => [
                'date' => $item->date,
                'attendance_count' => $item->count,
                'avg_hours' => round($item->avg_hours, 2),
            ]);

        // Leave requests in period
        $leaveRequests = LeaveRequest::whereHas('employee', function($q) use ($shopOwnerId) {
            $q->where('shop_owner_id', $shopOwnerId);
        })
            ->whereBetween('start_date', [$start, $end])
            ->get();

        $leaveStats = [
            'total_requests' => $leaveRequests->count(),
            'approved' => $leaveRequests->where('status', 'approved')->count(),
            'pending' => $leaveRequests->where('status', 'pending')->count(),
            'rejected' => $leaveRequests->where('status', 'rejected')->count(),
            'total_days' => $leaveRequests->where('status', 'approved')->sum('days'),
        ];

        return [
            'total_records' => $totalRecords,
            'average_working_hours' => round($avgWorkingHours ?? 0, 2),
            'total_overtime_hours' => round($totalOvertimeHours, 2),
            'late_arrivals' => $lateArrivals,
            'late_arrival_rate' => $totalRecords > 0 ? round(($lateArrivals / $totalRecords) * 100, 2) : 0,
            'absenteeism_rate' => $absenteeismRate,
            'by_department' => $byDepartment,
            'daily_trend' => $dailyTrend,
            'leave_statistics' => $leaveStats,
        ];
    }

    /**
     * Get payroll cost analysis
     */
    public function getPayrollCosts($shopOwnerId, $startDate = null, $endDate = null): array
    {
        $start = Carbon::parse($startDate ?? Carbon::now()->subYear());
        $end = Carbon::parse($endDate ?? Carbon::now());

        // Total payroll costs
        $payrollQuery = Payroll::whereHas('employee', function($q) use ($shopOwnerId) {
            $q->where('shop_owner_id', $shopOwnerId);
        })->whereBetween('created_at', [$start, $end]);

        $totalGrossSalary = (clone $payrollQuery)->sum('gross_salary');
        $totalDeductions = (clone $payrollQuery)->sum('deductions');
        $totalNetSalary = (clone $payrollQuery)->sum('net_salary');
        $totalRecords = (clone $payrollQuery)->count();

        // Average salaries
        $avgGrossSalary = $totalRecords > 0 ? $totalGrossSalary / $totalRecords : 0;
        $avgNetSalary = $totalRecords > 0 ? $totalNetSalary / $totalRecords : 0;

        // By department
        $byDepartment = Payroll::select(
                'employees.department',
                DB::raw('SUM(payrolls.gross_salary) as total_gross'),
                DB::raw('SUM(payrolls.net_salary) as total_net'),
                DB::raw('COUNT(*) as count')
            )
            ->join('employees', 'payrolls.employee_id', '=', 'employees.id')
            ->where('employees.shop_owner_id', $shopOwnerId)
            ->whereBetween('payrolls.created_at', [$start, $end])
            ->groupBy('employees.department')
            ->get()
            ->map(fn($item) => [
                'department' => $item->department ?? 'Unassigned',
                'total_gross' => round($item->total_gross, 2),
                'total_net' => round($item->total_net, 2),
                'count' => $item->count,
                'average_gross' => round($item->total_gross / $item->count, 2),
            ]);

        // Monthly trend
        $monthlyTrend = Payroll::select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                DB::raw('SUM(gross_salary) as total_gross'),
                DB::raw('SUM(deductions) as total_deductions'),
                DB::raw('SUM(net_salary) as total_net'),
                DB::raw('COUNT(*) as count')
            )
            ->whereHas('employee', function($q) use ($shopOwnerId) {
                $q->where('shop_owner_id', $shopOwnerId);
            })
            ->whereBetween('created_at', [$start, $end])
            ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m")'))
            ->orderBy('month')
            ->get()
            ->map(fn($item) => [
                'month' => $item->month,
                'total_gross' => round($item->total_gross, 2),
                'total_deductions' => round($item->total_deductions, 2),
                'total_net' => round($item->total_net, 2),
                'payroll_count' => $item->count,
            ]);

        // Current active employees salary distribution
        $salaryDistribution = Employee::where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->select('salary')
            ->get()
            ->map(function($employee) {
                $salary = $employee->salary ?? 0;
                if ($salary < 30000) return '<30K';
                if ($salary < 50000) return '30K-50K';
                if ($salary < 75000) return '50K-75K';
                if ($salary < 100000) return '75K-100K';
                return '100K+';
            })
            ->countBy()
            ->map(fn($count, $range) => [
                'range' => $range,
                'count' => $count,
            ])
            ->values();

        return [
            'total_gross_salary' => round($totalGrossSalary, 2),
            'total_deductions' => round($totalDeductions, 2),
            'total_net_salary' => round($totalNetSalary, 2),
            'average_gross_salary' => round($avgGrossSalary, 2),
            'average_net_salary' => round($avgNetSalary, 2),
            'payroll_records' => $totalRecords,
            'by_department' => $byDepartment,
            'monthly_trend' => $monthlyTrend,
            'salary_distribution' => $salaryDistribution,
        ];
    }

    /**
     * Get performance distribution and metrics
     */
    public function getPerformanceDistribution($shopOwnerId, $startDate = null, $endDate = null): array
    {
        $start = Carbon::parse($startDate ?? Carbon::now()->subYear());
        $end = Carbon::parse($endDate ?? Carbon::now());

        // Performance reviews in period
        $reviewsQuery = PerformanceReview::whereHas('employee', function($q) use ($shopOwnerId) {
            $q->where('shop_owner_id', $shopOwnerId);
        })->whereBetween('review_date', [$start, $end]);

        $totalReviews = (clone $reviewsQuery)->count();
        $avgRating = (clone $reviewsQuery)->avg('overall_rating');

        // Rating distribution
        $ratingDistribution = (clone $reviewsQuery)
            ->select('overall_rating', DB::raw('COUNT(*) as count'))
            ->groupBy('overall_rating')
            ->get()
            ->map(fn($item) => [
                'rating' => $item->overall_rating,
                'count' => $item->count,
                'percentage' => $totalReviews > 0 ? round(($item->count / $totalReviews) * 100, 2) : 0,
            ]);

        // By department
        $byDepartment = PerformanceReview::select(
                'employees.department',
                DB::raw('AVG(performance_reviews.overall_rating) as avg_rating'),
                DB::raw('COUNT(*) as count')
            )
            ->join('employees', 'performance_reviews.employee_id', '=', 'employees.id')
            ->where('employees.shop_owner_id', $shopOwnerId)
            ->whereBetween('performance_reviews.review_date', [$start, $end])
            ->groupBy('employees.department')
            ->get()
            ->map(fn($item) => [
                'department' => $item->department ?? 'Unassigned',
                'average_rating' => round($item->avg_rating, 2),
                'review_count' => $item->count,
            ]);

        // Competency averages
        $competencyAverages = (clone $reviewsQuery)
            ->select(
                DB::raw('AVG(communication_skills) as communication'),
                DB::raw('AVG(teamwork_collaboration) as teamwork'),
                DB::raw('AVG(reliability_responsibility) as reliability'),
                DB::raw('AVG(productivity_efficiency) as productivity')
            )
            ->first();

        // Performance categories
        $outstanding = (clone $reviewsQuery)->where('overall_rating', '>=', 4.5)->count();
        $exceeds = (clone $reviewsQuery)->whereBetween('overall_rating', [4.0, 4.49])->count();
        $meets = (clone $reviewsQuery)->whereBetween('overall_rating', [3.0, 3.99])->count();
        $needsImprovement = (clone $reviewsQuery)->where('overall_rating', '<', 3.0)->count();

        // By status
        $byStatus = (clone $reviewsQuery)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn($item) => [
                'status' => $item->status,
                'count' => $item->count,
            ]);

        return [
            'total_reviews' => $totalReviews,
            'average_rating' => round($avgRating ?? 0, 2),
            'rating_distribution' => $ratingDistribution,
            'by_department' => $byDepartment,
            'competency_averages' => [
                'communication' => round($competencyAverages->communication ?? 0, 2),
                'teamwork' => round($competencyAverages->teamwork ?? 0, 2),
                'reliability' => round($competencyAverages->reliability ?? 0, 2),
                'productivity' => round($competencyAverages->productivity ?? 0, 2),
            ],
            'performance_categories' => [
                'outstanding' => ['count' => $outstanding, 'percentage' => $totalReviews > 0 ? round(($outstanding / $totalReviews) * 100, 2) : 0],
                'exceeds_expectations' => ['count' => $exceeds, 'percentage' => $totalReviews > 0 ? round(($exceeds / $totalReviews) * 100, 2) : 0],
                'meets_expectations' => ['count' => $meets, 'percentage' => $totalReviews > 0 ? round(($meets / $totalReviews) * 100, 2) : 0],
                'needs_improvement' => ['count' => $needsImprovement, 'percentage' => $totalReviews > 0 ? round(($needsImprovement / $totalReviews) * 100, 2) : 0],
            ],
            'by_status' => $byStatus,
        ];
    }

    /**
     * Get summary metrics for quick overview
     */
    private function getSummaryMetrics($shopOwnerId): array
    {
        $activeEmployees = Employee::where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->count();

        $departments = Department::where('shop_owner_id', $shopOwnerId)->count();

        $pendingLeaveRequests = LeaveRequest::whereHas('employee', function($q) use ($shopOwnerId) {
            $q->where('shop_owner_id', $shopOwnerId);
        })->where('status', 'pending')->count();

        $thisMonthPayroll = Payroll::whereHas('employee', function($q) use ($shopOwnerId) {
            $q->where('shop_owner_id', $shopOwnerId);
        })
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->sum('net_salary');

        return [
            'active_employees' => $activeEmployees,
            'total_departments' => $departments,
            'pending_leave_requests' => $pendingLeaveRequests,
            'this_month_payroll' => round($thisMonthPayroll, 2),
        ];
    }

    /**
     * Get individual metric endpoint - Headcount
     */
    public function headcount(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $startDate = $request->input('start_date', Carbon::now()->subYear());
        $endDate = $request->input('end_date', Carbon::now());

        return response()->json([
            'headcount' => $this->getHeadcountMetrics($user->shop_owner_id, $startDate, $endDate),
        ]);
    }

    /**
     * Get individual metric endpoint - Turnover
     */
    public function turnover(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $startDate = $request->input('start_date', Carbon::now()->subYear());
        $endDate = $request->input('end_date', Carbon::now());

        return response()->json([
            'turnover' => $this->getTurnoverRate($user->shop_owner_id, $startDate, $endDate),
        ]);
    }

    /**
     * Get individual metric endpoint - Attendance
     */
    public function attendance(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $startDate = $request->input('start_date', Carbon::now()->subMonth());
        $endDate = $request->input('end_date', Carbon::now());

        return response()->json([
            'attendance' => $this->getAttendanceStats($user->shop_owner_id, $startDate, $endDate),
        ]);
    }

    /**
     * Get individual metric endpoint - Payroll
     */
    public function payroll(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $startDate = $request->input('start_date', Carbon::now()->subYear());
        $endDate = $request->input('end_date', Carbon::now());

        return response()->json([
            'payroll' => $this->getPayrollCosts($user->shop_owner_id, $startDate, $endDate),
        ]);
    }

    /**
     * Get individual metric endpoint - Performance
     */
    public function performance(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $startDate = $request->input('start_date', Carbon::now()->subYear());
        $endDate = $request->input('end_date', Carbon::now());

        return response()->json([
            'performance' => $this->getPerformanceDistribution($user->shop_owner_id, $startDate, $endDate),
        ]);
    }
}
