<?php

namespace App\Models\HR;

use App\Models\Employee;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Payroll extends Model
{
    use HasFactory;

    protected $table = 'payrolls';

    protected $fillable = [
        'employee_id',
        'shop_owner_id',
        'pay_period_start',
        'pay_period_end',
        'payroll_period',
        'basic_salary',
        'base_salary',
        'gross_salary',
        'allowances',
        'deductions',
        'total_deductions',
        'tax_amount',
        'overtime_pay',
        'bonus',
        'net_salary',
        'status',
        'payment_date',
        'payment_method',
        'tax_deductions',
        'sss_contributions',
        'philhealth',
        'pag_ibig',
        'attendance_days',
        'leave_days',
        'overtime_hours',
        'generated_by',
        'generated_at',
        'approved_by',
        'approved_at',
        'approval_status',
        'approval_notes',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'basic_salary' => 'decimal:2',
        'gross_salary' => 'decimal:2',
        'allowances' => 'decimal:2',
        'deductions' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'overtime_pay' => 'decimal:2',
        'bonus' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'tax_deductions' => 'decimal:2',
        'sss_contributions' => 'decimal:2',
        'philhealth' => 'decimal:2',
        'pag_ibig' => 'decimal:2',
        'payment_date' => 'datetime',
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
        'attendance_days' => 'integer',
        'leave_days' => 'integer',
        'overtime_hours' => 'decimal:2',
    ];

    /**
     * Available statuses
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'processed' => 'Processed',
        'paid' => 'Paid',
    ];

    /**
     * Available payment methods
     */
    public const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'check' => 'Check',
    ];

    /**
     * Get the employee this payroll belongs to
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the shop owner this payroll belongs to
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }
    
    /**
     * Get all payroll components for this payroll
     */
    public function components(): HasMany
    {
        return $this->hasMany(PayrollComponent::class);
    }

    /**
     * Scope to filter by shop owner
     */
    public function scopeForShopOwner(Builder $query, $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Scope to filter by payroll period
     */
    public function scopeForPeriod(Builder $query, $period): Builder
    {
        return $query->where('payroll_period', $period);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus(Builder $query, $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by employee
     */
    public function scopeForEmployee(Builder $query, $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }
    
    /**
     * Scope: pending status
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
    
    /**
     * Scope: processed status
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', 'processed');
    }
    
    /**
     * Scope: with specific status
     */
    public function scopeWithStatus(Builder $query, $status): Builder
    {
        return $query->where('status', $status);
    }
    
    /**
     * Scope: load components grouped by type
     */
    public function scopeWithComponents(Builder $query): Builder
    {
        return $query->with(['components' => function($q) {
            $q->orderBy('component_type')->orderBy('component_name');
        }]);
    }
    
    /**
     * Get total earnings from components
     */
    public function getTotalEarnings(): float
    {
        return $this->components()
            ->where('component_type', PayrollComponent::TYPE_EARNING)
            ->sum('calculated_amount');
    }
    
    /**
     * Get total deductions from components
     */
    public function getTotalDeductions(): float
    {
        return $this->components()
            ->where('component_type', PayrollComponent::TYPE_DEDUCTION)
            ->sum('calculated_amount');
    }
    
    /**
     * Get total benefits from components
     */
    public function getTotalBenefits(): float
    {
        return $this->components()
            ->where('component_type', PayrollComponent::TYPE_BENEFIT)
            ->sum('calculated_amount');
    }
    
    /**
     * Get taxable amount
     */
    public function getTaxableAmount(): float
    {
        return $this->components()
            ->where('is_taxable', true)
            ->sum('calculated_amount');
    }

    /**
     * Calculate net salary automatically
     */
    public function calculateNetSalary(): float
    {
        $gross = $this->base_salary + $this->allowances + $this->overtime_pay + $this->bonus;
        $totalDeductions = $this->deductions + $this->tax_deductions + 
                          $this->sss_contributions + $this->philhealth + $this->pag_ibig;
        
        return round($gross - $totalDeductions, 2);
    }

    /**
     * Auto-calculate net salary before saving
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($payroll) {
            $payroll->net_salary = $payroll->calculateNetSalary();
        });
    }

    /**
     * Mark as processed
     */
    public function markAsProcessed(): bool
    {
        $this->status = 'processed';
        return $this->save();
    }

    /**
     * Mark as paid
     */
    public function markAsPaid(): bool
    {
        $this->status = 'paid';
        $this->payment_date = now();
        return $this->save();
    }

    /**
     * Check if payroll can be processed
     */
    public function canBeProcessed(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if payroll can be marked as paid
     */
    public function canBePaid(): bool
    {
        return $this->status === 'processed';
    }

    /**
     * Get formatted payroll period for display
     */
    public function getFormattedPeriodAttribute(): string
    {
        return \Carbon\Carbon::createFromFormat('Y-m', $this->payroll_period)->format('F Y');
    }
}