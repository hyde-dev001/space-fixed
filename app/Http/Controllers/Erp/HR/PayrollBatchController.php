<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\HR\HolidayCalendar;
use App\Models\HR\LeaveRequest;
use App\Models\HR\AuditLog;
use App\Models\ShopOwner;
use App\Services\HR\PayrollService;
use App\Services\HR\EmployeeOperationalPolicy;
use App\Traits\HR\LogsHRActivity;
use App\Notifications\HR\PayslipGenerated;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    protected EmployeeOperationalPolicy $employeePolicy;

    public function __construct(PayrollService $payrollService, EmployeeOperationalPolicy $employeePolicy)
    {
        $this->payrollService = $payrollService;
        $this->employeePolicy = $employeePolicy;
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
                $employee = Employee::forShopOwner($this->shopOwnerId($user))
                    ->findOrFail($employeeId);

                if (! $this->employeePolicy->isEligibleForRoutinePayroll($employee)) {
                    $errors[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                        'message' => 'Employee is not eligible for routine payroll.',
                        'error_code' => 'EMPLOYEE_NOT_ELIGIBLE_FOR_ROUTINE_PAYROLL',
                        'severity' => 'error',
                    ];
                    continue;
                }

                // Already generated for this period?
                $existingPayroll = Payroll::forEmployee($employeeId)
                    ->forPeriod($payrollPeriod)
                    ->first();

                if ($existingPayroll) {
                    if ((string) $existingPayroll->approval_status === 'rejected') {
                        $warnings[] = [
                            'employee_id'   => $employeeId,
                            'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                            'message'       => 'Existing rejected payroll will be regenerated for this period',
                            'severity'      => 'warning',
                        ];
                    } else {
                        $errors[] = [
                            'employee_id'   => $employeeId,
                            'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                            'message'       => 'Payroll already exists for this period',
                            'severity'      => 'error',
                        ];
                        continue;
                    }
                }

                [$periodStart, $periodEnd] = $this->parsePeriod($payrollPeriod);
                $attendanceData = $this->getAttendanceData($employeeId, $periodStart, $periodEnd);

                if (! $attendanceData['is_finalized']) {
                    $errors[] = [
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
                $employee = Employee::forShopOwner($this->shopOwnerId($user))
                    ->findOrFail($employeeId);

                if (! $this->employeePolicy->isEligibleForRoutinePayroll($employee)) {
                    $errors[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                        'error' => 'Employee is not eligible for routine payroll.',
                        'error_code' => 'EMPLOYEE_NOT_ELIGIBLE_FOR_ROUTINE_PAYROLL',
                    ];
                    continue;
                }

                $existingPayroll = Payroll::forEmployee($employeeId)
                    ->forPeriod($payrollPeriod)
                    ->first();

                if ($existingPayroll) {
                    if ((string) $existingPayroll->approval_status === 'rejected') {
                        DB::transaction(function () use ($existingPayroll) {
                            // Remove stale rejected record before regenerating to satisfy unique period constraint.
                            $existingPayroll->approval()->delete();
                            $existingPayroll->components()->delete();
                            $existingPayroll->delete();
                        });
                    } else {
                        $errors[] = [
                            'employee_id'   => $employeeId,
                            'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                            'error'         => 'Payroll already exists for this period',
                            'error_code'    => 'DUPLICATE_PAYROLL',
                        ];
                        continue;
                    }
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

                $serviceOverrides = $this->buildServiceOverridesFromAttendance($attendanceData, $paymentMethod);
                $extraEarnings = $this->payrollService->resolveAdditionalEarnings($employee, $payrollPeriod);

                $payroll = $this->payrollService->generatePayroll(
                    $employee,
                    $payrollPeriod,
                    $extraEarnings['components'],
                    $serviceOverrides
                );

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

                $createdPayrolls[] = $payroll->load('employee', 'components');

                $this->auditCustom(
                    AuditLog::MODULE_PAYROLL,
                    AuditLog::ACTION_GENERATED,
                    "Payroll generated: {$employee->first_name} {$employee->last_name} - Period {$payrollPeriod} - Net: {$payroll->net_salary}",
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
                'total_gross' => collect($createdPayrolls)->sum(fn ($payroll) => (float) ($payroll->gross_salary ?? 0)),
                'total_net'   => collect($createdPayrolls)->sum(fn ($payroll) => (float) ($payroll->net_salary ?? 0)),
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
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollPeriod' => 'required|string',
            'format'        => 'required|in:csv,pdf',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payrolls = Payroll::forShopOwner($this->shopOwnerId($user))
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
        $employee = Employee::find($employeeId);
        $shopOwnerId = $employee?->shop_owner_id;
        $shopOwner = $shopOwnerId ? ShopOwner::find($shopOwnerId) : null;

        $records = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        // Load approved leave requests for the period (keyed by date string for O(1) lookups)
        $leaveRequestsByDate = [];
        $approvedLeaves = LeaveRequest::where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhere(function ($subquery) use ($startDate, $endDate) {
                        $subquery->whereDate('end_date', '>=', $startDate)
                            ->whereDate('start_date', '<=', $endDate);
                    });
            })
            ->get();

        // Build a set of dates covered by approved leave (keyed by date string)
        foreach ($approvedLeaves as $leave) {
            $current = Carbon::parse($leave->start_date);
            $leaveEnd = Carbon::parse($leave->end_date);
            while ($current->lte($leaveEnd)) {
                $dateStr = $current->toDateString();
                if (!isset($leaveRequestsByDate[$dateStr])) {
                    $leaveRequestsByDate[$dateStr] = $leave;
                }
                $current->addDay();
            }
        }

        // Load holiday calendar for the period (keyed by date string for O(1) lookups)
        $holidays = $shopOwnerId
            ? HolidayCalendar::where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true)
                ->whereBetween('holiday_date', [$startDate, $endDate])
                ->get()
                ->keyBy(fn ($h) => $h->holiday_date->toDateString())
            : collect();

        $totalRegularHours       = 0;
        $totalOvertimeHours      = 0;
        $totalUndertimeHours     = 0;
        $totalAbsentDays         = 0;
        $totalLeaveDays          = 0;
        $totalLateDays           = 0;
        $totalPresentDays        = 0;
        $specialHolidayHours     = 0;
        $regularHolidayHours     = 0;

        foreach ($records as $record) {
            $dateStr = $record->date->toDateString();

            if ($record->status !== 'present') {
                // Check if this absent/late day is covered by an approved leave
                if (isset($leaveRequestsByDate[$dateStr])) {
                    $totalLeaveDays++;
                } elseif ($record->status === 'absent') {
                    $totalAbsentDays++;
                }
                continue;
            }

            $totalPresentDays++;
            $workedHours      = (float) ($record->working_hours ?? 8);
            $totalOvertimeHours  += (float) ($record->overtime_hours ?? 0);
            $minutesEarlyDeparture = max((float) ($record->minutes_early_departure ?? 0), 0.0);
            $totalUndertimeHours += (float) ($minutesEarlyDeparture / 60);
            if ($record->is_late) {
                $totalLateDays++;
            }

            $dateStr = $record->date->toDateString();
            $holiday = $holidays->get($dateStr);

            if ($holiday) {
                // Employee worked on a holiday — classify hours accordingly
                if ($holiday->holiday_type === 'regular') {
                    $regularHolidayHours += $workedHours;
                } else {
                    // special_non_working, special_working, local
                    $specialHolidayHours += $workedHours;
                }
            } elseif (! $this->isRestDay($record->date, $shopOwner)) {
                $totalRegularHours += $workedHours;
            }

        }

        $workingDays = Carbon::parse($startDate)->diffInWeekdays(Carbon::parse($endDate)) + 1;
        $isFinalized = $records->count() >= ($workingDays * 0.8);

        return [
            'total_regular_hours'      => round($totalRegularHours, 2),
            'total_overtime_hours'     => round($totalOvertimeHours, 2),
            'total_undertime_hours'    => round($totalUndertimeHours, 2),
            'total_absent_days'        => $totalAbsentDays,
            'total_leave_days'         => $totalLeaveDays,
            'total_late_days'          => $totalLateDays,
            'total_present_days'       => $totalPresentDays,
            'special_holiday_hours'    => round($specialHolidayHours, 2),
            'regular_holiday_hours'    => round($regularHolidayHours, 2),
            'working_days'             => $workingDays,
            'is_finalized'             => $isFinalized,
        ];
    }

    protected function isRestDay(Carbon $date, ?ShopOwner $shopOwner): bool
    {
        $dayName = strtolower($date->format('l'));

        if ($shopOwner && $shopOwner->hasScheduleOn($dayName)) {
            return $shopOwner->isClosedOn($dayName);
        }

        return $date->isSunday();
    }

    /**
     * Full payroll calculation for one employee (all logic in backend).
     */
    protected function calculatePayrollPreview(Employee $employee, array $attendanceData, string $startDate, string $endDate): array
    {
        $baseDailyRate = (float) ($employee->salary ?? 0);
        $extraEarnings = $this->payrollService->resolveAdditionalEarnings(
            $employee,
            $startDate . ' to ' . $endDate
        );
        $salesCommission = (float) ($extraEarnings['sales_commission'] ?? 0);
        $performanceBonus = (float) ($extraEarnings['performance_bonus'] ?? 0);
        $otherAllowances = (float) ($extraEarnings['other_allowances'] ?? 0);

        $calculation = $this->payrollService->buildPayrollCalculation(
            $employee,
            $extraEarnings['components'],
            [
                'attendance_days' => (float) ($attendanceData['total_present_days'] ?? 0),
                'absent_days' => (float) ($attendanceData['total_absent_days'] ?? 0),
                'leave_days' => (float) ($attendanceData['total_leave_days'] ?? 0),
                'overtime_hours' => (float) ($attendanceData['total_overtime_hours'] ?? 0),
                'undertime_hours' => (float) ($attendanceData['total_undertime_hours'] ?? 0),
                'special_holiday_hours' => (float) ($attendanceData['special_holiday_hours'] ?? 0),
                'regular_holiday_hours' => (float) ($attendanceData['regular_holiday_hours'] ?? 0),
            ],
            $endDate
        );

        $ruleEngine = $calculation['rules'] ?? [];
        $breakdown = $calculation['breakdown'] ?? [];
        $statutory = $calculation['statutory'] ?? [];

        $basicPay = (float) ($breakdown['basic_pay'] ?? 0);
        $monthlyEquivalentSalary = (float) ($ruleEngine['monthly_base_salary'] ?? ($baseDailyRate * 26));
        $overtimePay = (float) ($breakdown['overtime_pay'] ?? 0);
        $specialHolidayPay = (float) ($breakdown['special_holiday_pay'] ?? 0);
        $regularHolidayPay = (float) ($breakdown['regular_holiday_pay'] ?? 0);

        $totalAllowances = $salesCommission + $performanceBonus + $otherAllowances;
        $grossSalary = (float) ($calculation['gross_salary'] ?? 0);
        $sss = (float) ($statutory['sss_contribution'] ?? 0);
        $philhealth = (float) ($statutory['philhealth_contribution'] ?? 0);
        $pagibig = (float) ($statutory['pagibig_contribution'] ?? 0);
        $withholdingTax = (float) ($statutory['withholding_tax'] ?? 0);
        $absentDeductions = (float) ($breakdown['absent_deductions'] ?? 0);
        $undertimeDeductions = (float) ($breakdown['undertime_deductions'] ?? 0);
        $totalDeductions = (float) ($calculation['total_deductions'] ?? 0);
        $netSalary = (float) ($calculation['net_salary'] ?? 0);

        return [
            'base_salary'            => round($monthlyEquivalentSalary, 2),
            'daily_rate'             => round((float) ($ruleEngine['daily_rate'] ?? 0), 4),
            'hourly_rate'            => round((float) ($ruleEngine['hourly_rate'] ?? 0), 4),
            'work_days_basis'        => (float) ($ruleEngine['work_days_basis'] ?? 0),
            'work_hours_basis'       => (float) ($ruleEngine['work_hours_basis'] ?? 0),
            'basic_pay'              => round($basicPay, 2),
            'overtime_pay'           => round($overtimePay, 2),
            'special_holiday_pay'    => round($specialHolidayPay, 2),
            'regular_holiday_pay'    => round($regularHolidayPay, 2),
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

    protected function buildServiceOverridesFromAttendance(array $attendanceData, string $paymentMethod): array
    {
        return [
            'payment_method' => $paymentMethod,
            'attendance_days' => (int) ($attendanceData['total_present_days'] ?? 0),
            'absent_days' => (int) ($attendanceData['total_absent_days'] ?? 0),
            'leave_days' => (int) ($attendanceData['total_leave_days'] ?? 0),
            'overtime_hours' => (float) ($attendanceData['total_overtime_hours'] ?? 0),
            'undertime_hours' => (float) ($attendanceData['total_undertime_hours'] ?? 0),
            'special_holiday_hours' => (float) ($attendanceData['special_holiday_hours'] ?? 0),
            'regular_holiday_hours' => (float) ($attendanceData['regular_holiday_hours'] ?? 0),
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
