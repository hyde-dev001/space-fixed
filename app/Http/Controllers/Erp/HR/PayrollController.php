<?php

namespace App\Http\Controllers\Erp\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Models\HR\BranchPayrollSetting;
use App\Models\Finance\Expense;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\HR\AuditLog;
use App\Services\HR\PayrollService;
use App\Services\NotificationService;
use App\Traits\HR\LogsHRActivity;
use App\Notifications\HR\PayslipGenerated;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;
use Carbon\Carbon;

/**
 * PayrollController
 *
 * Core payroll CRUD + single-employee operations.
 *
 * Batch generation → PayrollBatchController
 * Component management → PayrollComponentController
 * Payslip approval workflow → PayslipApprovalController
 */
class PayrollController extends Controller
{
    use LogsHRActivity;

    protected PayrollService $payrollService;
    protected NotificationService $notificationService;

    public function __construct(PayrollService $payrollService, NotificationService $notificationService)
    {
        $this->payrollService = $payrollService;
        $this->notificationService = $notificationService;
    }

    // ============================================================
    // AUTH HELPER
    // ============================================================

    private function authorizeUser(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        if ($shopOwner) {
            return $shopOwner;
        }

        $user = Auth::guard('user')->user();

        if (! $user) {
            return null;
        }

        if (
            ! $user->hasRole('Shop Owner')
            && ! $user->can('access-payslip-generation')
            && ! $user->can('access-view-payslip')
            && ! $user->can('access-payslip-approval')
            && ! $user->can('access-approval-workflow')
        ) {
            return null;
        }

        return $user;
    }

    private function shopOwnerId(\Illuminate\Contracts\Auth\Authenticatable $actor): int
    {
        return $actor instanceof ShopOwner
            ? (int) $actor->getKey()
            : (int) ($actor->shop_owner_id ?? 0);
    }

    private function canDisbursePayroll($user): bool
    {
        if (! $user) {
            return false;
        }

        try {
            return $user->hasRole('Shop Owner')
                || $user->can('access-payslip-approval')
                || $user->can('access-approval-workflow');
        } catch (\Throwable $e) {
            // Defensive fallback: if role metadata is stale/missing in production,
            // still allow explicit permission checks to decide disbursement access.
            \Log::warning('Payroll disbursement role check fallback applied', [
                'user_id' => $user->id ?? null,
                'shop_owner_id' => $user instanceof ShopOwner ? $user->getKey() : ($user->shop_owner_id ?? null),
                'error' => $e->getMessage(),
            ]);

            return $user->can('access-payslip-approval')
                || $user->can('access-approval-workflow');
        }
    }

    // ============================================================
    // CRUD
    // ============================================================

    /**
     * Display a listing of payrolls with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = Payroll::forShopOwner($this->shopOwnerId($user))
            ->with('employee:id,first_name,last_name,department');

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('payroll_period', 'like', "%{$term}%")
                  ->orWhere('employee_id', 'like', "%{$term}%")
                  ->orWhereHas('employee', fn ($e) =>
                      $e->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('department', 'like', "%{$term}%")
                        ->orWhere('employee_id', 'like', "%{$term}%")
                  );
            });
        }

        if ($request->filled('employee_id')) {
            $query->forEmployee($request->employee_id);
        }

        if ($request->filled('period')) {
            $query->forPeriod($request->period);
        }

        if ($request->filled('workflow_status')) {
            $workflowStatus = strtolower((string) $request->workflow_status);

            if ($workflowStatus === 'rejected') {
                $query->where('approval_status', 'rejected');
            } elseif ($workflowStatus === 'approved') {
                $query->where(function ($q) {
                    $q->where('status', 'approved')
                      ->orWhere('approval_status', 'approved');
                });
            } elseif ($workflowStatus === 'pending') {
                $query->where('approval_status', 'pending')
                      ->where('status', 'pending');
            } elseif (in_array($workflowStatus, ['paid', 'processed'], true)) {
                $query->withStatus($workflowStatus);
            }
        }

        if ($request->filled('status')) {
            $query->withStatus($request->status);
        }

        if ($request->filled('department')) {
            $query->whereHas('employee', fn ($q) =>
                $q->where('department', $request->department)
            );
        }

        $payrolls = $query->orderBy('payroll_period', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($payrolls);
    }

    /**
     * Create a single-employee payroll via PayrollService (full tax-bracket pipeline).
     *
     * POST /api/hr/payroll
     *
     * Delegates entirely to PayrollService::generatePayroll() so that tax brackets,
     * SSS, PhilHealth, and Pag-IBIG are always applied consistently — the same
     * calculation engine used by the batch generator.
     *
     * The payroll is set to 'pending' after creation so it flows through the
     * standard approval workflow (pending → approved → paid).
     *
     * Optional allowance inputs (salesCommission, performanceBonus, otherAllowances)
     * are mapped to custom PayrollComponent rows that the service appends on top of
     * its standard earnings/deductions pipeline.
     *
     * Note: baseSalary and deductions parameters are no longer accepted — base salary
     * is always read from the employee record, and statutory deductions are computed
     * by the service.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id'      => 'required|exists:employees,id',
            'payrollPeriod'    => 'required|string',
            'paymentMethod'    => 'required|in:bank_transfer,check,cash',
            'attendance_days'  => 'nullable|integer|min:0|max:31',
            'leave_days'       => 'nullable|integer|min:0|max:31',
            'absent_days'      => 'nullable|integer|min:0|max:31',
            'overtime_hours'   => 'nullable|numeric|min:0|max:744',
            'special_holiday_hours' => 'nullable|numeric|min:0|max:744',
            'regular_holiday_hours' => 'nullable|numeric|min:0|max:744',
            'undertime_hours'  => 'nullable|numeric|min:0|max:744',
            'salesCommission'  => 'nullable|numeric|min:0',
            'performanceBonus' => 'nullable|numeric|min:0',
            'otherAllowances'  => 'nullable|numeric|min:0',
            'notes'            => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee = Employee::forShopOwner($this->shopOwnerId($user))
            ->where('status', 'active')
            ->findOrFail($request->employee_id);

        $existingPayroll = Payroll::forEmployee($request->employee_id)
            ->forPeriod($request->payrollPeriod)
            ->first();

        if ($existingPayroll) {
            if ((string) $existingPayroll->approval_status === 'rejected') {
                DB::transaction(function () use ($existingPayroll) {
                    // Remove stale approval workflow artifacts before regenerating.
                    $existingPayroll->approval()->delete();
                    $existingPayroll->components()->delete();
                    $existingPayroll->delete();
                });
            } else {
                return response()->json([
                    'error' => 'Payroll already exists for this employee and period',
                ], 422);
            }
        }

        $extraEarnings = $this->payrollService->resolveAdditionalEarnings($employee, $request->payrollPeriod, [
            'sales_commission' => (float) ($request->salesCommission ?? 0),
            'performance_bonus' => (float) ($request->performanceBonus ?? 0),
            'other_allowances' => (float) ($request->otherAllowances ?? 0),
        ]);
        $customComponents = $extraEarnings['components'];

        // Build overrides for the service (attendance, leave, overtime, payment method).
        $overrides = ['payment_method' => $request->paymentMethod];
        if ($request->filled('attendance_days')) $overrides['attendance_days'] = (int)   $request->attendance_days;
        if ($request->filled('leave_days'))      $overrides['leave_days']      = (int)   $request->leave_days;
        if ($request->filled('absent_days'))     $overrides['absent_days']     = (int)   $request->absent_days;
        if ($request->filled('overtime_hours'))  $overrides['overtime_hours']  = (float) $request->overtime_hours;
        if ($request->filled('special_holiday_hours')) $overrides['special_holiday_hours'] = (float) $request->special_holiday_hours;
        if ($request->filled('regular_holiday_hours')) $overrides['regular_holiday_hours'] = (float) $request->regular_holiday_hours;
        if ($request->filled('undertime_hours')) $overrides['undertime_hours'] = (float) $request->undertime_hours;

        try {
            $payroll = $this->payrollService->generatePayroll(
                $employee,
                $request->payrollPeriod,
                $customComponents,
                $overrides
            );

            // The service always sets status = 'processed'. Reset it to the
            // workflow entry state so Finance and Owner approvals are explicit.
            $payroll->update([
                'status' => 'pending',
                'approval_status' => 'pending',
                'approved_by' => null,
                'approved_at' => null,
                'approval_notes' => null,
                'final_approved_by' => null,
                'final_approved_at' => null,
                'final_approval_notes' => null,
                'payout_reference' => null,
                'payout_proof_type' => null,
                'payout_proof_reference' => null,
                'payout_proof_notes' => null,
                'disbursed_by' => null,
                'disbursed_at' => null,
            ]);

            $this->auditCustom(
                AuditLog::MODULE_PAYROLL,
                AuditLog::ACTION_CREATED,
                "Payroll generated: {$employee->first_name} {$employee->last_name} – Period {$request->payrollPeriod} – Net: {$payroll->net_salary}",
                [
                    'severity'    => AuditLog::SEVERITY_INFO,
                    'tags'        => ['financial', 'payroll', 'sensitive'],
                    'employee_id' => $employee->id,
                    'entity_type' => Payroll::class,
                    'entity_id'   => $payroll->id,
                    'new_values'  => [
                        'status' => (string) $payroll->status,
                        'approval_status' => (string) $payroll->approval_status,
                        'payroll_period' => (string) $payroll->payroll_period,
                        'gross_salary' => (float) $payroll->gross_salary,
                        'net_salary' => (float) $payroll->net_salary,
                        'payment_method' => (string) $payroll->payment_method,
                    ],
                ]
            );

            return response()->json([
                'message' => 'Payroll created successfully',
                'payroll' => $payroll->fresh(['components', 'employee']),
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Payroll store failed', [
                'employee_id' => $request->employee_id,
                'period'      => $request->payrollPeriod,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Payroll generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified payroll.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($this->shopOwnerId($user))
            ->with('employee')
            ->findOrFail($id);

        return response()->json($payroll);
    }

    /**
     * Update editable metadata on a pending payroll.
     *
     * Salary figures and deduction totals are system-derived and cannot be
     * overridden here.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($this->shopOwnerId($user))->findOrFail($id);

        if ($payroll->status !== 'pending') {
            return response()->json(['error' => 'Cannot update payroll that is not pending'], 422);
        }

        $forbiddenFields = ['baseSalary', 'allowances', 'deductions', 'netSalary', 'grossSalary'];
        $providedForbiddenFields = [];
        $input = $request->all();

        foreach ($forbiddenFields as $field) {
            if (array_key_exists($field, $input)) {
                $providedForbiddenFields[] = $field;
            }
        }

        if (! empty($providedForbiddenFields)) {
            $errors = [];
            foreach ($providedForbiddenFields as $field) {
                $errors[$field] = ['Manual salary and deduction overrides are not allowed. Regenerate payroll to apply calculation changes.'];
            }

            return response()->json(['errors' => $errors], 422);
        }

        $validator = Validator::make($request->all(), [
            'paymentMethod' => 'sometimes|required|in:bank-transfer,check,cash',
            'notes'         => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $updates = [];

        if (array_key_exists('paymentMethod', $data)) {
            $updates['payment_method'] = str_replace('-', '_', (string) $data['paymentMethod']);
        }

        if (array_key_exists('notes', $data)) {
            $updates['approval_notes'] = $data['notes'];
        }

        if ($updates === []) {
            return response()->json([
                'message' => 'No editable payroll fields were provided',
                'payroll' => $payroll->load('employee'),
            ]);
        }

        $payroll->update($updates);

        return response()->json([
            'message' => 'Payroll updated successfully',
            'payroll' => $payroll->load('employee'),
        ]);
    }

    /**
     * Delete a pending payroll.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($this->shopOwnerId($user))->findOrFail($id);

        if ($payroll->status !== 'pending') {
            return response()->json(['error' => 'Cannot delete payroll that is not pending'], 422);
        }

        $payroll->delete();

        return response()->json(['message' => 'Payroll deleted successfully']);
    }

    // ============================================================
    // APPROVAL
    // ============================================================

    /**
     * Deprecated HR approval endpoint kept for backward compatibility.
     * Finance checker approval and owner final approval now live in the
     * Finance approval workflow.
     *
     * @deprecated Use /api/finance/payslip-approvals/{id}/approve (Finance checker)
     *             or /api/finance/payslip-approvals/{id}/final-approve (Final approver) instead.
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Log deprecation warning for monitoring
        \Log::warning('Deprecated PayrollController::approve() endpoint called', [
            'payroll_id' => $id,
            'user_id' => $user?->id ?? 'unknown',
            'timestamp' => now(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'error' => 'Payroll approval endpoint deprecated and moved to Finance workflow',
            'message' => 'HR can prepare payroll, but approval must now happen in the Finance module.',
            'migration_guide' => [
                'for_finance_checker' => [
                    'endpoint' => 'POST /api/finance/payslip-approvals/{id}/approve',
                    'description' => 'Finance checker review and approval',
                    'required_permission' => 'access-payslip-approval or approve-payroll',
                ],
                'for_final_approver' => [
                    'endpoint' => 'POST /api/finance/payslip-approvals/{id}/final-approve',
                    'description' => 'Shop owner final approval (owner-only)',
                    'required_permission' => 'Shop Owner role or auth:shop_owner',
                ],
                'for_disbursement' => [
                    'endpoint' => 'POST /api/finance/payslip-approvals/disburse',
                    'description' => 'Mark final-approved payslips as paid',
                    'required_fields' => [
                        'payrollIds' => 'array of payroll IDs',
                    ],
                    'optional_fields' => [
                        'paymentDate' => 'YYYY-MM-DD format',
                        'paymentMethod' => 'bank_transfer|check|cash',
                        'payoutReference' => 'transaction reference',
                        'payoutProofType' => 'bank_reference|receipt_number|check_number|other',
                        'payoutProofReference' => 'proof identifier',
                        'payoutProofNotes' => 'free-text notes',
                    ],
                ],
            ],
            'documentation' => '/docs/api/finance-payslip-approval',
        ], 410);
    }

    // ============================================================
    // BATCH STATUS OPERATIONS
    // ============================================================

    /**
     * Mark one or more final-approved payrolls as paid and capture payout proof.
     */
    public function process(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('user')->user();
            if (! $user) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if (! $this->canDisbursePayroll($user)) {
                return response()->json([
                    'error' => 'Unauthorized. Payroll disbursement requires payroll or approval workflow access.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'payrollIds' => 'required|array',
                'payrollIds.*' => 'exists:payrolls,id',
                'paymentDate' => 'nullable|date',
                'paymentMethod' => 'nullable|in:bank_transfer,check,cash',
                'payoutReference' => 'nullable|string|max:255',
                'payoutProofType' => 'nullable|in:bank_reference,receipt_number,check_number,other',
                'payoutProofReference' => 'nullable|string|max:255',
                'payoutProofNotes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $payrollIds = array_values(array_unique(array_map('intval', (array) $request->payrollIds)));
            if (count($payrollIds) !== count((array) $request->payrollIds)) {
                return response()->json([
                    'errors' => [
                        'payrollIds' => ['Duplicate payroll IDs are not allowed in a single disbursement request.'],
                    ],
                ], 422);
            }

            $processedCount = 0;
            $errors = [];
            $idempotencyConflicts = 0;
            $paymentDate = (string) ($request->input('paymentDate') ?: now()->toDateString());

            foreach ($payrollIds as $payrollId) {
                try {
                    DB::transaction(function () use ($user, $payrollId, $request, $paymentDate) {
                        $payroll = Payroll::forShopOwner($this->shopOwnerId($user))
                            ->with('employee')
                            ->whereKey($payrollId)
                            ->lockForUpdate()
                            ->firstOrFail();

                        if ($payroll->status === 'paid') {
                            throw new \RuntimeException("Payroll ID {$payrollId} is already marked as paid");
                        }

                        if ($payroll->approval_status !== 'approved' || empty($payroll->approved_by)) {
                            throw new \RuntimeException("Payroll ID {$payrollId} requires Finance checker approval before disbursement");
                        }

                        if ($payroll->status !== 'approved' || empty($payroll->final_approved_by)) {
                            throw new \RuntimeException("Payroll ID {$payrollId} requires final owner approval before disbursement");
                        }

                        if ((int) $payroll->approved_by === (int) $payroll->final_approved_by) {
                            throw new \RuntimeException("Payroll ID {$payrollId} has an invalid approval chain. Checker and final approver must differ.");
                        }

                        $disbursementDetails = [
                            'disbursed_by' => (int) $user->id,
                        ];

                        if ($request->filled('paymentMethod')) {
                            $disbursementDetails['payment_method'] = (string) $request->input('paymentMethod');
                        }

                        if ($request->filled('payoutReference')) {
                            $disbursementDetails['payout_reference'] = (string) $request->input('payoutReference');
                        }

                        if ($request->filled('payoutProofType')) {
                            $disbursementDetails['payout_proof_type'] = (string) $request->input('payoutProofType');
                        }

                        if ($request->filled('payoutProofReference')) {
                            $disbursementDetails['payout_proof_reference'] = (string) $request->input('payoutProofReference');
                        }

                        if ($request->filled('payoutProofNotes')) {
                            $disbursementDetails['payout_proof_notes'] = (string) $request->input('payoutProofNotes');
                        }

                        $payroll->markAsPaid($paymentDate, $disbursementDetails);

                        // Expense sync is best-effort only. Do not block disbursement if
                        // finance expense schema/config differs in production.
                        try {
                            $this->createExpenseFromPaidPayroll($payroll, (int) $user->id, $paymentDate);
                        } catch (\Throwable $expenseError) {
                            \Log::warning('Payroll disbursement expense sync skipped', [
                                'payroll_id' => $payroll->id,
                                'shop_owner_id' => $payroll->shop_owner_id,
                                'error' => $expenseError->getMessage(),
                            ]);
                        }
                    });

                    $processedCount++;
                } catch (\RuntimeException $e) {
                    if (str_contains($e->getMessage(), 'already marked as paid')) {
                        $idempotencyConflicts++;
                    }
                    $errors[] = $e->getMessage();
                } catch (\Throwable $e) {
                    $errors[] = "Error processing payroll ID {$payrollId}: " . $e->getMessage();
                }
            }

            if ($processedCount === 0 && ! empty($errors)) {
                $allErrorsAreIdempotencyConflicts = $idempotencyConflicts === count($errors);

                return response()->json([
                    'message' => $allErrorsAreIdempotencyConflicts
                        ? 'Disbursement conflict: payroll is already marked as paid'
                        : 'Payroll disbursement failed',
                    'processed' => $processedCount,
                    'errors' => $errors,
                ], $allErrorsAreIdempotencyConflicts ? 409 : 422);
            }

            return response()->json([
                'message' => 'Payroll disbursement completed',
                'processed' => $processedCount,
                'errors' => $errors,
            ]);
        } catch (QueryException $e) {
            \Log::error('Payroll disbursement query failure', [
                'user_id' => Auth::guard('user')->id(),
                'shop_owner_id' => Auth::guard('user')->user()?->shop_owner_id,
                'sql_state' => $e->errorInfo[0] ?? null,
                'db_code' => $e->errorInfo[1] ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Payroll disbursement failed due to a database schema mismatch. Please contact support.',
                'error' => 'DISBURSEMENT_SCHEMA_MISMATCH',
            ], 422);
        } catch (\Throwable $e) {
            \Log::error('Payroll disbursement unexpected failure', [
                'user_id' => Auth::guard('user')->id(),
                'shop_owner_id' => Auth::guard('user')->user()?->shop_owner_id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unexpected error during payroll disbursement. Please try again.',
                'error' => 'DISBURSEMENT_UNEXPECTED_ERROR',
            ], 500);
        }
    }

    /**
     * Auto-create Finance expense from paid payroll disbursement.
     */
    private function createExpenseFromPaidPayroll(Payroll $payroll, int $userId, string $paymentDate): void
    {
        $amount = (float) ($payroll->net_salary ?? 0);
        if ($amount <= 0) {
            return;
        }

        $template = config('finance_expense_templates.payroll', []);

        $category = (string) ($template['category'] ?? 'Payroll');
        // Auto-approved since the payroll is already disbursed (paid)
        $status = 'approved';
        $referencePrefix = (string) ($template['reference_prefix'] ?? 'PAY-EXP-');
        $descriptionTemplate = (string) ($template['description_template'] ?? 'Auto-generated from Payroll: :employee_name (:payroll_period)');
        $metaSource = (string) ($template['meta_source'] ?? 'payroll');

        $referenceToken = (string) $payroll->id;
        $reference = $referencePrefix . $referenceToken;

        $payroll->loadMissing('employee');
        $employeeName = trim((string) ($payroll->employee?->first_name ?? '') . ' ' . (string) ($payroll->employee?->last_name ?? ''));
        if ($employeeName === '') {
            $employeeName = 'Employee #' . $payroll->employee_id;
        }

        $description = strtr($descriptionTemplate, [
            ':reference' => $referenceToken,
            ':employee_name' => $employeeName,
            ':payroll_period' => (string) ($payroll->payroll_period ?? 'N/A'),
        ]);

        $expenseDate = \Illuminate\Support\Carbon::parse($paymentDate)->toDateString();

        Expense::firstOrCreate(
            ['reference' => $reference],
            [
                'date' => $expenseDate,
                'category' => $category,
                'vendor' => $employeeName,
                'description' => $description,
                'amount' => $amount,
                'tax_amount' => 0,
                'status' => $status,
                'shop_id' => $payroll->shop_owner_id,
                'meta' => [
                    'source' => $metaSource,
                    'payroll_id' => $payroll->id,
                    'employee_id' => $payroll->employee_id,
                    'payroll_period' => $payroll->payroll_period,
                    'created_by' => $userId,
                    'payment_method' => $payroll->payment_method,
                    'payout_reference' => $payroll->payout_reference,
                    'payout_proof_type' => $payroll->payout_proof_type,
                    'payout_proof_reference' => $payroll->payout_proof_reference,
                ],
            ]
        );
    }

    // ============================================================
    // STATISTICS & EXPORTS
    // ============================================================

    /**
     * Get payroll statistics for a period.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $period = $request->get('period');
        $query  = Payroll::forShopOwner($this->shopOwnerId($user));

        if ($period) {
            $query->forPeriod($period);
        }

        $totalPayrolls = (clone $query)->count();
        $pendingPayrolls = (clone $query)->pending()->count();
        $approvedPayrolls = (clone $query)->withStatus('approved')->count();
        $paidPayrolls = (clone $query)->withStatus('paid')->count();
        $totalAmount = (clone $query)->sum('net_salary');
        $pendingAmount = (clone $query)->pending()->sum('net_salary');
        $paidAmount = (clone $query)->withStatus('paid')->sum('net_salary');

        return response()->json([
            'totalPayrolls' => $totalPayrolls,
            'pendingPayrolls' => $pendingPayrolls,
            'processedPayrolls' => $approvedPayrolls,
            'approvedPayrolls' => $approvedPayrolls,
            'paidPayrolls' => $paidPayrolls,
            'totalAmount' => $totalAmount,
            'pendingAmount' => $pendingAmount,
            'paidAmount' => $paidAmount,
        ]);
    }

    /**
     * Export a single payslip as PDF.
     *
     * Security: Validates role before allowing sensitive data export.
     */
    public function exportPayslip(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            \Log::warning('Unauthorized payroll export attempt', [
                'user_id'    => Auth::guard('user')->id(),
                'payroll_id' => $id,
            ]);
            return response()->json([
                'error' => 'Unauthorized. You do not have permission to export payslips.',
            ], 403);
        }

        $payroll = Payroll::forShopOwner($this->shopOwnerId($user))
            ->with('employee')
            ->findOrFail($id);

        \Log::info('Payroll exported', [
            'exporter_id'   => $user->id,
            'exporter_role' => $user->getRoleNames()->first(),
            'payroll_id'    => $id,
            'employee_id'   => $payroll->employee_id,
        ]);

        // TODO: Generate PDF payslip using dompdf or similar
        return response()->json([
            'message' => 'PDF generation not implemented yet',
            'payroll' => $payroll,
        ]);
    }

    // ============================================================
    // RECALCULATE / SUMMARY / PREVIEW
    // ============================================================

    /**
     * Recalculate a pending payroll using PayrollService (full tax-bracket pipeline).
     */
    public function recalculate(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($this->shopOwnerId($user))->findOrFail($id);

        if ($payroll->status !== 'pending') {
            return response()->json(['error' => 'Cannot recalculate non-pending payroll'], 422);
        }

        $validator = Validator::make($request->all(), [
            'attendance_days' => 'sometimes|integer|min:0|max:31',
            'leave_days'      => 'sometimes|integer|min:0|max:31',
            'overtime_hours'  => 'sometimes|numeric|min:0|max:744',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $recalculated = $this->payrollService->recalculatePayroll(
                $payroll,
                $request->only(['attendance_days', 'leave_days', 'overtime_hours'])
            );

            return response()->json([
                'message' => 'Payroll recalculated successfully',
                'payroll' => $recalculated->load('components', 'employee'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Recalculation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get aggregated payroll summary for a date range via PayrollService.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $summary = $this->payrollService->getPayrollSummary(
                $this->shopOwnerId($user),
                $request->period_start,
                $request->period_end
            );

            return response()->json($summary);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Summary generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Controlled 13th-month release process.
     *
     * December-only by default; can be overridden with allow_non_december=true
     * for authorized users.
     */
    public function releaseThirteenthMonth(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $hasReleasePermission =
            $user->hasRole('Shop Owner')
            || $user->can('access-approval-workflow')
            || $user->can('access-payslip-approval');

        if (! $hasReleasePermission) {
            return response()->json([
                'error' => 'Unauthorized. 13th-month release requires final approval permissions.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|min:2000|max:2100',
            'employee_ids' => 'sometimes|array',
            'employee_ids.*' => 'exists:employees,id',
            'release_date' => 'sometimes|date',
            'allow_non_december' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $result = $this->payrollService->releaseThirteenthMonth(
                $this->shopOwnerId($user),
                (int) $request->year,
                (int) $user->id,
                $request->input('employee_ids', []),
                [
                    'release_date' => $request->input('release_date'),
                    'allow_non_december' => (bool) $request->boolean('allow_non_december', false),
                ]
            );

            $this->auditCustom(
                AuditLog::MODULE_PAYROLL,
                'thirteenth_month_release',
                '13th-month controlled release executed for year ' . (int) $request->year,
                [
                    'severity' => AuditLog::SEVERITY_WARNING,
                    'tags' => ['payroll', '13th-month', 'release', 'controlled'],
                    'year' => (int) $request->year,
                    'processed_count' => (int) ($result['processed_count'] ?? 0),
                    'skipped_count' => (int) ($result['skipped_count'] ?? 0),
                ]
            );

            return response()->json([
                'message' => '13th-month release process completed',
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => '13th-month release failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * 13th-month accrual vs release reconciliation report.
     */
    public function thirteenthMonthReconciliation(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|min:2000|max:2100',
            'employee_ids' => 'sometimes|array',
            'employee_ids.*' => 'exists:employees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $report = $this->payrollService->getThirteenthMonthReconciliationReport(
                $this->shopOwnerId($user),
                (int) $request->year,
                [
                    'employee_ids' => $request->input('employee_ids', []),
                ]
            );

            return response()->json($report);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Reconciliation report generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate payroll preview for a single employee from request inputs.
     * Returns computed figures without saving to database.
     *
     * Used for live "what-if" calculations on the frontend.
     */
    public function calculatePreview(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id'    => 'required|exists:employees,id',
            'start_date'     => 'required|date',
            'end_date'       => 'required|date|after_or_equal:start_date',
            'regular_hours'  => 'required|numeric|min:0',
            'attendance_days' => 'nullable|integer|min:0|max:31',
            'leave_days'     => 'nullable|integer|min:0|max:31',
            'overtime_hours' => 'nullable|numeric|min:0',
            'special_holiday_hours' => 'nullable|numeric|min:0',
            'regular_holiday_hours' => 'nullable|numeric|min:0',
            'undertime_hours' => 'nullable|numeric|min:0',
            'absent_days'    => 'nullable|integer|min:0',
            'sales_commission' => 'nullable|numeric|min:0',
            'performance_bonus' => 'nullable|numeric|min:0',
            'other_allowances' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee = Employee::forShopOwner($this->shopOwnerId($user))->findOrFail($request->employee_id);

        $regularHours = (float) $request->regular_hours;
        $attendanceDays = $request->filled('attendance_days')
            ? (int) $request->attendance_days
            : (int) floor($regularHours / 8);
        $leaveDays = (int) ($request->leave_days ?? 0);
        $overtimeHours = (float) ($request->overtime_hours ?? 0);
        $specialHolidayHours = (float) ($request->special_holiday_hours ?? 0);
        $regularHolidayHours = (float) ($request->regular_holiday_hours ?? 0);
        $undertimeHours = (float) ($request->undertime_hours ?? 0);
        $absentDays = (int) ($request->absent_days ?? 0);

        $preview = $this->payrollService->previewPayroll(
            $employee,
            Carbon::parse((string) $request->start_date)->toDateString() . ' to ' . Carbon::parse((string) $request->end_date)->toDateString(),
            [
                'sales_commission' => (float) ($request->sales_commission ?? 0),
                'performance_bonus' => (float) ($request->performance_bonus ?? 0),
                'other_allowances' => (float) ($request->other_allowances ?? 0),
            ],
            [
                'attendance_days' => $attendanceDays,
                'leave_days' => $leaveDays,
                'overtime_hours' => $overtimeHours,
                'special_holiday_hours' => $specialHolidayHours,
                'regular_holiday_hours' => $regularHolidayHours,
                'undertime_hours' => $undertimeHours,
                'absent_days' => $absentDays,
            ]
        );

        $resolvedAdditionalEarnings = $preview['resolved_additional_earnings'] ?? [];
        $salesCommission = (float) ($resolvedAdditionalEarnings['sales_commission'] ?? 0);
        $performanceBonus = (float) ($resolvedAdditionalEarnings['performance_bonus'] ?? 0);
        $otherAllowances = (float) ($resolvedAdditionalEarnings['other_allowances'] ?? 0);
        $calculation = $preview['calculation'] ?? [];

        $ruleEngine = $calculation['rules'] ?? [];
        $breakdown = $calculation['breakdown'] ?? [];
        $statutory = $calculation['statutory'] ?? [];

        $basicPay = (float) ($breakdown['basic_pay'] ?? 0);
        $dailyRate = (float) ($ruleEngine['daily_rate'] ?? ($employee->salary ?? 0));
        $monthlyEquivalentSalary = (float) ($ruleEngine['monthly_base_salary'] ?? ($dailyRate * 26));
        $overtimePay = (float) ($breakdown['overtime_pay'] ?? 0);
        $specialHolidayPay = (float) ($breakdown['special_holiday_pay'] ?? 0);
        $regularHolidayPay = (float) ($breakdown['regular_holiday_pay'] ?? 0);
        $tax = (float) ($statutory['withholding_tax'] ?? 0);
        $sss = (float) ($statutory['sss_contribution'] ?? 0);
        $philhealth = (float) ($statutory['philhealth_contribution'] ?? 0);
        $pagibig = (float) ($statutory['pagibig_contribution'] ?? 0);
        $absentDeductions = (float) ($breakdown['absent_deductions'] ?? 0);
        $undertimeDeductions = (float) ($breakdown['undertime_deductions'] ?? 0);
        $totalDeductions = (float) ($calculation['total_deductions'] ?? 0);
        $totalEarnings = (float) ($calculation['gross_salary'] ?? 0);
        $netPay = (float) ($calculation['net_salary'] ?? 0);

        return response()->json([
            'calculation' => [
                'employee' => [
                    'id'             => $employee->id,
                    'name'           => "{$employee->first_name} {$employee->last_name}",
                    'daily_rate'     => round($dailyRate, 2),
                    'monthly_salary' => round($monthlyEquivalentSalary, 2),
                ],
                'hours' => [
                    'attendance_days' => $attendanceDays,
                    'leave_days' => $leaveDays,
                    'regular_hours'  => round($regularHours, 2),
                    'overtime_hours' => round($overtimeHours, 2),
                    'special_holiday_hours' => round($specialHolidayHours, 2),
                    'regular_holiday_hours' => round($regularHolidayHours, 2),
                    'undertime_hours' => round($undertimeHours, 2),
                    'absent_days'    => $absentDays,
                ],
                'earnings' => [
                    'basic_pay'          => round($basicPay, 2),
                    'overtime_pay'       => round($overtimePay, 2),
                    'special_holiday_pay' => round($specialHolidayPay, 2),
                    'regular_holiday_pay' => round($regularHolidayPay, 2),
                    'sales_commission'   => round($salesCommission, 2),
                    'performance_bonus'  => round($performanceBonus, 2),
                    'other_allowances'   => round($otherAllowances, 2),
                    'total_earnings'     => round($totalEarnings, 2),
                ],
                'deductions' => [
                    'withholding_tax'         => round($tax, 2),
                    'sss_contribution'        => round($sss, 2),
                    'philhealth_contribution' => round($philhealth, 2),
                    'pagibig_contribution'    => round($pagibig, 2),
                    'absent_deductions'       => round($absentDeductions, 2),
                    'undertime_deductions'    => round($undertimeDeductions, 2),
                    'total_deductions'        => round($totalDeductions, 2),
                ],
                'net_pay'   => round($netPay, 2),
                'gross_pay' => round($totalEarnings, 2),
            ],
        ]);
    }

    // ============================================================
    // PAYROLL PERIODS
    // ============================================================

    /**
    * Return the last 12 months + current month as selectable payroll periods.
    * Each entry includes:
    * - working-day count (Mon–Fri)
    * - expected regular hours derived from configured shop operating schedule
    * - whether attendance for that month is considered finalized
     */
    public function payrollPeriods(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $today   = Carbon::today();
        $periods = [];

        $payCycle = 'monthly';
        $payDayFirst = 15;
        $shopOwner = ShopOwner::find($this->shopOwnerId($user));

        if (Schema::hasTable('hr_branch_payroll_settings')) {
            $setting = BranchPayrollSetting::query()
                ->forShopOwner($this->shopOwnerId($user))
                ->active()
                ->orderBy('id')
                ->first();

            if ($setting) {
                $rawPayCycle = (string) ($setting->pay_cycle ?: 'monthly');
                if (! in_array($rawPayCycle, ['monthly', 'semi_monthly'], true)) {
                    \Log::warning('Unexpected pay_cycle detected in hr_branch_payroll_settings; defaulting to monthly', [
                        'shop_owner_id' => $this->shopOwnerId($user),
                        'raw_pay_cycle' => $rawPayCycle,
                    ]);
                    $rawPayCycle = 'monthly';
                }
                $payCycle = $rawPayCycle;
                $payDayFirst = (int) ($setting->pay_day_first ?: 15);
            }
        }

        // Generate from 11 months ago → current month, newest first after sorting.
        for ($offset = 0; $offset <= 11; $offset++) {
            $month = $today->copy()->startOfMonth()->subMonths($offset);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            if ($payCycle === 'semi_monthly') {
                $firstCutoffDay = max(1, min($payDayFirst, $monthEnd->day));
                $firstStart = $monthStart->copy();
                $firstEnd = $monthStart->copy()->day($firstCutoffDay);

                $secondStart = $firstEnd->copy()->addDay();
                $secondEnd = $monthEnd->copy();

                $segments = [
                    [
                        'start' => $firstStart,
                        'end' => $firstEnd,
                        'label' => sprintf('%s (%d-%d)', $month->format('F Y'), $firstStart->day, $firstEnd->day),
                    ],
                ];

                if ($secondStart->lte($secondEnd)) {
                    $segments[] = [
                        'start' => $secondStart,
                        'end' => $secondEnd,
                        'label' => sprintf('%s (%d-%d)', $month->format('F Y'), $secondStart->day, $secondEnd->day),
                    ];
                }

                foreach ($segments as $segment) {
                    $workingDays = 0;
                    $expectedAttendanceDays = 0;
                    $expectedRegularHours = 0.0;
                    $hasConfiguredOperatingHours = false;
                    $cursor = $segment['start']->copy();
                    while ($cursor->lte($segment['end'])) {
                        if ($cursor->isWeekday()) {
                            $workingDays++;
                        }

                        $dailyConfiguredHours = $this->resolveConfiguredDailyOperatingHours($shopOwner, $cursor);
                        if ($dailyConfiguredHours !== null) {
                            $expectedRegularHours += $dailyConfiguredHours;
                            if ($dailyConfiguredHours > 0) {
                                $expectedAttendanceDays++;
                            }
                            $hasConfiguredOperatingHours = true;
                        }

                        $cursor->addDay();
                    }

                    if ($segment['start']->gt($today)) {
                        $attendanceStatus = 'not_started';
                    } else {
                        $attendanceStatus = $segment['end']->lt($today) ? 'finalized' : 'pending';
                    }
                    $periodKey = $segment['start']->format('Y-m-d') . ' to ' . $segment['end']->format('Y-m-d');

                    $periods[] = [
                        'month'            => $segment['label'],
                        'periodKey'        => $periodKey,
                        'startDate'        => $segment['start']->format('Y-m-d'),
                        'endDate'          => $segment['end']->format('Y-m-d'),
                        'attendanceStatus' => $attendanceStatus,
                        'workingDays'      => $workingDays,
                        'expectedAttendanceDays' => $expectedAttendanceDays,
                        'expectedRegularHours' => round($expectedRegularHours, 2),
                        'hasConfiguredOperatingHours' => $hasConfiguredOperatingHours,
                        'payCycle'         => 'semi_monthly',
                    ];
                }

                continue;
            }

            $workingDays = 0;
            $expectedAttendanceDays = 0;
            $expectedRegularHours = 0.0;
            $hasConfiguredOperatingHours = false;
            $cursor = $monthStart->copy();
            while ($cursor->lte($monthEnd)) {
                if ($cursor->isWeekday()) {
                    $workingDays++;
                }

                $dailyConfiguredHours = $this->resolveConfiguredDailyOperatingHours($shopOwner, $cursor);
                if ($dailyConfiguredHours !== null) {
                    $expectedRegularHours += $dailyConfiguredHours;
                    if ($dailyConfiguredHours > 0) {
                        $expectedAttendanceDays++;
                    }
                    $hasConfiguredOperatingHours = true;
                }

                $cursor->addDay();
            }

            $attendanceStatus = $monthEnd->lt($today) ? 'finalized' : 'pending';

            $periods[] = [
                'month'            => $month->format('F Y'),
                'periodKey'        => $month->format('Y-m'),
                'startDate'        => $monthStart->format('Y-m-d'),
                'endDate'          => $monthEnd->format('Y-m-d'),
                'attendanceStatus' => $attendanceStatus,
                'workingDays'      => $workingDays,
                'expectedAttendanceDays' => $expectedAttendanceDays,
                'expectedRegularHours' => round($expectedRegularHours, 2),
                'hasConfiguredOperatingHours' => $hasConfiguredOperatingHours,
                'payCycle'         => 'monthly',
            ];
        }

        usort($periods, static function (array $a, array $b): int {
            return strcmp($b['startDate'], $a['startDate']);
        });

        return response()->json($periods);
    }

    private function resolveConfiguredDailyOperatingHours(?ShopOwner $shopOwner, Carbon $date): ?float
    {
        if (! $shopOwner) {
            return null;
        }

        $dayName = strtolower($date->format('l'));
        $openColumn = $dayName . '_open';
        $closeColumn = $dayName . '_close';

        $legacyOpen = $shopOwner->{$openColumn} ?? null;
        $legacyClose = $shopOwner->{$closeColumn} ?? null;

        if (! empty($legacyOpen) || ! empty($legacyClose)) {
            if (empty($legacyOpen) || empty($legacyClose)) {
                return 0.0;
            }

            return $this->computeDailyOperatingHours((string) $legacyOpen, (string) $legacyClose);
        }

        $operatingHours = $shopOwner->operating_hours;
        if (is_string($operatingHours)) {
            $rawOperatingHours = $operatingHours;
            $decoded = json_decode($rawOperatingHours, true);

            static $operatingHoursWarningLogged = [];
            $warningKey = (int) ($shopOwner->id ?? 0);

            if (json_last_error() !== JSON_ERROR_NONE) {
                if (! isset($operatingHoursWarningLogged[$warningKey])) {
                    \Log::warning('Malformed operating_hours JSON detected', [
                        'shop_owner_id' => $shopOwner->id,
                        'json_error' => json_last_error_msg(),
                    ]);
                    $operatingHoursWarningLogged[$warningKey] = true;
                }
            } elseif ($decoded !== null && ! is_array($decoded)) {
                if (! isset($operatingHoursWarningLogged[$warningKey])) {
                    \Log::warning('Unexpected operating_hours JSON type; expected object/array', [
                        'shop_owner_id' => $shopOwner->id,
                        'decoded_type' => gettype($decoded),
                    ]);
                    $operatingHoursWarningLogged[$warningKey] = true;
                }
            }

            $operatingHours = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($operatingHours) || ! array_key_exists($dayName, $operatingHours)) {
            return null;
        }

        $dayConfig = $operatingHours[$dayName];
        if (! is_array($dayConfig)) {
            return 0.0;
        }

        $isClosed = filter_var($dayConfig['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $open = $dayConfig['open'] ?? null;
        $close = $dayConfig['close'] ?? null;

        if ($isClosed || empty($open) || empty($close)) {
            return 0.0;
        }

        return $this->computeDailyOperatingHours((string) $open, (string) $close);
    }

    private function computeDailyOperatingHours(string $openTime, string $closeTime): float
    {
        try {
            $open = Carbon::parse('2000-01-01 ' . substr($openTime, 0, 8));
            $close = Carbon::parse('2000-01-01 ' . substr($closeTime, 0, 8));

            if ($close->lte($open)) {
                $close->addDay();
            }

            return round($open->diffInMinutes($close) / 60, 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    // ============================================================
    // STAFF SELF-SERVICE
    // ============================================================

    /**
     * Return the authenticated employee's released-ready or paid payslips.
     *
     * GET /api/staff/payslips/my
     *
     * Employees only see payrolls that have already passed final approval
     * (`status = approved`) or have been paid.
     */
    public function myPayslips(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();

        // Resolve the employee record by matching the logged-in user's e-mail
        $employee = Employee::where('shop_owner_id', $this->shopOwnerId($user))
            ->where('email', $user->email)
            ->first();

        if (! $employee) {
            return response()->json(['data' => [], 'message' => 'No employee record found'], 200);
        }

        $query = Payroll::forShopOwner($this->shopOwnerId($user))
            ->where('employee_id', $employee->id)
            ->where(function ($q) {
                $q->where('status', 'approved')
                  ->orWhere('status', 'paid');
            })
            ->with([
                'components' => fn ($q) => $q->orderBy('component_type')->orderBy('display_order'),
                'disburser:id,name',
            ]);

        $periodFilter = trim((string) $request->input('period', ''));
        if ($periodFilter !== '') {
            $query->where(function ($q) use ($periodFilter) {
                // Exact semi-monthly range key, e.g. "2026-03-01 to 2026-03-15"
                if (preg_match('/^\d{4}-\d{2}-\d{2}\s+to\s+\d{4}-\d{2}-\d{2}$/i', $periodFilter) === 1) {
                    [$startRaw, $endRaw] = preg_split('/\s+to\s+/i', $periodFilter, 2);
                    $startDate = Carbon::parse((string) $startRaw)->toDateString();
                    $endDate = Carbon::parse((string) $endRaw)->toDateString();

                    if ($startDate > $endDate) {
                        [$startDate, $endDate] = [$endDate, $startDate];
                    }

                    $q->whereDate('pay_period_start', $startDate)
                        ->whereDate('pay_period_end', $endDate);

                    return;
                }

                // Month filter, e.g. "2026-03" or "March 2026"
                if (preg_match('/^\d{4}-\d{2}$/', $periodFilter) === 1 || preg_match('/^[A-Za-z]{3,9}\s+\d{4}$/', $periodFilter) === 1) {
                    try {
                        if (preg_match('/^\d{4}-\d{2}$/', $periodFilter) === 1) {
                            $monthDate = Carbon::createFromFormat('Y-m', $periodFilter);
                        } else {
                            try {
                                $monthDate = Carbon::createFromFormat('F Y', $periodFilter);
                            } catch (\Throwable $e) {
                                $monthDate = Carbon::createFromFormat('M Y', $periodFilter);
                            }
                        }

                        $monthStart = $monthDate->copy()->startOfMonth()->toDateString();
                        $monthEnd = $monthDate->copy()->endOfMonth()->toDateString();

                        $q->where(function ($monthQuery) use ($monthStart, $monthEnd) {
                            $monthQuery->whereBetween('pay_period_start', [$monthStart, $monthEnd])
                                ->orWhereBetween('pay_period_end', [$monthStart, $monthEnd])
                                ->orWhere(function ($overlapQuery) use ($monthStart, $monthEnd) {
                                    $overlapQuery->whereDate('pay_period_start', '<=', $monthStart)
                                        ->whereDate('pay_period_end', '>=', $monthEnd);
                                });
                        });

                        return;
                    } catch (\Throwable $e) {
                        // Fall back to exact payroll_period match below.
                    }
                }

                // Single date filter, e.g. "2026-03-15" (match payslip containing that date)
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodFilter) === 1) {
                    $targetDate = Carbon::parse($periodFilter)->toDateString();
                    $q->whereDate('pay_period_start', '<=', $targetDate)
                        ->whereDate('pay_period_end', '>=', $targetDate);

                    return;
                }

                // Strict fallback: exact payroll_period text match only.
                $q->where('payroll_period', $periodFilter);
            });
        }

        $payslips = $query->orderByDesc('pay_period_start')
            ->paginate($request->get('per_page', 12));

        return response()->json($payslips);
    }
}
