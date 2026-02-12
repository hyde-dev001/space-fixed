<?php

namespace App\Repositories\HR;

use App\Models\Payroll;
use App\Repositories\HR\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PayrollRepository - Handles all payroll-related database queries
 */
class PayrollRepository extends BaseRepository
{
    /**
     * PayrollRepository constructor
     *
     * @param Payroll $model
     */
    public function __construct(Payroll $model)
    {
        $this->model = $model;
    }

    /**
     * Get payroll records for a shop with filters
     *
     * @param int $shopOwnerId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getShopPayrolls(int $shopOwnerId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId);

        // Apply filters
        if (isset($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['month'])) {
            $query->where('pay_period_month', $filters['month']);
        }

        if (isset($filters['year'])) {
            $query->where('pay_period_year', $filters['year']);
        }

        if (isset($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        // Default relationships
        $with = $filters['with'] ?? ['employee'];
        $query->with($with);

        // Default ordering
        $orderBy = $filters['order_by'] ?? 'pay_date';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        return $query->paginate($perPage);
    }

    /**
     * Get payrolls by month and year
     *
     * @param int $shopOwnerId
     * @param string $month
     * @param int $year
     * @return Collection
     */
    public function getPayrollsByMonth(int $shopOwnerId, string $month, int $year): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('pay_period_month', $month)
            ->where('pay_period_year', $year)
            ->with('employee')
            ->get();
    }

    /**
     * Get pending payrolls for approval
     *
     * @param int $shopOwnerId
     * @return Collection
     */
    public function getPendingPayrolls(int $shopOwnerId): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'pending')
            ->with('employee')
            ->orderBy('pay_date', 'asc')
            ->get();
    }

    /**
     * Get processed payrolls
     *
     * @param int $shopOwnerId
     * @param int|null $year
     * @return Collection
     */
    public function getProcessedPayrolls(int $shopOwnerId, ?int $year = null): Collection
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'processed');

        if ($year) {
            $query->where('pay_period_year', $year);
        }

        return $query->with('employee')
            ->orderBy('pay_date', 'desc')
            ->get();
    }

    /**
     * Get employee's payroll history
     *
     * @param int $employeeId
     * @param int $limit
     * @return Collection
     */
    public function getEmployeePayrollHistory(int $employeeId, int $limit = 12): Collection
    {
        return $this->model->where('employee_id', $employeeId)
            ->orderBy('pay_date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get employee's payroll for specific month/year
     *
     * @param int $employeeId
     * @param string $month
     * @param int $year
     * @return Payroll|null
     */
    public function getEmployeePayrollByMonth(int $employeeId, string $month, int $year): ?Payroll
    {
        return $this->model->where('employee_id', $employeeId)
            ->where('pay_period_month', $month)
            ->where('pay_period_year', $year)
            ->first();
    }

    /**
     * Get payroll statistics for shop
     *
     * @param int $shopOwnerId
     * @param int|null $year
     * @return array
     */
    public function getPayrollStatistics(int $shopOwnerId, ?int $year = null): array
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId);

        if ($year) {
            $query->where('pay_period_year', $year);
        }

        $total = $query->count();
        $pending = (clone $query)->where('status', 'pending')->count();
        $processed = (clone $query)->where('status', 'processed')->count();
        
        $totalGrossPay = (clone $query)->sum('gross_salary');
        $totalNetPay = (clone $query)->sum('net_salary');
        $totalDeductions = (clone $query)->sum('total_deductions');
        
        $byMonth = (clone $query)->select(
            'pay_period_month',
            'pay_period_year',
            DB::raw('COUNT(*) as count'),
            DB::raw('SUM(gross_salary) as total_gross'),
            DB::raw('SUM(net_salary) as total_net')
        )
            ->groupBy('pay_period_year', 'pay_period_month')
            ->orderBy('pay_period_year', 'desc')
            ->orderBy('pay_period_month', 'desc')
            ->get();

        return [
            'total' => $total,
            'pending' => $pending,
            'processed' => $processed,
            'total_gross_pay' => round($totalGrossPay, 2),
            'total_net_pay' => round($totalNetPay, 2),
            'total_deductions' => round($totalDeductions, 2),
            'by_month' => $byMonth
        ];
    }

    /**
     * Get payroll summary for a specific month
     *
     * @param int $shopOwnerId
     * @param string $month
     * @param int $year
     * @return array
     */
    public function getMonthlyPayrollSummary(int $shopOwnerId, string $month, int $year): array
    {
        $payrolls = $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('pay_period_month', $month)
            ->where('pay_period_year', $year)
            ->get();

        $totalEmployees = $payrolls->count();
        $totalGrossPay = $payrolls->sum('gross_salary');
        $totalNetPay = $payrolls->sum('net_salary');
        $totalDeductions = $payrolls->sum('total_deductions');
        $totalBonuses = $payrolls->sum('bonus');
        $totalOvertime = $payrolls->sum('overtime_pay');

        $processed = $payrolls->where('status', 'processed')->count();
        $pending = $payrolls->where('status', 'pending')->count();

        return [
            'month' => $month,
            'year' => $year,
            'total_employees' => $totalEmployees,
            'total_gross_pay' => round($totalGrossPay, 2),
            'total_net_pay' => round($totalNetPay, 2),
            'total_deductions' => round($totalDeductions, 2),
            'total_bonuses' => round($totalBonuses, 2),
            'total_overtime' => round($totalOvertime, 2),
            'processed_count' => $processed,
            'pending_count' => $pending
        ];
    }

    /**
     * Calculate total compensation for employee in a year
     *
     * @param int $employeeId
     * @param int $year
     * @return array
     */
    public function getEmployeeAnnualCompensation(int $employeeId, int $year): array
    {
        $payrolls = $this->model->where('employee_id', $employeeId)
            ->where('pay_period_year', $year)
            ->where('status', 'processed')
            ->get();

        return [
            'year' => $year,
            'total_gross_pay' => round($payrolls->sum('gross_salary'), 2),
            'total_net_pay' => round($payrolls->sum('net_salary'), 2),
            'total_deductions' => round($payrolls->sum('total_deductions'), 2),
            'total_bonuses' => round($payrolls->sum('bonus'), 2),
            'total_overtime' => round($payrolls->sum('overtime_pay'), 2),
            'months_paid' => $payrolls->count()
        ];
    }

    /**
     * Get payrolls due for payment
     *
     * @param int $shopOwnerId
     * @param Carbon $dueDate
     * @return Collection
     */
    public function getPayrollsDueForPayment(int $shopOwnerId, Carbon $dueDate): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'pending')
            ->whereDate('pay_date', '<=', $dueDate)
            ->with('employee')
            ->get();
    }

    /**
     * Mark payroll as processed
     *
     * @param int $payrollId
     * @param array $additionalData
     * @return bool
     */
    public function markAsProcessed(int $payrollId, array $additionalData = []): bool
    {
        return $this->model->where('id', $payrollId)->update(array_merge([
            'status' => 'processed',
            'processed_at' => Carbon::now()
        ], $additionalData));
    }

    /**
     * Mark payroll as paid
     *
     * @param int $payrollId
     * @param string $paymentMethod
     * @param string|null $transactionReference
     * @return bool
     */
    public function markAsPaid(int $payrollId, string $paymentMethod, ?string $transactionReference = null): bool
    {
        return $this->model->where('id', $payrollId)->update([
            'status' => 'paid',
            'payment_method' => $paymentMethod,
            'payment_date' => Carbon::now(),
            'transaction_reference' => $transactionReference
        ]);
    }

    /**
     * Bulk create payroll records
     *
     * @param array $payrollData
     * @return bool
     */
    public function bulkCreatePayrolls(array $payrollData): bool
    {
        return $this->model->insert($payrollData);
    }

    /**
     * Bulk update payroll status
     *
     * @param array $payrollIds
     * @param string $status
     * @param array $additionalData
     * @return int
     */
    public function bulkUpdateStatus(array $payrollIds, string $status, array $additionalData = []): int
    {
        return $this->model->whereIn('id', $payrollIds)
            ->update(array_merge(['status' => $status], $additionalData));
    }

    /**
     * Check if payroll exists for employee in month/year
     *
     * @param int $employeeId
     * @param string $month
     * @param int $year
     * @return bool
     */
    public function payrollExists(int $employeeId, string $month, int $year): bool
    {
        return $this->model->where('employee_id', $employeeId)
            ->where('pay_period_month', $month)
            ->where('pay_period_year', $year)
            ->exists();
    }

    /**
     * Get highest paid employees
     *
     * @param int $shopOwnerId
     * @param int $limit
     * @param int|null $year
     * @return Collection
     */
    public function getHighestPaidEmployees(int $shopOwnerId, int $limit = 10, ?int $year = null): Collection
    {
        $query = $this->model->select('employee_id', DB::raw('SUM(net_salary) as total_compensation'))
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'processed');

        if ($year) {
            $query->where('pay_period_year', $year);
        }

        return $query->groupBy('employee_id')
            ->orderBy('total_compensation', 'desc')
            ->limit($limit)
            ->with('employee')
            ->get();
    }

    /**
     * Get payroll cost trends by month
     *
     * @param int $shopOwnerId
     * @param int $year
     * @return Collection
     */
    public function getPayrollCostTrends(int $shopOwnerId, int $year): Collection
    {
        return $this->model->select(
            'pay_period_month',
            DB::raw('COUNT(*) as employee_count'),
            DB::raw('SUM(gross_salary) as total_gross'),
            DB::raw('SUM(net_salary) as total_net'),
            DB::raw('SUM(total_deductions) as total_deductions'),
            DB::raw('AVG(net_salary) as average_salary')
        )
            ->where('shop_owner_id', $shopOwnerId)
            ->where('pay_period_year', $year)
            ->where('status', 'processed')
            ->groupBy('pay_period_month')
            ->orderBy('pay_period_month', 'asc')
            ->get();
    }

    /**
     * Get deductions breakdown
     *
     * @param int $shopOwnerId
     * @param string $month
     * @param int $year
     * @return array
     */
    public function getDeductionsBreakdown(int $shopOwnerId, string $month, int $year): array
    {
        $payrolls = $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('pay_period_month', $month)
            ->where('pay_period_year', $year)
            ->get();

        return [
            'tax_deductions' => round($payrolls->sum('tax_deduction'), 2),
            'insurance_deductions' => round($payrolls->sum('insurance_deduction'), 2),
            'pension_deductions' => round($payrolls->sum('pension_deduction'), 2),
            'other_deductions' => round($payrolls->sum('other_deductions'), 2),
            'total_deductions' => round($payrolls->sum('total_deductions'), 2)
        ];
    }

    /**
     * Recalculate payroll totals
     *
     * @param int $payrollId
     * @return bool
     */
    public function recalculateTotals(int $payrollId): bool
    {
        $payroll = $this->model->find($payrollId);
        
        if (!$payroll) {
            return false;
        }

        // Calculate total deductions
        $totalDeductions = 
            ($payroll->tax_deduction ?? 0) +
            ($payroll->insurance_deduction ?? 0) +
            ($payroll->pension_deduction ?? 0) +
            ($payroll->other_deductions ?? 0);

        // Calculate net salary
        $netSalary = $payroll->gross_salary - $totalDeductions;

        return $payroll->update([
            'total_deductions' => $totalDeductions,
            'net_salary' => $netSalary
        ]);
    }
}
