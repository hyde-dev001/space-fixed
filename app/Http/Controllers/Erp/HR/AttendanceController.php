<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\AttendanceRecord;
use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Services\HR\LatenessTrackingService;
use App\Traits\HR\LogsHRActivity;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    use LogsHRActivity;
    
    protected LatenessTrackingService $latenessService;
    
    public function __construct(LatenessTrackingService $latenessService)
    {
        $this->latenessService = $latenessService;
    }
    /**
     * Display a listing of attendance records.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('view-attendance')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = AttendanceRecord::forShopOwner($user->shop_owner_id)
            ->with('employee:id,first_name,last_name,name,email,department,position,shop_owner_id');

        // Apply filters
        if ($request->filled('employee_id')) {
            $query->forEmployee($request->employee_id);
        }

        if ($request->filled('department')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('department', $request->department);
            });
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('date', [$request->date_from, $request->date_to]);
        } elseif ($request->filled('date')) {
            $query->where('date', $request->date);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $attendance = $query->orderBy('date', 'desc')
            ->orderBy('check_in_time', 'asc')
            ->paginate($request->get('per_page', 20));

        return response()->json($attendance);
    }

    /**
     * Store a new attendance record.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'check_in_time' => 'nullable|date_format:H:i',
            'check_out_time' => 'nullable|date_format:H:i|after:check_in_time',
            'status' => 'required|in:present,absent,late,half-day',
            'biometric_id' => 'nullable|string',
            'notes' => 'nullable|string|max:500',
            'lateness_reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if employee belongs to the same shop owner
        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->findOrFail($request->employee_id);

        // Check if attendance record already exists for this date
        $existingRecord = AttendanceRecord::forEmployee($request->employee_id)
            ->where('date', $request->date)
            ->first();

        if ($existingRecord) {
            return response()->json([
                'error' => 'Attendance record already exists for this date'
            ], 422);
        }

        $data = $validator->validated();
        $data['shop_owner_id'] = $user->shop_owner_id;

        $attendance = AttendanceRecord::create($data);

        return response()->json([
            'message' => 'Attendance record created successfully',
            'attendance' => $attendance->load('employee')
        ], 201);
    }

    /**
     * Display the specified attendance record.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $attendance = AttendanceRecord::forShopOwner($user->shop_owner_id)
            ->with('employee')
            ->findOrFail($id);

        return response()->json($attendance);
    }

    /**
     * Update the specified attendance record.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $attendance = AttendanceRecord::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'check_in_time' => 'nullable|date_format:H:i',
            'check_out_time' => 'nullable|date_format:H:i|after:check_in_time',
            'status' => 'sometimes|required|in:present,absent,late,half-day',
            'biometric_id' => 'nullable|string',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $attendance->update($validator->validated());

        return response()->json([
            'message' => 'Attendance record updated successfully',
            'attendance' => $attendance->load('employee')
        ]);
    }

    /**
     * Remove the specified attendance record.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $attendance = AttendanceRecord::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        $attendance->delete();

        return response()->json(['message' => 'Attendance record deleted successfully']);
    }

    /**
     * Check in an employee.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'biometric_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if employee belongs to the same shop owner
        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->findOrFail($request->employee_id);

        $today = Carbon::today()->toDateString();

        // Check if employee already checked in today
        $existingRecord = AttendanceRecord::forEmployee($request->employee_id)
            ->where('date', $today)
            ->first();

        if ($existingRecord && $existingRecord->check_in_time) {
            return response()->json([
                'error' => 'Employee already checked in today'
            ], 422);
        }

        $now = Carbon::now();
        $checkInTime = $now->format('H:i');

        // Determine status based on check-in time
        $standardTime = Carbon::parse('08:00:00');
        $status = $now->gt($standardTime) ? 'late' : 'present';

        if ($existingRecord) {
            // Update existing record
            $existingRecord->update([
                'check_in_time' => $checkInTime,
                'status' => $status,
                'biometric_id' => $request->biometric_id,
            ]);
            $attendance = $existingRecord;
        } else {
            // Create new record
            $attendance = AttendanceRecord::create([
                'employee_id' => $request->employee_id,
                'date' => $today,
                'check_in_time' => $checkInTime,
                'status' => $status,
                'biometric_id' => $request->biometric_id,
                'shop_owner_id' => $user->shop_owner_id,
            ]);
        }

        return response()->json([
            'message' => 'Employee checked in successfully',
            'attendance' => $attendance->load('employee')
        ]);
    }

    /**
     * Check out an employee.
     */
    public function checkOut(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if employee belongs to the same shop owner
        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->findOrFail($request->employee_id);

        $today = Carbon::today()->toDateString();

        $attendance = AttendanceRecord::forEmployee($request->employee_id)
            ->where('date', $today)
            ->first();

        if (!$attendance || !$attendance->check_in_time) {
            return response()->json([
                'error' => 'Employee has not checked in today'
            ], 422);
        }

        if ($attendance->check_out_time) {
            return response()->json([
                'error' => 'Employee already checked out today'
            ], 422);
        }

        $attendance->update([
            'check_out_time' => Carbon::now()->format('H:i'),
        ]);

        return response()->json([
            'message' => 'Employee checked out successfully',
            'attendance' => $attendance->load('employee')
        ]);
    }

    /**
     * Get attendance statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employeeId = $request->get('employee_id');
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->endOfMonth()->toDateString());

        if ($employeeId) {
            // Check if employee belongs to the same shop owner
            Employee::forShopOwner($user->shop_owner_id)
                ->findOrFail($employeeId);

            $stats = AttendanceRecord::getAttendanceStats($employeeId, $startDate, $endDate);
        } else {
            // Get overall stats for all employees
            $query = AttendanceRecord::forShopOwner($user->shop_owner_id)
                ->betweenDates($startDate, $endDate);

            $totalRecords = $query->count();
            $presentRecords = $query->withStatus('present')->count();
            $absentRecords = $query->withStatus('absent')->count();
            $lateRecords = $query->withStatus('late')->count();
            $halfDayRecords = $query->withStatus('half-day')->count();

            $stats = [
                'totalDays' => $totalRecords,
                'presentDays' => $presentRecords,
                'absentDays' => $absentRecords,
                'lateDays' => $lateRecords,
                'halfDays' => $halfDayRecords,
                'attendanceRate' => $totalRecords > 0 ? round(($presentRecords / $totalRecords) * 100, 2) : 0,
                'period' => $startDate . ' to ' . $endDate,
            ];
        }

        return response()->json($stats);
    }

    /**
     * Get today's attendance summary.
     */
    public function todaySummary(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if ($user->role !== 'HR') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $today = Carbon::today()->toDateString();
        $totalEmployees = Employee::forShopOwner($user->shop_owner_id)->active()->count();

        $todayAttendance = AttendanceRecord::forShopOwner($user->shop_owner_id)
            ->where('date', $today)
            ->get();

        $present = $todayAttendance->where('status', 'present')->count();
        $late = $todayAttendance->where('status', 'late')->count();
        $absent = $totalEmployees - $todayAttendance->count();
        $halfDay = $todayAttendance->where('status', 'half-day')->count();

        return response()->json([
            'date' => $today,
            'totalEmployees' => $totalEmployees,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'halfDay' => $halfDay,
            'attendanceRate' => $totalEmployees > 0 ? round((($present + $late + $halfDay) / $totalEmployees) * 100, 2) : 0,
        ]);
    }

    /**
     * Self check-in for staff/managers.
     * Staff/managers can record their own attendance.
     */
    public function selfCheckIn(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user has staff role using Spatie permissions
        $hasStaffAccess = $user->hasRole(['STAFF', 'Manager', 'shop_owner']) || 
                         $user->role === 'STAFF' || 
                         $user->role === 'MANAGER' || 
                         $user->role === 'shop_owner';
        
        if (!$hasStaffAccess) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Use shop timezone for all date/time operations
        $shopTimezone = config('app.shop_timezone', 'Asia/Manila');
        $today = Carbon::now($shopTimezone)->toDateString();
        $now = Carbon::now($shopTimezone);
        $checkInTime = $now->format('H:i');

        // Find or create employee record
        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (!$employee) {
            // Auto-create employee record from user data
            $employee = Employee::create([
                'shop_owner_id' => $user->shop_owner_id,
                'email' => $user->email,
                'first_name' => $user->first_name ?? 'Staff',
                'last_name' => $user->last_name ?? 'Member',
                'name' => $user->name ?? ($user->first_name . ' ' . $user->last_name),
                'position' => $user->hasRole('Manager') ? 'Manager' : 'Staff',
                'department' => $user->hasRole('Manager') ? 'Manager' : 'General',
                'status' => 'active',
                'hire_date' => $today,
            ]);
        }

        // Check if employee has an approved leave request for today
        $approvedLeave = \App\Models\HR\LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->first();

        if ($approvedLeave) {
            return response()->json([
                'error' => 'You have an approved leave request for today',
                'message' => 'You cannot clock in because you have an approved ' . ucfirst($approvedLeave->leave_type) . ' leave from ' . 
                    Carbon::parse($approvedLeave->start_date)->format('M d, Y') . ' to ' . 
                    Carbon::parse($approvedLeave->end_date)->format('M d, Y') . '.',
                'leave_type' => $approvedLeave->leave_type,
                'start_date' => $approvedLeave->start_date,
                'end_date' => $approvedLeave->end_date,
            ], 422);
        }

        // Check if user already checked in today (and hasn't clocked out yet)
        $existingRecord = AttendanceRecord::where('employee_id', $employee->id)
            ->where('date', $today)
            ->first();

        if ($existingRecord && $existingRecord->check_in_time && !$existingRecord->check_out_time) {
            return response()->json([
                'error' => 'You have already checked in and have not clocked out yet',
                'check_in_time' => $existingRecord->check_in_time
            ], 422);
        }
        
        // If employee already clocked out today, they cannot check in again for the same day
        if ($existingRecord && $existingRecord->check_out_time) {
            return response()->json([
                'error' => 'You have already completed your shift for today',
                'message' => 'You checked in at ' . $existingRecord->check_in_time . ' and checked out at ' . $existingRecord->check_out_time . '. If you need to work additional hours, please submit an overtime request.',
                'check_in_time' => $existingRecord->check_in_time,
                'check_out_time' => $existingRecord->check_out_time
            ], 422);
        }
        
        $isSecondShift = false;
        
        // Get shop hours (needed for status determination)
        $shopOwner = \App\Models\ShopOwner::find($user->shop_owner_id);
        $dayOfWeek = strtolower($now->format('l')); // monday, tuesday, etc.
        $openField = $dayOfWeek . '_open';
        $shopOpenTimeValue = $shopOwner ? $shopOwner->{$openField} : null;
        
        $isEarly = false;
        $minutesEarly = 0;
        $expectedCheckIn = null;
        
        if (!$isSecondShift && $shopOpenTimeValue) {
            // Only validate early check-in for first shift
            $expectedCheckIn = substr($shopOpenTimeValue, 0, 5); // HH:MM format
            // Parse shop open time for TODAY using shop timezone
            $shopOpenTime = Carbon::now($shopTimezone)->startOfDay()->setTimeFromTimeString($shopOpenTimeValue);
            
            // Grace period: Allow check-in up to 30 minutes before shop opens
            $earliestAllowedTime = $shopOpenTime->copy()->subMinutes(30);
            
            // Calculate if checking in too early (more than 30 minutes before opening)
            // Only block if current time is BEFORE the earliest allowed time
            if ($now->lt($earliestAllowedTime)) {
                $minutesTooEarly = ceil($now->diffInMinutes($earliestAllowedTime, true));
                return response()->json([
                    'error' => 'Too early to check in',
                    'message' => "Shop opens at {$expectedCheckIn}. You can check in starting from {$earliestAllowedTime->format('H:i')}.",
                    'shop_open_time' => $expectedCheckIn,
                    'earliest_check_in' => $earliestAllowedTime->format('H:i'),
                    'minutes_too_early' => $minutesTooEarly,
                ], 422);
            }
            
            // Check if early (within 30 minute grace period but before opening)
            // Only mark as early if BEFORE shop open time AND after earliest allowed
            if ($now->lt($shopOpenTime) && $now->gte($earliestAllowedTime)) {
                $isEarly = true;
                $minutesEarly = $now->diffInMinutes($shopOpenTime);
            }
        }
        
        // Determine status based on check-in time
        $standardTime = $shopOpenTimeValue 
            ? Carbon::now($shopTimezone)->startOfDay()->setTimeFromTimeString($shopOpenTimeValue)
            : Carbon::parse('08:00:00', $shopTimezone);
        
        $status = $now->gt($standardTime) ? 'late' : 'present';

        if ($existingRecord) {
            // Update existing record
            $existingRecord->update([
                'check_in_time' => $checkInTime,
                'status' => $status,
                'is_early' => $isEarly,
                'minutes_early' => $minutesEarly,
                'expected_check_in' => $expectedCheckIn,
            ]);
            $attendance = $existingRecord;
        } else {
            // Create new record
            $attendance = AttendanceRecord::create([
                'employee_id' => $employee->id,
                'date' => $today,
                'check_in_time' => $checkInTime,
                'status' => $status,
                'shop_owner_id' => $user->shop_owner_id,
                'is_early' => $isEarly,
                'minutes_early' => $minutesEarly,
                'expected_check_in' => $expectedCheckIn,
            ]);
        }

        // Refresh to get calculated lateness fields
        $attendance->refresh();

        return response()->json([
            'message' => 'Checked in successfully',
            'attendance' => [
                'id' => $attendance->id,
                'date' => $attendance->date,
                'check_in_time' => $attendance->check_in_time,
                'check_out_time' => $attendance->check_out_time,
                'status' => $attendance->status,
                'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                'is_late' => $attendance->is_late,
                'minutes_late' => $attendance->minutes_late,
                'is_early' => $attendance->is_early,
                'minutes_early' => $attendance->minutes_early,
                'expected_check_in' => $attendance->expected_check_in,
                'expected_check_out' => $attendance->expected_check_out,
                'is_early_departure' => $attendance->is_early_departure,
                'minutes_early_departure' => $attendance->minutes_early_departure,
            ]
        ]);
    }

    /**
     * Self check-out for staff/managers.
     */
    public function selfCheckOut(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user has staff role using Spatie permissions
        $hasStaffAccess = $user->hasRole(['STAFF', 'Manager', 'shop_owner']) || 
                         $user->role === 'STAFF' || 
                         $user->role === 'MANAGER' || 
                         $user->role === 'shop_owner';
        
        if (!$hasStaffAccess) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Use shop timezone for all date/time operations
        $shopTimezone = config('app.shop_timezone', 'Asia/Manila');
        $today = Carbon::now($shopTimezone)->toDateString();
        $now = Carbon::now($shopTimezone);
        $checkOutTime = $now->format('H:i');

        // Find employee record by user email
        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (!$employee) {
            return response()->json([
                'error' => 'You need to check in first. No attendance record found.'
            ], 404);
        }

        $attendance = AttendanceRecord::where('employee_id', $employee->id)
            ->where('date', $today)
            ->first();

        if (!$attendance || !$attendance->check_in_time) {
            return response()->json([
                'error' => 'You have not checked in today'
            ], 422);
        }

        if ($attendance->check_out_time) {
            return response()->json([
                'error' => 'You have already checked out today',
                'check_out_time' => $attendance->check_out_time
            ], 422);
        }

        // Calculate working hours
        $checkInDateTime = Carbon::parse($attendance->date)->setTimeFromTimeString($attendance->check_in_time);
        $checkOutDateTime = Carbon::parse($today)->setTimeFromTimeString($checkOutTime);
        $totalHours = $checkOutDateTime->diffInHours($checkInDateTime, true);
        
        // SEAMLESS OVERTIME: Automatically calculate overtime hours
        $regularHours = 0;
        $overtimeHours = 0;
        
        // Check if employee has approved overtime for today
        $approvedOvertime = OvertimeRequest::where('employee_id', $employee->id)
            ->where('overtime_date', $today)
            ->whereIn('status', ['approved', 'assigned'])
            ->first();
        
        // Get shop closing time (regular shift end)
        $shopOwner = \App\Models\ShopOwner::find($user->shop_owner_id);
        $dayOfWeek = strtolower(Carbon::parse($today)->format('l'));
        $closeField = $dayOfWeek . '_close';
        $shopCloseTime = $shopOwner ? $shopOwner->{$closeField} : '17:00:00';
        
        // Parse times for comparison
        $regularEndTime = Carbon::parse($today)->setTimeFromTimeString($shopCloseTime);
        
        if ($approvedOvertime && $checkOutDateTime->greaterThan($regularEndTime)) {
            // Employee worked beyond regular hours with approved OT
            $regularHours = $regularEndTime->diffInHours($checkInDateTime, true);
            $overtimeHours = $checkOutDateTime->diffInHours($regularEndTime, true);
            
            // Cap overtime at approved amount
            $approvedOvertimeHours = $approvedOvertime->hours;
            if ($overtimeHours > $approvedOvertimeHours) {
                $overtimeHours = $approvedOvertimeHours;
            }
            
            // Update overtime request with actual hours worked
            $approvedOvertime->update([
                'actual_hours' => $overtimeHours,
                'actual_start_time' => $regularEndTime->format('H:i:s'),
                'actual_end_time' => $checkOutTime,
                'checked_out_at' => $now,
            ]);
            
            \Log::info('Overtime hours automatically calculated', [
                'employee_id' => $employee->id,
                'regular_hours' => $regularHours,
                'overtime_hours' => $overtimeHours,
                'approved_hours' => $approvedOvertimeHours,
            ]);
        } else {
            // No overtime or didn't work past regular hours
            $regularHours = $totalHours;
        }

        $attendance->update([
            'check_out_time' => $checkOutTime,
            'working_hours' => round($regularHours, 2),
            'overtime_hours' => round($overtimeHours, 2),
        ]);

        return response()->json([
            'message' => 'Checked out successfully',
            'attendance' => [
                'id' => $attendance->id,
                'date' => $attendance->date,
                'check_in_time' => $attendance->check_in_time,
                'check_out_time' => $attendance->check_out_time,
                'working_hours' => $attendance->working_hours,
                'overtime_hours' => $attendance->overtime_hours,
                'total_hours' => $attendance->working_hours + $attendance->overtime_hours,
                'status' => $attendance->status,
            ]
        ]);
    }

    /**
     * Check attendance status for current day (includes overtime extension info)
     */
    public function checkStatus(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        $hasStaffAccess = $user->hasRole(['STAFF', 'Manager', 'shop_owner']) || 
                         $user->role === 'STAFF' || 
                         $user->role === 'MANAGER' || 
                         $user->role === 'shop_owner';
        
        if (!$hasStaffAccess) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $shopTimezone = config('app.shop_timezone', 'Asia/Manila');
        $today = Carbon::now($shopTimezone)->toDateString();
        
        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();
        
        if (!$employee) {
            return response()->json(['error' => 'Employee record not found'], 404);
        }
        
        $attendance = AttendanceRecord::where('employee_id', $employee->id)
            ->where('date', $today)
            ->first();
        
        // Check for approved overtime for today (seamless overtime integration)
        $approvedOvertime = OvertimeRequest::where('employee_id', $employee->id)
            ->where('overtime_date', $today)
            ->whereIn('status', ['approved', 'assigned'])
            ->first();
        
        $response = [
            'checked_in' => $attendance && $attendance->check_in_time ? true : false,
            'checked_out' => $attendance && $attendance->check_out_time ? true : false,
            'check_in_time' => $attendance ? $attendance->check_in_time : null,
            'check_out_time' => $attendance ? $attendance->check_out_time : null,
            'status' => $attendance ? $attendance->status : 'pending',
            'working_hours' => $attendance ? $attendance->working_hours : 0,
        ];
        
        // SEAMLESS OVERTIME: Include extended schedule information
        if ($approvedOvertime) {
            $response['has_approved_overtime'] = true;
            $response['overtime_end_time'] = $approvedOvertime->end_time;
            $response['overtime_hours'] = $approvedOvertime->hours;
            $response['overtime_id'] = $approvedOvertime->id;
            $response['adjusted_checkout_time'] = $approvedOvertime->end_time;
            $response['overtime_reason'] = $approvedOvertime->reason;
        } else {
            $response['has_approved_overtime'] = false;
        }
        
        return response()->json($response);
    }
    
    /**
     * Get my attendance records (for staff/managers).
     */
    public function myRecords(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user has staff role using Spatie permissions
        $hasStaffAccess = $user->hasRole(['STAFF', 'Manager', 'shop_owner']) || 
                         $user->role === 'STAFF' || 
                         $user->role === 'MANAGER' || 
                         $user->role === 'shop_owner';
        
        if (!$hasStaffAccess) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Find or create employee record
        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (!$employee) {
            // Auto-create employee record from user data
            $employee = Employee::create([
                'shop_owner_id' => $user->shop_owner_id,
                'email' => $user->email,
                'first_name' => $user->first_name ?? 'Staff',
                'last_name' => $user->last_name ?? 'Member',
                'name' => $user->name ?? ($user->first_name . ' ' . $user->last_name),
                'position' => $user->hasRole('Manager') ? 'Manager' : 'Staff',
                'department' => $user->hasRole('Manager') ? 'Manager' : 'General',
                'status' => 'active',
                'hire_date' => Carbon::today()->toDateString(),
            ]);
        }

        $query = AttendanceRecord::where('employee_id', $employee->id)
            ->orderBy('date', 'desc');

        // Apply date filters
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->betweenDates($request->date_from, $request->date_to);
        } elseif ($request->filled('month')) {
            $month = Carbon::parse($request->month);
            $query->whereYear('date', $month->year)
                  ->whereMonth('date', $month->month);
        } else {
            // Default to current month
            $query->whereYear('date', Carbon::now()->year)
                  ->whereMonth('date', Carbon::now()->month);
        }

        $records = $query->paginate($request->get('per_page', 30));

        return response()->json($records);
    }

    /**
     * Get lateness statistics
     */
    public function getLatenessStats(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('view-attendance')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $stats = $this->latenessService->getLatenessStats(
            $user->shop_owner_id,
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json($stats);
    }

    /**
     * Get employees with most lateness
     */
    public function getMostLateEmployees(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('view-attendance')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employees = $this->latenessService->getMostLateEmployees(
            $user->shop_owner_id,
            $request->get('limit', 10),
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json($employees);
    }

    /**
     * Get lateness report for a specific employee
     */
    public function getEmployeeLatenessReport(Request $request, $employeeId): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('view-attendance')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Verify employee belongs to shop owner
        $employee = Employee::forShopOwner($user->shop_owner_id)->findOrFail($employeeId);

        $report = $this->latenessService->getEmployeeLatenessReport(
            $employeeId,
            $user->shop_owner_id,
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json($report);
    }

    /**
     * Get daily lateness summary
     */
    public function getDailyLatenessSummary(Request $request, $date): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('view-attendance')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $summary = $this->latenessService->getDailyLatenessSummary(
            $user->shop_owner_id,
            $date
        );

        return response()->json($summary);
    }

    /**
     * Get lateness trends by day of week
     */
    public function getLatenessTrends(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('view-attendance')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $trends = $this->latenessService->getLatenessTrendsByDayOfWeek(
            $user->shop_owner_id,
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json($trends);
    }

    /**
     * Get shop hours for today (staff self-service)
     */
    public function getShopHoursToday(): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user || !$user->shop_owner_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $shopOwner = \App\Models\ShopOwner::find($user->shop_owner_id);
        
        if (!$shopOwner) {
            return response()->json(['error' => 'Shop not found'], 404);
        }

        $today = strtolower(now()->format('l')); // monday, tuesday, etc. (lowercase)
        
        $openField = $today . '_open';
        $closeField = $today . '_close';
        
        $openTime = $shopOwner->{$openField};
        $closeTime = $shopOwner->{$closeField};
        
        return response()->json([
            'day' => ucfirst($today),
            'is_open' => !empty($openTime) && !empty($closeTime),
            'open' => $openTime ? substr($openTime, 0, 5) : null,
            'close' => $closeTime ? substr($closeTime, 0, 5) : null,
        ]);
    }

    /**
     * Get employee's own lateness statistics (staff self-service)
     */
    public function getMyLatenessStats(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user || !$user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Find employee record for this user
        $employee = Employee::where('email', $user->email)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee record not found'], 404);
        }

        // Get stats for current month by default
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->endOfMonth()->format('Y-m-d'));

        $stats = $this->latenessService->getEmployeeLatenessReport(
            $employee->id,
            $user->shop_owner_id,
            $startDate,
            $endDate
        );

        return response()->json($stats);
    }

    /**
     * Add lateness reason to an attendance record (staff self-service)
     */
    public function addLatenessReason(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user || !$user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'lateness_reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Find attendance record
        $attendance = AttendanceRecord::where('id', $id)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->first();

        if (!$attendance) {
            return response()->json(['error' => 'Attendance record not found'], 404);
        }

        // Verify this is the employee's own record
        $employee = Employee::where('email', $user->email)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->first();

        if (!$employee || $attendance->employee_id !== $employee->id) {
            return response()->json(['error' => 'Unauthorized - can only update your own records'], 403);
        }

        $attendance->lateness_reason = $request->lateness_reason;
        $attendance->save();

        return response()->json([
            'message' => 'Lateness reason added successfully',
            'attendance' => $attendance
        ]);
    }
    
    /**
     * Add early check-in reason to attendance record.
     */
    public function addEarlyReason(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user || !$user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'early_reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Find attendance record
        $attendance = AttendanceRecord::where('id', $id)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->first();

        if (!$attendance) {
            return response()->json(['error' => 'Attendance record not found'], 404);
        }

        // Verify this is the employee's own record
        $employee = Employee::where('email', $user->email)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->first();

        if (!$employee || $attendance->employee_id !== $employee->id) {
            return response()->json(['error' => 'Unauthorized - can only update your own records'], 403);
        }

        $attendance->early_reason = $request->early_reason;
        $attendance->save();

        return response()->json([
            'message' => 'Early check-in reason added successfully',
            'attendance' => $attendance
        ]);
    }
    
    /**
     * Get attendance records for an employee within a date range (for payroll calculation).
     */
    public function getByEmployee(Request $request, $employeeId): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if employee belongs to the same shop owner
        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->findOrFail($employeeId);

        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $attendanceRecords = AttendanceRecord::forEmployee($employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'asc')
            ->get();

        // Calculate summary statistics
        $totalPresent = $attendanceRecords->where('status', 'present')->count();
        $totalAbsent = $attendanceRecords->where('status', 'absent')->count();
        $totalLate = $attendanceRecords->where('status', 'late')->count();
        $totalHalfDay = $attendanceRecords->where('status', 'half-day')->count();
        
        // Calculate hours
        $totalRegularHours = 0;
        $totalOvertimeHours = 0;
        $totalUndertimeHours = 0;

        foreach ($attendanceRecords as $record) {
            // Use working_hours field if available (most reliable)
            if ($record->working_hours !== null && $record->working_hours > 0) {
                $totalRegularHours += $record->working_hours;
            } elseif ($record->status === 'present' || $record->status === 'late') {
                // If no working hours but marked present/late, assume 8 hours
                $totalRegularHours += 8;
            } elseif ($record->status === 'half_day') {
                $totalRegularHours += 4;
            }
            // Absent days contribute 0 hours (already handled by not adding anything)
            
            // Add overtime hours from the overtime_hours field (includes approved overtime sessions)
            if ($record->overtime_hours !== null) {
                $totalOvertimeHours += $record->overtime_hours;
            }
        }

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'employee_id' => $employee->employee_id,
            ],
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => [
                'total_records' => $attendanceRecords->count(),
                'total_present' => $totalPresent,
                'total_absent' => $totalAbsent,
                'total_late' => $totalLate,
                'total_half_day' => $totalHalfDay,
                'total_regular_hours' => round($totalRegularHours, 2),
                'total_overtime_hours' => round($totalOvertimeHours, 2),
                'total_undertime_hours' => round($totalUndertimeHours, 2),
            ],
            'records' => $attendanceRecords->map(function ($record) {
                return [
                    'id' => $record->id,
                    'date' => $record->date,
                    'status' => $record->status,
                    'check_in_time' => $record->check_in_time,
                    'check_out_time' => $record->check_out_time,
                    'notes' => $record->notes,
                ];
            }),
        ]);
    }
}
