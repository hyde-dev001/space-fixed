<?php

namespace App\Http\Controllers\Erp\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\HR\LeaveRequest;
use App\Models\HR\Payroll;
use App\Traits\LogsHRActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

/**
 * Employee Self-Service Portal Controller
 * 
 * Provides employees with access to their own HR information and actions.
 * All endpoints are employee-scoped and only return data for the authenticated employee.
 */
class EmployeeSelfServiceController extends Controller
{
    use LogsHRActivity;

    /**
     * Get the authenticated employee's profile
     */
    public function getProfile(Request $request)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            
            // Find employee linked to this user
            $employee = Employee::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->where('shop_owner_id', $user->shop_owner_id)
                ->with(['department'])
                ->first();

            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee profile not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'phone' => $employee->phone,
                    'department' => $employee->department?->name,
                    'position' => $employee->position,
                    'hire_date' => $employee->hire_date?->format('Y-m-d'),
                    'status' => $employee->status,
                    'salary' => $employee->salary,
                    'address' => $employee->address,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch profile',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update employee's own profile (limited fields)
     */
    public function updateProfile(Request $request)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            
            $employee = Employee::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->where('shop_owner_id', $user->shop_owner_id)
                ->first();

            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee profile not found',
                ], 404);
            }

            // Validate only fields employees can update
            $validated = $request->validate([
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:255',
            ]);

            $employee->update($validated);

            $this->logActivity('employee_profile_updated', $employee->id, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'employee' => $employee,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get employee's payslips
     */
    public function getPayslips(Request $request)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            
            $employee = Employee::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->where('shop_owner_id', $user->shop_owner_id)
                ->first();

            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee profile not found',
                ], 404);
            }

            $query = Payroll::where('employee_id', $employee->id);

            // Filter by year if provided
            if ($request->has('year')) {
                $query->whereYear('pay_period_start', $request->year);
            }

            // Filter by month if provided
            if ($request->has('month')) {
                $query->whereMonth('pay_period_start', $request->month);
            }

            $payslips = $query->orderBy('pay_period_start', 'desc')
                ->get()
                ->map(fn($payroll) => [
                    'id' => $payroll->id,
                    'period_start' => $payroll->pay_period_start->format('Y-m-d'),
                    'period_end' => $payroll->pay_period_end->format('Y-m-d'),
                    'basic_salary' => $payroll->basic_salary,
                    'allowances' => $payroll->allowances,
                    'deductions' => $payroll->deductions,
                    'gross_salary' => $payroll->gross_salary,
                    'net_salary' => $payroll->net_salary,
                    'status' => $payroll->status,
                    'generated_at' => $payroll->created_at->format('Y-m-d H:i:s'),
                ]);

            return response()->json([
                'success' => true,
                'payslips' => $payslips,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payslips',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get specific payslip details
     */
    public function getPayslipDetails($id)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            
            $employee = Employee::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->where('shop_owner_id', $user->shop_owner_id)
                ->first();

            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee profile not found',
                ], 404);
            }

            $payslip = Payroll::where('id', $id)
                ->where('employee_id', $employee->id)
                ->with(['employee.department'])
                ->first();

            if (!$payslip) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payslip not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'payslip' => [
                    'id' => $payslip->id,
                    'employee' => [
                        'name' => $payslip->employee->name,
                        'position' => $payslip->employee->position,
                        'department' => $payslip->employee->department?->name,
                    ],
                    'period_start' => $payslip->pay_period_start->format('Y-m-d'),
                    'period_end' => $payslip->pay_period_end->format('Y-m-d'),
                    'basic_salary' => $payslip->basic_salary,
                    'allowances' => $payslip->allowances,
                    'deductions' => $payslip->deductions,
                    'gross_salary' => $payslip->gross_salary,
                    'net_salary' => $payslip->net_salary,
                    'status' => $payslip->status,
                    'payment_method' => $payslip->payment_method ?? 'Bank Transfer',
                    'generated_at' => $payslip->created_at->format('Y-m-d H:i:s'),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payslip details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get employee's attendance records
     */
    public function getAttendance(Request $request)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            
            $employee = Employee::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->where('shop_owner_id', $user->shop_owner_id)
                ->first();

            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee profile not found',
                ], 404);
            }

            // Default to current month if no date range provided
            $startDate = $request->start_date 
                ? Carbon::parse($request->start_date)
                : now()->startOfMonth();
            
            $endDate = $request->end_date 
                ? Carbon::parse($request->end_date)
                : now()->endOfMonth();

            $attendance = AttendanceRecord::where('employee_id', $employee->id)
                ->whereBetween('check_in_time', [$startDate, $endDate])
                ->orderBy('check_in_time', 'desc')
                ->get()
                ->map(fn($record) => [
                    'id' => $record->id,
                    'date' => $record->check_in_time->format('Y-m-d'),
                    'check_in' => $record->check_in_time->format('H:i:s'),
                    'check_out' => $record->check_out_time?->format('H:i:s'),
                    'working_hours' => $record->working_hours,
                    'overtime_hours' => $record->overtime_hours ?? 0,
                    'status' => $record->status ?? 'present',
                    'notes' => $record->notes,
                ]);

            // Calculate summary statistics
            $totalDays = $attendance->count();
            $totalHours = $attendance->sum('working_hours');
            $totalOvertime = $attendance->sum('overtime_hours');
            $avgHours = $totalDays > 0 ? round($totalHours / $totalDays, 2) : 0;

            return response()->json([
                'success' => true,
                'period' => [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d'),
                ],
                'summary' => [
                    'total_days' => $totalDays,
                    'total_hours' => round($totalHours, 2),
                    'total_overtime' => round($totalOvertime, 2),
                    'average_hours' => $avgHours,
                ],
                'attendance' => $attendance,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attendance records',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get employee's leave requests
     */
    public function getLeaveRequests(Request $request)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            
            $employee = Employee::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->where('shop_owner_id', $user->shop_owner_id)
                ->first();

            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee profile not found',
                ], 404);
            }

            $query = LeaveRequest::where('employee_id', $employee->id);

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by year if provided
            if ($request->has('year')) {
                $query->whereYear('start_date', $request->year);
            }

            $leaveRequests = $query->orderBy('start_date', 'desc')
                ->get()
                ->map(fn($leave) => [
                    'id' => $leave->id,
                    'leave_type' => $leave->leave_type,
                    'start_date' => $leave->start_date->format('Y-m-d'),
                    'end_date' => $leave->end_date->format('Y-m-d'),
                    'days' => $leave->days,
                    'reason' => $leave->reason,
                    'status' => $leave->status,
                    'rejection_reason' => $leave->rejection_reason,
                    'applied_at' => $leave->created_at->format('Y-m-d H:i:s'),
                ]);

            return response()->json([
                'success' => true,
                'leave_requests' => $leaveRequests,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch leave requests',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Apply for leave
     */
    public function applyLeave(Request $request)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            
            $employee = Employee::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->where('shop_owner_id', $user->shop_owner_id)
                ->first();

            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee profile not found',
                ], 404);
            }

            $validated = $request->validate([
                'leave_type' => 'required|string|max:50',
                'start_date' => 'required|date|after_or_equal:today',
                'end_date' => 'required|date|after_or_equal:start_date',
                'reason' => 'required|string|max:500',
            ]);

            // Calculate days
            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);
            $days = $startDate->diffInDays($endDate) + 1;

            $leaveRequest = LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type' => $validated['leave_type'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'days' => $days,
                'reason' => $validated['reason'],
                'status' => 'pending',
                'shop_owner_id' => $user->shop_owner_id,
            ]);

            $this->logActivity('leave_request_submitted', $leaveRequest->id, [
                'employee_id' => $employee->id,
                'leave_type' => $validated['leave_type'],
                'days' => $days,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Leave request submitted successfully',
                'leave_request' => $leaveRequest,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit leave request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get employee's leave balance
     */
    public function getLeaveBalance()
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            
            $employee = Employee::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->where('shop_owner_id', $user->shop_owner_id)
                ->first();

            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee profile not found',
                ], 404);
            }

            // Get current year leave balance
            $currentYear = now()->year;
            
            // Calculate leave usage
            $usedLeave = LeaveRequest::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->whereYear('start_date', $currentYear)
                ->sum('days');

            // Assume 20 days annual leave (should come from policy)
            $totalLeave = 20;
            $remainingLeave = $totalLeave - $usedLeave;

            return response()->json([
                'success' => true,
                'leave_balance' => [
                    'year' => $currentYear,
                    'total' => $totalLeave,
                    'used' => $usedLeave,
                    'remaining' => max(0, $remainingLeave),
                    'pending' => LeaveRequest::where('employee_id', $employee->id)
                        ->where('status', 'pending')
                        ->whereYear('start_date', $currentYear)
                        ->sum('days'),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch leave balance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get dashboard summary for employee
     */
    public function getDashboard()
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            
            $employee = Employee::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->where('shop_owner_id', $user->shop_owner_id)
                ->first();

            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee profile not found',
                ], 404);
            }

            // Get quick stats
            $currentMonth = now()->month;
            $currentYear = now()->year;

            $attendanceThisMonth = AttendanceRecord::where('employee_id', $employee->id)
                ->whereMonth('check_in_time', $currentMonth)
                ->whereYear('check_in_time', $currentYear)
                ->count();

            $pendingLeaves = LeaveRequest::where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->count();

            $latestPayslip = Payroll::where('employee_id', $employee->id)
                ->orderBy('pay_period_start', 'desc')
                ->first();

            $usedLeaveThisYear = LeaveRequest::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->whereYear('start_date', $currentYear)
                ->sum('days');

            return response()->json([
                'success' => true,
                'dashboard' => [
                    'employee' => [
                        'name' => $employee->name,
                        'position' => $employee->position,
                        'department' => $employee->department?->name,
                    ],
                    'attendance_this_month' => $attendanceThisMonth,
                    'pending_leave_requests' => $pendingLeaves,
                    'leave_used_this_year' => $usedLeaveThisYear,
                    'latest_payslip' => $latestPayslip ? [
                        'id' => $latestPayslip->id,
                        'period' => $latestPayslip->pay_period_start->format('M Y'),
                        'net_salary' => $latestPayslip->net_salary,
                    ] : null,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Change employee password
     */
    public function changePassword(Request $request)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $validated = $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            $user = auth()->user();

            // Verify current password
            if (!Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect',
                ], 422);
            }

            // Update password
            $user->password = Hash::make($validated['new_password']);
            $user->save();

            $this->logActivity('employee_password_changed', $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to change password',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
