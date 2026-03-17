<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\Finance\TaxRate;
use App\Models\HR\BranchPayrollSetting;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Models\HR\SalaryChange;
use App\Models\HR\TaxBracket;
use App\Models\HR\ThirteenthMonthAccrual;
use App\Models\HR\AuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Exception;

class PayrollService
{
    /**
     * Phase 3 rule engine breakdown.
     * Monthly base pay is the source of truth, then daily/hourly are derived from
     * branch payroll settings (or defaults) and used for premium computations.
     */
    public function computeRuleEngineAmounts(Employee $employee, array $overrides = []): array
    {
        $basis = $this->resolveRateBasis($employee, $overrides);

        $overtimeHours          = $this->normalizeNumber($overrides['overtime_hours'] ?? 0);
        $restDayHours           = $this->normalizeNumber($overrides['rest_day_hours'] ?? 0);
        $specialHolidayHours    = $this->normalizeNumber($overrides['special_holiday_hours'] ?? 0);
        $regularHolidayHours    = $this->normalizeNumber($overrides['regular_holiday_hours'] ?? 0);
        $nightDifferentialHours = $this->normalizeNumber($overrides['night_differential_hours'] ?? 0);
        $absentDays             = $this->normalizeNumber($overrides['absent_days'] ?? 0);
        $undertimeHours         = $this->normalizeNumber($overrides['undertime_hours'] ?? 0);

        $hourlyRate = $basis['hourly_rate'];
        $dailyRate  = $basis['daily_rate'];

        return [
            'monthly_base_salary'      => $basis['monthly_base_salary'],
            'daily_rate'               => $dailyRate,
            'hourly_rate'              => $hourlyRate,
            'work_days_basis'          => $basis['work_days_basis'],
            'work_hours_basis'         => $basis['work_hours_basis'],
            'overtime_multiplier'      => $basis['overtime_multiplier'],
            'rest_day_multiplier'      => $basis['rest_day_multiplier'],
            'special_holiday_multiplier' => $basis['special_holiday_multiplier'],
            'regular_holiday_multiplier' => $basis['regular_holiday_multiplier'],
            'night_differential_rate'  => $basis['night_differential_rate'],

            'overtime_hours'           => $overtimeHours,
            'rest_day_hours'           => $restDayHours,
            'special_holiday_hours'    => $specialHolidayHours,
            'regular_holiday_hours'    => $regularHolidayHours,
            'night_differential_hours' => $nightDifferentialHours,
            'absent_days'              => $absentDays,
            'undertime_hours'          => $undertimeHours,

            'overtime_pay'             => round($hourlyRate * $overtimeHours * $basis['overtime_multiplier'], 2),
            'rest_day_pay'             => round($hourlyRate * $restDayHours * $basis['rest_day_multiplier'], 2),
            'special_holiday_pay'      => round($hourlyRate * $specialHolidayHours * $basis['special_holiday_multiplier'], 2),
            'regular_holiday_pay'      => round($hourlyRate * $regularHolidayHours * $basis['regular_holiday_multiplier'], 2),
            'night_differential_pay'   => round($hourlyRate * $nightDifferentialHours * $basis['night_differential_rate'], 2),

            'absent_deduction'         => round($dailyRate * $absentDays, 2),
            'undertime_deduction'      => round($hourlyRate * $undertimeHours, 2),
        ];
    }

    /**
     * Generate complete payroll for an employee
     *
     * @param Employee $employee
     * @param string $payPeriod Format: 'YYYY-MM' or 'YYYY-MM-01 to YYYY-MM-30'
     * @param array $customComponents Additional components to include
     * @param array $overrides Override calculations (e.g., ['attendance_days' => 20])
     * @return Payroll
     * @throws Exception
     */
    public function generatePayroll(Employee $employee, string $payPeriod, array $customComponents = [], array $overrides = []): Payroll
    {
        DB::beginTransaction();
        
        try {
            // 0. Auto-apply any approved salary changes whose effective_date <= pay period end
            $this->applyPendingSalaryChanges($employee, $payPeriod);

            // 1. Create base payroll record
            $payroll = $this->createPayrollRecord($employee, $payPeriod, $overrides);
            
            // 2. Calculate and create all components
            $components = $this->calculateComponents($employee, $payroll, $customComponents, $overrides);
            
            // 3. Calculate totals
            $earnings = $components
                ->where('component_type', PayrollComponent::TYPE_EARNING)
                ->where('affects_gross', true);
            $deductions = $components->where('component_type', PayrollComponent::TYPE_DEDUCTION);
            $benefits = $components
                ->where('component_type', PayrollComponent::TYPE_BENEFIT)
                ->where('affects_gross', true);
            
            $totalEarnings = $earnings->sum('calculated_amount');
            $totalDeductions = $deductions->sum('calculated_amount');
            $totalBenefits = $benefits->sum('calculated_amount');
            
            // 4. Calculate gross pay (earnings + benefits)
            $grossPay = $totalEarnings + $totalBenefits;
            
            // 5. Calculate statutory deductions + withholding tax per run date
            $taxableAmount = $components
                ->where('is_taxable', true)
                ->where('affects_gross', true)
                ->sum('calculated_amount');
            $runDate = $this->resolveRunDate($payroll->pay_period_end ?? null, $overrides);
            $statutory = $this->calculateStatutoryDeductions(
                (int) $employee->shop_owner_id,
                (float) $taxableAmount,
                $runDate
            );

            $sssContribution = (float) ($statutory['sss_contribution'] ?? 0);
            $philhealthContribution = (float) ($statutory['philhealth_contribution'] ?? 0);
            $pagibigContribution = (float) ($statutory['pagibig_contribution'] ?? 0);
            $taxAmount = (float) ($statutory['withholding_tax'] ?? 0);
            
            // 6. Calculate net pay
            $netPay = $grossPay
                - $totalDeductions
                - $taxAmount
                - $sssContribution
                - $philhealthContribution
                - $pagibigContribution;

            $legacyDeductions = $totalDeductions + $taxAmount + $sssContribution + $philhealthContribution + $pagibigContribution;
            
            // 7. Update payroll record with totals
            $payroll->update([
                'gross_salary' => $grossPay,
                'deductions' => round($legacyDeductions, 2),
                'total_deductions' => $totalDeductions,
                'tax_amount' => $taxAmount,
                'tax_deductions' => $taxAmount,
                'sss_contributions' => round($sssContribution, 2),
                'philhealth' => round($philhealthContribution, 2),
                'pag_ibig' => round($pagibigContribution, 2),
                'net_salary' => round(max(0, $netPay), 2),
                'status' => 'processed'
            ]);
            
            // 8. Create tax component record
            if ($taxAmount > 0) {
                PayrollComponent::create([
                    'payroll_id' => $payroll->id,
                    'shop_owner_id' => $employee->shop_owner_id,
                    'component_type' => PayrollComponent::TYPE_DEDUCTION,
                    'component_name' => 'Income Tax',
                    'base_amount' => 0,
                    'calculation_method' => PayrollComponent::METHOD_CUSTOM,
                    'calculated_amount' => $taxAmount,
                    'is_taxable' => false,
                    'is_recurring' => true,
                    'description' => 'Progressive income tax calculated on taxable components'
                ]);
            }

            // 10. Persist 13th-month monthly accrual ledger
            $this->recordThirteenthMonthAccrual($payroll, $employee, $components, $runDate);
            
            // 11. Log audit trail
            $this->logPayrollGeneration($payroll, $employee, $components->count());
            
            DB::commit();
            
            return $payroll->fresh(['components', 'employee']);
            
        } catch (Exception $e) {
            DB::rollBack();
            
            // Log error
            if (Auth::check()) {
                AuditLog::createLog([
                    'shop_owner_id' => $employee->shop_owner_id,
                    'employee_id' => $employee->id,
                    'module' => AuditLog::MODULE_PAYROLL,
                    'action' => 'generate_failed',
                    'description' => 'Payroll generation failed for ' . $employee->first_name . ' ' . $employee->last_name,
                    'new_values' => [
                        'employee_id' => $employee->id,
                        'payroll_period' => $payPeriod,
                        'error' => $e->getMessage(),
                    ],
                    'severity' => AuditLog::SEVERITY_WARNING,
                    'tags' => ['payroll', 'generation', 'failure'],
                ]);
            }
            
            throw new Exception("Payroll generation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Auto-apply any approved salary changes whose effective_date falls within or before
     * the pay period being generated. Applies the most-recent eligible change first
     * so `$employee->salary` is up-to-date when the payroll record is created.
     */
    protected function applyPendingSalaryChanges(Employee $employee, string $payPeriod): void
    {
        if (strpos($payPeriod, ' to ') !== false) {
            [, $endDate] = explode(' to ', $payPeriod);
        } else {
            $endDate = date('Y-m-t', strtotime($payPeriod . '-01'));
        }

        $pendingChanges = SalaryChange::where('employee_id', $employee->id)
            ->where('status', SalaryChange::STATUS_APPROVED)
            ->whereNull('applied_at')
            ->whereDate('effective_date', '<=', $endDate)
            ->orderBy('effective_date', 'asc')
            ->get();

        foreach ($pendingChanges as $change) {
            try {
                $change->applyToEmployee();
            } catch (\Exception $e) {
                // Log but do not abort payroll generation for a failed salary-change apply
                \Illuminate\Support\Facades\Log::warning(
                    "Failed to auto-apply salary change #{$change->id} during payroll generation: " . $e->getMessage()
                );
            }
        }

        // Refresh the employee model so the new salary is used below
        if ($pendingChanges->isNotEmpty()) {
            $employee->refresh();
        }
    }

    /**
     * Create initial payroll record
     */
    protected function createPayrollRecord(Employee $employee, string $payPeriod, array $overrides): Payroll
    {
        // Parse pay period
        if (strpos($payPeriod, ' to ') !== false) {
            [$startDate, $endDate] = explode(' to ', $payPeriod);
        } else {
            // Assume monthly format YYYY-MM
            $startDate = $payPeriod . '-01';
            $endDate = date('Y-m-t', strtotime($startDate));
        }

        $normalizedPeriodKey = strpos($payPeriod, ' to ') !== false
            ? trim($payPeriod)
            : date('Y-m', strtotime($startDate));
        
        return Payroll::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $employee->shop_owner_id,
            'payroll_period' => $normalizedPeriodKey,
            'pay_period_start' => $startDate,
            'pay_period_end' => $endDate,
            'basic_salary' => $employee->salary ?? 0,   // new canonical column
            'base_salary'  => $employee->salary ?? 0,   // original non-nullable column kept in sync
            'gross_salary' => 0,                        // placeholder; updated after component calculation
            'net_salary'   => 0,                        // placeholder; updated after component calculation
            'attendance_days' => $overrides['attendance_days'] ?? 0,
            'leave_days' => $overrides['leave_days'] ?? 0,
            'absent_days' => $overrides['absent_days'] ?? 0,
            'overtime_hours' => $overrides['overtime_hours'] ?? 0,
            'status' => 'pending',
            'payment_date' => $overrides['payment_date'] ?? date('Y-m-d', strtotime($endDate . ' +5 days')),
            'payment_method' => $overrides['payment_method'] ?? 'bank_transfer',
            'generated_by' => Auth::id(),
            'generated_at' => now()
        ]);
    }
    
    /**
     * Calculate all payroll components
     */
    protected function calculateComponents(Employee $employee, Payroll $payroll, array $customComponents, array $overrides)
    {
        $components = collect();
        $basicSalary = $employee->salary ?? 0;
        $rules = $this->computeRuleEngineAmounts($employee, $overrides);
        
        // Standard earnings (Philippine SME structure)
        $standardEarnings = [
            [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Basic Salary',
                'code' => PayrollComponent::CODE_BASIC_SALARY,
                'base_amount' => $basicSalary,
                'method' => PayrollComponent::METHOD_FIXED,
                'taxable' => true,
                'recurring' => true,
                'affects_gross' => true,
                'category' => 'Basic Pay',
                'description' => 'Monthly basic salary'
            ],
            [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => '13th Month Pay (Accrual)',
                'code' => PayrollComponent::CODE_13TH_ACCRUAL,
                'base_amount' => $basicSalary / 12,
                'method' => PayrollComponent::METHOD_FIXED,
                'taxable' => false, // Tax-exempt up to ₱90,000 per NIRC Sec. 32(B)(7)(e)
                'recurring' => true,
                'affects_gross' => false,
                'category' => 'Accruals',
                'description' => 'Monthly accrual — 1/12 of basic salary (PD 851)'
            ],
        ];
        
        // Standard deductions
        // Statutory deductions (SSS, PhilHealth, Pag-IBIG, withholding tax) are
        // stored as dedicated columns on the Payroll record and handled by the
        // controller / Payroll model boot hook — not duplicated here as components.
        $standardDeductions = [];
        
        if (($rules['overtime_pay'] ?? 0) > 0) {
            $standardEarnings[] = [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Overtime Pay',
                'base_amount' => $rules['overtime_pay'],
                'method' => PayrollComponent::METHOD_OVERTIME,
                'taxable' => true,
                'recurring' => false,
                'affects_gross' => true,
                'category' => 'Premium Pay',
                'description' => number_format($rules['overtime_hours'], 2) . ' hour(s) × ₱' . number_format($rules['hourly_rate'], 2) . ' × ' . number_format($rules['overtime_multiplier'], 2),
            ];
        }

        if (($rules['rest_day_pay'] ?? 0) > 0) {
            $standardEarnings[] = [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Rest Day Pay',
                'base_amount' => $rules['rest_day_pay'],
                'method' => PayrollComponent::METHOD_CUSTOM,
                'taxable' => true,
                'recurring' => false,
                'affects_gross' => true,
                'category' => 'Premium Pay',
                'description' => number_format($rules['rest_day_hours'], 2) . ' hour(s) × ₱' . number_format($rules['hourly_rate'], 2) . ' × ' . number_format($rules['rest_day_multiplier'], 2),
            ];
        }

        if (($rules['special_holiday_pay'] ?? 0) > 0) {
            $standardEarnings[] = [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Special Holiday Pay',
                'base_amount' => $rules['special_holiday_pay'],
                'method' => PayrollComponent::METHOD_CUSTOM,
                'taxable' => true,
                'recurring' => false,
                'affects_gross' => true,
                'category' => 'Premium Pay',
                'description' => number_format($rules['special_holiday_hours'], 2) . ' hour(s) × ₱' . number_format($rules['hourly_rate'], 2) . ' × ' . number_format($rules['special_holiday_multiplier'], 2),
            ];
        }

        if (($rules['regular_holiday_pay'] ?? 0) > 0) {
            $standardEarnings[] = [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Regular Holiday Pay',
                'base_amount' => $rules['regular_holiday_pay'],
                'method' => PayrollComponent::METHOD_CUSTOM,
                'taxable' => true,
                'recurring' => false,
                'affects_gross' => true,
                'category' => 'Premium Pay',
                'description' => number_format($rules['regular_holiday_hours'], 2) . ' hour(s) × ₱' . number_format($rules['hourly_rate'], 2) . ' × ' . number_format($rules['regular_holiday_multiplier'], 2),
            ];
        }

        if (($rules['night_differential_pay'] ?? 0) > 0) {
            $standardEarnings[] = [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Night Differential Pay',
                'base_amount' => $rules['night_differential_pay'],
                'method' => PayrollComponent::METHOD_CUSTOM,
                'taxable' => true,
                'recurring' => false,
                'affects_gross' => true,
                'category' => 'Premium Pay',
                'description' => number_format($rules['night_differential_hours'], 2) . ' hour(s) × ₱' . number_format($rules['hourly_rate'], 2) . ' × ' . number_format($rules['night_differential_rate'], 2),
            ];
        }
        
        // Absent-day deduction: prorate daily rate × absent days
        // absent_days = working days that were neither attended nor on approved leave.
        if (($rules['absent_deduction'] ?? 0) > 0) {
            $standardDeductions[] = [
                'type'        => PayrollComponent::TYPE_DEDUCTION,
                'name'        => 'Absent Day Deduction',
                'base_amount' => $rules['absent_deduction'],
                'method'      => PayrollComponent::METHOD_FIXED,
                'taxable'     => false,
                'recurring'   => false,
                'affects_gross' => false,
                'category'    => 'Attendance Deductions',
                'description' => number_format($rules['absent_days'], 2) . ' absent day(s) × ₱' . number_format($rules['daily_rate'], 2) . '/day (basic ÷ workdays)',
            ];
        }

        if (($rules['undertime_deduction'] ?? 0) > 0) {
            $standardDeductions[] = [
                'type'        => PayrollComponent::TYPE_DEDUCTION,
                'name'        => 'Undertime Deduction',
                'base_amount' => $rules['undertime_deduction'],
                'method'      => PayrollComponent::METHOD_FIXED,
                'taxable'     => false,
                'recurring'   => false,
                'affects_gross' => false,
                'category'    => 'Attendance Deductions',
                'description' => number_format($rules['undertime_hours'], 2) . ' undertime hour(s) × ₱' . number_format($rules['hourly_rate'], 2) . '/hour',
            ];
        }

        // Merge standard and custom components
        $allComponents = array_merge($standardEarnings, $standardDeductions, $customComponents);
        
        // Create component records
        foreach ($allComponents as $componentData) {
            $component = PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'shop_owner_id' => $employee->shop_owner_id,
                'component_type' => $componentData['type'],
                'component_name' => $componentData['name'],
                'component_code' => $componentData['code'] ?? null,
                'amount' => $componentData['base_amount'],           // raw input amount
                'base_amount' => $componentData['base_amount'],      // alias stored for reference
                'calculation_method' => $componentData['method'],
                'calculated_amount' => $this->calculateComponentAmount(
                    $componentData['method'],
                    $componentData['base_amount'],
                    $basicSalary,
                    $overrides
                ),
                'is_taxable' => $componentData['taxable'],
                'is_recurring' => $componentData['recurring'],
                'affects_gross' => (bool) ($componentData['affects_gross'] ?? true),
                'show_on_payslip' => (bool) ($componentData['show_on_payslip'] ?? true),
                'category' => $componentData['category'] ?? null,
                'metadata' => $componentData['metadata'] ?? null,
                'applies_to_grade' => $componentData['grade'] ?? null,
                'applies_to_department' => $componentData['department'] ?? null,
                'description' => $componentData['description'] ?? null
            ]);
            
            $components->push($component);
        }
        
        return $components;
    }
    
    /**
     * Calculate component amount based on method
     */
    protected function calculateComponentAmount(string $method, float $baseAmount, float $basicSalary, array $overrides): float
    {
        $workDays = max(1, $this->normalizeNumber($overrides['standard_work_days_per_month'] ?? 26));
        $workHours = max(1, $this->normalizeNumber($overrides['standard_work_hours_per_day'] ?? 8));
        $monthlyHours = max(1, $workDays * $workHours);

        return match($method) {
            PayrollComponent::METHOD_FIXED => $baseAmount,
            PayrollComponent::METHOD_PERCENTAGE_OF_BASIC => $basicSalary * ($baseAmount / 100),
            PayrollComponent::METHOD_PERCENTAGE_OF_GROSS => ($overrides['gross_salary'] ?? $basicSalary) * ($baseAmount / 100),
            PayrollComponent::METHOD_DAYS_WORKED => ($basicSalary / $workDays) * ($overrides['attendance_days'] ?? $workDays),
            PayrollComponent::METHOD_HOURS_WORKED => ($basicSalary / $monthlyHours) * ($overrides['hours_worked'] ?? $monthlyHours),
            PayrollComponent::METHOD_ALLOWANCE => $baseAmount,
            PayrollComponent::METHOD_OVERTIME => $baseAmount,
            PayrollComponent::METHOD_COMMISSION => $baseAmount,
            PayrollComponent::METHOD_CUSTOM => $baseAmount,
            default => $baseAmount
        };
    }

    protected function resolveRateBasis(Employee $employee, array $overrides): array
    {
        $monthlyBase = $this->normalizeNumber($employee->salary ?? 0);

        $setting = $this->resolveBranchPayrollSetting($employee, $overrides);

        $workDays = $this->normalizeNumber(
            $overrides['standard_work_days_per_month']
                ?? ($setting?->standard_work_days_per_month ?? 26)
        );
        $workHours = $this->normalizeNumber(
            $overrides['standard_work_hours_per_day']
                ?? ($setting?->standard_work_hours_per_day ?? 8)
        );

        $workDays = $workDays > 0 ? $workDays : 26;
        $workHours = $workHours > 0 ? $workHours : 8;

        $dailyRate = $workDays > 0 ? $monthlyBase / $workDays : 0;
        $hourlyRate = $workHours > 0 ? $dailyRate / $workHours : 0;

        return [
            'monthly_base_salary'       => round($monthlyBase, 2),
            'work_days_basis'           => $workDays,
            'work_hours_basis'          => $workHours,
            'daily_rate'                => round($dailyRate, 6),
            'hourly_rate'               => round($hourlyRate, 6),
            'overtime_multiplier'       => $this->normalizeNumber($overrides['overtime_multiplier'] ?? ($setting?->overtime_multiplier ?? 1.25)),
            'rest_day_multiplier'       => $this->normalizeNumber($overrides['rest_day_multiplier'] ?? ($setting?->rest_day_multiplier ?? 1.30)),
            'special_holiday_multiplier' => $this->normalizeNumber($overrides['special_holiday_multiplier'] ?? ($setting?->special_holiday_multiplier ?? 1.30)),
            'regular_holiday_multiplier' => $this->normalizeNumber($overrides['regular_holiday_multiplier'] ?? ($setting?->regular_holiday_multiplier ?? 2.00)),
            'night_differential_rate'   => $this->normalizeNumber($overrides['night_differential_rate'] ?? ($setting?->night_differential_rate ?? 0.10)),
        ];
    }

    protected function resolveBranchPayrollSetting(Employee $employee, array $overrides): ?BranchPayrollSetting
    {
        if (! Schema::hasTable('hr_branch_payroll_settings')) {
            return null;
        }

        $branchName = trim((string) ($overrides['branch_name'] ?? $employee->branch ?? ''));

        $query = BranchPayrollSetting::query()
            ->forShopOwner((int) $employee->shop_owner_id)
            ->active();

        if ($branchName !== '') {
            $setting = (clone $query)->where('branch_name', $branchName)->first();
            if ($setting) {
                return $setting;
            }
        }

        return $query->first();
    }

    protected function normalizeNumber(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return max(0, (float) $value);
    }
    
    /**
     * Calculate progressive income tax (BIR TRAIN Law)
     */
    public function calculateTax(int $shopOwnerId, float $grossIncome, mixed $runDate = null, array $options = []): float
    {
        $date = $this->resolveRunDate($runDate, []);

        $configuredTax = $this->calculateWithholdingTaxFromConfiguredTrainRate($shopOwnerId, $grossIncome, $date);
        if ($configuredTax !== null) {
            return $configuredTax;
        }

        $result = TaxBracket::calculateTax($shopOwnerId, $grossIncome, array_merge([
            'date' => $date,
            'tax_type' => TaxBracket::TAX_INCOME,
            'filing_status' => TaxBracket::STATUS_SINGLE,
            'tax_year' => (int) $date->format('Y'),
        ], $options));

        return (float) ($result['total_tax'] ?? 0);
    }

    /**
     * Calculate statutory deductions for a payroll run date.
     */
    public function calculateStatutoryDeductions(int $shopOwnerId, float $taxableIncome, mixed $runDate = null): array
    {
        $date = $this->resolveRunDate($runDate, []);

        $sss = $this->calculateSssContribution($shopOwnerId, $taxableIncome, $date);
        $philhealth = $this->calculatePhilHealthContribution($shopOwnerId, $taxableIncome, $date);
        $pagibig = $this->calculatePagIbigContribution($shopOwnerId, $taxableIncome, $date);
        $withholdingTax = $this->calculateTax($shopOwnerId, max(0, $taxableIncome - ($sss + $philhealth + $pagibig)), $date);

        return [
            'sss_contribution' => round($sss, 2),
            'philhealth_contribution' => round($philhealth, 2),
            'pagibig_contribution' => round($pagibig, 2),
            'withholding_tax' => round($withholdingTax, 2),
        ];
    }

    protected function calculateSssContribution(int $shopOwnerId, float $income, Carbon $runDate): float
    {
        $taxRate = $this->resolveEffectiveTaxRate($shopOwnerId, 'PAYROLL_SSS_EE', $runDate);

        if ($taxRate) {
            $meta = is_array($taxRate->meta) ? $taxRate->meta : [];
            $brackets = $meta['brackets'] ?? [];

            if (is_array($brackets) && !empty($brackets)) {
                foreach ($brackets as $bracket) {
                    $min = (float) ($bracket['min'] ?? 0);
                    $max = isset($bracket['max']) ? (float) $bracket['max'] : null;
                    $employeeShare = (float) ($bracket['employee_share'] ?? 0);

                    if ($income >= $min && ($max === null || $income <= $max)) {
                        return $employeeShare;
                    }
                }
            }

            if ($taxRate->type === 'fixed') {
                return (float) ($taxRate->fixed_amount ?? 0);
            }

            return round(($income * (float) $taxRate->rate) / 100, 2);
        }

        return $this->calculateSssFallback($income);
    }

    protected function calculatePhilHealthContribution(int $shopOwnerId, float $income, Carbon $runDate): float
    {
        $taxRate = $this->resolveEffectiveTaxRate($shopOwnerId, 'PAYROLL_PHILHEALTH_EE', $runDate);

        if ($taxRate) {
            $meta = is_array($taxRate->meta) ? $taxRate->meta : [];

            $floor = (float) ($meta['min_salary'] ?? 10000);
            $ceiling = (float) ($meta['max_salary'] ?? 100000);

            $base = min(max($income, $floor), $ceiling);

            if ($taxRate->type === 'fixed') {
                return (float) ($taxRate->fixed_amount ?? 0);
            }

            return round(($base * (float) $taxRate->rate) / 100, 2);
        }

        return round(min(max($income, 10000), 100000) * 0.025, 2);
    }

    protected function calculatePagIbigContribution(int $shopOwnerId, float $income, Carbon $runDate): float
    {
        $taxRate = $this->resolveEffectiveTaxRate($shopOwnerId, 'PAYROLL_PAGIBIG_EE', $runDate);

        if ($taxRate) {
            $meta = is_array($taxRate->meta) ? $taxRate->meta : [];
            $tiers = $meta['tiers'] ?? [];
            $maxContribution = (float) ($meta['max_contribution'] ?? 100);

            if (is_array($tiers) && !empty($tiers)) {
                foreach ($tiers as $tier) {
                    $max = isset($tier['max_salary']) ? (float) $tier['max_salary'] : null;
                    $rate = (float) ($tier['rate'] ?? 0);

                    if ($max === null || $income <= $max) {
                        return min(round(($income * $rate) / 100, 2), $maxContribution);
                    }
                }
            }

            if ($taxRate->type === 'fixed') {
                return (float) ($taxRate->fixed_amount ?? 0);
            }

            return min(round(($income * (float) $taxRate->rate) / 100, 2), $maxContribution);
        }

        return $income <= 1500
            ? round($income * 0.01, 2)
            : min(round($income * 0.02, 2), 100);
    }

    protected function calculateSssFallback(float $income): float
    {
        if ($income >= 30000) {
            return 1350;
        }

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

        foreach ($table as $ceiling => $contribution) {
            if ($income < $ceiling) {
                return $contribution;
            }
        }

        return 900;
    }

    protected function calculateWithholdingTaxFromConfiguredTrainRate(int $shopOwnerId, float $taxableIncome, Carbon $runDate): ?float
    {
        $taxRate = $this->resolveEffectiveTaxRate($shopOwnerId, 'PAYROLL_WHT_TRAIN', $runDate);

        if (! $taxRate) {
            return null;
        }

        $meta = is_array($taxRate->meta) ? $taxRate->meta : [];
        $brackets = $meta['monthly_brackets'] ?? [];

        if (! is_array($brackets) || empty($brackets)) {
            if ($taxRate->type === 'fixed') {
                return (float) ($taxRate->fixed_amount ?? 0);
            }

            return round(($taxableIncome * (float) $taxRate->rate) / 100, 2);
        }

        foreach ($brackets as $bracket) {
            $min = (float) ($bracket['min'] ?? 0);
            $max = isset($bracket['max']) ? (float) $bracket['max'] : null;
            $fixed = (float) ($bracket['fixed'] ?? 0);
            $rate = (float) ($bracket['rate'] ?? 0);

            if ($taxableIncome >= $min && ($max === null || $taxableIncome <= $max)) {
                return round($fixed + (($taxableIncome - $min) * ($rate / 100)), 2);
            }
        }

        return 0.0;
    }

    protected function resolveEffectiveTaxRate(int $shopOwnerId, string $code, Carbon $runDate): ?TaxRate
    {
        if (! Schema::hasTable('finance_tax_rates')) {
            return null;
        }

        return TaxRate::query()
            ->forShop($shopOwnerId)
            ->where('code', $code)
            ->where('is_active', true)
            ->where(function ($query) use ($runDate) {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $runDate->toDateString());
            })
            ->where(function ($query) use ($runDate) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $runDate->toDateString());
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    protected function resolveRunDate(mixed $runDate, array $overrides): Carbon
    {
        if (! empty($overrides['run_date'])) {
            return Carbon::parse($overrides['run_date'])->startOfDay();
        }

        if (! empty($runDate)) {
            return Carbon::parse($runDate)->startOfDay();
        }

        if (! empty($overrides['pay_period_end'])) {
            return Carbon::parse($overrides['pay_period_end'])->startOfDay();
        }

        if (! empty($overrides['payment_date'])) {
            return Carbon::parse($overrides['payment_date'])->startOfDay();
        }

        return now()->startOfDay();
    }

    protected function extractThirteenthMonthAccrualAmount($components): float
    {
        return (float) $components
            ->where('component_code', PayrollComponent::CODE_13TH_ACCRUAL)
            ->sum('calculated_amount');
    }

    protected function recordThirteenthMonthAccrual(Payroll $payroll, Employee $employee, $components, Carbon $runDate): void
    {
        if (! Schema::hasTable('hr_thirteenth_month_accruals')) {
            return;
        }

        $accrualAmount = round($this->extractThirteenthMonthAccrualAmount($components), 2);
        if ($accrualAmount <= 0) {
            return;
        }

        ThirteenthMonthAccrual::query()->updateOrCreate(
            [
                'shop_owner_id' => (int) $employee->shop_owner_id,
                'employee_id' => (int) $employee->id,
                'accrual_year' => (int) $runDate->format('Y'),
                'accrual_month' => (int) $runDate->format('n'),
            ],
            [
                'payroll_id' => $payroll->id,
                'accrual_amount' => $accrualAmount,
                'status' => 'accrued',
            ]
        );
    }

    /**
     * Controlled 13th-month release process.
     *
     * - Runs by year
     * - Defaults to December-only release window (override via options)
     * - Uses accrued balance ledger and writes release as a payroll earning component
     */
    public function releaseThirteenthMonth(int $shopOwnerId, int $year, int $releasedBy, array $employeeIds = [], array $options = []): array
    {
        if (! Schema::hasTable('hr_thirteenth_month_accruals')) {
            throw new Exception('13th-month accrual ledger table is not available. Run migrations first.');
        }

        $releaseDate = $this->resolveRunDate($options['release_date'] ?? null, []);
        $allowNonDecember = (bool) ($options['allow_non_december'] ?? false);

        if (! $allowNonDecember && (int) $releaseDate->format('n') !== 12) {
            throw new Exception('13th-month release is restricted to December unless explicitly overridden.');
        }

        $employeeQuery = Employee::query()
            ->forShopOwner($shopOwnerId)
            ->where('status', 'active');

        if (! empty($employeeIds)) {
            $employeeQuery->whereIn('id', $employeeIds);
        }

        $employees = $employeeQuery->get(['id', 'first_name', 'last_name']);

        $processed = 0;
        $skipped = 0;
        $results = [];

        DB::beginTransaction();

        try {
            foreach ($employees as $employee) {
                $accrualQuery = ThirteenthMonthAccrual::query()
                    ->forShopOwner($shopOwnerId)
                    ->forEmployee((int) $employee->id)
                    ->forYear($year);

                $totalAccrued = round((float) $accrualQuery->sum('accrual_amount'), 2);
                $totalReleased = round((float) $accrualQuery->sum('release_amount'), 2);
                $remainingBalance = round(max(0, $totalAccrued - $totalReleased), 2);

                if ($remainingBalance <= 0) {
                    $skipped++;
                    $results[] = [
                        'employee_id' => (int) $employee->id,
                        'employee_name' => trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')),
                        'status' => 'skipped',
                        'reason' => 'no_unreleased_balance',
                        'accrued' => $totalAccrued,
                        'released' => $totalReleased,
                        'released_now' => 0,
                    ];
                    continue;
                }

                $decemberPeriod = sprintf('%04d-12', $year);

                $payroll = Payroll::query()
                    ->forShopOwner($shopOwnerId)
                    ->forEmployee((int) $employee->id)
                    ->forPeriod($decemberPeriod)
                    ->first();

                if (! $payroll) {
                    $skipped++;
                    $results[] = [
                        'employee_id' => (int) $employee->id,
                        'employee_name' => trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')),
                        'status' => 'skipped',
                        'reason' => 'missing_december_payroll',
                        'accrued' => $totalAccrued,
                        'released' => $totalReleased,
                        'released_now' => 0,
                    ];
                    continue;
                }

                if ($payroll->status === 'paid') {
                    $skipped++;
                    $results[] = [
                        'employee_id' => (int) $employee->id,
                        'employee_name' => trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')),
                        'status' => 'skipped',
                        'reason' => 'december_payroll_already_paid',
                        'accrued' => $totalAccrued,
                        'released' => $totalReleased,
                        'released_now' => 0,
                    ];
                    continue;
                }

                $requireChecker = (bool) config('payroll_governance.maker_checker.require_checker_before_release', true);
                $requireFinalApprover = (bool) config('payroll_governance.maker_checker.require_final_approver_before_release', true);

                if ($requireChecker && empty($payroll->approved_by)) {
                    $skipped++;
                    $results[] = [
                        'employee_id' => (int) $employee->id,
                        'employee_name' => trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')),
                        'status' => 'skipped',
                        'reason' => 'checker_approval_required',
                        'accrued' => $totalAccrued,
                        'released' => $totalReleased,
                        'released_now' => 0,
                    ];
                    continue;
                }

                if ($requireFinalApprover && empty($payroll->final_approved_by)) {
                    $skipped++;
                    $results[] = [
                        'employee_id' => (int) $employee->id,
                        'employee_name' => trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')),
                        'status' => 'skipped',
                        'reason' => 'final_approval_required',
                        'accrued' => $totalAccrued,
                        'released' => $totalReleased,
                        'released_now' => 0,
                    ];
                    continue;
                }

                if ($requireFinalApprover && (int) ($payroll->approved_by ?? 0) === (int) ($payroll->final_approved_by ?? 0)) {
                    $skipped++;
                    $results[] = [
                        'employee_id' => (int) $employee->id,
                        'employee_name' => trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')),
                        'status' => 'skipped',
                        'reason' => 'final_approver_must_differ_from_checker',
                        'accrued' => $totalAccrued,
                        'released' => $totalReleased,
                        'released_now' => 0,
                    ];
                    continue;
                }

                $existingReleaseComponent = PayrollComponent::query()
                    ->where('payroll_id', $payroll->id)
                    ->where('component_code', PayrollComponent::CODE_13TH_RELEASE)
                    ->first();

                $existingReleaseAmount = (float) ($existingReleaseComponent->calculated_amount ?? 0);
                $deltaToApply = round($remainingBalance - $existingReleaseAmount, 2);

                PayrollComponent::query()->updateOrCreate(
                    [
                        'payroll_id' => $payroll->id,
                        'component_code' => PayrollComponent::CODE_13TH_RELEASE,
                    ],
                    [
                        'shop_owner_id' => $shopOwnerId,
                        'component_type' => PayrollComponent::TYPE_EARNING,
                        'component_name' => '13th Month Pay (December Release)',
                        'amount' => $remainingBalance,
                        'base_amount' => $remainingBalance,
                        'calculation_method' => PayrollComponent::METHOD_FIXED,
                        'calculated_amount' => $remainingBalance,
                        'is_taxable' => false,
                        'is_statutory' => false,
                        'is_recurring' => false,
                        'affects_gross' => true,
                        'show_on_payslip' => true,
                        'category' => 'Bonuses',
                        'description' => 'Controlled December release of accrued 13th month pay (PD 851)',
                        'metadata' => [
                            'year' => $year,
                            'released_by' => $releasedBy,
                            'released_at' => $releaseDate->toDateString(),
                        ],
                    ]
                );

                if (abs($deltaToApply) >= 0.01) {
                    $payroll->gross_salary = round((float) $payroll->gross_salary + $deltaToApply, 2);
                    $payroll->bonus = round((float) $payroll->bonus + $deltaToApply, 2);
                    $payroll->net_salary = round((float) $payroll->net_salary + $deltaToApply, 2);
                    $payroll->save();
                }

                $decemberAccrual = ThirteenthMonthAccrual::query()->updateOrCreate(
                    [
                        'shop_owner_id' => $shopOwnerId,
                        'employee_id' => (int) $employee->id,
                        'accrual_year' => $year,
                        'accrual_month' => 12,
                    ],
                    [
                        'payroll_id' => $payroll->id,
                        'accrual_amount' => 0,
                        'release_amount' => 0,
                        'status' => 'accrued',
                    ]
                );

                $decemberAccrual->payroll_id = $payroll->id;
                $decemberAccrual->release_amount = round((float) $decemberAccrual->release_amount + $remainingBalance, 2);
                $decemberAccrual->released_by = $releasedBy;
                $decemberAccrual->released_at = $releaseDate;
                $decemberAccrual->release_reference = '13TH-' . $year . '-' . $payroll->id;

                $updatedReleased = round($totalReleased + $remainingBalance, 2);
                $decemberAccrual->status = $updatedReleased >= round($totalAccrued, 2)
                    ? 'released'
                    : 'partially_released';

                $decemberAccrual->save();

                $processed++;
                $results[] = [
                    'employee_id' => (int) $employee->id,
                    'employee_name' => trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')),
                    'status' => 'released',
                    'reason' => null,
                    'payroll_id' => (int) $payroll->id,
                    'accrued' => $totalAccrued,
                    'released' => $updatedReleased,
                    'released_now' => $remainingBalance,
                    'remaining_balance' => round(max(0, $totalAccrued - $updatedReleased), 2),
                ];
            }

            DB::commit();

            return [
                'year' => $year,
                'release_date' => $releaseDate->toDateString(),
                'processed_count' => $processed,
                'skipped_count' => $skipped,
                'items' => $results,
            ];
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getThirteenthMonthReconciliationReport(int $shopOwnerId, int $year, array $filters = []): array
    {
        if (! Schema::hasTable('hr_thirteenth_month_accruals')) {
            return [
                'year' => $year,
                'generated_at' => now()->toIso8601String(),
                'summary' => [
                    'employees' => 0,
                    'total_accrued' => 0,
                    'total_released' => 0,
                    'total_balance' => 0,
                ],
                'employees' => [],
            ];
        }

        $employeeIds = array_values(array_filter(array_map('intval', $filters['employee_ids'] ?? [])));

        $query = ThirteenthMonthAccrual::query()
            ->forShopOwner($shopOwnerId)
            ->forYear($year);

        if (! empty($employeeIds)) {
            $query->whereIn('employee_id', $employeeIds);
        }

        $rows = $query
            ->with('employee:id,first_name,last_name')
            ->orderBy('employee_id')
            ->orderBy('accrual_month')
            ->get();

        $grouped = $rows->groupBy('employee_id');

        $employees = [];
        $totalAccruedAll = 0.0;
        $totalReleasedAll = 0.0;
        $totalBalanceAll = 0.0;

        foreach ($grouped as $employeeId => $employeeRows) {
            $employee = $employeeRows->first()->employee;
            $employeeName = trim((string) ($employee->first_name ?? '') . ' ' . (string) ($employee->last_name ?? ''));

            $totalAccrued = round((float) $employeeRows->sum('accrual_amount'), 2);
            $totalReleased = round((float) $employeeRows->sum('release_amount'), 2);
            $balance = round(max(0, $totalAccrued - $totalReleased), 2);

            $decemberPayroll = Payroll::query()
                ->forShopOwner($shopOwnerId)
                ->forEmployee((int) $employeeId)
                ->forPeriod(sprintf('%04d-12', $year))
                ->first();

            $decemberReleaseComponent = 0.0;
            if ($decemberPayroll) {
                $decemberReleaseComponent = round((float) PayrollComponent::query()
                    ->where('payroll_id', $decemberPayroll->id)
                    ->where('component_code', PayrollComponent::CODE_13TH_RELEASE)
                    ->sum('calculated_amount'), 2);
            }

            $employees[] = [
                'employee_id' => (int) $employeeId,
                'employee_name' => $employeeName,
                'monthly_breakdown' => $employeeRows->map(function ($row) {
                    return [
                        'month' => (int) $row->accrual_month,
                        'accrual_amount' => (float) $row->accrual_amount,
                        'release_amount' => (float) $row->release_amount,
                        'status' => $row->status,
                        'payroll_id' => $row->payroll_id,
                        'released_at' => $row->released_at?->toDateString(),
                    ];
                })->values(),
                'totals' => [
                    'accrued' => $totalAccrued,
                    'released' => $totalReleased,
                    'balance' => $balance,
                    'december_payroll_id' => $decemberPayroll?->id,
                    'december_release_component' => $decemberReleaseComponent,
                    'reconciliation_variance' => round($totalReleased - $decemberReleaseComponent, 2),
                ],
            ];

            $totalAccruedAll += $totalAccrued;
            $totalReleasedAll += $totalReleased;
            $totalBalanceAll += $balance;
        }

        return [
            'year' => $year,
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'employees' => count($employees),
                'total_accrued' => round($totalAccruedAll, 2),
                'total_released' => round($totalReleasedAll, 2),
                'total_balance' => round($totalBalanceAll, 2),
            ],
            'employees' => $employees,
        ];
    }
    
    /**
     * Recalculate existing payroll
     */
    public function recalculatePayroll(Payroll $payroll, array $overrides = []): Payroll
    {
        // Delete existing components except manually added ones
        $payroll->components()->where('is_recurring', true)->delete();
        
        // Regenerate with new overrides
        return $this->generatePayroll(
            $payroll->employee,
            $payroll->pay_period_start . ' to ' . $payroll->pay_period_end,
            [],
            array_merge([
                'attendance_days' => $payroll->attendance_days,
                'leave_days' => $payroll->leave_days,
                'overtime_hours' => $payroll->overtime_hours,
                'payment_date' => $payroll->payment_date,
                'payment_method' => $payroll->payment_method
            ], $overrides)
        );
    }
    
    /**
     * Get payroll summary by period
     */
    public function getPayrollSummary(int $shopOwnerId, string $periodStart, string $periodEnd): array
    {
        $payrolls = Payroll::where('shop_owner_id', $shopOwnerId)
            ->whereBetween('pay_period_start', [$periodStart, $periodEnd])
            ->with('components')
            ->get();
        
        return [
            'total_employees' => $payrolls->count(),
            'total_gross' => $payrolls->sum('gross_salary'),
            'total_deductions' => $payrolls->sum('total_deductions'),
            'total_tax' => $payrolls->sum('tax_amount'),
            'total_net' => $payrolls->sum('net_salary'),
            'components_breakdown' => $this->getComponentsBreakdown($payrolls),
            'payrolls' => $payrolls
        ];
    }
    
    /**
     * Get components breakdown
     */
    protected function getComponentsBreakdown($payrolls): array
    {
        $allComponents = $payrolls->flatMap->components;
        
        return [
            'earnings' => $allComponents
                ->where('component_type', PayrollComponent::TYPE_EARNING)
                ->groupBy('component_name')
                ->map->sum('calculated_amount'),
            'deductions' => $allComponents
                ->where('component_type', PayrollComponent::TYPE_DEDUCTION)
                ->groupBy('component_name')
                ->map->sum('calculated_amount'),
            'benefits' => $allComponents
                ->where('component_type', PayrollComponent::TYPE_BENEFIT)
                ->groupBy('component_name')
                ->map->sum('calculated_amount')
        ];
    }
    
    /**
     * Log payroll generation activity
     */
    protected function logPayrollGeneration(Payroll $payroll, Employee $employee, int $componentCount): void
    {
        if (!Auth::check()) return;
        
        AuditLog::createLog([
            'shop_owner_id' => $payroll->shop_owner_id,
            'employee_id' => $employee->id,
            'module' => AuditLog::MODULE_PAYROLL,
            'action' => AuditLog::ACTION_GENERATED,
            'entity_type' => Payroll::class,
            'entity_id' => $payroll->id,
            'description' => 'Payroll generated for ' . $employee->first_name . ' ' . $employee->last_name,
            'new_values' => [
                'employee_id' => $employee->id,
                'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                'period' => $payroll->pay_period_start . ' to ' . $payroll->pay_period_end,
                'gross_salary' => $payroll->gross_salary,
                'net_salary' => $payroll->net_salary,
                'components_count' => $componentCount,
                'payment_date' => $payroll->payment_date
            ],
            'severity' => AuditLog::SEVERITY_WARNING,
            'tags' => ['payroll', 'generation'],
        ]);
    }
    
    /**
     * Validate payroll data
     */
    public function validatePayroll(Payroll $payroll): array
    {
        $issues = [];
        
        // Check if components exist
        if ($payroll->components->isEmpty()) {
            $issues[] = 'No payroll components found';
        }
        
        // Verify calculations
        $calculatedGross = $payroll->components
            ->whereIn('component_type', [PayrollComponent::TYPE_EARNING, PayrollComponent::TYPE_BENEFIT])
            ->sum('calculated_amount');
            
        if (abs($calculatedGross - $payroll->gross_salary) > 0.01) {
            $issues[] = 'Gross salary mismatch: Expected ' . $calculatedGross . ', got ' . $payroll->gross_salary;
        }
        
        // Check for negative values
        if ($payroll->net_salary < 0) {
            $issues[] = 'Negative net salary detected';
        }
        
        return $issues;
    }
}
