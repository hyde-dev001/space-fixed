<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Models\HR\SalaryChange;
use App\Models\HR\Payroll;
use App\Models\User;
use App\Traits\HR\LogsHRActivity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SalaryChangeController extends Controller
{
    use LogsHRActivity;

    // ─── Auth helpers ─────────────────────────────────────────

    private function authorizeHR(User $user): bool
    {
        return $user->can('manage-salary-changes');
    }

    private function authorizeApprover(User $user): bool
    {
        return $user->hasRole('Shop Owner')
            || $user->can('approve-salary-change');
    }

    // ─── Index ────────────────────────────────────────────────

    /**
     * GET /api/hr/salary-changes
     * List salary changes for the authenticated user's shop.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$this->authorizeHR($user) && !$this->authorizeApprover($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = SalaryChange::forShopOwner($user->shop_owner_id)
            ->with(['employee:id,first_name,last_name,department,position',
                    'proposer:id,name',
                    'approver:id,name',
                    'rejector:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->employee_id);
        }

        if ($request->filled('change_type')) {
            $query->where('change_type', $request->change_type);
        }

        if ($request->filled('from')) {
            $query->whereDate('effective_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('effective_date', '<=', $request->to);
        }

        $perPage = (int) $request->get('per_page', 15);
        $results = $query->paginate($perPage);

        // Summary counts
        $baseCount = SalaryChange::forShopOwner($user->shop_owner_id);
        $summary = [
            'pending'   => (clone $baseCount)->where('status', 'pending')->count(),
            'approved'  => (clone $baseCount)->where('status', 'approved')->count(),
            'applied'   => (clone $baseCount)->where('status', 'applied')->count(),
            'rejected'  => (clone $baseCount)->where('status', 'rejected')->count(),
            'cancelled' => (clone $baseCount)->where('status', 'cancelled')->count(),
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
    }

    // ─── Store ────────────────────────────────────────────────

    /**
     * POST /api/hr/salary-changes
      * Propose a new daily-rate change.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$this->authorizeHR($user)) {
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
        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
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
                'shop_owner_id' => $user->shop_owner_id,
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
        $user = Auth::guard('user')->user();

        if (!$this->authorizeHR($user) && !$this->authorizeApprover($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $change = SalaryChange::forShopOwner($user->shop_owner_id)
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
        $user = Auth::guard('user')->user();

        if (!$this->authorizeApprover($user)) {
            return response()->json(['error' => 'Only authorized approvers can approve salary changes.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $change = SalaryChange::forShopOwner($user->shop_owner_id)
            ->where('status', SalaryChange::STATUS_PENDING)
            ->findOrFail($id);

        // Approver must not be the same person who proposed (unless they have override)
        if ($change->proposed_by === $user->id && !$user->can('override-salary-retroactive')) {
            return response()->json(['error' => 'You cannot approve a salary change you proposed.'], 403);
        }

        DB::beginTransaction();
        try {
            $change->status      = SalaryChange::STATUS_APPROVED;
            $change->approved_by = $user->id;
            $change->approved_at = now();
            $change->notes       = $request->notes;
            $change->save();

            // If effective date is today or past, immediately apply
            if ($change->effective_date->lte(now()->startOfDay())) {
                $change->applyToEmployee();
            }

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
                        'applied_now'      => $change->status === SalaryChange::STATUS_APPLIED,
                    ],
                    'severity'    => AuditLog::SEVERITY_WARNING,
                    'tags'        => ['salary_change', 'approved', 'phase_7'],
                ]
            );

            DB::commit();

            return response()->json([
                'message' => $change->status === SalaryChange::STATUS_APPLIED
                    ? 'Salary change approved and applied immediately.'
                    : 'Salary change approved. It will be applied on the effective date during the next payroll run.',
                'data'    => $change->fresh(['employee:id,first_name,last_name', 'approver:id,name']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Approval failed: ' . $e->getMessage()], 500);
        }
    }

    // ─── Reject ───────────────────────────────────────────────

    /**
     * POST /api/hr/salary-changes/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$this->authorizeApprover($user)) {
            return response()->json(['error' => 'Only authorized approvers can reject salary changes.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'required|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $change = SalaryChange::forShopOwner($user->shop_owner_id)
            ->where('status', SalaryChange::STATUS_PENDING)
            ->findOrFail($id);

        $change->status      = SalaryChange::STATUS_REJECTED;
        $change->rejected_by = $user->id;
        $change->rejected_at = now();
        $change->notes       = $request->notes;
        $change->save();

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
        $user = Auth::guard('user')->user();

        if (!$this->authorizeHR($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $change = SalaryChange::forShopOwner($user->shop_owner_id)
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
        $user = Auth::guard('user')->user();

        $change = SalaryChange::forShopOwner($user->shop_owner_id)
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
