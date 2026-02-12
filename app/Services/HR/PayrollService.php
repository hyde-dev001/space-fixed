<?php

namespace App\Services\HR;

use App\Models\HR\Employee;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Models\HR\TaxBracket;
use App\Models\HR\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;

class PayrollService
{
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
            // 1. Create base payroll record
            $payroll = $this->createPayrollRecord($employee, $payPeriod, $overrides);
            
            // 2. Calculate and create all components
            $components = $this->calculateComponents($employee, $payroll, $customComponents, $overrides);
            
            // 3. Calculate totals
            $earnings = $components->where('component_type', PayrollComponent::TYPE_EARNING);
            $deductions = $components->where('component_type', PayrollComponent::TYPE_DEDUCTION);
            $benefits = $components->where('component_type', PayrollComponent::TYPE_BENEFIT);
            
            $totalEarnings = $earnings->sum('calculated_amount');
            $totalDeductions = $deductions->sum('calculated_amount');
            $totalBenefits = $benefits->sum('calculated_amount');
            
            // 4. Calculate gross pay (earnings + benefits)
            $grossPay = $totalEarnings + $totalBenefits;
            
            // 5. Calculate tax on taxable components
            $taxableAmount = $components->where('is_taxable', true)->sum('calculated_amount');
            $taxAmount = $this->calculateTax($employee->shop_owner_id, $taxableAmount);
            
            // 6. Calculate net pay
            $netPay = $grossPay - $totalDeductions - $taxAmount;
            
            // 7. Update payroll record with totals
            $payroll->update([
                'gross_salary' => $grossPay,
                'total_deductions' => $totalDeductions,
                'tax_amount' => $taxAmount,
                'net_salary' => $netPay,
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
            
            // 9. Log audit trail
            $this->logPayrollGeneration($payroll, $employee, $components->count());
            
            DB::commit();
            
            return $payroll->fresh(['components', 'employee']);
            
        } catch (Exception $e) {
            DB::rollBack();
            
            // Log error
            if (Auth::check()) {
                AuditLog::createLog(
                    'payroll',
                    null,
                    'generate_failed',
                    null,
                    ['employee_id' => $employee->id, 'error' => $e->getMessage()],
                    $employee->shop_owner_id
                );
            }
            
            throw new Exception("Payroll generation failed: " . $e->getMessage());
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
        
        return Payroll::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $employee->shop_owner_id,
            'pay_period_start' => $startDate,
            'pay_period_end' => $endDate,
            'basic_salary' => $employee->salary ?? 0,
            'attendance_days' => $overrides['attendance_days'] ?? 0,
            'leave_days' => $overrides['leave_days'] ?? 0,
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
        
        // Standard earnings
        $standardEarnings = [
            [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Basic Salary',
                'base_amount' => $basicSalary,
                'method' => PayrollComponent::METHOD_FIXED,
                'taxable' => true,
                'recurring' => true,
                'description' => 'Monthly basic salary'
            ],
            [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'House Rent Allowance',
                'base_amount' => $basicSalary * 0.4, // 40% of basic
                'method' => PayrollComponent::METHOD_PERCENTAGE_OF_BASIC,
                'taxable' => true,
                'recurring' => true,
                'description' => '40% of basic salary'
            ],
            [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Transport Allowance',
                'base_amount' => 2000,
                'method' => PayrollComponent::METHOD_ALLOWANCE,
                'taxable' => false,
                'recurring' => true,
                'description' => 'Fixed monthly transport allowance'
            ]
        ];
        
        // Standard deductions
        $standardDeductions = [
            [
                'type' => PayrollComponent::TYPE_DEDUCTION,
                'name' => 'Provident Fund',
                'base_amount' => $basicSalary * 0.12, // 12% of basic
                'method' => PayrollComponent::METHOD_PERCENTAGE_OF_BASIC,
                'taxable' => false,
                'recurring' => true,
                'description' => '12% of basic salary for retirement savings'
            ],
            [
                'type' => PayrollComponent::TYPE_DEDUCTION,
                'name' => 'Professional Tax',
                'base_amount' => 200,
                'method' => PayrollComponent::METHOD_FIXED,
                'taxable' => false,
                'recurring' => true,
                'description' => 'State professional tax'
            ]
        ];
        
        // Overtime calculation (if applicable)
        if (($overrides['overtime_hours'] ?? 0) > 0) {
            $hourlyRate = $basicSalary / 160; // Assuming 160 working hours per month
            $overtimeRate = $hourlyRate * 1.5; // 1.5x for overtime
            
            $standardEarnings[] = [
                'type' => PayrollComponent::TYPE_EARNING,
                'name' => 'Overtime Pay',
                'base_amount' => $overtimeRate * $overrides['overtime_hours'],
                'method' => PayrollComponent::METHOD_OVERTIME,
                'taxable' => true,
                'recurring' => false,
                'description' => $overrides['overtime_hours'] . ' hours @ 1.5x hourly rate'
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
                'base_amount' => $componentData['base_amount'],
                'calculation_method' => $componentData['method'],
                'calculated_amount' => $this->calculateComponentAmount(
                    $componentData['method'],
                    $componentData['base_amount'],
                    $basicSalary,
                    $overrides
                ),
                'is_taxable' => $componentData['taxable'],
                'is_recurring' => $componentData['recurring'],
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
        return match($method) {
            PayrollComponent::METHOD_FIXED => $baseAmount,
            PayrollComponent::METHOD_PERCENTAGE_OF_BASIC => $basicSalary * ($baseAmount / 100),
            PayrollComponent::METHOD_PERCENTAGE_OF_GROSS => ($overrides['gross_salary'] ?? $basicSalary) * ($baseAmount / 100),
            PayrollComponent::METHOD_DAYS_WORKED => ($basicSalary / 30) * ($overrides['attendance_days'] ?? 30),
            PayrollComponent::METHOD_HOURS_WORKED => ($basicSalary / 160) * ($overrides['hours_worked'] ?? 160),
            PayrollComponent::METHOD_ALLOWANCE => $baseAmount,
            PayrollComponent::METHOD_OVERTIME => $baseAmount,
            PayrollComponent::METHOD_COMMISSION => $baseAmount,
            PayrollComponent::METHOD_CUSTOM => $baseAmount,
            default => $baseAmount
        };
    }
    
    /**
     * Calculate progressive income tax
     */
    public function calculateTax(int $shopOwnerId, float $grossIncome): float
    {
        return TaxBracket::calculateTax($shopOwnerId, $grossIncome);
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
        
        AuditLog::createLog(
            'payroll',
            $payroll->id,
            'generated',
            null,
            [
                'employee_id' => $employee->id,
                'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                'period' => $payroll->pay_period_start . ' to ' . $payroll->pay_period_end,
                'gross_salary' => $payroll->gross_salary,
                'net_salary' => $payroll->net_salary,
                'components_count' => $componentCount,
                'payment_date' => $payroll->payment_date
            ],
            $payroll->shop_owner_id
        );
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
