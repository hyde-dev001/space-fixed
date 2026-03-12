<?php

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ShopOwner;
use App\Models\Employee;
use Carbon\Carbon;

class LeavePolicy extends Model
{
    protected $table = 'hr_leave_policies';

    protected $fillable = [
        'shop_owner_id',
        'leave_type',
        'display_name',
        'description',
        'accrual_rate',
        'accrual_frequency',
        'max_balance',
        'max_carry_forward',
        'carry_forward_expires',
        'carry_forward_expiry_months',
        'min_service_days',
        'is_paid',
        'requires_approval',
        'min_notice_days',
        'min_days',
        'max_days',
        'allow_half_day',
        'requires_document',
        'document_required_after_days',
        'allow_negative_balance',
        'negative_balance_limit',
        'applicable_gender',
        'applicable_departments',
        'is_encashable',
        'encashable_after_days',
        'encashment_percentage',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'accrual_rate' => 'decimal:2',
        'max_balance' => 'integer',
        'max_carry_forward' => 'integer',
        'carry_forward_expires' => 'boolean',
        'carry_forward_expiry_months' => 'integer',
        'min_service_days' => 'integer',
        'is_paid' => 'boolean',
        'requires_approval' => 'boolean',
        'min_notice_days' => 'integer',
        'min_days' => 'integer',
        'max_days' => 'integer',
        'allow_half_day' => 'boolean',
        'requires_document' => 'boolean',
        'document_required_after_days' => 'integer',
        'allow_negative_balance' => 'boolean',
        'negative_balance_limit' => 'decimal:2',
        'applicable_departments' => 'array',
        'is_encashable' => 'boolean',
        'encashable_after_days' => 'integer',
        'encashment_percentage' => 'decimal:2',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    // ==================== QUERY SCOPES ====================

    public function scopeForShopOwner($query, $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, $leaveType)
    {
        return $query->where('leave_type', $leaveType);
    }

    public function scopeRequiringApproval($query)
    {
        return $query->where('requires_approval', true);
    }

    public function scopeEncashable($query)
    {
        return $query->where('is_encashable', true);
    }

    public function scopeOrderedByPriority($query)
    {
        return $query->orderBy('priority', 'asc')->orderBy('display_name', 'asc');
    }

    // ==================== STATIC HELPER METHODS ====================

    /**
     * Find policy by leave type for a shop owner
     */
    public static function findByType($shopOwnerId, $leaveType): ?self
    {
        return self::forShopOwner($shopOwnerId)
            ->byType($leaveType)
            ->active()
            ->first();
    }

    /**
     * Get all active policies for a shop owner
     */
    public static function getActivePolicies($shopOwnerId)
    {
        return self::forShopOwner($shopOwnerId)
            ->active()
            ->orderedByPriority()
            ->get();
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Check if employee is eligible for this leave type
     */
    public function isEligibleEmployee(Employee $employee): bool
    {
        // Check gender restriction
        if ($this->applicable_gender !== 'all') {
            if ($employee->gender !== $this->applicable_gender) {
                return false;
            }
        }

        // Check department restriction
        if (!empty($this->applicable_departments)) {
            if (!in_array($employee->department_id, $this->applicable_departments)) {
                return false;
            }
        }

        // Check minimum service days
        if ($this->min_service_days > 0) {
            $serviceDays = Carbon::parse($employee->hire_date)->diffInDays(now());
            if ($serviceDays < $this->min_service_days) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if document is required for given duration
     */
    public function requiresDocument($days): bool
    {
        if (!$this->requires_document) {
            return false;
        }

        if ($this->document_required_after_days === null) {
            return true;
        }

        return $days >= $this->document_required_after_days;
    }

    /**
     * Validate leave duration against policy
     */
    public function validateDuration($days, $isHalfDay = false): array
    {
        $errors = [];

        // Check half-day allowance
        if ($isHalfDay && !$this->allow_half_day) {
            $errors[] = "Half-day leave is not allowed for {$this->display_name}";
        }

        // Check minimum days
        if ($days < $this->min_days) {
            $errors[] = "Minimum {$this->min_days} day(s) required for {$this->display_name}";
        }

        // Check maximum days
        if ($days > $this->max_days) {
            $errors[] = "Maximum {$this->max_days} day(s) allowed for {$this->display_name}";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check if sufficient notice was given
     */
    public function validateNotice(Carbon $startDate): array
    {
        if ($this->min_notice_days === 0) {
            return ['valid' => true, 'errors' => []];
        }

        $noticeDays = now()->diffInDays($startDate, false);
        
        if ($noticeDays < $this->min_notice_days) {
            return [
                'valid' => false,
                'errors' => ["At least {$this->min_notice_days} day(s) notice required for {$this->display_name}"],
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * Calculate accrued leave days based on service duration
     */
    public function calculateAccruedDays(Employee $employee, Carbon $asOfDate = null): float
    {
        $asOfDate = $asOfDate ?? now();
        $hireDate = Carbon::parse($employee->hire_date);

        // Check if employee is eligible
        if ($hireDate->greaterThan($asOfDate)) {
            return 0;
        }

        switch ($this->accrual_frequency) {
            case 'on_joining':
                return $this->accrual_rate;

            case 'monthly':
                $months = $hireDate->diffInMonths($asOfDate);
                $accrued = $months * $this->accrual_rate;
                break;

            case 'quarterly':
                $quarters = floor($hireDate->diffInMonths($asOfDate) / 3);
                $accrued = $quarters * $this->accrual_rate;
                break;

            case 'annually':
                $years = $hireDate->diffInYears($asOfDate);
                $accrued = $years * $this->accrual_rate;
                break;

            default:
                $accrued = 0;
        }

        // Apply maximum balance limit
        if ($this->max_balance !== null && $accrued > $this->max_balance) {
            $accrued = $this->max_balance;
        }

        return round($accrued, 2);
    }

    /**
     * Get policy summary for display
     */
    public function getSummary(): array
    {
        return [
            'leave_type' => $this->leave_type,
            'display_name' => $this->display_name,
            'description' => $this->description,
            'is_paid' => $this->is_paid,
            'requires_approval' => $this->requires_approval,
            'accrual' => $this->accrual_rate . ' days per ' . $this->accrual_frequency,
            'max_balance' => $this->max_balance ? $this->max_balance . ' days' : 'Unlimited',
            'carry_forward' => $this->max_carry_forward . ' days',
            'min_notice' => $this->min_notice_days . ' days',
            'duration_range' => $this->min_days . '-' . $this->max_days . ' days',
            'requires_document' => $this->requires_document,
            'is_encashable' => $this->is_encashable,
        ];
    }
}
