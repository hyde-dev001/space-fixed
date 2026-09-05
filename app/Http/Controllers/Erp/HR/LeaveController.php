<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\LeaveRequest;
use App\Models\Employee;
use App\Models\HR\LeaveBalance;
use App\Models\HR\LeavePolicy;
use App\Models\HR\LeaveApprovalHierarchy;
use App\Models\HR\AuditLog;
use App\Services\HR\LeaveApprovalService;
use App\Services\Manager\ManagerAuthorizationService;
use App\Models\User;
use App\Traits\HR\LogsHRActivity;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class LeaveController extends Controller
{
    use LogsHRActivity;

    public function __construct(
        private LeaveApprovalService $leaveApprovalService,
        private ManagerAuthorizationService $managerAuthorization
    ) {}

    private function resolveShopOwnerId(?User $user): ?int
    {
        if (!$user) {
            return null;
        }

        $role = strtoupper(str_replace(['_', '-'], ' ', trim((string) $user->role)));

        return $role === 'SHOP OWNER'
            ? (int) $user->id
            : ($this->managerAuthorization->shopOwnerId($user) ?? null);
    }

    private function isHrActor(User $user): bool
    {
        if (strtoupper(trim((string) $user->role)) === 'HR') {
            return true;
        }

        return method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['HR']);
    }

    private function isShopOwnerActor(User $user): bool
    {
        return strtoupper(str_replace(['_', '-'], ' ', trim((string) $user->role))) === 'SHOP OWNER';
    }

    private function canReadLeave(User $user, int $shopOwnerId): bool
    {
        if ($this->managerAuthorization->allows(
            $user,
            ManagerAuthorizationService::LEAVE_APPROVALS_READ,
            $shopOwnerId,
        )) {
            return true;
        }

        // HR may read the HR queue with its explicit leave permission or
        // employee-directory access; neither grants decision authority.
        return ($this->isHrActor($user) || $this->isShopOwnerActor($user))
            && ($user->can('access-leave-approvals') || $user->can('access-employee-directory'));
    }

    private function canDecideLeave(User $user, int $shopOwnerId): bool
    {
        if ($this->managerAuthorization->allows(
            $user,
            ManagerAuthorizationService::LEAVE_DECISION,
            $shopOwnerId,
        )) {
            return true;
        }

        // Legacy HR/Owner actors must have the explicit leave approval
        // permission. Directory, attendance, and payslip access is not enough.
        return ($this->isHrActor($user) || $this->isShopOwnerActor($user))
            && $user->can('access-leave-approvals');
    }

    private function mapLeaveRequest(LeaveRequest $leaveRequest): array
    {
        $createdAt = $leaveRequest->created_at;
        $ageDays = $createdAt ? (int) $createdAt->diffInDays(now()) : 0;
        $slaMinutes = config('manager.leave_sla_minutes');
        $overdue = is_numeric($slaMinutes) && (int) $slaMinutes > 0
            ? $createdAt?->lte(now()->subMinutes((int) $slaMinutes))
            : false;
        $employee = $leaveRequest->employee;
        $employeeName = trim((string) ($employee?->name ?: (($employee?->first_name ?? '') . ' ' . ($employee?->last_name ?? ''))));

        return [
            'id' => (int) $leaveRequest->id,
            'employee_id' => (int) $leaveRequest->employee_id,
            'shop_owner_id' => (int) $leaveRequest->shop_owner_id,
            'employee' => [
                'id' => $employee?->id,
                'name' => $employeeName !== '' ? $employeeName : 'Unknown employee',
                'email' => (string) ($employee?->email ?? ''),
                'position' => (string) ($employee?->position ?? ''),
                'department' => (string) ($employee?->department ?? ''),
            ],
            'leave_type' => (string) $leaveRequest->leave_type,
            'leave_type_label' => LeaveRequest::LEAVE_TYPES[$leaveRequest->leave_type] ?? $leaveRequest->leave_type,
            'start_date' => optional($leaveRequest->start_date)->toDateString(),
            'end_date' => optional($leaveRequest->end_date)->toDateString(),
            'no_of_days' => (float) $leaveRequest->no_of_days,
            'reason' => (string) $leaveRequest->reason,
            'status' => (string) $leaveRequest->status,
            'approval_stage' => $leaveRequest->status === 'pending' ? 'manager_review' : 'terminal',
            'created_at' => optional($createdAt)->toIso8601String(),
            'age_days' => $ageDays,
            'overdue' => (bool) $overdue,
            'sla' => [
                'configured' => is_numeric($slaMinutes) && (int) $slaMinutes > 0,
                'minutes' => is_numeric($slaMinutes) && (int) $slaMinutes > 0 ? (int) $slaMinutes : null,
            ],
            'coverage_status' => 'not_assessed',
            'next_action' => $leaveRequest->status === 'pending' ? 'Manager decision required' : 'No action required',
            'approved_by' => $leaveRequest->approved_by,
            'approval_date' => optional($leaveRequest->approval_date)->toIso8601String(),
            'rejection_reason' => $leaveRequest->rejection_reason,
            'approver_comments' => $leaveRequest->approver_comments,
            'history' => array_values(array_filter([
                $leaveRequest->approved_by ? [
                    'actor_id' => (int) $leaveRequest->approved_by,
                    'action' => $leaveRequest->status === 'approved' ? 'approved' : 'rejected',
                    'at' => optional($leaveRequest->approval_date)->toIso8601String(),
                    'reason' => $leaveRequest->rejection_reason ?: $leaveRequest->approver_comments,
                ] : null,
            ])),
        ];
    }

    /**
     * Display a listing of leave requests.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();

        $shopOwnerId = $this->resolveShopOwnerId($user);
        if (!$user || !$shopOwnerId || !$this->canReadLeave($user, $shopOwnerId)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'employee_id' => ['nullable', 'integer'],
            'leave_type' => ['nullable', 'in:' . implode(',', array_keys(LeaveRequest::LEAVE_TYPES))],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = LeaveRequest::forShopOwner($shopOwnerId)
            ->with(['employee:id,first_name,last_name,name,email,position,department', 'approver:id,name']);

        // Apply search filter
        if (!empty($validated['search'])) {
            $searchTerm = $validated['search'];
            $query->where(function ($q) use ($searchTerm) {
                $q->where('reason', 'like', "%{$searchTerm}%")
                  ->orWhereHas('employee', function ($empQuery) use ($searchTerm) {
                      $empQuery->where('name', 'like', "%{$searchTerm}%")
                               ->orWhere('first_name', 'like', "%{$searchTerm}%")
                               ->orWhere('last_name', 'like', "%{$searchTerm}%")
                               ->orWhere('email', 'like', "%{$searchTerm}%")
                               ->orWhere('department', 'like', "%{$searchTerm}%");
                  });
            });
        }

        // Apply filters
        if (isset($validated['employee_id'])) {
            $query->forEmployee($validated['employee_id']);
        }

        if (!empty($validated['status'])) {
            $query->byStatus($validated['status']);
        }

        if (!empty($validated['leave_type'])) {
            $query->where('leave_type', $validated['leave_type']);
        }

        $dateFrom = $validated['date_from'] ?? $validated['start_date'] ?? null;
        $dateTo = $validated['date_to'] ?? $validated['end_date'] ?? null;
        if ($dateFrom) {
            $query->whereDate('end_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('start_date', '<=', $dateTo);
        }

        $leaves = $query->orderBy('created_at', 'asc')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->withQueryString();
        $leaves->setCollection($leaves->getCollection()->map(function (LeaveRequest $leaveRequest): array {
            return $this->mapLeaveRequest($leaveRequest);
        }));

        return response()->json($leaves);
    }

    /**
     * Store a newly created leave request.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('access-employee-directory') && !$user->can('access-attendance-records') && !$user->can('access-payslip-generation') && !$user->can('access-view-payslip')) {
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
            ->forYear(date('Y', strtotime($request->startDate)))
            ->forShopOwner($user->shop_owner_id)
            ->first();

        if (!$leaveBalance) {
            // Create default leave balance if not exists
            $leaveBalance = LeaveBalance::createForNewEmployee(
                $request->employee_id,
                $user->shop_owner_id,
                (int) date('Y', strtotime($request->startDate))
            );
        }

        // Check if balance is sufficient using per-type columns
        $availableBalance = $leaveBalance->getRemainingForType($request->leaveType);

        if ($request->leaveType !== 'unpaid' && $availableBalance < $noOfDays) {
            return response()->json([
                'error' => 'Insufficient leave balance',
                'message' => "Available: {$availableBalance} days, Requested: {$noOfDays} days",
                'available_balance' => $availableBalance,
                'requested_days' => $noOfDays
            ], 422);
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

        // ==================== 4. SERVICE-LEVEL TRANSITION NOTIFICATIONS ====================
        $this->leaveApprovalService->notifyLeaveSubmitted(
            $leaveRequest->fresh(['employee', 'approver']),
            $employee,
            ($policy->requires_approval && $approverInfo) ? $approverInfo : null
        );

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
        if (!$user->hasRole('Manager') && !$user->can('access-employee-directory') && !$user->can('access-attendance-records') && !$user->can('access-payslip-generation') && !$user->can('access-view-payslip')) {
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
        if (!$user->hasRole('Manager') && !$user->can('access-employee-directory') && !$user->can('access-attendance-records') && !$user->can('access-payslip-generation') && !$user->can('access-view-payslip')) {
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
        if (!$user->hasRole('Manager') && !$user->can('access-employee-directory') && !$user->can('access-attendance-records') && !$user->can('access-payslip-generation') && !$user->can('access-view-payslip')) {
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

        $shopOwnerId = $this->resolveShopOwnerId($user);
        if (!$user || !$shopOwnerId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $leaveRequest = LeaveRequest::forShopOwner($shopOwnerId)
            ->with('employee')
            ->findOrFail($id);

        if (!$this->canDecideLeave($user, $shopOwnerId)) {
            \Log::warning('Unauthorized leave approval attempt', [
                'user_id' => $user->id,
                'user_role' => $user->getRoleNames()->first(),
                'leave_request_id' => $id
            ]);
            return response()->json([
                'error' => 'Unauthorized. Only Managers or users with HR permissions can approve leave requests.'
            ], 403);
        }

        // Service-level transition handles state update, balance mutation, and notifications.
        try {
            $leaveRequest = $this->leaveApprovalService->approveLeaveRequest(
                $leaveRequest,
                $user,
                $request->string('reason')->toString() ?: null,
            );
        } catch (\Throwable $e) {
            if ((int) $e->getCode() === 409) {
                return response()->json([
                    'error' => 'Leave request has already been decided.',
                    'code' => 'LEAVE_REQUEST_ALREADY_DECIDED',
                    'leaveRequest' => $leaveRequest->fresh(['employee', 'approver']),
                ], 409);
            }

            if ((int) $e->getCode() === 422) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            \Log::error('Failed to approve leave request', [
                'leave_request_id' => $id,
                'user_id' => $user->id,
                'exception' => $e,
            ]);
            return response()->json([
                'error' => 'Failed to approve leave request.'
            ], 500);
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
        // $manager here is a User — look up their Employee record by email
        $managerEmployee = Employee::where('shop_owner_id', $manager->shop_owner_id)
            ->where('email', $manager->email)
            ->first();

        if (!$managerEmployee) {
            return false;
        }

        $targetEmployee = Employee::find($employeeId);

        if (!$targetEmployee) {
            return false;
        }

        // Manager can approve if they are the direct manager of the employee
        return $targetEmployee->manager_id === $managerEmployee->id;
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

        $shopOwnerId = $this->resolveShopOwnerId($user);
        if (!$user || !$shopOwnerId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $leaveRequest = LeaveRequest::forShopOwner($shopOwnerId)
            ->with('employee')
            ->findOrFail($id);

        if (!$this->canDecideLeave($user, $shopOwnerId)) {
            \Log::warning('Unauthorized leave rejection attempt', [
                'user_id' => $user->id,
                'user_role' => $user->getRoleNames()->first(),
                'leave_request_id' => $id
            ]);
            return response()->json([
                'error' => 'Unauthorized. Only Managers or users with HR permissions can reject leave requests.'
            ], 403);
        }

        try {
            $leaveRequest = $this->leaveApprovalService->rejectLeaveRequest(
                $leaveRequest,
                $user,
                (string) $request->reason
            );
        } catch (\Throwable $e) {
            if ((int) $e->getCode() === 409) {
                return response()->json([
                    'error' => 'Leave request has already been decided.',
                    'code' => 'LEAVE_REQUEST_ALREADY_DECIDED',
                    'leaveRequest' => $leaveRequest->fresh(['employee', 'approver']),
                ], 409);
            }

            \Log::error('Failed to reject leave request', [
                'leave_request_id' => $id,
                'user_id' => $user->id,
                'exception' => $e,
            ]);
            return response()->json([
                'error' => 'Failed to reject leave request.'
            ], 500);
        }

        return response()->json([
            'message' => 'Leave request rejected successfully',
            'leaveRequest' => $leaveRequest->load(['employee', 'approver'])
        ]);
    }

    /**
     * Get pending leave requests (route alias used by hr-api.php).
     */
    public function getPending(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();

        $shopOwnerId = $this->resolveShopOwnerId($user);
        if (!$user || !$shopOwnerId || !$this->canReadLeave($user, $shopOwnerId)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->merge(['status' => 'pending']);

        return $this->index($request);
    }

    /**
     * Get leave balance for employee (route alias for hr-api.php).
     */
    public function getBalance(Request $request, $employeeId): JsonResponse
    {
        return $this->balance($request, $employeeId);
    }

    /**
     * Staff views their own leave requests.
     */
    public function myRequests(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();

        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (!$employee) {
            return response()->json(['data' => [], 'message' => 'No employee record found'], 200);
        }

        $query = LeaveRequest::where('employee_id', $employee->id)
            ->where('shop_owner_id', $user->shop_owner_id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $requests = $query->orderBy('start_date', 'desc')
            ->paginate($request->get('per_page', 20));

        // Also return current balance
        $leaveBalance = LeaveBalance::forEmployee($employee->id)
            ->forYear(now()->year)
            ->forShopOwner($user->shop_owner_id)
            ->first();

        if (!$leaveBalance) {
            $leaveBalance = LeaveBalance::createForNewEmployee($employee->id, $user->shop_owner_id, now()->year);
        }

        return response()->json([
            'data'    => $requests,
            'balance' => $leaveBalance->getAllBalances(),
        ]);
    }

    /**
     * Staff cancels their own pending leave request.
     */
    public function cancelOwn(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee record not found'], 404);
        }

        try {
            $leaveRequest = \DB::transaction(function () use ($employee, $user, $id): LeaveRequest {
                $leaveRequest = LeaveRequest::where('employee_id', $employee->id)
                    ->where('shop_owner_id', $user->shop_owner_id)
                    ->lockForUpdate()
                    ->findOrFail($id);

                if ($leaveRequest->status !== 'pending') {
                    throw new \RuntimeException('Only pending leave requests can be cancelled', 422);
                }

                $leaveRequest->update([
                    'status' => 'rejected',
                    'rejection_reason' => 'Cancelled by employee',
                ]);

                AuditLog::createLog([
                    'shop_owner_id' => $user->shop_owner_id,
                    'user_id' => $user->id,
                    'employee_id' => $employee->id,
                    'module' => AuditLog::MODULE_LEAVE,
                    'action' => 'cancelled',
                    'entity_type' => LeaveRequest::class,
                    'entity_id' => $leaveRequest->id,
                    'description' => 'Leave request cancelled by employee',
                    'old_values' => ['status' => 'pending'],
                    'new_values' => ['status' => 'rejected', 'reason' => 'Cancelled by employee'],
                    'severity' => AuditLog::SEVERITY_INFO,
                    'tags' => ['leave', 'self_service', 'cancellation'],
                ]);

                return $leaveRequest;
            });
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ((int) $e->getCode() === 422) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            \Log::error('Failed to cancel own leave request', [
                'leave_request_id' => $id,
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Failed to cancel leave request.'], 500);
        }

        return response()->json(['message' => 'Leave request cancelled successfully']);
    }

    /**
     * Get leave statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('access-employee-directory') && !$user->can('access-attendance-records') && !$user->can('access-payslip-generation') && !$user->can('access-view-payslip')) {
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
        if (!$user->hasRole('Manager') && !$user->can('access-employee-directory') && !$user->can('access-attendance-records') && !$user->can('access-payslip-generation') && !$user->can('access-view-payslip')) {
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

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Self-service leave is available to authenticated ERP users tied to a shop.
        if (empty($user->shop_owner_id)) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'No shop association found for this account.'
            ], 403);
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
        
        // Count business days only
        $noOfDays = $isHalfDay ? 0.5 : LeaveRequest::calculateDays($request->start_date, $request->end_date);

        // Check for overlapping approved/pending leaves
        $overlap = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($q) use ($request) {
                $q->whereBetween('start_date', [$request->start_date, $request->end_date])
                  ->orWhereBetween('end_date', [$request->start_date, $request->end_date])
                  ->orWhere(function ($q2) use ($request) {
                      $q2->where('start_date', '<=', $request->start_date)
                         ->where('end_date', '>=', $request->end_date);
                  });
            })->exists();

        if ($overlap) {
            return response()->json([
                'error' => 'Overlapping leave',
                'message' => 'You already have a pending or approved leave request covering these dates.',
            ], 422);
        }

        // Check leave balance (skip for unpaid leave)
        if ($request->leave_type !== 'unpaid') {
            $leaveBalance = LeaveBalance::forEmployee($employee->id)
                ->forYear($startDate->year)
                ->forShopOwner($user->shop_owner_id)
                ->first();

            if (!$leaveBalance) {
                $leaveBalance = LeaveBalance::createForNewEmployee($employee->id, $user->shop_owner_id, $startDate->year);
            }

            $available = $leaveBalance->getRemainingForType($request->leave_type);
            if ($available < $noOfDays) {
                return response()->json([
                    'error' => 'Insufficient leave balance',
                    'message' => "Available: {$available} day(s), Requested: {$noOfDays} day(s)",
                    'available_balance' => $available,
                    'requested_days' => $noOfDays,
                ], 422);
            }
        }

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

        // Centralized submit transition notification hook
        $this->leaveApprovalService->notifyLeaveSubmitted($leaveRequest->fresh(['employee', 'approver']), $employee);

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
