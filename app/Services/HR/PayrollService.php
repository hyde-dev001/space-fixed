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
use App\Support\PayrollMoney;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Exception;

class PayrollService
{
    /**
     * Phase 3 rule engine breakdown.
        * Daily base pay is the source of truth, while monthly/hourly are derived from
        * branch payroll settings (or defaults) and used for payroll computations.
     */
    public function computeRuleEngineAmounts(Employee $employee, array $overrides = []): array
    {
        $basis = $this->resolveRateBasis($employee, $overrides);

        $overtimeHours          = $this->normalizeNumber($overrides['overtime_hours'] ?? 0);
        $specialHolidayHours    = $this->normalizeNumber($overrides['special_holiday_hours'] ?? 0);
        $regularHolidayHours    = $this->normalizeNumber($overrides['regular_holiday_hours'] ?? 0);
        $absentDays             = $this->normalizeNumber($overrides['absent_days'] ?? 0);
        $undertimeHours         = $this->normalizeNumber($overrides['undertime_hours'] ?? 0);
        $lateHours              = $this->normalizeNumber($overrides['late_hours'] ?? 0);
        $halfDayDays            = $this->normalizeNumber($overrides['half_day_days'] ?? 0);

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
            'rest_day_hours'           => 0,
            'special_holiday_hours'    => $specialHolidayHours,
            'regular_holiday_hours'    => $regularHolidayHours,
            'night_differential_hours' => 0,
            'absent_days'              => $absentDays,
            'undertime_hours'          => $undertimeHours,
            'late_hours'               => $lateHours,
            'half_day_days'            => $halfDayDays,

            'overtime_pay'             => PayrollMoney::multiply(
                PayrollMoney::multiply($hourlyRate, $overtimeHours),
                $basis['overtime_multiplier']
            ),
            'rest_day_pay'             => 0,
            'special_holiday_pay'      => PayrollMoney::multiply(
                PayrollMoney::multiply($hourlyRate, $specialHolidayHours),
                $basis['special_holiday_multiplier']
            ),
            'regular_holiday_pay'      => PayrollMoney::multiply(
                PayrollMoney::multiply($hourlyRate, $regularHolidayHours),
                $basis['regular_holiday_multiplier']
            ),
            'night_differential_pay'   => 0,

            'absent_deduction'         => PayrollMoney::multiply($dailyRate, $absentDays),
            'undertime_deduction'      => PayrollMoney::multiply($hourlyRate, $undertimeHours),
            'late_deduction'           => PayrollMoney::multiply($hourlyRate, $lateHours),
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
        $period = $this->parsePayPeriod($payPeriod);
        $calculationOverrides = array_merge($overrides, [
            'pay_period_start' => $period['start_date'],
            'pay_period_end' => $period['end_date'],
            'rate_effective_date' => $period['start_date'],
        ]);

        DB::beginTransaction();
        
        try {
            // 1. Create base payroll record
            $payroll = $this->createPayrollRecord($employee, $payPeriod, $calculationOverrides);

            // 2. Build a shared calculation payload used by preview and generation.
            $calculation = $this->buildPayrollCalculation(
                $employee,
                $customComponents,
                $calculationOverrides,
                $payroll->pay_period_end ?? null
            );
            
            // 3. Persist the calculation's component rows.
            $components = $this->calculateComponents($employee, $payroll, $customComponents, $overrides, $calculation);

            // 4. Reuse the shared totals for the saved payroll record.
            $grossPay = PayrollMoney::round($calculation['gross_salary'] ?? 0);
            $totalDeductions = PayrollMoney::round($calculation['total_deductions'] ?? 0);
            $runDate = $calculation['run_date'];
            $statutory = $calculation['statutory'] ?? [];

            $sssContribution = PayrollMoney::round($statutory['sss_contribution'] ?? 0);
            $philhealthContribution = PayrollMoney::round($statutory['philhealth_contribution'] ?? 0);
            $pagibigContribution = PayrollMoney::round($statutory['pagibig_contribution'] ?? 0);
            $taxAmount = PayrollMoney::round($statutory['withholding_tax'] ?? 0);
            
            // 5. Calculate net pay
            $netPay = PayrollMoney::maxZero($calculation['net_salary'] ?? 0);
            $basicPayForRun = PayrollMoney::round($calculation['breakdown']['basic_pay'] ?? 0);

            // 6. Update payroll record with totals
            $payroll->update([
                'basic_salary' => $basicPayForRun,
                'base_salary' => $basicPayForRun,
                'gross_salary' => $grossPay,
                'deductions' => $totalDeductions,
                'total_deductions' => $totalDeductions,
                'tax_amount' => $taxAmount,
                'tax_deductions' => $taxAmount,
                'sss_contributions' => $sssContribution,
                'philhealth' => $philhealthContribution,
                'pag_ibig' => $pagibigContribution,
                'net_salary' => $netPay,
                'calculation_snapshot' => [
                    'version' => 2,
                    'period' => [
                        'start' => $period['start_date'],
                        'end' => $period['end_date'],
                    ],
                    'run_date' => $runDate->toDateString(),
                    'rules' => $calculation['rules'],
                    'statutory_bases' => $calculation['statutory_bases'] ?? [],
                    'statutory' => $calculation['statutory'],
                    'employer_contributions' => $calculation['statutory']['employer_contributions'] ?? [],
                    'totals' => [
                        'gross_salary' => $grossPay,
                        'total_deductions' => $totalDeductions,
                        'net_salary' => $netPay,
                    ],
                ],
                'status' => 'processed'
            ]);
            
            // 7. Create tax component record
            if (PayrollMoney::compare($taxAmount, 0) > 0) {
                PayrollComponent::create([
                    'payroll_id' => $payroll->id,
                    'shop_owner_id' => $employee->shop_owner_id,
                    'component_type' => PayrollComponent::TYPE_DEDUCTION,
                    'component_name' => 'Income Tax',
                    'amount' => $taxAmount,
                    'base_amount' => 0,
                    'calculation_method' => PayrollComponent::METHOD_CUSTOM,
                    'calculated_amount' => $taxAmount,
                    'is_taxable' => false,
                    'is_recurring' => true,
                    'description' => 'Progressive income tax calculated on taxable components'
                ]);
            }

            // 8. Persist 13th-month monthly accrual ledger
            $this->recordThirteenthMonthAccrual($payroll, $employee, $components, $runDate);
            
            // 9. Log audit trail
            $this->logPayrollGeneration($payroll, $employee, $components->count());
            
            DB::commit();
            
            return $payroll->fresh(['components', 'employee']);
            
        } catch (Exception $e) {
            DB::rollBack();
            
            // Log error
            if (Auth::guard('user')->check() || Auth::guard('shop_owner')->check()) {
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
     * Build a payroll preview using the same core computation path as generation
     * without persisting payroll/component rows.
     */
    public function previewPayroll(Employee $employee, string $payPeriod, array $additionalEarnings = [], array $overrides = []): array
    {
        $period = $this->parsePayPeriod($payPeriod);
        $calculationOverrides = array_merge($overrides, [
            'pay_period_start' => $period['start_date'],
            'pay_period_end' => $period['end_date'],
            'rate_effective_date' => $period['start_date'],
        ]);

        $resolvedAdditionalEarnings = $this->resolveAdditionalEarnings(
            $employee,
            $period['normalized_period_key'],
            array_merge($additionalEarnings, ['rate_effective_date' => $period['start_date']])
        );
        $customComponents = $resolvedAdditionalEarnings['components'] ?? [];

        $calculation = $this->buildPayrollCalculation(
            $employee,
            $customComponents,
            $calculationOverrides,
            $period['end_date']
        );

        return [
            'period' => $period,
            'resolved_additional_earnings' => [
                'sales_commission' => (float) ($resolvedAdditionalEarnings['sales_commission'] ?? 0),
                'performance_bonus' => (float) ($resolvedAdditionalEarnings['performance_bonus'] ?? 0),
                'other_allowances' => (float) ($resolvedAdditionalEarnings['other_allowances'] ?? 0),
            ],
            'calculation' => $calculation,
        ];
    }
    
    /**
     * Create initial payroll record
     */
    protected function createPayrollRecord(Employee $employee, string $payPeriod, array $overrides): Payroll
    {
        $period = $this->parsePayPeriod($payPeriod);
        $startDate = $period['start_date'];
        $endDate = $period['end_date'];
        $normalizedPeriodKey = $period['normalized_period_key'];
        $rateBasis = $this->resolveRateBasis($employee, $overrides);
        
        return Payroll::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $employee->shop_owner_id,
            'payroll_period' => $normalizedPeriodKey,
            'pay_period_start' => $startDate,
            'pay_period_end' => $endDate,
            'basic_salary' => $rateBasis['monthly_base_salary'], // placeholder monthly-equivalent; finalized after calculations
            'base_salary'  => $rateBasis['monthly_base_salary'], // original non-nullable column kept in sync
            'gross_salary' => 0,                        // placeholder; updated after component calculation
            'net_salary'   => 0,                        // placeholder; updated after component calculation
            'attendance_days' => $overrides['attendance_days'] ?? 0,
            'leave_days' => $overrides['leave_days'] ?? 0,
            'absent_days' => $overrides['absent_days'] ?? 0,
            'overtime_hours' => $overrides['overtime_hours'] ?? 0,
            'status' => 'pending',
            'payment_date' => $overrides['payment_date'] ?? date('Y-m-d', strtotime($endDate . ' +5 days')),
            'payment_method' => $overrides['payment_method'] ?? 'bank_transfer',
            // Shop owners authenticate through a separate guard and do not
            // have a users-table id. Keep this nullable user metadata valid
            // while AuditLog::createLog() records the shop-owner actor.
            'generated_by' => Auth::guard('user')->id(),
            'generated_at' => now()
        ]);
    }

    /**
     * Normalize payroll period labels into concrete bounds and canonical keys.
     */
    protected function parsePayPeriod(string $payPeriod): array
    {
        if (strpos($payPeriod, ' to ') !== false) {
            [$startDateRaw, $endDateRaw] = array_map('trim', explode(' to ', $payPeriod, 2));
            $startDate = Carbon::parse($startDateRaw)->toDateString();
            $endDate = Carbon::parse($endDateRaw)->toDateString();

            if ($startDate > $endDate) {
                [$startDate, $endDate] = [$endDate, $startDate];
            }

            return [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'normalized_period_key' => $startDate . ' to ' . $endDate,
            ];
        }

        $startDate = Carbon::createFromFormat('Y-m-d', trim($payPeriod) . '-01')->toDateString();
        $endDate = Carbon::parse($startDate)->endOfMonth()->toDateString();

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'normalized_period_key' => Carbon::parse($startDate)->format('Y-m'),
        ];
    }

    /**
     * Build a normalized payroll calculation payload that can be reused by
     * preview and generation flows without persisting component rows.
     */
    public function buildPayrollCalculation(
        Employee $employee,
        array $customComponents = [],
        array $overrides = [],
        mixed $runDate = null
    ): array {
        $rules = $this->computeRuleEngineAmounts($employee, $overrides);
        $componentDefinitions = $this->buildComponentDefinitions($employee, $customComponents, $overrides, $rules);
        $basicSalary = PayrollMoney::round($rules['monthly_base_salary'] ?? 0);

        $components = collect($componentDefinitions)
            ->map(function (array $componentData) use ($basicSalary, $overrides) {
                $baseAmount = PayrollMoney::round($componentData['base_amount'] ?? 0);

                return [
                    'type' => $componentData['type'],
                    'name' => $componentData['name'],
                    'component_type' => $componentData['type'],
                    'component_name' => $componentData['name'],
                    'code' => $componentData['code'] ?? null,
                    'component_code' => $componentData['code'] ?? null,
                    'amount' => $baseAmount,
                    'base_amount' => $baseAmount,
                    'method' => $componentData['method'],
                    'calculation_method' => $componentData['method'],
                    'calculated_amount' => PayrollMoney::round($this->calculateComponentAmount(
                        $componentData['method'],
                        $baseAmount,
                        $basicSalary,
                        $overrides
                    )
                    ),
                    'is_taxable' => (bool) ($componentData['taxable'] ?? false),
                    'is_recurring' => (bool) ($componentData['recurring'] ?? false),
                    'affects_gross' => (bool) ($componentData['affects_gross'] ?? true),
                    'show_on_payslip' => (bool) ($componentData['show_on_payslip'] ?? true),
                    'category' => $componentData['category'] ?? null,
                    'metadata' => $componentData['metadata'] ?? null,
                    'applies_to_grade' => $componentData['grade'] ?? null,
                    'applies_to_department' => $componentData['department'] ?? null,
                    'description' => $componentData['description'] ?? null,
                ];
            })
            ->values();

        $earnings = $components
            ->where('component_type', PayrollComponent::TYPE_EARNING)
            ->where('affects_gross', true);
        $deductions = $components->where('component_type', PayrollComponent::TYPE_DEDUCTION);
        $benefits = $components
            ->where('component_type', PayrollComponent::TYPE_BENEFIT)
            ->where('affects_gross', true);

        $grossPay = PayrollMoney::add(
            ...$earnings->pluck('calculated_amount')->merge($benefits->pluck('calculated_amount'))->all()
        );
        $componentDeductions = PayrollMoney::add(...$deductions->pluck('calculated_amount')->all());
        $resolvedRunDate = $this->resolveRunDate($runDate, $overrides);
        $taxableAmount = array_key_exists('taxable_income_override', $overrides)
            ? PayrollMoney::round($overrides['taxable_income_override'])
            : PayrollMoney::add(...$components
                ->where('is_taxable', true)
                ->where('affects_gross', true)
                ->pluck('calculated_amount')
                ->all());
        $statutoryBases = [
            'sss' => $grossPay,
            'philhealth' => $rules['monthly_base_salary'] ?? $basicSalary,
            'pagibig' => $grossPay,
        ];
        $statutory = $this->calculateStatutoryDeductions(
            (int) $employee->shop_owner_id,
            $taxableAmount,
            $resolvedRunDate,
            $statutoryBases
        );

        $withholdingTax = PayrollMoney::round($statutory['withholding_tax'] ?? 0);
        $sssContribution = PayrollMoney::round($statutory['sss_contribution'] ?? 0);
        $philhealthContribution = PayrollMoney::round($statutory['philhealth_contribution'] ?? 0);
        $pagibigContribution = PayrollMoney::round($statutory['pagibig_contribution'] ?? 0);
        $totalDeductions = PayrollMoney::add(
            $componentDeductions,
            $withholdingTax,
            $sssContribution,
            $philhealthContribution,
            $pagibigContribution,
        );
        $netPay = PayrollMoney::subtract($grossPay, $totalDeductions);

        return [
            'run_date' => $resolvedRunDate,
            'rules' => $rules,
            'components' => $components,
            'gross_salary' => $grossPay,
            'net_salary' => PayrollMoney::maxZero($netPay),
            'taxable_income' => PayrollMoney::round($taxableAmount),
            'statutory_bases' => array_map(
                static fn (mixed $amount): string => PayrollMoney::round($amount),
                $statutoryBases
            ),
            'component_deductions' => $componentDeductions,
            'total_deductions' => $totalDeductions,
            'statutory' => [
                'withholding_tax' => $withholdingTax,
                'sss_contribution' => $sssContribution,
                'philhealth_contribution' => $philhealthContribution,
                'pagibig_contribution' => $pagibigContribution,
                'employer_contributions' => $statutory['employer_contributions'] ?? [],
            ],
            'breakdown' => [
                'basic_pay' => $this->sumComponentAmounts($components, ['Basic Salary']),
                'overtime_pay' => $this->sumComponentAmounts($components, ['Overtime Pay']),
                'special_holiday_pay' => $this->sumComponentAmounts($components, ['Special Holiday Pay']),
                'regular_holiday_pay' => $this->sumComponentAmounts($components, ['Regular Holiday Pay']),
                'sales_commission' => $this->sumComponentAmounts($components, ['Sales Commission']),
                'performance_bonus' => $this->sumComponentAmounts($components, ['Performance Bonus']),
                'other_allowances' => $this->sumComponentAmounts($components, ['Other Allowances', 'Allowances']),
                'absent_deductions' => $this->sumComponentAmounts($components, ['Absent Day Deduction', 'Absent Deductions']),
                'undertime_deductions' => $this->sumComponentAmounts($components, ['Undertime Deduction', 'Undertime Deductions']),
                'late_deductions' => $this->sumComponentAmounts($components, ['Late Deduction', 'Late Deductions']),
            ],
        ];
    }

    /**
     * Resolve additional earning amounts and map them to custom payroll components.
     *
     * When explicit amounts are not supplied, employee-level defaults are used:
     * - sales_commission = monthly-equivalent salary × sales_commission_rate
     * - performance_bonus = monthly-equivalent salary × performance_bonus_rate
     * - other_allowances = employee.other_allowances
     */
    public function resolveAdditionalEarnings(Employee $employee, ?string $periodLabel = null, array $values = []): array
    {
        $rateBasis = $this->resolveRateBasis($employee, [
            'rate_effective_date' => $values['rate_effective_date'] ?? $this->periodStartDate($periodLabel),
        ]);
        $baseSalary = $this->normalizeNumber($rateBasis['monthly_base_salary'] ?? 0);

        $salesCommission = array_key_exists('sales_commission', $values)
            ? $this->normalizeNumber($values['sales_commission'])
            : (float) PayrollMoney::multiply($baseSalary, $this->normalizeNumber($employee->sales_commission_rate ?? 0));

        $performanceBonus = array_key_exists('performance_bonus', $values)
            ? $this->normalizeNumber($values['performance_bonus'])
            : (float) PayrollMoney::multiply($baseSalary, $this->normalizeNumber($employee->performance_bonus_rate ?? 0));

        $otherAllowances = array_key_exists('other_allowances', $values)
            ? $this->normalizeNumber($values['other_allowances'])
            : $this->normalizeNumber($employee->other_allowances ?? 0);

        $periodSuffix = $periodLabel ? ' – ' . $periodLabel : '';
        $components = [];

        if ($salesCommission > 0) {
            $components[] = [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Sales Commission',
                'base_amount' => $salesCommission,
                'method' => PayrollComponent::METHOD_COMMISSION,
                'taxable' => true,
                'recurring' => false,
                'affects_gross' => true,
                'description' => 'Sales commission' . $periodSuffix,
            ];
        }

        if ($performanceBonus > 0) {
            $components[] = [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Performance Bonus',
                'base_amount' => $performanceBonus,
                'method' => PayrollComponent::METHOD_CUSTOM,
                'taxable' => true,
                'recurring' => false,
                'affects_gross' => true,
                'description' => 'Performance bonus' . $periodSuffix,
            ];
        }

        if ($otherAllowances > 0) {
            $components[] = [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Other Allowances',
                'base_amount' => $otherAllowances,
                'method' => PayrollComponent::METHOD_ALLOWANCE,
                'taxable' => false,
                'recurring' => false,
                'affects_gross' => true,
                'description' => 'Additional allowances' . $periodSuffix,
            ];
        }

        return [
            'sales_commission' => (float) PayrollMoney::round($salesCommission),
            'performance_bonus' => (float) PayrollMoney::round($performanceBonus),
            'other_allowances' => (float) PayrollMoney::round($otherAllowances),
            'components' => $components,
        ];
    }
    
    /**
     * Calculate all payroll components
     */
    protected function calculateComponents(Employee $employee, Payroll $payroll, array $customComponents, array $overrides, ?array $calculation = null)
    {
        $calculation ??= $this->buildPayrollCalculation(
            $employee,
            $customComponents,
            $overrides,
            $payroll->pay_period_end ?? null
        );

        return $this->persistCalculatedComponents($employee, $payroll, $calculation['components'] ?? collect());
    }

    protected function buildComponentDefinitions(Employee $employee, array $customComponents, array $overrides, ?array $rules = null): array
    {
        $rules ??= $this->computeRuleEngineAmounts($employee, $overrides);
        $basicSalary = PayrollMoney::round($rules['monthly_base_salary'] ?? 0);
        $noWorkNoPay = $this->isNoWorkNoPayEnabled();

        $standardEarnings = [
            [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Basic Salary',
                'code' => PayrollComponent::CODE_BASIC_SALARY,
                'base_amount' => $basicSalary,
                'method' => $noWorkNoPay ? PayrollComponent::METHOD_DAYS_WORKED : PayrollComponent::METHOD_FIXED,
                'taxable' => true,
                'recurring' => true,
                'affects_gross' => true,
                'category' => 'Basic Pay',
                'description' => $noWorkNoPay
                    ? 'No-work-no-pay: prorated by paid days (attendance + approved leave)'
                    : 'Daily base rate converted to monthly-equivalent salary'
            ],
            [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => '13th Month Pay (Accrual)',
                'code' => PayrollComponent::CODE_13TH_ACCRUAL,
                'base_amount' => PayrollMoney::divide($basicSalary, 12),
                'method' => PayrollComponent::METHOD_FIXED,
                'taxable' => false, // Tax-exempt up to ₱90,000 per NIRC Sec. 32(B)(7)(e)
                'recurring' => true,
                'affects_gross' => false,
                'category' => 'Accruals',
                'description' => 'Monthly accrual — 1/12 of monthly-equivalent basic salary (PD 851)'
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

        
        // Absent-day deduction: prorate daily rate × absent days
        // absent_days = working days that were neither attended nor on approved leave.
        if (! $noWorkNoPay && ($rules['absent_deduction'] ?? 0) > 0) {
            $standardDeductions[] = [
                'type'        => PayrollComponent::TYPE_DEDUCTION,
                'name'        => 'Absent Day Deduction',
                'base_amount' => $rules['absent_deduction'],
                'method'      => PayrollComponent::METHOD_FIXED,
                'taxable'     => false,
                'recurring'   => false,
                'affects_gross' => false,
                'category'    => 'Attendance Deductions',
                'description' => number_format($rules['absent_days'], 2) . ' absent day(s) × ₱' . number_format($rules['daily_rate'], 2) . '/day',
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

        if (($rules['late_deduction'] ?? 0) > 0) {
            $standardDeductions[] = [
                'type'        => PayrollComponent::TYPE_DEDUCTION,
                'name'        => 'Late Deduction',
                'base_amount' => $rules['late_deduction'],
                'method'      => PayrollComponent::METHOD_FIXED,
                'taxable'     => false,
                'recurring'   => false,
                'affects_gross' => false,
                'category'    => 'Attendance Deductions',
                'description' => number_format($rules['late_hours'], 2) . ' late hour(s) × ₱' . number_format($rules['hourly_rate'], 2) . '/hour',
            ];
        }

        return array_merge($standardEarnings, $standardDeductions, $customComponents);
    }

    protected function persistCalculatedComponents(Employee $employee, Payroll $payroll, $calculatedComponents)
    {
        $components = collect();

        foreach (collect($calculatedComponents) as $componentData) {
            $component = PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'shop_owner_id' => $employee->shop_owner_id,
                'component_type' => $componentData['component_type'] ?? $componentData['type'],
                'component_name' => $componentData['component_name'] ?? $componentData['name'],
                'component_code' => $componentData['component_code'] ?? $componentData['code'] ?? null,
                'amount' => $componentData['amount'] ?? $componentData['base_amount'],
                'base_amount' => $componentData['base_amount'],
                'calculation_method' => $componentData['calculation_method'] ?? $componentData['method'],
                'calculated_amount' => $componentData['calculated_amount'],
                'is_taxable' => $componentData['is_taxable'] ?? $componentData['taxable'],
                'is_recurring' => $componentData['is_recurring'] ?? $componentData['recurring'],
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

    protected function sumComponentAmounts($components, array $names): string
    {
        return PayrollMoney::add(...collect($components)
            ->whereIn('component_name', $names)
            ->pluck('calculated_amount')
            ->all());
    }
    
    /**
     * Calculate component amount based on method
     */
    protected function calculateComponentAmount(string $method, mixed $baseAmount, mixed $basicSalary, array $overrides): string
    {
        $workDays = max(1, $this->normalizeNumber($overrides['standard_work_days_per_month'] ?? 26));
        $workHours = max(1, $this->normalizeNumber($overrides['standard_work_hours_per_day'] ?? 8));
        $monthlyHours = max(1, $workDays * $workHours);

        $attendanceDays = $this->normalizeNumber($overrides['attendance_days'] ?? $workDays);
        $leaveDays = $this->normalizeNumber($overrides['leave_days'] ?? 0);
        $halfDayDays = min($attendanceDays, $this->normalizeNumber($overrides['half_day_days'] ?? 0));
        $paidLeaveAsWorked = $this->doesPaidLeaveCountAsWorked();
        $paidDays = min(
            $workDays,
            max(0, $attendanceDays - ($halfDayDays * 0.5) + ($paidLeaveAsWorked ? $leaveDays : 0))
        );

        return match($method) {
            PayrollComponent::METHOD_FIXED => PayrollMoney::round($baseAmount),
            PayrollComponent::METHOD_PERCENTAGE_OF_BASIC => PayrollMoney::percent($basicSalary, $baseAmount),
            PayrollComponent::METHOD_PERCENTAGE_OF_GROSS => PayrollMoney::percent($overrides['gross_salary'] ?? $basicSalary, $baseAmount),
            PayrollComponent::METHOD_DAYS_WORKED => PayrollMoney::multiply(PayrollMoney::divide($basicSalary, $workDays), $paidDays),
            PayrollComponent::METHOD_HOURS_WORKED => PayrollMoney::multiply(PayrollMoney::divide($basicSalary, $monthlyHours), $overrides['hours_worked'] ?? $monthlyHours),
            PayrollComponent::METHOD_ALLOWANCE => PayrollMoney::round($baseAmount),
            PayrollComponent::METHOD_OVERTIME => PayrollMoney::round($baseAmount),
            PayrollComponent::METHOD_COMMISSION => PayrollMoney::round($baseAmount),
            PayrollComponent::METHOD_CUSTOM => PayrollMoney::round($baseAmount),
            default => PayrollMoney::round($baseAmount)
        };
    }

    protected function isNoWorkNoPayEnabled(): bool
    {
        return (bool) config('payroll_governance.attendance_policy.no_work_no_pay', false);
    }

    protected function doesPaidLeaveCountAsWorked(): bool
    {
        return (bool) config('payroll_governance.attendance_policy.paid_leave_counts_as_worked', true);
    }

    protected function resolveRateBasis(Employee $employee, array $overrides): array
    {
        $dailyBase = PayrollMoney::round($this->resolveDailySalary($employee, $overrides));

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

        $dailyRate = $dailyBase;
        $monthlyBase = PayrollMoney::multiply($dailyRate, $workDays);
        $hourlyRate = $workHours > 0 ? PayrollMoney::divide($dailyRate, $workHours) : '0.00000000';

        return [
            'monthly_base_salary'       => $monthlyBase,
            'work_days_basis'           => $workDays,
            'work_hours_basis'          => $workHours,
            'daily_rate'                => $dailyRate,
            'hourly_rate'               => $hourlyRate,
            'overtime_multiplier'       => $this->normalizeNumber($overrides['overtime_multiplier'] ?? ($setting?->overtime_multiplier ?? 1.25)),
            'rest_day_multiplier'       => $this->normalizeNumber($overrides['rest_day_multiplier'] ?? ($setting?->rest_day_multiplier ?? 1.30)),
            'special_holiday_multiplier' => $this->normalizeNumber($overrides['special_holiday_multiplier'] ?? ($setting?->special_holiday_multiplier ?? 1.30)),
            'regular_holiday_multiplier' => $this->normalizeNumber($overrides['regular_holiday_multiplier'] ?? ($setting?->regular_holiday_multiplier ?? 2.00)),
            'night_differential_rate'   => $this->normalizeNumber($overrides['night_differential_rate'] ?? ($setting?->night_differential_rate ?? 0.10)),
        ];
    }

    protected function resolveDailySalary(Employee $employee, array $overrides): string
    {
        $rateDate = $overrides['rate_effective_date'] ?? $overrides['pay_period_start'] ?? null;
        if (empty($rateDate) || ! Schema::hasTable('salary_changes')) {
            return PayrollMoney::round($employee->salary ?? 0);
        }

        $eligibleStatuses = [SalaryChange::STATUS_APPROVED, SalaryChange::STATUS_APPLIED];
        $effectiveChange = SalaryChange::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', $eligibleStatuses)
            ->whereDate('effective_date', '<=', $rateDate)
            ->latest('effective_date')
            ->latest('id')
            ->first();

        if ($effectiveChange) {
            return PayrollMoney::round($effectiveChange->new_salary);
        }

        $futureChange = SalaryChange::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', $eligibleStatuses)
            ->whereDate('effective_date', '>', $rateDate)
            ->oldest('effective_date')
            ->oldest('id')
            ->first();

        return PayrollMoney::round($futureChange?->previous_salary ?? $employee->salary ?? 0);
    }

    protected function periodStartDate(?string $periodLabel): ?string
    {
        if (! $periodLabel) {
            return null;
        }

        try {
            return $this->parsePayPeriod($periodLabel)['start_date'];
        } catch (\Throwable) {
            return null;
        }
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
    public function calculateTax(int $shopOwnerId, mixed $grossIncome, mixed $runDate = null, array $options = []): string
    {
        $date = $this->resolveRunDate($runDate, []);

        $configuredTax = $this->calculateWithholdingTaxFromConfiguredTrainRate($shopOwnerId, $grossIncome, $date);
        if ($configuredTax !== null) {
            return PayrollMoney::round($configuredTax);
        }

        $result = TaxBracket::calculateTax($shopOwnerId, $grossIncome, array_merge([
            'date' => $date,
            'tax_type' => TaxBracket::TAX_INCOME,
            'filing_status' => TaxBracket::STATUS_SINGLE,
            'tax_year' => (int) $date->format('Y'),
        ], $options));

        if (($result['message'] ?? null) === 'No tax brackets found') {
            return $this->calculateOfficialMonthlyWithholdingTax($grossIncome, $date);
        }

        return PayrollMoney::round($result['total_tax'] ?? 0);
    }

    /**
     * Calculate statutory deductions for a payroll run date.
     */
    public function calculateStatutoryDeductions(
        int $shopOwnerId,
        mixed $taxableIncome,
        mixed $runDate = null,
        array $bases = []
    ): array
    {
        $date = $this->resolveRunDate($runDate, []);
        $sssBase = $bases['sss'] ?? $taxableIncome;
        $philhealthBase = $bases['philhealth'] ?? $taxableIncome;
        $pagibigBase = $bases['pagibig'] ?? $taxableIncome;

        $sss = $this->calculateSssContribution($shopOwnerId, $sssBase, $date);
        $philhealth = $this->calculatePhilHealthContribution($shopOwnerId, $philhealthBase, $date);
        $pagibig = $this->calculatePagIbigContribution($shopOwnerId, $pagibigBase, $date);
        $withholdingTax = $this->calculateTax(
            $shopOwnerId,
            PayrollMoney::maxZero(PayrollMoney::subtract($taxableIncome, PayrollMoney::add($sss, $philhealth, $pagibig))),
            $date
        );

        return [
            'sss_contribution' => PayrollMoney::round($sss),
            'philhealth_contribution' => PayrollMoney::round($philhealth),
            'pagibig_contribution' => PayrollMoney::round($pagibig),
            'withholding_tax' => PayrollMoney::round($withholdingTax),
            'employer_contributions' => [
                'sss_contribution' => $this->calculateSssEmployerContribution($shopOwnerId, $sssBase, $date),
                'philhealth_contribution' => $this->calculatePhilHealthEmployerContribution($shopOwnerId, $philhealthBase, $date),
                'pagibig_contribution' => $this->calculatePagIbigEmployerContribution($shopOwnerId, $pagibigBase, $date),
            ],
        ];
    }

    protected function calculateSssContribution(int $shopOwnerId, mixed $income, Carbon $runDate): string
    {
        $taxRate = $this->resolveEffectiveTaxRate($shopOwnerId, 'PAYROLL_SSS_EE', $runDate);

        if ($taxRate) {
            $meta = is_array($taxRate->meta) ? $taxRate->meta : [];
            $brackets = $meta['brackets'] ?? [];

            if (is_array($brackets) && !empty($brackets)) {
                foreach ($brackets as $bracket) {
                    $min = $bracket['min'] ?? 0;
                    $max = $bracket['max'] ?? null;
                    $employeeShare = $bracket['employee_share'] ?? 0;

                    if (PayrollMoney::compare($income, $min) >= 0
                        && ($max === null || PayrollMoney::compare($income, $max) <= 0)) {
                        return PayrollMoney::round($employeeShare);
                    }
                }
            }

            if ($taxRate->type === 'fixed') {
                return PayrollMoney::round($taxRate->fixed_amount ?? 0);
            }

            $base = ($meta['calculation_base'] ?? null) === 'sss_msc'
                ? $this->resolveSssMsc($income)
                : $income;

            return PayrollMoney::percent($base, $meta['employee_rate'] ?? $taxRate->rate ?? 0);
        }

        return $this->calculateSssFallback($income, $runDate);
    }

    protected function calculatePhilHealthContribution(int $shopOwnerId, mixed $income, Carbon $runDate): string
    {
        $taxRate = $this->resolveEffectiveTaxRate($shopOwnerId, 'PAYROLL_PHILHEALTH_EE', $runDate);

        if ($taxRate) {
            $meta = is_array($taxRate->meta) ? $taxRate->meta : [];

            $floor = $meta['min_salary'] ?? 10000;
            $ceiling = $meta['max_salary'] ?? 100000;
            $base = PayrollMoney::compare($income, $floor) < 0 ? $floor : $income;
            $base = PayrollMoney::compare($base, $ceiling) > 0 ? $ceiling : $base;

            if ($taxRate->type === 'fixed') {
                return PayrollMoney::round($taxRate->fixed_amount ?? 0);
            }

            return PayrollMoney::percent($base, $meta['employee_rate'] ?? $taxRate->rate ?? 0);
        }

        [$floor, $ceiling] = $this->philHealthSalaryBounds($runDate);
        $base = PayrollMoney::compare($income, $floor) < 0 ? $floor : $income;
        $base = PayrollMoney::compare($base, $ceiling) > 0 ? $ceiling : $base;

        return PayrollMoney::percent($base, $this->philHealthEmployeeRate($runDate));
    }

    protected function calculatePagIbigContribution(int $shopOwnerId, mixed $income, Carbon $runDate): string
    {
        $taxRate = $this->resolveEffectiveTaxRate($shopOwnerId, 'PAYROLL_PAGIBIG_EE', $runDate);

        if ($taxRate) {
            $meta = is_array($taxRate->meta) ? $taxRate->meta : [];
            $tiers = $meta['tiers'] ?? [];
            $maxContribution = $meta['max_contribution'] ?? 100;
            $maxSalary = $meta['max_salary'] ?? 5000;
            $income = PayrollMoney::compare($income, $maxSalary) > 0 ? $maxSalary : PayrollMoney::maxZero($income);

            if (is_array($tiers) && !empty($tiers)) {
                foreach ($tiers as $tier) {
                    $max = $tier['max_salary'] ?? null;
                    $rate = $tier['rate'] ?? 0;

                    if ($max === null || PayrollMoney::compare($income, $max) <= 0) {
                        $contribution = PayrollMoney::percent($income, $rate);

                        return PayrollMoney::compare($contribution, $maxContribution) > 0
                            ? PayrollMoney::round($maxContribution)
                            : $contribution;
                    }
                }
            }

            if ($taxRate->type === 'fixed') {
                return PayrollMoney::round($taxRate->fixed_amount ?? 0);
            }

            $contribution = PayrollMoney::percent($income, $taxRate->rate ?? 0);

            return PayrollMoney::compare($contribution, $maxContribution) > 0
                ? PayrollMoney::round($maxContribution)
                : $contribution;
        }

        $base = PayrollMoney::compare($income, 5000) > 0 ? '5000' : PayrollMoney::maxZero($income);
        $contribution = PayrollMoney::percent($base, PayrollMoney::compare($base, 1500) <= 0 ? '1' : '2');

        return PayrollMoney::compare($contribution, 100) > 0
            ? '100.00'
            : $contribution;
    }

    protected function calculateSssEmployerContribution(int $shopOwnerId, mixed $income, Carbon $runDate): string
    {
        $taxRate = $this->resolveEffectiveTaxRate($shopOwnerId, 'PAYROLL_SSS_EE', $runDate);

        if ($taxRate) {
            $meta = is_array($taxRate->meta) ? $taxRate->meta : [];
            foreach ((array) ($meta['brackets'] ?? []) as $bracket) {
                $min = $bracket['min'] ?? 0;
                $max = $bracket['max'] ?? null;
                if (PayrollMoney::compare($income, $min) >= 0
                    && ($max === null || PayrollMoney::compare($income, $max) <= 0)) {
                    if (array_key_exists('employer_share', $bracket)) {
                        return PayrollMoney::round($bracket['employer_share']);
                    }

                    break;
                }
            }
        }

        return $this->calculateSssEmployerFallback($income, $runDate);
    }

    protected function calculatePhilHealthEmployerContribution(int $shopOwnerId, mixed $income, Carbon $runDate): string
    {
        $taxRate = $this->resolveEffectiveTaxRate($shopOwnerId, 'PAYROLL_PHILHEALTH_EE', $runDate);

        if ($taxRate) {
            $meta = is_array($taxRate->meta) ? $taxRate->meta : [];
            [$floor, $ceiling] = [
                $meta['min_salary'] ?? 10000,
                $meta['max_salary'] ?? 100000,
            ];
            $base = PayrollMoney::compare($income, $floor) < 0 ? $floor : $income;
            $base = PayrollMoney::compare($base, $ceiling) > 0 ? $ceiling : $base;

            return PayrollMoney::percent($base, $meta['employer_rate'] ?? $meta['employee_rate'] ?? $taxRate->rate ?? 0);
        }

        return $this->calculatePhilHealthContribution($shopOwnerId, $income, $runDate);
    }

    protected function calculatePagIbigEmployerContribution(int $shopOwnerId, mixed $income, Carbon $runDate): string
    {
        $taxRate = $this->resolveEffectiveTaxRate($shopOwnerId, 'PAYROLL_PAGIBIG_EE', $runDate);
        $meta = $taxRate && is_array($taxRate->meta) ? $taxRate->meta : [];
        $maxSalary = $meta['max_salary'] ?? 5000;
        $base = PayrollMoney::compare($income, $maxSalary) > 0 ? $maxSalary : PayrollMoney::maxZero($income);
        $rate = $meta['employer_rate'] ?? 2;

        return PayrollMoney::percent($base, $rate);
    }

    protected function calculateSssFallback(mixed $income, Carbon $runDate): string
    {
        $rules = $this->resolveSssFallbackRules($runDate);

        return PayrollMoney::percent(
            $this->resolveSssMscForRules($income, $rules),
            $rules['employee_rate']
        );
    }

    protected function resolveSssMsc(mixed $income): string
    {
        return $this->resolveSssMscForRules($income, $this->resolveSssFallbackRules(Carbon::parse('2025-01-01')));
    }

    protected function resolveSssMscForRules(mixed $income, array $rules): string
    {
        $msc = (int) $rules['min_msc'];

        for ($candidate = $msc + 500; $candidate <= (int) $rules['max_msc']; $candidate += 500) {
            if (PayrollMoney::compare($income, PayrollMoney::subtract($candidate, 250)) < 0) {
                break;
            }

            $msc = $candidate;
        }

        return (string) $msc;
    }

    protected function resolveSssFallbackRules(Carbon $runDate): array
    {
        return match (true) {
            $runDate->greaterThanOrEqualTo(Carbon::parse('2025-01-01')) => [
                'min_msc' => 5000,
                'max_msc' => 35000,
                'employee_rate' => '5',
                'employer_rate' => '10',
            ],
            $runDate->greaterThanOrEqualTo(Carbon::parse('2023-01-01')) => [
                'min_msc' => 4000,
                'max_msc' => 30000,
                'employee_rate' => '4.5',
                'employer_rate' => '9.5',
            ],
            $runDate->greaterThanOrEqualTo(Carbon::parse('2021-01-01')) => [
                'min_msc' => 3000,
                'max_msc' => 25000,
                'employee_rate' => '4.5',
                'employer_rate' => '8.5',
            ],
            default => [
                'min_msc' => 2000,
                'max_msc' => 20000,
                'employee_rate' => '4',
                'employer_rate' => '8',
            ],
        };
    }

    protected function calculateSssEmployerFallback(mixed $income, Carbon $runDate): string
    {
        $rules = $this->resolveSssFallbackRules($runDate);
        $msc = $this->resolveSssMscForRules($income, $rules);
        $regular = PayrollMoney::percent(min((int) $msc, 20000), $rules['employer_rate']);
        $mpf = PayrollMoney::percent(PayrollMoney::maxZero(PayrollMoney::subtract($msc, 20000)), $rules['employer_rate']);
        $ecp = PayrollMoney::compare($msc, 15000) < 0 ? '10.00' : '30.00';

        return PayrollMoney::add($regular, $mpf, $ecp);
    }

    protected function philHealthSalaryBounds(Carbon $runDate): array
    {
        return match (true) {
            $runDate->greaterThanOrEqualTo(Carbon::parse('2024-01-01')) => ['10000', '100000'],
            // PhilHealth's planned 2023 increase was suspended; the official
            // 2023 statement retained the 4% rate and ₱80,000 ceiling.
            $runDate->greaterThanOrEqualTo(Carbon::parse('2023-01-01')) => ['10000', '80000'],
            $runDate->greaterThanOrEqualTo(Carbon::parse('2022-01-01')) => ['10000', '80000'],
            $runDate->greaterThanOrEqualTo(Carbon::parse('2021-01-01')) => ['10000', '70000'],
            $runDate->greaterThanOrEqualTo(Carbon::parse('2020-01-01')) => ['10000', '60000'],
            default => ['10000', '50000'],
        };
    }

    protected function philHealthEmployeeRate(Carbon $runDate): string
    {
        return match (true) {
            $runDate->greaterThanOrEqualTo(Carbon::parse('2024-01-01')) => '2.5',
            $runDate->greaterThanOrEqualTo(Carbon::parse('2023-01-01')) => '2',
            $runDate->greaterThanOrEqualTo(Carbon::parse('2022-01-01')) => '2',
            $runDate->greaterThanOrEqualTo(Carbon::parse('2021-01-01')) => '1.75',
            $runDate->greaterThanOrEqualTo(Carbon::parse('2020-01-01')) => '1.5',
            default => '1.375',
        };
    }

    protected function calculateOfficialMonthlyWithholdingTax(mixed $taxableIncome, Carbon $runDate): string
    {
        if ($runDate->lessThan(Carbon::parse('2023-01-01'))) {
            return '0.00';
        }

        if (PayrollMoney::compare($taxableIncome, 20833) <= 0) {
            return '0.00';
        }

        if (PayrollMoney::compare($taxableIncome, 33333) <= 0) {
            return PayrollMoney::percent(PayrollMoney::subtract($taxableIncome, 20833), '15');
        }

        if (PayrollMoney::compare($taxableIncome, 66667) <= 0) {
            return PayrollMoney::add(1875, PayrollMoney::percent(PayrollMoney::subtract($taxableIncome, 33333), '20'));
        }

        if (PayrollMoney::compare($taxableIncome, 166667) <= 0) {
            return PayrollMoney::add(8541.80, PayrollMoney::percent(PayrollMoney::subtract($taxableIncome, 66667), '25'));
        }

        if (PayrollMoney::compare($taxableIncome, 666667) <= 0) {
            return PayrollMoney::add(33541.80, PayrollMoney::percent(PayrollMoney::subtract($taxableIncome, 166667), '30'));
        }

        return PayrollMoney::add(183541.80, PayrollMoney::percent(PayrollMoney::subtract($taxableIncome, 666667), '35'));
    }

    protected function calculateWithholdingTaxFromConfiguredTrainRate(int $shopOwnerId, mixed $taxableIncome, Carbon $runDate): ?string
    {
        $taxRate = $this->resolveEffectiveTaxRate($shopOwnerId, 'PAYROLL_WHT_TRAIN', $runDate);

        if (! $taxRate) {
            return null;
        }

        $meta = is_array($taxRate->meta) ? $taxRate->meta : [];
        $brackets = $meta['monthly_brackets'] ?? [];

        if (! is_array($brackets) || empty($brackets)) {
            if ($taxRate->type === 'fixed') {
                return PayrollMoney::round($taxRate->fixed_amount ?? 0);
            }

            return PayrollMoney::percent($taxableIncome, $taxRate->rate ?? 0);
        }

        foreach ($brackets as $bracket) {
            $min = $bracket['min'] ?? 0;
            $max = $bracket['max'] ?? null;
            $fixed = $bracket['fixed'] ?? 0;
            $rate = $bracket['rate'] ?? 0;

            if (PayrollMoney::compare($taxableIncome, $min) >= 0
                && ($max === null || PayrollMoney::compare($taxableIncome, $max) <= 0)) {
                return PayrollMoney::add(
                    $fixed,
                    PayrollMoney::percent(PayrollMoney::subtract($taxableIncome, $min), $rate)
                );
            }
        }

        return '0.00';
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

        $employeeQuery = Employee::query()->forShopOwner($shopOwnerId);

        if (empty($employeeIds)) {
            $employeeQuery->where('status', 'active');
        } else {
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
        if ($payroll->status !== 'pending' || $payroll->financialMutationLocked()) {
            throw new Exception('Only pending payrolls without an approval lock can be recalculated.');
        }

        $customComponents = $payroll->components()
            ->where('is_recurring', false)
            ->whereNotIn('component_name', ['Income Tax'])
            ->get()
            ->map(static fn (PayrollComponent $component): array => [
                'type' => $component->component_type,
                'name' => $component->component_name,
                'base_amount' => $component->base_amount ?? $component->amount ?? 0,
                'method' => $component->calculation_method ?? PayrollComponent::METHOD_CUSTOM,
                'taxable' => (bool) $component->is_taxable,
                'recurring' => false,
                'affects_gross' => (bool) ($component->affects_gross ?? true),
                'category' => $component->category,
                'description' => $component->description,
            ])
            ->all();

        $employee = $payroll->employee;
        $period = $payroll->pay_period_start . ' to ' . $payroll->pay_period_end;
        $recalculationOverrides = array_merge([
            'attendance_days' => $payroll->attendance_days,
            'half_day_days' => data_get($payroll->calculation_snapshot, 'rules.half_day_days', 0),
            'late_hours' => data_get($payroll->calculation_snapshot, 'rules.late_hours', 0),
            'leave_days' => $payroll->leave_days,
            'overtime_hours' => $payroll->overtime_hours,
            'payment_date' => $payroll->payment_date,
            'payment_method' => $payroll->payment_method,
        ], $overrides);

        return DB::transaction(function () use ($payroll, $employee, $period, $customComponents, $recalculationOverrides): Payroll {
            $payroll->delete();

            return $this->generatePayroll(
                $employee,
                $period,
                $customComponents,
                $recalculationOverrides
            );
        });
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
        if (!Auth::guard('user')->check() && !Auth::guard('shop_owner')->check()) return;
        
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
        if (! $payroll->relationLoaded('components')) {
            $payroll->load('components');
        }

        $issues = $payroll->reconciliationIssues();
        if ($payroll->components->isEmpty()) {
            array_unshift($issues, 'No payroll components found');
        }

        return $issues;
    }
}
