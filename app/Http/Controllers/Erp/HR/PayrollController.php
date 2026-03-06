<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Services\HR\PayrollService;
use App\Traits\HR\LogsHRActivity;
use App\Notifications\HR\PayslipGenerated;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    // ============================================================
    // AUTH HELPER
    // ============================================================

    private function authorizeUser(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $user = Auth::guard('user')->user();

        if (
            ! $user->hasRole('Manager')
            && ! $user->can('view-employees')
            && ! $user->can('view-attendance')
            && ! $user->can('view-payroll')
        ) {
            return null;
        }

        return $user;
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
            'overtime_hours'   => 'nullable|numeric|min:0|max:744',
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
        if ($request->filled('overtime_hours'))  $overrides['overtime_hours']  = (float) $request->overtime_hours;

        try {
            $payroll = $this->payrollService->generatePayroll(
                $employee,
                $request->payrollPeriod,
                $customComponents,
                $overrides
            );

            // The service always sets status = 'processed'.
            // Reset to 'pending' so this entry goes through the approval workflow.
            $payroll->update(['status' => 'pending']);

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
     * Approve a payroll (HR-level approval, dual-control: prevents self-approval).
     *
     * Security: Approver cannot be the same user who generated the payroll.
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (
            ! $user->hasRole('Manager')
            && ! $user->can('view-employees')
            && ! $user->can('view-attendance')
            && ! $user->can('view-payroll')
        ) {
            \Log::warning('Unauthorized payroll approval attempt', [
                'user_id'    => $user->id,
                'user_role'  => $user->getRoleNames()->first(),
                'payroll_id' => $id,
            ]);
            return response()->json([
                'error' => 'Unauthorized. Only Managers or users with HR permissions can approve payroll.',
            ], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)
            ->with('employee')
            ->findOrFail($id);

        // Dual-control: prevent self-approval
        if ($payroll->generated_by == $user->id) {
            \Log::warning('Attempted self-approval of payroll', [
                'user_id'    => $user->id,
                'payroll_id' => $id,
            ]);
            return response()->json([
                'error' => 'You cannot approve payroll that you generated. Requires independent approval.',
            ], 403);
        }

        if ($payroll->status !== 'pending') {
            return response()->json(['error' => 'Payroll is not pending approval'], 422);
        }

        $payroll->update([
            'status'      => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        \Log::info('Payroll approved', [
            'approver_id'   => $user->id,
            'approver_role' => $user->getRoleNames()->first(),
            'payroll_id'    => $id,
            'employee_id'   => $payroll->employee_id,
            'amount'        => $payroll->net_salary,
        ]);

        try {
            if ($payroll->employee && $payroll->employee->user) {
                $payroll->employee->user->notify(new PayslipGenerated($payroll));
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send payslip notification', [
                'payroll_id' => $payroll->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Payroll approved successfully',
            'payroll' => $payroll,
        ]);
    }

    // ============================================================
    // BATCH STATUS OPERATIONS
    // ============================================================

    /**
     * Mark one or more approved payrolls as paid.
     */
    public function process(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollIds'    => 'required|array',
            'payrollIds.*'  => 'exists:payrolls,id',
            'paymentDate'   => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $processedCount = 0;
        $errors         = [];

        foreach ($request->payrollIds as $payrollId) {
            try {
                $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($payrollId);

                if ($payroll->status === 'paid') {
                    $errors[] = "Payroll for {$payroll->employee->fullName} is already paid";
                    continue;
                }

                $payroll->markAsPaid($request->paymentDate);
                $processedCount++;

            } catch (\Exception $e) {
                $errors[] = "Error processing payroll ID {$payrollId}: " . $e->getMessage();
            }
        }

        return response()->json([
            'message'   => 'Payroll processing completed',
            'processed' => $processedCount,
            'errors'    => $errors,
        ]);
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

        $totalPayrolls     = (clone $query)->count();
        $pendingPayrolls   = (clone $query)->pending()->count();
        $processedPayrolls = (clone $query)->processed()->count();
        $paidPayrolls      = (clone $query)->withStatus('paid')->count();
        $totalAmount       = (clone $query)->sum('net_salary');
        $pendingAmount     = (clone $query)->pending()->sum('net_salary');
        $paidAmount        = (clone $query)->withStatus('paid')->sum('net_salary');

        return response()->json([
            'totalPayrolls'     => $totalPayrolls,
            'pendingPayrolls'   => $pendingPayrolls,
            'processedPayrolls' => $processedPayrolls,
            'paidPayrolls'      => $paidPayrolls,
            'totalAmount'       => $totalAmount,
            'pendingAmount'     => $pendingAmount,
            'paidAmount'        => $paidAmount,
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
            && ! $user->can('view-employees')
            && ! $user->can('view-attendance')
            && ! $user->can('view-payroll')
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
            'absent_days'    => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee       = Employee::forShopOwner($user->shop_owner_id)->findOrFail($request->employee_id);
        $regularHours   = $request->regular_hours;
        $overtimeHours  = $request->overtime_hours ?? 0;
        $absentDays     = $request->absent_days ?? 0;

        $monthlySalary = $employee->salary ?? 0;
        $workingDays   = Carbon::parse($request->start_date)->diffInDays(Carbon::parse($request->end_date)) + 1;
        $dailyRate     = $workingDays > 0 ? $monthlySalary / $workingDays : 0;
        $hourlyRate    = $dailyRate / 8;

        $basicPay      = $regularHours * $hourlyRate;
        $overtimePay   = $overtimeHours * ($hourlyRate * 1.25);
        $grossPay      = $basicPay + $overtimePay;
        $totalEarnings = $grossPay; // TODO: add commissions / bonuses when data is available

        $sss        = $this->calculateSSSContribution($monthlySalary);
        $philhealth = $this->calculatePhilHealthContribution($monthlySalary);
        $pagibig    = $this->calculatePagIbigContribution($monthlySalary);
        $tax        = $this->calculateMonthlyTax($grossPay, $sss + $philhealth + $pagibig);

        $absentDeductions = $absentDays * $dailyRate;
        $totalDeductions  = $tax + $sss + $philhealth + $pagibig + $absentDeductions;
        $netPay           = $totalEarnings - $totalDeductions;

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
                    'absent_days'    => $absentDays,
                ],
                'earnings' => [
                    'basic_pay'          => round($basicPay, 2),
                    'overtime_pay'       => round($overtimePay, 2),
                    'sales_commission'   => 0,
                    'performance_bonus'  => 0,
                    'other_allowances'   => 0,
                    'total_earnings'     => round($totalEarnings, 2),
                ],
                'deductions' => [
                    'withholding_tax'         => round($tax, 2),
                    'sss_contribution'        => round($sss, 2),
                    'philhealth_contribution' => round($philhealth, 2),
                    'pagibig_contribution'    => round($pagibig, 2),
                    'absent_deductions'       => round($absentDeductions, 2),
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
}
