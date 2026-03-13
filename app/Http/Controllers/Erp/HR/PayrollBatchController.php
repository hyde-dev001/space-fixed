<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
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
 * PayrollBatchController
 *
 * Handles multi-employee batch payroll operations:
 *  - Preview batch (calculate without saving)
 *  - Generate batch (save payrolls for all employees in a period)
 *  - Retry failed batch items
 *  - Export batch to CSV / PDF
 *
 * All heavy calculation is contained here to keep PayrollController lean.
 */
class PayrollBatchController extends Controller
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
            && ! $user->can('access-employee-directory')
            && ! $user->can('access-attendance-records')
            && ! $user->can('access-payslip-generation')
            && ! $user->can('access-view-payslip')
        ) {
            return null;
        }

        return $user;
    }

    // ============================================================
    // BATCH ENDPOINTS
    // ============================================================

    /**
     * Preview batch payroll generation with validation summary.
     * Returns calculation preview without saving to database.
     */
    public function previewBatch(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollPeriod' => 'required|string',
            'employeeIds'   => 'required|array',
            'employeeIds.*' => 'exists:employees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payrollPeriod = $request->payrollPeriod;
        $employeeIds   = $request->employeeIds;

        $previews  = [];
        $errors    = [];
        $warnings  = [];

        foreach ($employeeIds as $employeeId) {
            try {
                $employee = Employee::forShopOwner($user->shop_owner_id)
                    ->where('status', 'active')
                    ->findOrFail($employeeId);

                // Already generated for this period?
                $existingPayroll = Payroll::forEmployee($employeeId)
                    ->forPeriod($payrollPeriod)
                    ->first();

                if ($existingPayroll) {
                    $warnings[] = [
                        'employee_id'   => $employeeId,
                        'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                        'message'       => 'Payroll already exists for this period',
                        'severity'      => 'warning',
                    ];
                    continue;
                }

                [$periodStart, $periodEnd] = $this->parsePeriod($payrollPeriod);
                $attendanceData = $this->getAttendanceData($employeeId, $periodStart, $periodEnd);

                if (! $attendanceData['is_finalized']) {
                    $warnings[] = [
                        'employee_id'   => $employeeId,
                        'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                        'message'       => 'Attendance not finalized for this period',
                        'severity'      => 'error',
                    ];
                    continue;
                }

                $calculation = $this->calculatePayrollPreview($employee, $attendanceData, $periodStart, $periodEnd);

                $previews[] = [
                    'employee_id'   => $employeeId,
                    'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                    'department'    => $employee->department,
                    'position'      => $employee->position,
                    'attendance'    => $attendanceData,
                    'calculation'   => $calculation,
                ];

            } catch (\Exception $e) {
                $errors[] = [
                    'employee_id' => $employeeId,
                    'message'     => $e->getMessage(),
                    'severity'    => 'error',
                ];
            }
        }

        return response()->json([
            'previews'  => $previews,
            'errors'    => $errors,
            'warnings'  => $warnings,
            'summary'   => [
                'total_employees' => count($employeeIds),
                'preview_count'   => count($previews),
                'error_count'     => count($errors),
                'warning_count'   => count($warnings),
                'total_gross'     => array_sum(array_column(array_column($previews, 'calculation'), 'gross_salary')),
                'total_net'       => array_sum(array_column(array_column($previews, 'calculation'), 'net_salary')),
            ],
        ]);
    }

    /**
     * Generate batch payroll with comprehensive error handling and retry logic.
     * Moves all calculation logic to backend.
     */
    public function generateBatch(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            \Log::warning('Unauthorized batch payroll generation attempt', [
                'user_id'   => $user?->id,
                'user_role' => $user?->getRoleNames()->first(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollPeriod'    => 'required|string',
            'employeeIds'      => 'required|array',
            'employeeIds.*'    => 'exists:employees,id',
            'paymentMethod'    => 'sometimes|in:bank_transfer,check,cash',
            'sendNotifications' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payrollPeriod     = $request->payrollPeriod;
        $employeeIds       = $request->employeeIds;
        $paymentMethod     = $request->get('paymentMethod', 'bank_transfer');
        $sendNotifications = $request->get('sendNotifications', true);

        $createdPayrolls = [];
        $errors          = [];
        $retryQueue      = [];

        $this->auditCustom(
            AuditLog::MODULE_PAYROLL,
            'batch_generate_started',
            "Batch payroll generation started for period {$payrollPeriod} - {$user->name} ({$user->id})",
            [
                'severity'       => AuditLog::SEVERITY_INFO,
                'tags'           => ['batch', 'payroll', 'generation'],
                'employee_count' => count($employeeIds),
                'period'         => $payrollPeriod,
            ]
        );

        foreach ($employeeIds as $employeeId) {
            try {
                $employee = Employee::forShopOwner($user->shop_owner_id)
                    ->where('status', 'active')
                    ->findOrFail($employeeId);

                $existingPayroll = Payroll::forEmployee($employeeId)
                    ->forPeriod($payrollPeriod)
                    ->first();

                if ($existingPayroll) {
                    $errors[] = [
                        'employee_id'   => $employeeId,
                        'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                        'error'         => 'Payroll already exists for this period',
                        'error_code'    => 'DUPLICATE_PAYROLL',
                    ];
                    continue;
                }

                [$periodStart, $periodEnd] = $this->parsePeriod($payrollPeriod);
                $attendanceData = $this->getAttendanceData($employeeId, $periodStart, $periodEnd);

                if (! $attendanceData['is_finalized']) {
                    $errors[] = [
                        'employee_id'   => $employeeId,
                        'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                        'error'         => 'Attendance not finalized for this period',
                        'error_code'    => 'ATTENDANCE_NOT_FINALIZED',
                    ];
                    continue;
                }

                $calculation = $this->calculatePayrollPreview($employee, $attendanceData, $periodStart, $periodEnd);

                $payroll = Payroll::create([
                    'employee_id'    => $employee->id,
                    'shop_owner_id'  => $user->shop_owner_id,
                    'payroll_period' => $payrollPeriod,
                    'pay_period_start' => $periodStart,
                    'pay_period_end'   => $periodEnd,
                    'base_salary'    => $calculation['base_salary'],
                    'allowances'     => $calculation['total_allowances'],
                    'deductions'     => $calculation['total_deductions'],
                    'gross_salary'   => $calculation['gross_salary'],
                    'net_salary'     => $calculation['net_salary'],
                    'payment_method' => $paymentMethod,
                    'status'         => 'pending',
                    'generated_by'   => $user->id,
                    'generated_at'   => now(),
                ]);

                $this->createPayrollComponents($payroll, $calculation);

                $createdPayrolls[] = $payroll->load('employee');

                $this->auditCustom(
                    AuditLog::MODULE_PAYROLL,
                    AuditLog::ACTION_GENERATED,
                    "Payroll generated: {$employee->first_name} {$employee->last_name} - Period {$payrollPeriod} - Net: {$calculation['net_salary']}",
                    [
                        'severity'    => AuditLog::SEVERITY_WARNING,
                        'tags'        => ['financial', 'payroll', 'sensitive'],
                        'employee_id' => $employee->id,
                        'entity_type' => Payroll::class,
                        'entity_id'   => $payroll->id,
                    ]
                );

                if ($sendNotifications && $employee->user) {
                    try {
                        $employee->user->notify(new PayslipGenerated($payroll));
                    } catch (\Exception $e) {
                        \Log::error('Failed to send payslip notification', [
                            'payroll_id'  => $payroll->id,
                            'employee_id' => $employee->id,
                            'error'       => $e->getMessage(),
                        ]);
                    }
                }

            } catch (\Exception $e) {
                $errorDetails = [
                    'employee_id'   => $employeeId,
                    'employee_name' => isset($employee) ? $employee->first_name . ' ' . $employee->last_name : 'Unknown',
                    'error'         => $e->getMessage(),
                    'error_code'    => 'GENERATION_FAILED',
                    'trace'         => $e->getTraceAsString(),
                ];

                $errors[]     = $errorDetails;
                $retryQueue[] = $employeeId;

                \Log::error('Batch payroll generation error', $errorDetails);
            }
        }

        $this->auditCustom(
            AuditLog::MODULE_PAYROLL,
            'batch_generate_completed',
            "Batch payroll generation completed for period {$payrollPeriod} - Success: " . count($createdPayrolls) . ', Failed: ' . count($errors),
            [
                'severity'      => count($errors) > 0 ? AuditLog::SEVERITY_WARNING : AuditLog::SEVERITY_INFO,
                'tags'          => ['batch', 'payroll', 'generation'],
                'success_count' => count($createdPayrolls),
                'error_count'   => count($errors),
                'period'        => $payrollPeriod,
            ]
        );

        return response()->json([
            'message'      => 'Batch payroll generation completed',
            'created'      => count($createdPayrolls),
            'errors'       => count($errors),
            'payrolls'     => $createdPayrolls,
            'error_details' => $errors,
            'retry_queue'  => $retryQueue,
            'summary'      => [
                'total_gross' => ! empty($createdPayrolls) ? array_sum(array_column($createdPayrolls, 'gross_salary')) : 0,
                'total_net'   => ! empty($createdPayrolls) ? array_sum(array_column($createdPayrolls, 'net_salary')) : 0,
            ],
        ]);
    }

    /**
     * Retry failed payroll generation items.
     * Delegates to generateBatch with the same payload.
     */
    public function retryBatch(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollPeriod' => 'required|string',
            'employeeIds'   => 'required|array',
            'employeeIds.*' => 'exists:employees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return $this->generateBatch($request);
    }

    /**
     * Export batch payroll to CSV or PDF.
     */
    public function exportBatch(Request $request): mixed
    {
        $user = Auth::guard('user')->user();

        if (! $user->hasRole('Manager') && ! $user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollPeriod' => 'required|string',
            'format'        => 'required|in:csv,pdf',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payrolls = Payroll::forShopOwner($user->shop_owner_id)
            ->forPeriod($request->payrollPeriod)
            ->with(['employee', 'components'])
            ->get();

        if ($payrolls->isEmpty()) {
            return response()->json(['error' => 'No payrolls found for this period'], 404);
        }

        return $request->input('format') === 'csv'
            ? $this->exportToCSV($payrolls, $request->payrollPeriod)
            : $this->exportToPDF($payrolls, $request->payrollPeriod);
    }

    // ============================================================
    // PROTECTED HELPERS (calculation pipeline for batch)
    // ============================================================

    /**
     * Parse period string to [startDate, endDate].
     * Supports: "January 2026", "2026-01", "2026-01-01 to 2026-01-31"
     */
    protected function parsePeriod(string $period): array
    {
        if (str_contains($period, ' to ')) {
            return explode(' to ', $period);
        }

        if (preg_match('/^([A-Za-z]+)\s+(\d{4})$/', $period, $matches)) {
            $monthNum  = date('m', strtotime($matches[1]));
            $startDate = "{$matches[2]}-{$monthNum}-01";
            return [$startDate, date('Y-m-t', strtotime($startDate))];
        }

        // YYYY-MM
        $startDate = $period . '-01';
        return [$startDate, date('Y-m-t', strtotime($startDate))];
    }

    /**
     * Aggregate attendance records for an employee in a period.
     */
    protected function getAttendanceData(int $employeeId, string $startDate, string $endDate): array
    {
        $records = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $totalRegularHours  = 0;
        $totalOvertimeHours = 0;
        $totalUndertimeHours = 0;
        $totalAbsentDays    = 0;
        $totalLateDays      = 0;
        $totalPresentDays   = 0;

        foreach ($records as $record) {
            if ($record->status === 'present') {
                $totalPresentDays++;
                $totalRegularHours   += $record->regular_hours ?? 8;
                $totalOvertimeHours  += $record->overtime_hours ?? 0;
                $totalUndertimeHours += $record->undertime_hours ?? 0;
                if ($record->is_late) {
                    $totalLateDays++;
                }
            } elseif ($record->status === 'absent') {
                $totalAbsentDays++;
            }
        }

        $workingDays = Carbon::parse($startDate)->diffInWeekdays(Carbon::parse($endDate)) + 1;
        $isFinalized = $records->count() >= ($workingDays * 0.8); // 80 % threshold

        return [
            'total_regular_hours'   => $totalRegularHours,
            'total_overtime_hours'  => $totalOvertimeHours,
            'total_undertime_hours' => $totalUndertimeHours,
            'total_absent_days'     => $totalAbsentDays,
            'total_late_days'       => $totalLateDays,
            'total_present_days'    => $totalPresentDays,
            'working_days'          => $workingDays,
            'is_finalized'          => $isFinalized,
        ];
    }

    /**
     * Full payroll calculation for one employee (all logic in backend).
     */
    protected function calculatePayrollPreview(Employee $employee, array $attendanceData, string $startDate, string $endDate): array
    {
        $baseSalary  = $employee->salary ?? 0;
        $workingDays = $attendanceData['working_days'];
        $dailyRate   = $workingDays > 0 ? $baseSalary / $workingDays : 0;
        $hourlyRate  = $dailyRate / 8;

        $basicPay    = $attendanceData['total_regular_hours'] * $hourlyRate;
        $overtimePay = $attendanceData['total_overtime_hours'] * ($hourlyRate * 1.25);

        $salesCommission  = 0; // TODO: integrate with sales data
        $performanceBonus = 0; // TODO: integrate with performance metrics
        $otherAllowances  = $employee->other_allowances ?? 0;

        $totalAllowances = $salesCommission + $performanceBonus + $otherAllowances;
        $grossSalary     = $basicPay + $overtimePay + $totalAllowances;

        $sss        = $this->calculateSSS($baseSalary);
        $philhealth = $this->calculatePhilHealth($baseSalary);
        $pagibig    = $this->calculatePagIbig($baseSalary);

        $totalStatutory  = $sss + $philhealth + $pagibig;
        $withholdingTax  = $this->calculateWithholdingTaxMonthly($grossSalary, $totalStatutory);
        $absentDeductions = $attendanceData['total_absent_days'] * $dailyRate;
        $undertimeDeductions = $attendanceData['total_undertime_hours'] * $hourlyRate;

        $totalDeductions = $withholdingTax + $sss + $philhealth + $pagibig + $absentDeductions + $undertimeDeductions;
        $netSalary       = $grossSalary - $totalDeductions;

        return [
            'base_salary'            => round($baseSalary, 2),
            'basic_pay'              => round($basicPay, 2),
            'overtime_pay'           => round($overtimePay, 2),
            'sales_commission'       => round($salesCommission, 2),
            'performance_bonus'      => round($performanceBonus, 2),
            'other_allowances'       => round($otherAllowances, 2),
            'total_allowances'       => round($totalAllowances, 2),
            'gross_salary'           => round($grossSalary, 2),
            'withholding_tax'        => round($withholdingTax, 2),
            'sss_contribution'       => round($sss, 2),
            'philhealth_contribution' => round($philhealth, 2),
            'pagibig_contribution'   => round($pagibig, 2),
            'absent_deductions'      => round($absentDeductions, 2),
            'undertime_deductions'   => round($undertimeDeductions, 2),
            'loan_deductions'        => 0,
            'other_deductions'       => round($absentDeductions + $undertimeDeductions, 2),
            'total_deductions'       => round($totalDeductions, 2),
            'net_salary'             => round($netSalary, 2),
            'attendance_summary'     => $attendanceData,
        ];
    }

    /**
     * Persist PayrollComponent rows from a calculation array.
     */
    protected function createPayrollComponents(Payroll $payroll, array $calc): void
    {
        $earningsMap = [
            'Basic Pay'     => $calc['basic_pay'],
            'Overtime Pay'  => $calc['overtime_pay'],
            'Allowances'    => $calc['other_allowances'],
        ];

        foreach ($earningsMap as $name => $amount) {
            if ($amount > 0) {
                PayrollComponent::create([
                    'payroll_id'     => $payroll->id,
                    'component_type' => PayrollComponent::TYPE_EARNING,
                    'component_name' => $name,
                    'amount'         => $amount,
                ]);
            }
        }

        $deductionMap = [
            'Withholding Tax'       => $calc['withholding_tax'],
            'SSS Contribution'      => $calc['sss_contribution'],
            'PhilHealth Contribution' => $calc['philhealth_contribution'],
            'Pag-IBIG Contribution' => $calc['pagibig_contribution'],
            'Absent Deductions'     => $calc['absent_deductions'],
            'Undertime Deductions'  => $calc['undertime_deductions'],
        ];

        foreach ($deductionMap as $name => $amount) {
            if ($amount > 0) {
                PayrollComponent::create([
                    'payroll_id'     => $payroll->id,
                    'component_type' => PayrollComponent::TYPE_DEDUCTION,
                    'component_name' => $name,
                    'amount'         => $amount,
                ]);
            }
        }
    }

    // ============================================================
    // STATUTORY CONTRIBUTION HELPERS (batch pipeline)
    // ============================================================

    protected function calculateSSS(float $salary): float
    {
        $table = [
            4250  => 180,    4750 => 202.50, 5250 => 225,    5750 => 247.50,
            6250  => 270,    6750 => 292.50, 7250 => 315,    7750 => 337.50,
            8250  => 360,    8750 => 382.50, 9250 => 405,    9750 => 427.50,
            10250 => 450,   10750 => 472.50, 11250 => 495,  11750 => 517.50,
            12250 => 540,   12750 => 562.50, 13250 => 585,  13750 => 607.50,
            14250 => 630,   14750 => 652.50, 15250 => 675,  15750 => 697.50,
            16250 => 720,   16750 => 742.50, 17250 => 765,  17750 => 787.50,
            18250 => 810,   18750 => 832.50, 19250 => 855,  19750 => 877.50,
        ];

        if ($salary >= 30000) return 1350;

        foreach ($table as $ceiling => $contribution) {
            if ($salary < $ceiling) return $contribution;
        }

        return 900;
    }

    protected function calculatePhilHealth(float $salary): float
    {
        return round(min(max($salary, 10000), 100000) * 0.025, 2);
    }

    protected function calculatePagIbig(float $salary): float
    {
        return $salary <= 1500
            ? round($salary * 0.01, 2)
            : min(round($salary * 0.02, 2), 100);
    }

    /**
     * Annualise monthly gross, apply BIR progressive tax, return monthly share.
     */
    protected function calculateWithholdingTaxMonthly(float $monthlyGross, float $monthlyStatutory): float
    {
        $annual     = ($monthlyGross - $monthlyStatutory) * 12;
        $annualTax  = $this->calculateAnnualTax($annual);
        return round($annualTax / 12, 2);
    }

    protected function calculateAnnualTax(float $taxableIncome): float
    {
        if ($taxableIncome <= 250000)   return 0;
        if ($taxableIncome <= 400000)   return ($taxableIncome - 250000) * 0.15;
        if ($taxableIncome <= 800000)   return 22500 + ($taxableIncome - 400000) * 0.20;
        if ($taxableIncome <= 2000000)  return 102500 + ($taxableIncome - 800000) * 0.25;
        if ($taxableIncome <= 8000000)  return 402500 + ($taxableIncome - 2000000) * 0.30;
        return 2202500 + ($taxableIncome - 8000000) * 0.35;
    }

    // ============================================================
    // EXPORT HELPERS
    // ============================================================

    protected function exportToCSV($payrolls, string $period): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = 'payroll_batch_' . $period . '_' . date('Ymd') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ];

        return response()->stream(function () use ($payrolls) {
            $file = fopen('php://output', 'w');

            fputcsv($file, [
                'Employee ID', 'Employee Name', 'Department', 'Position',
                'Basic Pay', 'Overtime', 'Allowances', 'Gross Salary',
                'Deductions', 'Net Salary', 'Payment Method', 'Status',
            ]);

            foreach ($payrolls as $payroll) {
                fputcsv($file, [
                    $payroll->employee->employee_id ?? $payroll->employee->id,
                    $payroll->employee->first_name . ' ' . $payroll->employee->last_name,
                    $payroll->employee->department,
                    $payroll->employee->position,
                    $payroll->base_salary,
                    $payroll->components->where('component_name', 'Overtime Pay')->first()->calculated_amount ?? 0,
                    $payroll->allowances,
                    $payroll->gross_salary,
                    $payroll->deductions,
                    $payroll->net_salary,
                    $payroll->payment_method,
                    $payroll->status,
                ]);
            }

            fclose($file);
        }, 200, $headers);
    }

    protected function exportToPDF($payrolls, string $period): JsonResponse
    {
        // TODO: Implement PDF export using dompdf or similar
        return response()->json([
            'message'  => 'PDF export will be implemented',
            'payrolls' => $payrolls,
        ]);
    }
}
