<?php

namespace App\Models\HR;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SalaryChange extends Model
{
    use LogsActivity;

    protected $table = 'salary_changes';

    protected $fillable = [
        'employee_id',
        'shop_owner_id',
        'proposed_by',
        'approved_by',
        'rejected_by',
        'previous_salary',
        'new_salary',
        'change_percent',
        'change_type',
        'effective_date',
        'reason',
        'status',
        'requires_owner_approval',
        'notes',
        'approved_at',
        'rejected_at',
        'applied_at',
        'retroactive',
        'retroactive_override_by',
        'retroactive_override_reason',
    ];

    protected $casts = [
        'previous_salary'  => 'decimal:2',
        'new_salary'       => 'decimal:2',
        'change_percent'   => 'decimal:2',
        'effective_date'   => 'date',
        'requires_owner_approval' => 'boolean',
        'approved_at'      => 'datetime',
        'rejected_at'      => 'datetime',
        'applied_at'       => 'datetime',
        'retroactive'      => 'boolean',
    ];

    // ─── Constants ───────────────────────────────────────────

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_APPLIED   = 'applied';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        'pending'   => 'Pending Approval',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
        'applied'   => 'Applied',
        'cancelled' => 'Cancelled',
    ];

    public const TYPE_NEW_HIRE      = 'new_hire_rate_setup';
    public const TYPE_MINOR         = 'minor_adjustment';
    public const TYPE_MAJOR         = 'major_adjustment';
    public const TYPE_CORRECTION    = 'correction';

    public const CHANGE_TYPES = [
        'new_hire_rate_setup' => 'New Hire Rate Setup',
        'minor_adjustment'    => 'Minor Adjustment (≤5%)',
        'major_adjustment'    => 'Major Adjustment (>5%)',
        'correction'          => 'Correction',
    ];

    // ─── Relationships ────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function retroactiveOverrideGrantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retroactive_override_by');
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopeForShopOwner(Builder $query, int $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeReadyToApply(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED)
                     ->whereNull('applied_at')
                     ->whereDate('effective_date', '<=', now());
    }

    // ─── Business Logic Helpers ──────────────────────────────

    /**
     * Classify a change based on the governance matrix.
     */
    public static function classifyChangeType(float $previousSalary, float $newSalary): string
    {
        if ($previousSalary <= 0) {
            return self::TYPE_NEW_HIRE;
        }

        $pct = (float) config('payroll_governance.salary_change.minor_threshold_percent', 5.0);
        $changePct = abs(($newSalary - $previousSalary) / $previousSalary * 100);

        return $changePct <= $pct ? self::TYPE_MINOR : self::TYPE_MAJOR;
    }

    /**
     * Compute the percentage change between two salary values.
     */
    public static function computeChangePercent(float $previousSalary, float $newSalary): float
    {
        if ($previousSalary <= 0) {
            return 0.0;
        }

        return round(abs(($newSalary - $previousSalary) / $previousSalary) * 100, 2);
    }

    /**
     * Determine whether this change falls in a retroactive period
     * (i.e., the effective date falls within an already-processed payroll period).
     */
    public static function isRetroactive(int $employeeId, \Carbon\Carbon $effectiveDate): bool
    {
        return Payroll::where('employee_id', $employeeId)
            ->whereIn('status', ['processed', 'approved', 'paid'])
            ->where(function ($q) use ($effectiveDate) {
                $q->whereDate('pay_period_start', '<=', $effectiveDate)
                  ->whereDate('pay_period_end', '>=', $effectiveDate);
            })
            ->exists();
    }

    /**
     * Apply this salary change to the employee record.
     * Marks this record as 'applied' and updates the employee.salary field.
     *
     * @throws \Exception if status is not 'approved'
     */
    public function applyToEmployee(): bool
    {
        if ($this->status !== self::STATUS_APPROVED) {
            throw new \Exception("Only approved salary changes can be applied. Current status: {$this->status}");
        }

        $employee = $this->employee;
        if (!$employee) {
            throw new \Exception("Employee not found for salary change #{$this->id}");
        }

        $employee->salary = $this->new_salary;
        $employee->save();

        $this->status     = self::STATUS_APPLIED;
        $this->applied_at = now();
        return $this->save();
    }

    // ─── Activity Log ─────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status',
                'approved_by',
                'rejected_by',
                'new_salary',
                'effective_date',
                'applied_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $evt) => "SalaryChange {$evt}");
    }
}
