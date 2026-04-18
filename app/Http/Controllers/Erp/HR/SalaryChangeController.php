<?php

namespace App\Http\Controllers\Erp\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Models\HR\SalaryChange;
use App\Models\HR\Payroll;
use App\Models\User;
use App\Services\HR\SalaryChangeApprovalService;
use App\Traits\HR\LogsHRActivity;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class SalaryChangeController extends Controller
{
    use LogsHRActivity;

    private ?array $salaryChangeColumns = null;
    private ?array $employeeColumns = null;

    public function __construct(
        private SalaryChangeApprovalService $salaryChangeApprovalService
    ) {}

    // ─── Auth helpers ─────────────────────────────────────────

    private function authorizeHR(?User $user): bool
    {
        return $user?->can('manage-salary-changes') ?? false;
    }

    private function authorizeApprover(?User $user): bool
    {
        return ($user?->hasRole('Shop Owner') ?? false)
            || ($user?->can('approve-salary-change') ?? false);
    }

    private function hasSalaryChangeColumn(string $column): bool
    {
        if ($this->salaryChangeColumns === null) {
            try {
                $this->salaryChangeColumns = array_fill_keys(Schema::getColumnListing('salary_changes'), true);
            } catch (\Throwable $e) {
                $this->salaryChangeColumns = [];
            }
        }

        return isset($this->salaryChangeColumns[$column]);
    }

    private function hasEmployeeColumn(string $column): bool
    {
        if ($this->employeeColumns === null) {
            try {
                $this->employeeColumns = array_fill_keys(Schema::getColumnListing('employees'), true);
            } catch (\Throwable $e) {
                $this->employeeColumns = [];
            }
        }

        return isset($this->employeeColumns[$column]);
    }

    private function isSchemaDriftQueryException(QueryException $exception): bool
    {
        $message = (string) $exception->getMessage();

        return str_contains($message, 'SQLSTATE[42S22]')
            || str_contains($message, 'SQLSTATE[42S02]')
            || str_contains($message, 'Unknown column')
            || str_contains($message, 'Base table or view not found');
    }

    /**
     * Resolve actor and shop context for either auth:user or auth:shop_owner requests.
     *
     * @return array{actor: ?User, shop_owner_id: ?int, via_shop_owner_guard: bool}
     */
    private function resolveAuthContext(): array
    {
        $user = Auth::guard('user')->user();
        if ($user instanceof User) {
            return [
                'actor' => $user,
                'shop_owner_id' => (int) $user->shop_owner_id,
                'via_shop_owner_guard' => false,
            ];
        }

        $shopOwner = Auth::guard('shop_owner')->user();
        if ($shopOwner) {
            $ownerUser = User::where('shop_owner_id', $shopOwner->id)
                ->where('email', $shopOwner->email)
                ->first();

            return [
                'actor' => $ownerUser,
                'shop_owner_id' => (int) $shopOwner->id,
                'via_shop_owner_guard' => true,
            ];
        }

        return [
            'actor' => null,
            'shop_owner_id' => null,
            'via_shop_owner_guard' => false,
        ];
    }

    // ─── Index ────────────────────────────────────────────────

    /**
     * GET /api/hr/salary-changes
     * List salary changes for the authenticated user's shop.
     */
    public function index(Request $request): JsonResponse
    {
        $context = $this->resolveAuthContext();
        $user = $context['actor'];
        $shopOwnerId = $context['shop_owner_id'];
        $viaShopOwnerGuard = $context['via_shop_owner_guard'];

        if (!$shopOwnerId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!$viaShopOwnerGuard && !$this->authorizeHR($user) && !$this->authorizeApprover($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $employeeSelect = ['id', 'first_name', 'last_name'];
            if ($this->hasEmployeeColumn('department')) {
                $employeeSelect[] = 'department';
            }
            if ($this->hasEmployeeColumn('position')) {
                $employeeSelect[] = 'position';
            }

            $query = SalaryChange::forShopOwner($shopOwnerId)
                ->with([
                    'employee:' . implode(',', $employeeSelect),
                    'proposer:id,name',
                    'approver:id,name',
                    'rejector:id,name',
                ])
                ->orderByDesc('created_at');

            if ($request->filled('status')) {
                $requestedStatus = strtolower((string) $request->status);
                if (in_array($requestedStatus, array_keys(SalaryChange::STATUSES), true)) {
                    $query->where('status', $requestedStatus);
                }
            }

            if ($request->filled('employee_id')) {
                $query->where('employee_id', (int) $request->employee_id);
            }

            if ($request->filled('change_type')) {
                $requestedType = strtolower((string) $request->change_type);
                if (in_array($requestedType, array_keys(SalaryChange::CHANGE_TYPES), true)) {
                    $query->where('change_type', $requestedType);
                }
            }

            if ($request->filled('from')) {
                $query->whereDate('effective_date', '>=', $request->from);
            }

            if ($request->filled('to')) {
                $query->whereDate('effective_date', '<=', $request->to);
            }

            $perPage = max(1, min(100, (int) $request->get('per_page', 15)));
            $results = $query->paginate($perPage);

            // Summary counts
            $baseCount = SalaryChange::forShopOwner($shopOwnerId);
            $summary = [
                'pending'   => (clone $baseCount)->where('status', 'pending')->count(),
                'approved'  => (clone $baseCount)->where('status', 'approved')->count(),
                'applied'   => (clone $baseCount)->where('status', 'applied')->count(),
                'rejected'  => (clone $baseCount)->where('status', 'rejected')->count(),
                'cancelled' => $this->hasSalaryChangeColumn('status')
                    ? (clone $baseCount)->where('status', 'cancelled')->count()
                    : 0,
            ];

            return response()->json([
                'data'    => $results->items(),
                'meta'    => [
                    'current_page' => $results->currentPage(),
                    'last_page'    => $results->lastPage(),
                    'per_page'     => $results->perPage(),
                    'total'        => $results->total(),
                ],
                'summary' => $summary,
            ]);
        } catch (QueryException $e) {
            Log::error('Salary changes index query failed', [
                'shop_owner_id' => $shopOwnerId,
                'error' => $e->getMessage(),
            ]);

            if ($this->isSchemaDriftQueryException($e)) {
                return response()->json([
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 15,
                        'total' => 0,
                    ],
                    'summary' => [
                        'pending' => 0,
                        'approved' => 0,
                        'applied' => 0,
                        'rejected' => 0,
                        'cancelled' => 0,
                    ],
                    'warning' => 'Salary changes are temporarily unavailable while updates are being applied.',
                ]);
            }

            return response()->json(['error' => 'Server Error'], 500);
        }
    }

    // ─── Store ────────────────────────────────────────────────

    /**
     * POST /api/hr/salary-changes
      * Propose a new daily-rate change.
     */
    public function store(Request $request): JsonResponse
    {
        $context = $this->resolveAuthContext();
        $user = $context['actor'];
        $shopOwnerId = $context['shop_owner_id'];

        if (!$user || !$shopOwnerId || !$this->authorizeHR($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id'    => 'required|integer|exists:employees,id',
            'new_salary'     => 'required|numeric|min:0',
            'effective_date' => 'required|date',
            'reason'         => 'required|string|max:1000',
            'change_type'    => 'nullable|in:new_hire_rate_setup,minor_adjustment,major_adjustment,correction',
            'retroactive_override_reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Shop isolation — employee must belong to the same shop
        $employee = Employee::where('shop_owner_id', $shopOwnerId)
            ->findOrFail((int) $request->employee_id);

        $previousSalary = (float) ($employee->salary ?? 0);
        $newSalary      = (float) $request->new_salary;
        $effectiveDate  = Carbon::parse($request->effective_date);

        // ── Retroactive lock ───────────────────────────────────
        $isRetroactive = SalaryChange::isRetroactive($employee->id, $effectiveDate);

        if ($isRetroactive) {
            if (!$user->can('override-salary-retroactive')) {
                return response()->json([
                    'error' => 'Retroactive salary edits are locked. This effective date falls within an already-processed payroll period. An authorized override is required.',
                    'code'  => 'RETROACTIVE_LOCKED',
                ], 403);
            }
            // Override granted — require a reason
            if (empty($request->retroactive_override_reason)) {
                return response()->json([
                    'errors' => [
                        'retroactive_override_reason' => ['A reason for retroactive override is required.'],
                    ],
                ], 422);
            }
        }

        // ── Compute change metadata ────────────────────────────
        $changePercent = SalaryChange::computeChangePercent($previousSalary, $newSalary);
        $changeType    = $request->change_type
            ?? SalaryChange::classifyChangeType($previousSalary, $newSalary);

        DB::beginTransaction();
        try {
            $change = SalaryChange::create([
                'employee_id'   => $employee->id,
                'shop_owner_id' => $shopOwnerId,
                'proposed_by'   => $user->id,
                'previous_salary' => $previousSalary,
                'new_salary'      => $newSalary,
                'change_percent'  => $changePercent,
                'change_type'     => $changeType,
                'effective_date'  => $effectiveDate->toDateString(),
                'reason'          => $request->reason,
                'status'          => SalaryChange::STATUS_PENDING,
                'retroactive'     => $isRetroactive,
                'retroactive_override_by'     => $isRetroactive ? $user->id : null,
                'retroactive_override_reason' => $isRetroactive ? $request->retroactive_override_reason : null,
            ]);

            // Audit log
            $this->auditCustom(
                AuditLog::MODULE_EMPLOYEE,
                'salary_change_proposed',
                "Daily-rate change proposed for {$employee->first_name} {$employee->last_name}: " .
                    "₱{$previousSalary} → ₱{$newSalary} ({$changePercent}%)",
                [
                    'employee_id'    => $employee->id,
                    'entity_type'    => Employee::class,
                    'entity_id'      => $employee->id,
                    'old_values'     => ['salary' => $previousSalary],
                    'new_values'     => [
                        'salary'         => $newSalary,
                        'effective_date' => $effectiveDate->toDateString(),
                        'change_type'    => $changeType,
                        'change_percent' => $changePercent,
                        'retroactive'    => $isRetroactive,
                    ],
                    'severity'       => AuditLog::SEVERITY_WARNING,
                    'tags'           => ['salary_change', 'phase_7'],
                ]
            );

            DB::commit();

            $this->salaryChangeApprovalService->notifySalaryChangeSubmitted(
                $change->fresh(),
                $employee,
                $user,
                (string) $request->reason
            );

            return response()->json([
                'message' => 'Daily-rate change proposal submitted and awaiting approval.',
                'data'    => $change->load(['employee:id,first_name,last_name', 'proposer:id,name']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create salary change: ' . $e->getMessage()], 500);
        }
    }

    // ─── Show ─────────────────────────────────────────────────

    /**
     * GET /api/hr/salary-changes/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $context = $this->resolveAuthContext();
        $user = $context['actor'];
        $shopOwnerId = $context['shop_owner_id'];
        $viaShopOwnerGuard = $context['via_shop_owner_guard'];

        if (!$shopOwnerId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!$viaShopOwnerGuard && !$this->authorizeHR($user) && !$this->authorizeApprover($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $change = SalaryChange::forShopOwner($shopOwnerId)
            ->with(['employee:id,first_name,last_name,department,position,salary',
                    'proposer:id,name',
                    'approver:id,name',
                    'rejector:id,name',
                    'retroactiveOverrideGrantor:id,name'])
            ->findOrFail($id);

        return response()->json(['data' => $change]);
    }

    // ─── Approve ──────────────────────────────────────────────

    /**
     * POST /api/hr/salary-changes/{id}/approve
     * Requires approve-salary-change permission (Shop Owner / Manager).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $context = $this->resolveAuthContext();
        $user = $context['actor'];
        $shopOwnerId = $context['shop_owner_id'];
        $viaShopOwnerGuard = $context['via_shop_owner_guard'];

        if (!$shopOwnerId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!$viaShopOwnerGuard && !$this->authorizeApprover($user)) {
            return response()->json(['error' => 'Only authorized approvers can approve salary changes.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $change = SalaryChange::forShopOwner($shopOwnerId)
            ->where('status', SalaryChange::STATUS_PENDING)
            ->findOrFail($id);

        // Approver must not be the same person who proposed (unless they have override)
        if (!$viaShopOwnerGuard && $user && $change->proposed_by === $user->id && !$user->can('override-salary-retroactive')) {
            return response()->json(['error' => 'You cannot approve a salary change you proposed.'], 403);
        }

        try {
            $change = $this->salaryChangeApprovalService->approveSalaryChange(
                $change,
                $user,
                $request->notes
            );

            $employee = $change->employee;
            $this->auditCustom(
                AuditLog::MODULE_EMPLOYEE,
                'salary_change_approved',
                "Salary change approved for {$employee->first_name} {$employee->last_name}",
                [
                    'employee_id' => $employee->id,
                    'entity_type' => Employee::class,
                    'entity_id'   => $employee->id,
                    'new_values'  => [
                        'salary_change_id' => $change->id,
                        'new_salary'       => $change->new_salary,
                        'effective_date'   => $change->effective_date->toDateString(),
                        'applied_now'      => false,
                    ],
                    'severity'    => AuditLog::SEVERITY_WARNING,
                    'tags'        => ['salary_change', 'approved', 'phase_7'],
                ]
            );

            return response()->json([
                'message' => 'Salary change approved. HR must finalize and apply this request.',
                'data'    => $change->fresh(['employee:id,first_name,last_name', 'approver:id,name']),
            ]);

        } catch (\Throwable $e) {
            return response()->json(['error' => 'Approval failed: ' . $e->getMessage()], 500);
        }
    }

    // ─── Reject ───────────────────────────────────────────────

    /**
     * POST /api/hr/salary-changes/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $context = $this->resolveAuthContext();
        $user = $context['actor'];
        $shopOwnerId = $context['shop_owner_id'];
        $viaShopOwnerGuard = $context['via_shop_owner_guard'];

        if (!$shopOwnerId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!$viaShopOwnerGuard && !$this->authorizeApprover($user)) {
            return response()->json(['error' => 'Only authorized approvers can reject salary changes.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'required|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $change = SalaryChange::forShopOwner($shopOwnerId)
            ->where('status', SalaryChange::STATUS_PENDING)
            ->findOrFail($id);

        try {
            $change = $this->salaryChangeApprovalService->rejectSalaryChange(
                $change,
                $user,
                (string) $request->notes
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Rejection failed: ' . $e->getMessage()], 500);
        }

        $employee = $change->employee;
        $this->auditCustom(
            AuditLog::MODULE_EMPLOYEE,
            'salary_change_rejected',
            "Salary change rejected for {$employee->first_name} {$employee->last_name}",
            [
                'employee_id' => $employee->id,
                'entity_type' => Employee::class,
                'entity_id'   => $employee->id,
                'new_values'  => [
                    'salary_change_id' => $change->id,
                    'rejection_reason' => $request->notes,
                ],
                'severity'    => AuditLog::SEVERITY_INFO,
                'tags'        => ['salary_change', 'rejected', 'phase_7'],
            ]
        );

        return response()->json([
            'message' => 'Salary change rejected.',
            'data'    => $change->fresh(['employee:id,first_name,last_name', 'rejector:id,name']),
        ]);
    }

    // ─── Apply ────────────────────────────────────────────────

    /**
     * POST /api/hr/salary-changes/{id}/apply
     * Manually apply an approved change (for back-filling if paylroll scheduler missed it).
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        $context = $this->resolveAuthContext();
        $user = $context['actor'];
        $shopOwnerId = $context['shop_owner_id'];

        if (!$shopOwnerId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!$user || !$this->authorizeHR($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $change = SalaryChange::forShopOwner($shopOwnerId)
            ->where('status', SalaryChange::STATUS_APPROVED)
            ->findOrFail($id);

        DB::beginTransaction();
        try {
            $change->applyToEmployee();

            $employee = $change->employee;
            $this->auditCustom(
                AuditLog::MODULE_EMPLOYEE,
                'salary_change_applied',
                "Salary change manually applied for {$employee->first_name} {$employee->last_name}: ₱{$change->new_salary}",
                [
                    'employee_id' => $employee->id,
                    'entity_type' => Employee::class,
                    'entity_id'   => $employee->id,
                    'new_values'  => [
                        'salary_change_id' => $change->id,
                        'applied_salary'   => $change->new_salary,
                    ],
                    'severity'    => AuditLog::SEVERITY_WARNING,
                    'tags'        => ['salary_change', 'applied', 'phase_7'],
                ]
            );

            DB::commit();

            return response()->json([
                'message' => "Salary change applied. Employee salary is now ₱{$change->new_salary}.",
                'data'    => $change->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─── Cancel ───────────────────────────────────────────────

    /**
     * POST /api/hr/salary-changes/{id}/cancel
     * Proposer or authorized salary-change handler can cancel while still pending.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $context = $this->resolveAuthContext();
        $user = $context['actor'];
        $shopOwnerId = $context['shop_owner_id'];

        if (!$user || !$shopOwnerId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $change = SalaryChange::forShopOwner($shopOwnerId)
            ->where('status', SalaryChange::STATUS_PENDING)
            ->findOrFail($id);

        // Only the proposer or authorized salary handlers can cancel
        if (
            $change->proposed_by !== $user->id
            && !$this->authorizeHR($user)
            && !$this->authorizeApprover($user)
        ) {
            return response()->json(['error' => 'Only the proposer or an authorized salary handler can cancel this request.'], 403);
        }

        $change->status = SalaryChange::STATUS_CANCELLED;
        $change->save();

        return response()->json(['message' => 'Salary change cancelled.', 'data' => $change]);
    }
}
