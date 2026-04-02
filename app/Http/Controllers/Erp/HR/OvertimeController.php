<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\OvertimeRequest;
use App\Models\HR\LeaveRequest;
use App\Models\Employee;
use App\Services\HR\OvertimeApprovalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class OvertimeController extends Controller
{
    public function __construct(
        private OvertimeApprovalService $overtimeApprovalService,
        private NotificationService $notificationService
    ) {}

    /**
     * Parse shop time values (HH:MM or HH:MM:SS) into Carbon time.
     */
    private function parseClockTime(string $time, string $fallback = '17:00:00'): Carbon
    {
        $value = trim($time);
        if ($value === '') {
            return Carbon::createFromFormat('H:i:s', $fallback);
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }

        try {
            return Carbon::createFromFormat('H:i:s', $value);
        } catch (\Throwable $e) {
            return Carbon::createFromFormat('H:i:s', $fallback);
        }
    }

    /**
     * Build overtime window using shop's regular closing time as OT start.
     */
    private function buildOvertimeWindow(int $shopOwnerId, Carbon $overtimeDate, float $hours): array
    {
        $startTime = Carbon::createFromFormat('H:i:s', '17:00:00');

        $shopOwner = \App\Models\ShopOwner::find($shopOwnerId);
        if ($shopOwner) {
            $dayKey = strtolower($overtimeDate->format('l')) . '_close';
            $closeRaw = (string) ($shopOwner->{$dayKey} ?? '');
            $startTime = $this->parseClockTime($closeRaw, '17:00:00');
        }

        $durationMinutes = (int) round($hours * 60);
        $endTime = $startTime->copy()->addMinutes($durationMinutes);

        return [
            'start' => $startTime,
            'end' => $endTime,
        ];
    }
    /**
     * Staff submits overtime request
     */
    public function staffRequest(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // All authenticated employees may submit overtime regardless of role
        if (!$user || !$user->shop_owner_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'hours' => 'required|numeric|min:0.5|max:12',
            'reason' => 'required|string|max:500',
            'overtime_date' => 'nullable|date',
            'work_description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
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

        $shopTimezone = config('app.shop_timezone', 'Asia/Manila');

        // Use provided date or default to today in shop timezone
        $overtimeDate = $request->filled('overtime_date') 
            ? Carbon::parse($request->overtime_date, $shopTimezone)
            : Carbon::now($shopTimezone);

        $overtimeDateString = $overtimeDate->toDateString();

        // Restriction: employees on approved leave cannot request overtime for that leave date.
        $approvedLeave = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $overtimeDateString)
            ->whereDate('end_date', '>=', $overtimeDateString)
            ->first();

        if ($approvedLeave) {
            return response()->json([
                'error' => 'You cannot request overtime while on approved leave.',
                'leave_period' => [
                    'start_date' => optional($approvedLeave->start_date)->toDateString(),
                    'end_date' => optional($approvedLeave->end_date)->toDateString(),
                ],
            ], 422);
        }

        // Check if employee already has overtime for this date
        $existingOvertime = OvertimeRequest::where('employee_id', $employee->id)
            ->where('overtime_date', $overtimeDateString)
            ->whereIn('status', ['pending', 'approved', 'assigned'])
            ->first();

        if ($existingOvertime) {
            return response()->json([
                'error' => 'You already have an overtime request for this date',
                'existing_overtime' => [
                    'date' => $existingOvertime->overtime_date,
                    'hours' => $existingOvertime->hours,
                    'status' => $existingOvertime->status,
                ]
            ], 422);
        }

        $hours = (float) $request->hours;
        $window = $this->buildOvertimeWindow((int) $user->shop_owner_id, $overtimeDate, $hours);
        $startTime = $window['start'];
        $endTime = $window['end'];

        // Determine overtime type based on date
        $dayOfWeek = $overtimeDate->dayOfWeek;
        $overtimeType = ($dayOfWeek === 0 || $dayOfWeek === 6) ? 'weekend' : 'weekday';

        // Calculate amount if employee has hourly rate
        $calculatedAmount = null;
        if ($employee->hourly_rate) {
            $rateMultiplier = ($overtimeType === 'weekend') ? 2.00 : 1.50;
            $calculatedAmount = $employee->hourly_rate * $hours * $rateMultiplier;
        }

        $overtimeRequest = OvertimeRequest::create([
            'shop_owner_id' => $user->shop_owner_id,
            'employee_id' => $employee->id,
            'overtime_date' => $overtimeDate,
            'start_time' => $startTime->format('H:i:s'),
            'end_time' => $endTime->format('H:i:s'),
            'hours' => $hours,
            'rate_multiplier' => ($overtimeType === 'weekend') ? 2.00 : 1.50,
            'calculated_amount' => $calculatedAmount,
            'overtime_type' => $overtimeType,
            'reason' => $request->reason,
            'work_description' => $request->work_description,
            'status' => 'pending',
        ]);

        // Centralized submit transition notification hook
        try {
            $this->overtimeApprovalService->notifyOvertimeSubmitted($overtimeRequest->fresh(), $employee);
        } catch (\Throwable $e) {
            \Log::error('Failed to send overtime submitted notification', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Overtime request submitted successfully',
            'overtime_request' => [
                'id' => $overtimeRequest->id,
                'overtime_date' => $overtimeRequest->overtime_date,
                'hours' => $overtimeRequest->hours,
                'reason' => $overtimeRequest->reason,
                'status' => $overtimeRequest->status,
                'overtime_type' => $overtimeRequest->overtime_type,
                'calculated_amount' => $overtimeRequest->calculated_amount,
            ]
        ], 201);
    }

    /**
     * Get staff's own overtime requests
     */
    public function staffMyRequests(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // All authenticated employees may view their overtime requests regardless of role
        if (!$user || !$user->shop_owner_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (!$employee) {
            return response()->json(['data' => []]);
        }

        $query = OvertimeRequest::where('employee_id', $employee->id)
            ->where('shop_owner_id', $user->shop_owner_id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $requests = $query->orderBy('overtime_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($requests);
    }

    /**
     * List all overtime requests (for managers/HR)
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (! $user->hasRole('Manager') && ! $user->can('access-overtime-approvals') && ! $user->can('access-attendance-records')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = OvertimeRequest::forShopOwner($user->shop_owner_id)
            ->with('employee:id,first_name,last_name,name,email,position,department,shop_owner_id');

        if ($request->filled('employee_id')) {
            $query->forEmployee($request->employee_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('overtime_date', [$request->date_from, $request->date_to]);
        }

        $requests = $query->orderBy('overtime_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($requests);
    }

    /**
     * Approve overtime request
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (
            ! $user->hasRole('Manager')
            && ! $user->can('manage-attendance')
            && ! $user->can('access-overtime-approvals')
            && ! $user->can('access-attendance-records')
        ) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $overtimeRequest = OvertimeRequest::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        if ($overtimeRequest->status !== 'pending') {
            return response()->json([
                'error' => 'Only pending requests can be approved'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Recompute overtime window at approval time so schedule always follows shop hours.
        $approvalDate = Carbon::parse($overtimeRequest->overtime_date);
        $approvalHours = (float) ($overtimeRequest->hours ?? 0);
        if ($approvalHours > 0) {
            $window = $this->buildOvertimeWindow((int) $user->shop_owner_id, $approvalDate, $approvalHours);
            $overtimeRequest->update([
                'start_time' => $window['start']->format('H:i:s'),
                'end_time' => $window['end']->format('H:i:s'),
            ]);
            $overtimeRequest->refresh();
        }

        try {
            $overtimeRequest = $this->overtimeApprovalService->approveOvertimeRequest(
                $overtimeRequest,
                $user,
                $request->notes
            );
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to approve overtime request: ' . $e->getMessage(),
            ], 500);
        }

        // SEAMLESS OVERTIME: Automatically extend the employee's shift for this date
        // This eliminates the need for manual "Start Overtime" button clicks
        $this->autoExtendShiftSchedule($overtimeRequest);

        return response()->json([
            'message' => 'Overtime request approved successfully. Employee shift automatically extended.',
            'overtime_request' => $overtimeRequest->fresh()
        ]);
    }
    
    /**
     * Automatically extend employee's shift schedule when overtime is approved
     * This implements seamless overtime - no manual button clicks needed
     */
    private function autoExtendShiftSchedule($overtimeRequest)
    {
        // Only extend an existing attendance record.
        // Creating one here can produce invalid status values and inaccurate attendance data.
        $attendanceRecord = \App\Models\HR\AttendanceRecord::where('employee_id', $overtimeRequest->employee_id)
            ->whereDate('date', Carbon::parse($overtimeRequest->overtime_date)->toDateString())
            ->first();

        if (!$attendanceRecord) {
            \Log::info('No attendance record yet; deferring overtime shift extension', [
                'employee_id' => $overtimeRequest->employee_id,
                'date' => Carbon::parse($overtimeRequest->overtime_date)->toDateString(),
                'overtime_request_id' => $overtimeRequest->id,
                'overtime_end_time' => $overtimeRequest->end_time,
            ]);
            return;
        }

        $originalExpectedCheckout = $attendanceRecord->expected_check_out;
        
        // Update expected checkout time to include overtime
        // This makes the system recognize extended hours as valid work time
        $attendanceRecord->update([
            'expected_check_out' => $overtimeRequest->end_time,
            'has_approved_overtime' => true,
            'overtime_end_time' => $overtimeRequest->end_time,
        ]);
        
        \Log::info('Shift automatically extended for overtime', [
            'employee_id' => $overtimeRequest->employee_id,
            'date' => $overtimeRequest->overtime_date,
            'original_end' => $originalExpectedCheckout,
            'extended_to' => $overtimeRequest->end_time,
            'overtime_hours' => $overtimeRequest->hours,
        ]);
    }

    /**
     * Reject overtime request
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (
            ! $user->hasRole('Manager')
            && ! $user->can('manage-attendance')
            && ! $user->can('access-overtime-approvals')
            && ! $user->can('access-attendance-records')
        ) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $overtimeRequest = OvertimeRequest::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        if ($overtimeRequest->status !== 'pending') {
            return response()->json([
                'error' => 'Only pending requests can be rejected'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $overtimeRequest = $this->overtimeApprovalService->rejectOvertimeRequest(
                $overtimeRequest,
                $user,
                (string) $request->rejection_reason
            );
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to reject overtime request: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Overtime request rejected',
            'overtime_request' => $overtimeRequest->fresh()
        ]);
    }

    /**
     * Cancel own overtime request
     */
    public function cancel($id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee record not found'], 404);
        }

        $overtimeRequest = OvertimeRequest::where('employee_id', $employee->id)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->findOrFail($id);

        if ($overtimeRequest->status !== 'pending') {
            return response()->json([
                'error' => 'Only pending requests can be cancelled'
            ], 422);
        }

        $overtimeRequest->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Overtime request cancelled successfully'
        ]);
    }

    /**
     * Staff checks in for approved overtime
     */
    public function overtimeCheckIn(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // All authenticated employees may clock in for overtime regardless of role
        if (!$user || !$user->shop_owner_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee record not found'], 404);
        }

        $overtimeRequest = OvertimeRequest::where('employee_id', $employee->id)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->findOrFail($id);

        // Validate overtime can be started
        if ($overtimeRequest->status !== 'approved') {
            return response()->json([
                'error' => 'Only approved overtime requests can be started'
            ], 422);
        }

        if ($overtimeRequest->checked_in_at) {
            return response()->json([
                'error' => 'You have already checked in for this overtime',
                'checked_in_at' => $overtimeRequest->checked_in_at
            ], 422);
        }

        $now = Carbon::now();
        
        // Get shop timezone from config or default to Asia/Manila
        $shopTimezone = config('app.shop_timezone', 'Asia/Manila');
        $now->setTimezone($shopTimezone);
        
        // Validate overtime is not being started too late
        $overtimeDate = Carbon::parse($overtimeRequest->overtime_date, $shopTimezone)->format('Y-m-d');
        $startTimeString = substr($overtimeRequest->start_time, 0, 8);
        $endTimeString = substr($overtimeRequest->end_time, 0, 8);
        
        $overtimeStartTime = Carbon::parse($overtimeDate . ' ' . $startTimeString, $shopTimezone);
        $overtimeEndTime = Carbon::parse($overtimeDate . ' ' . $endTimeString, $shopTimezone);
        
        // Allow starting overtime from 30 minutes before scheduled start until 30 minutes before scheduled end
        $earliestAllowedStart = $overtimeStartTime->copy()->subMinutes(30);
        $latestAllowedStart = $overtimeEndTime->copy()->subMinutes(30);
        
        // Check if it's too early
        if ($now->lessThan($earliestAllowedStart)) {
            return response()->json([
                'error' => 'Too early to start this overtime',
                'scheduled_time' => $overtimeRequest->start_time . ' - ' . $overtimeRequest->end_time,
                'earliest_start' => $earliestAllowedStart->format('H:i:s'),
                'current_time' => $now->format('H:i:s'),
                'message' => 'Please wait until ' . $earliestAllowedStart->format('h:i A') . ' to start overtime.'
            ], 422);
        }
        
        // Check if it's too late
        if ($now->greaterThan($latestAllowedStart)) {
            return response()->json([
                'error' => 'Too late to start this overtime',
                'scheduled_time' => $overtimeRequest->start_time . ' - ' . $overtimeRequest->end_time,
                'latest_start' => $latestAllowedStart->format('H:i:s'),
                'current_time' => $now->format('H:i:s'),
                'message' => 'Overtime window has passed. You should have started by ' . $latestAllowedStart->format('h:i A') . '. Please contact your manager.'
            ], 422);
        }
        
        $overtimeRequest->update([
            'checked_in_at' => $now,
            'actual_start_time' => $now->format('H:i:s'),
        ]);

        return response()->json([
            'message' => 'Overtime check-in successful',
            'overtime_request' => $overtimeRequest->fresh()
        ]);
    }

    /**
     * Staff checks out from overtime
     */
    public function overtimeCheckOut(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // All authenticated employees may clock out from overtime regardless of role
        if (!$user || !$user->shop_owner_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee record not found'], 404);
        }

        $overtimeRequest = OvertimeRequest::where('employee_id', $employee->id)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->findOrFail($id);

        // Validate overtime can be ended
        if (!$overtimeRequest->checked_in_at) {
            return response()->json([
                'error' => 'You must check in before checking out'
            ], 422);
        }

        if ($overtimeRequest->checked_out_at) {
            return response()->json([
                'error' => 'You have already checked out from this overtime',
                'checked_out_at' => $overtimeRequest->checked_out_at
            ], 422);
        }

        $now = Carbon::now();
        $checkInTime = Carbon::parse($overtimeRequest->checked_in_at);
        
        // Calculate actual hours worked
        $actualHours = round($checkInTime->diffInMinutes($now) / 60, 2);
        
        $overtimeRequest->update([
            'checked_out_at' => $now,
            'actual_end_time' => $now->format('H:i:s'),
            'actual_hours' => $actualHours,
        ]);

        // Recalculate amount based on actual hours
        if ($employee->hourly_rate) {
            $rateMultiplier = $overtimeRequest->rate_multiplier ?? 1.50;
            $calculatedAmount = $employee->hourly_rate * $actualHours * $rateMultiplier;
            $overtimeRequest->update(['calculated_amount' => $calculatedAmount]);
        }

        return response()->json([
            'message' => 'Overtime check-out successful',
            'actual_hours' => $actualHours,
            'overtime_request' => $overtimeRequest->fresh()
        ]);
    }

    /**
     * Get today's approved overtime for staff
     */
    public function getTodayApprovedOvertime(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // All authenticated employees may view their today's approved overtime regardless of role
        if (!$user || !$user->shop_owner_id) {
            return response()->json(['data' => []]);
        }

        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (!$employee) {
            return response()->json(['data' => []]);
        }

        // Use shop timezone for "today" to ensure correct date in Philippines time
        $shopTimezone = config('app.shop_timezone', 'Asia/Manila');
        $today = Carbon::now($shopTimezone)->toDateString();
        
        $overtimeRequests = OvertimeRequest::where('employee_id', $employee->id)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->where('overtime_date', $today)
            ->whereIn('status', ['pending', 'approved', 'assigned'])
            ->get();

        return response()->json(['data' => $overtimeRequests]);
    }

    /**
     * Manager confirms overtime hours
     */
    public function confirmHours(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $overtimeRequest = OvertimeRequest::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        if ($overtimeRequest->status !== 'approved') {
            return response()->json([
                'error' => 'Only approved overtime can be confirmed'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'actual_hours' => 'nullable|numeric|min:0|max:24',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $updateData = [
            'confirmed_by' => $user->id,
            'confirmed_at' => Carbon::now(),
        ];

        // Allow manager to manually adjust actual hours if needed
        if ($request->filled('actual_hours')) {
            $updateData['actual_hours'] = $request->actual_hours;
            
            // Recalculate amount
            $employee = $overtimeRequest->employee;
            if ($employee && $employee->hourly_rate) {
                $rateMultiplier = $overtimeRequest->rate_multiplier ?? 1.50;
                $updateData['calculated_amount'] = $employee->hourly_rate * $request->actual_hours * $rateMultiplier;
            }
        }

        if ($request->filled('notes')) {
            $updateData['notes'] = $request->notes;
        }

        $overtimeRequest->update($updateData);

        return response()->json([
            'message' => 'Overtime hours confirmed successfully',
            'overtime_request' => $overtimeRequest->fresh()
        ]);
    }

    /**
     * Manager assigns overtime to employee (no approval needed)
     */
    public function assignOvertime(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'overtime_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'reason' => 'required|string|max:500',
            'work_description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->findOrFail($request->employee_id);

        // Calculate hours from start and end time
        $startTime = Carbon::parse($request->start_time);
        $endTime = Carbon::parse($request->end_time);
        $hours = $startTime->diffInMinutes($endTime) / 60;

        $overtimeDate = Carbon::parse($request->overtime_date);
        $dayOfWeek = $overtimeDate->dayOfWeek;
        $overtimeType = ($dayOfWeek === 0 || $dayOfWeek === 6) ? 'weekend' : 'weekday';

        // Calculate amount if employee has hourly rate
        $calculatedAmount = null;
        $rateMultiplier = ($overtimeType === 'weekend') ? 2.00 : 1.50;
        
        if ($employee->hourly_rate) {
            $calculatedAmount = $employee->hourly_rate * $hours * $rateMultiplier;
        }

        $overtimeRequest = OvertimeRequest::create([
            'shop_owner_id' => $user->shop_owner_id,
            'employee_id' => $employee->id,
            'overtime_date' => $overtimeDate,
            'start_time' => $request->start_time . ':00',
            'end_time' => $request->end_time . ':00',
            'hours' => $hours,
            'rate_multiplier' => $rateMultiplier,
            'calculated_amount' => $calculatedAmount,
            'overtime_type' => $overtimeType,
            'reason' => $request->reason,
            'work_description' => $request->work_description,
            'status' => 'assigned', // Directly assigned, no approval needed
            'approved_by' => $user->id,
            'approved_at' => Carbon::now(),
        ]);

        // Notify employee of manager-assigned overtime
        try {
            $employee->loadMissing('user');
            $recipientUserId = $employee->user?->id;

            if ($recipientUserId) {
                $this->notificationService->notifyOvertimeApproved($recipientUserId, $user->shop_owner_id, [
                    'overtime_id' => $overtimeRequest->id,
                    'date' => $overtimeRequest->overtime_date?->format('Y-m-d') ?? (string) $overtimeRequest->overtime_date,
                    'hours' => $overtimeRequest->hours,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send assigned overtime notification', [
                'overtime_request_id' => $overtimeRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Overtime assigned to employee successfully',
            'overtime_request' => $overtimeRequest->fresh()->load('employee:id,first_name,last_name,name,email,position,department,shop_owner_id')
        ], 201);
    }
}
