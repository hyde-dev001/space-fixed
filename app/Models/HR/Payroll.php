<?php

namespace App\Models\HR;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Payroll extends Model
{
    use HasFactory, LogsActivity;

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
        'absent_days',
        'overtime_hours',
        'generated_by',
        'generated_at',
        'approved_by',
        'approved_at',
        'final_approved_by',
        'final_approved_at',
        'approval_status',
        'approval_notes',
        'final_approval_notes',
        'payout_reference',
        'payout_proof_type',
        'payout_proof_reference',
        'payout_proof_notes',
        'disbursed_by',
        'disbursed_at',
        'approval_id',
        'current_approval_level',
        'approval_workflow_version',
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
        'final_approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'attendance_days' => 'integer',
        'leave_days' => 'integer',
        'absent_days' => 'integer',
        'overtime_hours' => 'decimal:2',
    ];

    /**
     * Available statuses
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'processed' => 'Processed',
        'approved' => 'Approved',
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

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function finalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_approved_by');
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    /**
     * Get the centralized approval workflow for this payslip (polymorphic)
     */
    public function approval()
    {
        return $this->morphOne(\App\Models\Approval::class, 'approvable');
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
     * Calculate net salary automatically.
     * Prefers the new canonical columns (basic_salary, total_deductions, tax_amount)
     * and falls back to the original columns for rows created before the migration.
     */
    public function calculateNetSalary(): float
    {
        $base  = $this->basic_salary ?? $this->base_salary ?? 0;
        $gross = $base + ($this->allowances ?? 0) + ($this->overtime_pay ?? 0) + ($this->bonus ?? 0);

        $deductions = ($this->total_deductions ?? $this->deductions ?? 0)
                    + ($this->tax_amount ?? $this->tax_deductions ?? 0)
                    + ($this->sss_contributions ?? 0)
                    + ($this->philhealth ?? 0)
                    + ($this->pag_ibig ?? 0);

        return round($gross - $deductions, 2);
    }

    /**
     * Auto-calculate net salary before saving.
     *
     * Rules:
     * - If the caller explicitly sets net_salary (e.g. PayrollService) → trust it.
     * - If an existing record's gross_salary is already > 0 and neither gross_salary
     *   nor net_salary is being changed this save (e.g. a status-only update) → leave
     *   the service-computed value untouched.
     * - Otherwise (new record with placeholders, or manual column edits) → derive
     *   net_salary from the simple column formula.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($payroll) {
            // The service always sets net_salary explicitly → honour it.
            if ($payroll->isDirty('net_salary')) {
                return;
            }

            // Partial update on an existing record (e.g. status change) where the
            // service has already written a correct gross/net — do not recalculate.
            if ($payroll->exists
                && ($payroll->gross_salary ?? 0) > 0
                && ! $payroll->isDirty('gross_salary')
            ) {
                return;
            }

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

    public function markAsFinalApproved(int $userId, ?string $notes = null): bool
    {
        $this->status = 'approved';
        $this->final_approved_by = $userId;
        $this->final_approved_at = now();
        $this->final_approval_notes = $notes;

        return $this->save();
    }

    /**
     * Mark as paid
     */
    public function markAsPaid(?string $paymentDate = null, array $details = []): bool
    {
        $this->status = 'paid';
        $this->payment_date = $paymentDate
            ? \Carbon\Carbon::parse($paymentDate)
            : now();

        if (array_key_exists('payment_method', $details)) {
            $this->payment_method = $details['payment_method'];
        }

        if (array_key_exists('payout_reference', $details)) {
            $this->payout_reference = $details['payout_reference'];
        }

        if (array_key_exists('payout_proof_type', $details)) {
            $this->payout_proof_type = $details['payout_proof_type'];
        }

        if (array_key_exists('payout_proof_reference', $details)) {
            $this->payout_proof_reference = $details['payout_proof_reference'];
        }

        if (array_key_exists('payout_proof_notes', $details)) {
            $this->payout_proof_notes = $details['payout_proof_notes'];
        }

        if (array_key_exists('disbursed_by', $details)) {
            $this->disbursed_by = $details['disbursed_by'];
        }

        $this->disbursed_at = now();

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
        return $this->status === 'approved' && ! empty($this->final_approved_by);
    }

    public function getWorkflowStatusAttribute(): string
    {
        if ($this->status === 'paid') {
            return 'paid';
        }

        if ($this->approval_status === 'rejected') {
            return 'rejected';
        }

        if ($this->status === 'approved' && ! empty($this->final_approved_by)) {
            return 'ready_for_disbursement';
        }

        if ($this->approval_status === 'approved') {
            return 'awaiting_final_approval';
        }

        return 'awaiting_checker';
    }

    public function getDisbursementStatusAttribute(): string
    {
        if ($this->status === 'paid') {
            return 'paid';
        }

        if ($this->status === 'approved' && ! empty($this->final_approved_by)) {
            return 'ready';
        }

        return 'pending';
    }

    /**
     * Get formatted payroll period for display
     */
    public function getFormattedPeriodAttribute(): string
    {
        $period = trim((string) $this->payroll_period);

        if ($period === '') {
            return '';
        }

        if (str_contains($period, ' to ')) {
            [$startDate, $endDate] = array_map('trim', explode(' to ', $period, 2));

            try {
                $start = \Carbon\Carbon::parse($startDate);
                $end = \Carbon\Carbon::parse($endDate);

                return $start->format('M d') . ' - ' . $end->format('M d, Y');
            } catch (\Throwable $e) {
                return $period;
            }
        }

        try {
            return \Carbon\Carbon::createFromFormat('Y-m', $period)->format('F Y');
        } catch (\Throwable $e) {
            return $period;
        }
    }

    /**
     * Activity Log Configuration
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'payroll_period',
                'net_salary',
                'gross_salary',
                'status',
                'payment_date',
                'approval_status',
                'approved_by',
                'final_approved_by',
                'disbursed_by',
                'payout_reference',
                'payout_proof_reference',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Payroll {$eventName}");
    }
}