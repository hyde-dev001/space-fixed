<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Models\HR\BranchPayrollSetting;
use App\Models\Finance\Expense;
use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Services\HR\PayrollService;
use App\Services\NotificationService;
use App\Traits\HR\LogsHRActivity;
use App\Notifications\HR\PayslipGenerated;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
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
        $user = Auth::guard('user')->user();

        if (
            ! $user->hasRole('Manager')
            && ! $user->can('access-employee-directory')
            && ! $user->can('access-attendance-records')
            && ! $user->can('access-payslip-generation') && !$user->can('access-view-payslip')
        ) {
            return null;
        }

        return $user;
    }

    private function canDisbursePayroll($user): bool
    {
        return $user
            && (
                $user->hasRole('Manager')
                || $user->hasRole('Shop Owner')
                || $user->can('access-payslip-generation')
                || $user->can('access-payslip-approval')
                || $user->can('access-approval-workflow')
            );
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

        $query = Payroll::forShopOwner($user->shop_owner_id)
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
            'rest_day_hours'   => 'nullable|numeric|min:0|max:744',
            'special_holiday_hours' => 'nullable|numeric|min:0|max:744',
            'regular_holiday_hours' => 'nullable|numeric|min:0|max:744',
            'night_differential_hours' => 'nullable|numeric|min:0|max:744',
            'undertime_hours'  => 'nullable|numeric|min:0|max:744',
            'salesCommission'  => 'nullable|numeric|min:0',
            'performanceBonus' => 'nullable|numeric|min:0',
            'otherAllowances'  => 'nullable|numeric|min:0',
            'notes'            => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->where('status', 'active')
            ->findOrFail($request->employee_id);

        $existingPayroll = Payroll::forEmployee($request->employee_id)
            ->forPeriod($request->payrollPeriod)
            ->first();

        if ($existingPayroll) {
            return response()->json([
                'error' => 'Payroll already exists for this employee and period',
            ], 422);
        }

        // Map optional allowance inputs to the custom-component format the service expects.
        // Each entry is appended to (not replacing) the standard component pipeline.
        $customComponents = [];

        if (($request->salesCommission ?? 0) > 0) {
            $customComponents[] = [
                'type'        => PayrollComponent::TYPE_EARNING,
                'name'        => 'Sales Commission',
                'base_amount' => (float) $request->salesCommission,
                'method'      => PayrollComponent::METHOD_COMMISSION,
                'taxable'     => true,
                'recurring'   => false,
                'description' => 'Sales commission – ' . $request->payrollPeriod,
            ];
        }

        if (($request->performanceBonus ?? 0) > 0) {
            $customComponents[] = [
                'type'        => PayrollComponent::TYPE_EARNING,
                'name'        => 'Performance Bonus',
                'base_amount' => (float) $request->performanceBonus,
                'method'      => PayrollComponent::METHOD_CUSTOM,
                'taxable'     => true,
                'recurring'   => false,
                'description' => 'Performance bonus – ' . $request->payrollPeriod,
            ];
        }

        if (($request->otherAllowances ?? 0) > 0) {
            $customComponents[] = [
                'type'        => PayrollComponent::TYPE_EARNING,
                'name'        => 'Other Allowances',
                'base_amount' => (float) $request->otherAllowances,
                'method'      => PayrollComponent::METHOD_ALLOWANCE,
                'taxable'     => false,
                'recurring'   => false,
                'description' => 'Additional allowances – ' . $request->payrollPeriod,
            ];
        }

        // Build overrides for the service (attendance, leave, overtime, payment method).
        $overrides = ['payment_method' => $request->paymentMethod];
        if ($request->filled('attendance_days')) $overrides['attendance_days'] = (int)   $request->attendance_days;
        if ($request->filled('leave_days'))      $overrides['leave_days']      = (int)   $request->leave_days;
        if ($request->filled('absent_days'))     $overrides['absent_days']     = (int)   $request->absent_days;
        if ($request->filled('overtime_hours'))  $overrides['overtime_hours']  = (float) $request->overtime_hours;
        if ($request->filled('rest_day_hours')) $overrides['rest_day_hours'] = (float) $request->rest_day_hours;
        if ($request->filled('special_holiday_hours')) $overrides['special_holiday_hours'] = (float) $request->special_holiday_hours;
        if ($request->filled('regular_holiday_hours')) $overrides['regular_holiday_hours'] = (float) $request->regular_holiday_hours;
        if ($request->filled('night_differential_hours')) $overrides['night_differential_hours'] = (float) $request->night_differential_hours;
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
                AuditLog::ACTION_GENERATED,
                "Payroll generated: {$employee->first_name} {$employee->last_name} – Period {$request->payrollPeriod} – Net: {$payroll->net_salary}",
                [
                    'severity'    => AuditLog::SEVERITY_WARNING,
                    'tags'        => ['financial', 'payroll', 'sensitive'],
                    'employee_id' => $employee->id,
                    'entity_type' => Payroll::class,
                    'entity_id'   => $payroll->id,
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

        $payroll = Payroll::forShopOwner($user->shop_owner_id)
            ->with('employee')
            ->findOrFail($id);

        return response()->json($payroll);
    }

    /**
     * Update a pending payroll.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($id);

        if ($payroll->status !== 'pending') {
            return response()->json(['error' => 'Cannot update payroll that is not pending'], 422);
        }

        $validator = Validator::make($request->all(), [
            'baseSalary'    => 'sometimes|required|numeric|min:0',
            'allowances'    => 'sometimes|nullable|numeric|min:0',
            'deductions'    => 'sometimes|nullable|numeric|min:0',
            'paymentMethod' => 'sometimes|required|in:bank-transfer,check,cash',
            'notes'         => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if (isset($data['baseSalary']) || isset($data['allowances']) || isset($data['deductions'])) {
            $data['netSalary'] = ($data['baseSalary'] ?? $payroll->base_salary)
                + ($data['allowances'] ?? $payroll->allowances)
                - ($data['deductions'] ?? $payroll->deductions);
        }

        $payroll->update($data);

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

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($id);

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
     */
    public function approve(Request $request, $id): JsonResponse
    {
        return response()->json([
            'error' => 'Payroll approval moved to the Finance approval workflow. HR can prepare payroll, but Finance and the final approver must approve it there.',
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
            'paymentDate' => 'required|date',
            'paymentMethod' => 'required|in:bank_transfer,check,cash',
            'payoutReference' => 'required|string|max:255',
            'payoutProofType' => 'required|in:bank_reference,receipt_number,check_number,other',
            'payoutProofReference' => 'required|string|max:255',
            'payoutProofNotes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $processedCount = 0;
        $errors = [];

        foreach ($request->payrollIds as $payrollId) {
            try {
                $payroll = Payroll::forShopOwner($user->shop_owner_id)
                    ->with('employee')
                    ->findOrFail($payrollId);

                if ($payroll->status === 'paid') {
                    $errors[] = "Payroll for {$payroll->employee->fullName} is already paid";
                    continue;
                }

                if ($payroll->approval_status !== 'approved' || empty($payroll->approved_by)) {
                    $errors[] = "Payroll ID {$payrollId} requires Finance checker approval before disbursement";
                    continue;
                }

                if ($payroll->status !== 'approved' || empty($payroll->final_approved_by)) {
                    $errors[] = "Payroll ID {$payrollId} requires final owner approval before disbursement";
                    continue;
                }

                if ((int) $payroll->approved_by === (int) $payroll->final_approved_by) {
                    $errors[] = "Payroll ID {$payrollId} has an invalid approval chain. Checker and final approver must differ.";
                    continue;
                }

                $payroll->markAsPaid((string) $request->paymentDate, [
                    'payment_method' => (string) $request->paymentMethod,
                    'payout_reference' => (string) $request->payoutReference,
                    'payout_proof_type' => (string) $request->payoutProofType,
                    'payout_proof_reference' => (string) $request->payoutProofReference,
                    'payout_proof_notes' => $request->input('payoutProofNotes'),
                    'disbursed_by' => (int) $user->id,
                ]);

                $this->createExpenseFromPaidPayroll($payroll, (int) $user->id, (string) $request->paymentDate);
                $processedCount++;
            } catch (\Exception $e) {
                $errors[] = "Error processing payroll ID {$payrollId}: " . $e->getMessage();
            }
        }

        return response()->json([
            'message' => 'Payroll disbursement completed',
            'processed' => $processedCount,
            'errors' => $errors,
        ]);
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
        $status = (string) ($template['status'] ?? 'submitted');
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
        $query  = Payroll::forShopOwner($user->shop_owner_id);

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
        $user = Auth::guard('user')->user();

        if (
            ! $user->hasRole('Manager')
            && ! $user->can('access-employee-directory')
            && ! $user->can('access-attendance-records')
            && ! $user->can('access-payslip-generation') && !$user->can('access-view-payslip')
        ) {
            \Log::warning('Unauthorized payroll export attempt', [
                'user_id'    => $user->id,
                'user_role'  => $user->getRoleNames()->first(),
                'payroll_id' => $id,
            ]);
            return response()->json([
                'error' => 'Unauthorized. You do not have permission to export payslips.',
            ], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)
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

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($id);

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
                $user->shop_owner_id,
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
            || $user->hasRole('Manager')
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
                (int) $user->shop_owner_id,
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
                (int) $user->shop_owner_id,
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
            'overtime_hours' => 'nullable|numeric|min:0',
            'rest_day_hours' => 'nullable|numeric|min:0',
            'special_holiday_hours' => 'nullable|numeric|min:0',
            'regular_holiday_hours' => 'nullable|numeric|min:0',
            'night_differential_hours' => 'nullable|numeric|min:0',
            'undertime_hours' => 'nullable|numeric|min:0',
            'absent_days'    => 'nullable|integer|min:0',
            'sales_commission' => 'nullable|numeric|min:0',
            'performance_bonus' => 'nullable|numeric|min:0',
            'other_allowances' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee = Employee::forShopOwner($user->shop_owner_id)->findOrFail($request->employee_id);

        $regularHours = (float) $request->regular_hours;
        $attendanceDays = (int) floor($regularHours / 8);
        $overtimeHours = (float) ($request->overtime_hours ?? 0);
        $restDayHours = (float) ($request->rest_day_hours ?? 0);
        $specialHolidayHours = (float) ($request->special_holiday_hours ?? 0);
        $regularHolidayHours = (float) ($request->regular_holiday_hours ?? 0);
        $nightDifferentialHours = (float) ($request->night_differential_hours ?? 0);
        $undertimeHours = (float) ($request->undertime_hours ?? 0);
        $absentDays = (int) ($request->absent_days ?? 0);

        $salesCommission = (float) ($request->sales_commission ?? 0);
        $performanceBonus = (float) ($request->performance_bonus ?? 0);
        $otherAllowances = (float) ($request->other_allowances ?? 0);

        $ruleEngine = $this->payrollService->computeRuleEngineAmounts($employee, [
            'attendance_days' => $attendanceDays,
            'overtime_hours' => $overtimeHours,
            'rest_day_hours' => $restDayHours,
            'special_holiday_hours' => $specialHolidayHours,
            'regular_holiday_hours' => $regularHolidayHours,
            'night_differential_hours' => $nightDifferentialHours,
            'undertime_hours' => $undertimeHours,
            'absent_days' => $absentDays,
        ]);

        $basicPay = (float) ($employee->salary ?? 0);
        $monthlySalary = $basicPay;
        $overtimePay = (float) ($ruleEngine['overtime_pay'] ?? 0);
        $restDayPay = (float) ($ruleEngine['rest_day_pay'] ?? 0);
        $specialHolidayPay = (float) ($ruleEngine['special_holiday_pay'] ?? 0);
        $regularHolidayPay = (float) ($ruleEngine['regular_holiday_pay'] ?? 0);
        $nightDifferentialPay = (float) ($ruleEngine['night_differential_pay'] ?? 0);

        $grossPay = $basicPay
            + $overtimePay
            + $restDayPay
            + $specialHolidayPay
            + $regularHolidayPay
            + $nightDifferentialPay
            + $salesCommission
            + $performanceBonus
            + $otherAllowances;

        $taxableIncome = $basicPay
            + $overtimePay
            + $restDayPay
            + $specialHolidayPay
            + $regularHolidayPay
            + $nightDifferentialPay
            + $salesCommission
            + $performanceBonus;

        $runDate = Carbon::parse($request->end_date)->startOfDay();
        $statutory = $this->payrollService->calculateStatutoryDeductions(
            (int) $user->shop_owner_id,
            (float) $taxableIncome,
            $runDate
        );

        $tax = (float) ($statutory['withholding_tax'] ?? 0);
        $sss = (float) ($statutory['sss_contribution'] ?? 0);
        $philhealth = (float) ($statutory['philhealth_contribution'] ?? 0);
        $pagibig = (float) ($statutory['pagibig_contribution'] ?? 0);
        $absentDeductions = (float) ($ruleEngine['absent_deduction'] ?? 0);
        $undertimeDeductions = (float) ($ruleEngine['undertime_deduction'] ?? 0);

        $totalDeductions = $tax + $sss + $philhealth + $pagibig + $absentDeductions + $undertimeDeductions;
        $totalEarnings = $grossPay;
        $netPay = $totalEarnings - $totalDeductions;

        return response()->json([
            'calculation' => [
                'employee' => [
                    'id'             => $employee->id,
                    'name'           => "{$employee->first_name} {$employee->last_name}",
                    'monthly_salary' => $monthlySalary,
                ],
                'hours' => [
                    'regular_hours'  => round($regularHours, 2),
                    'overtime_hours' => round($overtimeHours, 2),
                    'rest_day_hours' => round($restDayHours, 2),
                    'special_holiday_hours' => round($specialHolidayHours, 2),
                    'regular_holiday_hours' => round($regularHolidayHours, 2),
                    'night_differential_hours' => round($nightDifferentialHours, 2),
                    'undertime_hours' => round($undertimeHours, 2),
                    'absent_days'    => $absentDays,
                ],
                'earnings' => [
                    'basic_pay'          => round($basicPay, 2),
                    'overtime_pay'       => round($overtimePay, 2),
                    'rest_day_pay'       => round($restDayPay, 2),
                    'special_holiday_pay' => round($specialHolidayPay, 2),
                    'regular_holiday_pay' => round($regularHolidayPay, 2),
                    'night_differential_pay' => round($nightDifferentialPay, 2),
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
                'gross_pay' => round($grossPay, 2),
            ],
        ]);
    }

    // ============================================================
    // PRIVATE: STATUTORY CONTRIBUTION HELPERS
    // ============================================================

    private function calculateSSSContribution(float $salary): float
    {
        $table = [
            4250  => 180,    4750  => 202.50, 5250  => 225,    5750  => 247.50,
            6250  => 270,    6750  => 292.50, 7250  => 315,    7750  => 337.50,
            8250  => 360,    8750  => 382.50, 9250  => 405,    9750  => 427.50,
            10250 => 450,   10750  => 472.50, 11250 => 495,   11750  => 517.50,
            12250 => 540,   12750  => 562.50, 13250 => 585,   13750  => 607.50,
            14250 => 630,   14750  => 652.50, 15250 => 675,   15750  => 697.50,
            16250 => 720,   16750  => 742.50, 17250 => 765,   17750  => 787.50,
            18250 => 810,   18750  => 832.50, 19250 => 855,   19750  => 877.50,
        ];

        if ($salary >= 30000) return 1350;

        foreach ($table as $ceiling => $contribution) {
            if ($salary < $ceiling) return $contribution;
        }

        return 900;
    }

    private function calculatePhilHealthContribution(float $salary): float
    {
        return round(min(max($salary, 10000), 100000) * 0.025, 2);
    }

    private function calculatePagIbigContribution(float $salary): float
    {
        return $salary <= 1500
            ? round($salary * 0.01, 2)
            : min(round($salary * 0.02, 2), 100);
    }

    private function calculateWithholdingTax(float $taxableIncome): float
    {
        if ($taxableIncome <= 250000)  return 0;
        if ($taxableIncome <= 400000)  return ($taxableIncome - 250000) * 0.15;
        if ($taxableIncome <= 800000)  return 22500 + ($taxableIncome - 400000) * 0.20;
        if ($taxableIncome <= 2000000) return 102500 + ($taxableIncome - 800000) * 0.25;
        if ($taxableIncome <= 8000000) return 402500 + ($taxableIncome - 2000000) * 0.30;
        return 2202500 + ($taxableIncome - 8000000) * 0.35;
    }

    private function calculateMonthlyTax(float $monthlyGross, float $monthlyStatutory): float
    {
        $annual = ($monthlyGross - $monthlyStatutory) * 12;
        return round($this->calculateWithholdingTax($annual) / 12, 2);
    }

    // ============================================================
    // PAYROLL PERIODS
    // ============================================================

    /**
     * Return the last 12 months + current month as selectable payroll periods.
     * Each entry includes working-day count (Mon–Fri) and whether attendance
     * for that month is considered finalized (month has fully ended).
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

        if (Schema::hasTable('hr_branch_payroll_settings')) {
            $setting = BranchPayrollSetting::query()
                ->forShopOwner((int) $user->shop_owner_id)
                ->active()
                ->orderBy('id')
                ->first();

            if ($setting) {
                $payCycle = $setting->pay_cycle ?: 'monthly';
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
                    $cursor = $segment['start']->copy();
                    while ($cursor->lte($segment['end'])) {
                        if ($cursor->isWeekday()) {
                            $workingDays++;
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
                        'payCycle'         => 'semi_monthly',
                    ];
                }

                continue;
            }

            $workingDays = 0;
            $cursor = $monthStart->copy();
            while ($cursor->lte($monthEnd)) {
                if ($cursor->isWeekday()) {
                    $workingDays++;
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
                'payCycle'         => 'monthly',
            ];
        }

        usort($periods, static function (array $a, array $b): int {
            return strcmp($b['startDate'], $a['startDate']);
        });

        return response()->json($periods);
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
        $employee = Employee::where('shop_owner_id', $user->shop_owner_id)
            ->where('email', $user->email)
            ->first();

        if (! $employee) {
            return response()->json(['data' => [], 'message' => 'No employee record found'], 200);
        }

        $query = Payroll::forShopOwner($user->shop_owner_id)
            ->where('employee_id', $employee->id)
            ->where(function ($q) {
                $q->where('status', 'approved')
                  ->orWhere('status', 'paid');
            })
            ->with([
                'components' => fn ($q) => $q->orderBy('component_type')->orderBy('display_order'),
            ]);

        if ($request->filled('period')) {
            $query->where(function ($q) use ($request) {
                $q->where('payroll_period', 'like', "%{$request->period}%")
                  ->orWhere('pay_period_start', 'like', "%{$request->period}%");
            });
        }

        $payslips = $query->orderByDesc('pay_period_start')
            ->paginate($request->get('per_page', 12));

        return response()->json($payslips);
    }
}
