<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\LeaveRequest;
use App\Models\Employee;
use App\Models\HR\LeaveBalance;
use App\Models\HR\LeavePolicy;
use App\Models\HR\LeaveApprovalHierarchy;
use App\Models\HR\AuditLog;
use App\Traits\HR\LogsHRActivity;
use App\Notifications\HR\LeaveRequestSubmitted;
use App\Notifications\HR\LeaveRequestApproved;
use App\Notifications\HR\LeaveRequestRejected;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;

class LeaveController extends Controller
{
    use LogsHRActivity;
    /**
     * Display a listing of leave requests.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('view-employees') && !$user->can('approve-timeoff')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = LeaveRequest::forShopOwner($user->shop_owner_id)
            ->with(['employee:id,first_name,last_name,department', 'approver:id,name']);

        // Apply search filter
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('reason', 'like', "%{$searchTerm}%")
                  ->orWhereHas('employee', function ($empQuery) use ($searchTerm) {
                      $empQuery->where('first_name', 'like', "%{$searchTerm}%")
                               ->orWhere('last_name', 'like', "%{$searchTerm}%")
                               ->orWhere('department', 'like', "%{$searchTerm}%");
                  });
            });
        }

        // Apply filters
        if ($request->filled('employee_id')) {
            $query->forEmployee($request->employee_id);
        }

        if ($request->filled('status')) {
            $query->withStatus($request->status);
        }

        if ($request->filled('leave_type')) {
            $query->ofType($request->leave_type);
        }

        if ($request->filled('department')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('department', $request->department);
            });
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->where(function ($q) use ($request) {
                $q->whereBetween('startDate', [$request->date_from, $request->date_to])
                  ->orWhereBetween('endDate', [$request->date_from, $request->date_to]);
            });
        }

        $leaveRequests = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($leaveRequests);
    }

    /**
     * Store a newly created leave request.
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
            'leaveType' => 'required|string',
            'startDate' => 'required|date|after_or_equal:today',
            'endDate' => 'required|date|after_or_equal:startDate',
            'reason' => 'required|string|max:500',
            'is_half_day' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if employee belongs to the same shop owner
        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->findOrFail($request->employee_id);

        // ==================== 1. VALIDATE AGAINST POLICY ====================
        $policy = LeavePolicy::findByType($user->shop_owner_id, $request->leaveType);
        
        if (!$policy) {
            return response()->json([
                'error' => 'Leave policy not found',
                'message' => "No active policy found for leave type: {$request->leaveType}"
            ], 422);
        }

        // Check if employee is eligible for this leave type
        if (!$policy->isEligibleEmployee($employee)) {
            return response()->json([
                'error' => 'Employee not eligible',
                'message' => "Employee does not meet eligibility criteria for {$policy->display_name}"
            ], 422);
        }

        // Calculate number of days
        $startDate = Carbon::parse($request->startDate);
        $endDate = Carbon::parse($request->endDate);
        $isHalfDay = $request->boolean('is_half_day', false);
        $noOfDays = $isHalfDay ? 0.5 : LeaveRequest::calculateDays($request->startDate, $request->endDate);

        // Validate leave duration
        $durationValidation = $policy->validateDuration($noOfDays, $isHalfDay);
        if (!$durationValidation['valid']) {
            return response()->json([
                'error' => 'Invalid duration',
                'messages' => $durationValidation['errors']
            ], 422);
        }

        // Validate notice period
        $noticeValidation = $policy->validateNotice($startDate);
        if (!$noticeValidation['valid']) {
            return response()->json([
                'error' => 'Insufficient notice',
                'messages' => $noticeValidation['errors']
            ], 422);
        }

        // Check if document is required
        $requiresDocument = $policy->requiresDocument($noOfDays);
        if ($requiresDocument && !$request->hasFile('supporting_document')) {
            return response()->json([
                'error' => 'Document required',
                'message' => "Supporting document is required for {$policy->display_name} leave of {$noOfDays} days"
            ], 422);
        }

        // ==================== 2. CHECK BALANCE ====================
        $leaveBalance = LeaveBalance::forEmployee($request->employee_id)
            ->ofType($request->leaveType)
            ->forYear(date('Y', strtotime($request->startDate)))
            ->first();

        if (!$leaveBalance) {
            // Create default leave balance if not exists
            $leaveBalance = LeaveBalance::create([
                'employee_id' => $request->employee_id,
                'leave_type' => $request->leaveType,
                'year' => date('Y', strtotime($request->startDate)),
                'total_days' => $policy->calculateAccruedDays($employee),
                'used_days' => 0,
                'remaining_days' => $policy->calculateAccruedDays($employee),
                'shop_owner_id' => $user->shop_owner_id,
            ]);
        }

        // Check if balance is sufficient
        $availableBalance = $leaveBalance->remaining_days;
        
        if ($availableBalance < $noOfDays) {
            // Check if negative balance is allowed
            if (!$policy->allow_negative_balance) {
                return response()->json([
                    'error' => 'Insufficient leave balance',
                    'message' => "Available: {$availableBalance} days, Requested: {$noOfDays} days",
                    'available_balance' => $availableBalance,
                    'requested_days' => $noOfDays
                ], 422);
            }

            // Check negative balance limit
            $negativeAmount = $noOfDays - $availableBalance;
            if ($negativeAmount > $policy->negative_balance_limit) {
                return response()->json([
                    'error' => 'Negative balance limit exceeded',
                    'message' => "Maximum negative balance allowed: {$policy->negative_balance_limit} days",
                    'negative_balance_limit' => $policy->negative_balance_limit
                ], 422);
            }
        }

        // ==================== 3. CREATE REQUEST WITH PROPER APPROVAL ROUTING ====================
        
        // Get next approver from hierarchy
        $approverInfo = LeaveApprovalHierarchy::getNextApprover(
            $request->employee_id,
            $request->leaveType,
            $noOfDays
        );

        // Determine initial status based on policy and hierarchy
        $initialStatus = 'pending';
        $approverId = null;
        $approvalLevel = 1;

        if ($policy->requires_approval && $approverInfo) {
            $approverId = $approverInfo['approver_id'];
            $approvalLevel = $approverInfo['approval_level'];
        } elseif (!$policy->requires_approval) {
            // Auto-approve if policy doesn't require approval
            $initialStatus = 'approved';
        }

        // Handle document upload
        $documentPath = null;
        if ($request->hasFile('supporting_document')) {
            $documentPath = $request->file('supporting_document')->store('leave_documents', 'public');
        }

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $request->employee_id,
            'leaveType' => $request->leaveType,
            'startDate' => $request->startDate,
            'endDate' => $request->endDate,
            'noOfDays' => $noOfDays,
            'reason' => $request->reason,
            'status' => $initialStatus,
            'approver_id' => $approverId,
            'approval_level' => $approvalLevel,
            'supporting_document' => $documentPath,
            'shop_owner_id' => $user->shop_owner_id,
        ]);

        // Audit log
        $this->auditCreated(
            AuditLog::MODULE_LEAVE,
            $leaveRequest,
            "Leave request created: {$employee->first_name} {$employee->last_name} - {$policy->display_name} ({$noOfDays} days)",
            ['leave_type' => $request->leaveType, 'days' => $noOfDays]
        );

        // ==================== 4. SEND NOTIFICATION ====================
        if ($policy->requires_approval && $approverInfo) {
            try {
                $approver = $approverInfo['approver'];
                Notification::send($approver, new LeaveRequestSubmitted($leaveRequest, $employee, $approverInfo));
                
                // Log notification sent
                AuditLog::createLog([
                    'employee_id' => $request->employee_id,
                    'module' => AuditLog::MODULE_LEAVE,
                    'action' => 'notification_sent',
                    'entity_type' => LeaveRequest::class,
                    'entity_id' => $leaveRequest->id,
                    'description' => "Leave approval notification sent to " . $approver->name,
                    'severity' => AuditLog::SEVERITY_INFO,
                    'tags' => ['notification', 'leave_approval'],
                ]);
            } catch (\Exception $e) {
                // Log notification failure but don't fail the request
                \Log::error('Failed to send leave notification', [
                    'leave_request_id' => $leaveRequest->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return response()->json([
            'message' => 'Leave request created successfully',
            'data' => $leaveRequest->load(['employee', 'approver']),
            'approval_required' => $policy->requires_approval,
            'approver' => $approverInfo ? [
                'name' => $approverInfo['approver']->name,
                'is_delegated' => $approverInfo['is_delegated'],
                'level' => $approverInfo['approval_level']
            ] : null,
            'balance' => [
                'available' => $leaveBalance->remaining_days,
                'after_approval' => $leaveBalance->remaining_days - $noOfDays,
            ]
        ], 201);
    }

    /**
     * Display the specified leave request.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $leaveRequest = LeaveRequest::forShopOwner($user->shop_owner_id)
            ->with(['employee', 'approver'])
            ->findOrFail($id);

        return response()->json($leaveRequest);
    }

    /**
     * Update the specified leave request.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $leaveRequest = LeaveRequest::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        // Only allow updates if status is pending
        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'error' => 'Cannot update leave request that is not pending'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'leaveType' => 'sometimes|required|in:vacation,sick,personal,maternity,paternity,unpaid',
            'startDate' => 'sometimes|required|date|after_or_equal:today',
            'endDate' => 'sometimes|required|date|after_or_equal:startDate',
            'reason' => 'sometimes|required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Recalculate days if dates changed
        if (isset($data['startDate']) || isset($data['endDate'])) {
            $startDate = $data['startDate'] ?? $leaveRequest->startDate;
            $endDate = $data['endDate'] ?? $leaveRequest->endDate;
            $data['noOfDays'] = LeaveRequest::calculateDays($startDate, $endDate);
        }

        $leaveRequest->update($data);

        return response()->json([
            'message' => 'Leave request updated successfully',
            'leaveRequest' => $leaveRequest->load(['employee', 'approver'])
        ]);
    }

    /**
     * Remove the specified leave request.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $leaveRequest = LeaveRequest::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        // Only allow deletion if status is pending
        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'error' => 'Cannot delete leave request that is not pending'
            ], 422);
        }

        $leaveRequest->delete();

        return response()->json(['message' => 'Leave request deleted successfully']);
    }

    /**
     * Approve a leave request.
     * 
     * Security: Validates role (HR/shop_owner/Manager), manager authority, and shop isolation
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        $leaveRequest = LeaveRequest::forShopOwner($user->shop_owner_id)
            ->with('employee')
            ->findOrFail($id);

        // Security Check 1: Shop Isolation (already enforced by forShopOwner scope)
        
        // Security Check 2: Role Validation
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            \Log::warning('Unauthorized leave approval attempt', [
                'user_id' => $user->id,
                'user_role' => $user->getRoleNames()->first(),
                'leave_request_id' => $id
            ]);
            return response()->json([
                'error' => 'Unauthorized. Only Managers or users with HR permissions can approve leave requests.'
            ], 403);
        }

        // Security Check 3: Manager Authority Validation
        if ($user->hasRole('Manager')) {
            $employee = Employee::find($user->id);
            if (!$employee || !$this->canManagerApprove($employee, $leaveRequest->employee_id)) {
                \Log::warning('Manager attempted to approve leave for non-direct report', [
                    'manager_id' => $user->id,
                    'employee_id' => $leaveRequest->employee_id,
                    'leave_request_id' => $id
                ]);
                return response()->json([
                    'error' => 'You can only approve leave requests for your direct reports.'
                ], 403);
            }
        }

        // Security Check 4: Status Validation
        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'error' => 'Leave request is not pending'
            ], 422);
        }

        // Simple approval - skip leave balance check for now
        // Approve the leave request
        $leaveRequest->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approval_date' => now(),
        ]);

        // Audit log (if trait is available)
        if (method_exists($this, 'auditApproved')) {
            $this->auditApproved(
                AuditLog::MODULE_LEAVE,
                $leaveRequest,
                "Leave request approved: {$leaveRequest->employee->first_name} {$leaveRequest->employee->last_name} - {$leaveRequest->leave_type} leave for {$leaveRequest->no_of_days} days",
                ['workflow', 'approval']
            );
        }

        // Send approval notification to employee (optional)
        try {
            $employee = $leaveRequest->employee;
            if ($employee && $employee->user) {
                $employee->user->notify(new LeaveRequestApproved($leaveRequest, $user));
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send leave approval notification', [
                'leave_request_id' => $leaveRequest->id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'message' => 'Leave request approved successfully',
            'leaveRequest' => $leaveRequest->load(['employee', 'approver'])
        ]);
    }

    /**
     * Check if the employee is a manager in the system.
     */
    private function isEmployeeManager($employee): bool
    {
        // Check if this employee has any direct reports (is listed as manager_id for any employee)
        return Employee::where('manager_id', $employee->id)
            ->where('shop_owner_id', $employee->shop_owner_id)
            ->exists();
    }

    /**
     * Check if a manager can approve leave for a specific employee.
     * Managers can only approve leave for their direct reports.
     */
    private function canManagerApprove($manager, $employeeId): bool
    {
        $targetEmployee = Employee::find($employeeId);
        
        if (!$targetEmployee) {
            return false;
        }

        // Manager can approve if they are the direct manager of the employee
        return $targetEmployee->manager_id === $manager->id;
    }

    /**
     * Reject a leave request.
     * 
     * Security: Validates role (HR/shop_owner/Manager), manager authority, and shop isolation
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $leaveRequest = LeaveRequest::forShopOwner($user->shop_owner_id)
            ->with('employee')
            ->findOrFail($id);

        // Security Check 1: Shop Isolation (already enforced by forShopOwner scope)
        
        // Security Check 2: Role Validation
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            \Log::warning('Unauthorized leave rejection attempt', [
                'user_id' => $user->id,
                'user_role' => $user->getRoleNames()->first(),
                'leave_request_id' => $id
            ]);
            return response()->json([
                'error' => 'Unauthorized. Only Managers or users with HR permissions can reject leave requests.'
            ], 403);
        }

        // Security Check 3: Manager Authority Validation
        if ($user->hasRole('Manager')) {
            $employee = Employee::find($user->id);
            if (!$employee || !$this->canManagerApprove($employee, $leaveRequest->employee_id)) {
                \Log::warning('Manager attempted to reject leave for non-direct report', [
                    'manager_id' => $user->id,
                    'employee_id' => $leaveRequest->employee_id,
                    'leave_request_id' => $id
                ]);
                return response()->json([
                    'error' => 'You can only reject leave requests for your direct reports.'
                ], 403);
            }
        }

        // Security Check 4: Status Validation
        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'error' => 'Leave request is not pending'
            ], 422);
        }

        $leaveRequest->reject($request->reason);

        // Audit logging
        \Log::info('Leave request rejected', [
            'rejector_id' => $user->id,
            'rejector_role' => $user->role,
            'leave_request_id' => $id,
            'employee_id' => $leaveRequest->employee_id,
            'rejection_reason' => $request->reason
        ]);

        // Send rejection notification to employee
        try {
            $employee = $leaveRequest->employee;
            if ($employee && $employee->user) {
                $employee->user->notify(new LeaveRequestRejected($leaveRequest, $user));
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send leave rejection notification', [
                'leave_request_id' => $leaveRequest->id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'message' => 'Leave request rejected successfully',
            'leaveRequest' => $leaveRequest->load(['employee', 'approver'])
        ]);
    }

    /**
     * Get leave statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $totalRequests = LeaveRequest::forShopOwner($user->shop_owner_id)->count();
        $pendingRequests = LeaveRequest::forShopOwner($user->shop_owner_id)->pending()->count();
        $approvedRequests = LeaveRequest::forShopOwner($user->shop_owner_id)->approved()->count();
        $rejectedRequests = LeaveRequest::forShopOwner($user->shop_owner_id)
            ->withStatus('rejected')->count();

        // Leave requests by type
        $leaveByType = LeaveRequest::forShopOwner($user->shop_owner_id)
            ->approved()
            ->selectRaw('leaveType, COUNT(*) as count, SUM(noOfDays) as totalDays')
            ->groupBy('leaveType')
            ->get()
            ->pluck('count', 'leaveType');

        return response()->json([
            'totalRequests' => $totalRequests,
            'pendingRequests' => $pendingRequests,
            'approvedRequests' => $approvedRequests,
            'rejectedRequests' => $rejectedRequests,
            'leaveByType' => $leaveByType,
        ]);
    }

    /**
     * Get leave balance for an employee.
     */
    public function balance(Request $request, $employeeId): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if employee belongs to the same shop owner
        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->findOrFail($employeeId);

        $year = $request->get('year', date('Y'));

        $leaveBalance = LeaveBalance::forEmployee($employeeId)
            ->forYear($year)
            ->first();

        if (!$leaveBalance) {
            // Create initial leave balance if not exists
            $leaveBalance = LeaveBalance::createForNewEmployee(
                $employeeId,
                $user->shop_owner_id,
                $year
            );
        }

        return response()->json($leaveBalance);
    }

    /**
     * Self-service leave request for staff/managers.
     */
    public function selfRequestLeave(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is staff or manager
        if (!in_array($user->role, ['STAFF', 'MANAGER', 'shop_owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'leave_type' => 'required|in:vacation,sick,personal,maternity,paternity,unpaid',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:500',
            'is_half_day' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Find employee record by user email
        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (!$employee) {
            return response()->json([
                'error' => 'No employee record found. Please contact HR.'
            ], 404);
        }

        // Calculate number of days
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $isHalfDay = $request->boolean('is_half_day', false);
        
        // Simple day calculation
        $noOfDays = $isHalfDay ? 0.5 : $startDate->diffInDays($endDate) + 1;

        // Create leave request
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $user->shop_owner_id,
            'leave_type' => $request->leave_type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'no_of_days' => $noOfDays,
            'is_half_day' => $isHalfDay,
            'reason' => $request->reason,
            'status' => 'pending',
            'approval_level' => 1,
        ]);

        return response()->json([
            'message' => 'Leave request submitted successfully',
            'leave_request' => [
                'id' => $leaveRequest->id,
                'leave_type' => $leaveRequest->leave_type,
                'start_date' => $leaveRequest->start_date,
                'end_date' => $leaveRequest->end_date,
                'no_of_days' => $leaveRequest->no_of_days,
                'status' => $leaveRequest->status,
            ]
        ], 201);
    }
}