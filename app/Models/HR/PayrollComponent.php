<?php

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ShopOwner;

class PayrollComponent extends Model
{
    protected $table = 'hr_payroll_components';

    protected $fillable = [
        'payroll_id',
        'shop_owner_id',
        'component_type',
        'component_name',
        'component_code',
        'description',
        'amount',
        'calculation_method',
        'calculation_value',
        'calculation_base',
        'formula',
        'is_taxable',
        'is_statutory',
        'affects_gross',
        'display_order',
        'show_on_payslip',
        'category',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'calculation_value' => 'decimal:2',
        'is_taxable' => 'boolean',
        'is_statutory' => 'boolean',
        'affects_gross' => 'boolean',
        'display_order' => 'integer',
        'show_on_payslip' => 'boolean',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Component type constants
    public const TYPE_EARNING = 'earning';
    public const TYPE_DEDUCTION = 'deduction';
    public const TYPE_BENEFIT = 'benefit';

    // Common component codes
    public const CODE_BASIC_SALARY = 'BASIC';
    public const CODE_HRA = 'HRA';
    public const CODE_TRANSPORT = 'TRANSPORT';
    public const CODE_MEDICAL = 'MEDICAL';
    public const CODE_BONUS = 'BONUS';
    public const CODE_OVERTIME = 'OVERTIME';
    public const CODE_INCOME_TAX = 'TAX';
    public const CODE_SSF = 'SSF';
    public const CODE_PENSION = 'PENSION';
    public const CODE_LOAN = 'LOAN';
    public const CODE_ADVANCE = 'ADVANCE';

    // Calculation method constants
    public const CALC_FIXED = 'fixed';
    public const CALC_PERCENTAGE = 'percentage';
    public const CALC_FORMULA = 'formula';
    public const CALC_ATTENDANCE = 'attendance_based';
    public const CALC_HOURLY = 'hourly';

    // ==================== RELATIONSHIPS ====================

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    // ==================== QUERY SCOPES ====================

    public function scopeForPayroll($query, $payrollId)
    {
        return $query->where('payroll_id', $payrollId);
    }

    public function scopeForShopOwner($query, $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('component_type', $type);
    }

    public function scopeEarnings($query)
    {
        return $query->where('component_type', self::TYPE_EARNING);
    }

    public function scopeDeductions($query)
    {
        return $query->where('component_type', self::TYPE_DEDUCTION);
    }

    public function scopeBenefits($query)
    {
        return $query->where('component_type', self::TYPE_BENEFIT);
    }

    public function scopeTaxable($query)
    {
        return $query->where('is_taxable', true);
    }

    public function scopeStatutory($query)
    {
        return $query->where('is_statutory', true);
    }

    public function scopeAffectsGross($query)
    {
        return $query->where('affects_gross', true);
    }

    public function scopeVisibleOnPayslip($query)
    {
        return $query->where('show_on_payslip', true);
    }

    public function scopeByCode($query, $code)
    {
        return $query->where('component_code', $code);
    }

    public function scopeOrderedByDisplay($query)
    {
        return $query->orderBy('display_order', 'asc')->orderBy('component_name', 'asc');
    }

    // ==================== STATIC HELPER METHODS ====================

    /**
     * Get total earnings for a payroll
     */
    public static function getTotalEarnings($payrollId): float
    {
        return self::forPayroll($payrollId)
            ->earnings()
            ->sum('amount');
    }

    /**
     * Get total deductions for a payroll
     */
    public static function getTotalDeductions($payrollId): float
    {
        return self::forPayroll($payrollId)
            ->deductions()
            ->sum('amount');
    }

    /**
     * Get taxable income for a payroll
     */
    public static function getTaxableIncome($payrollId): float
    {
        return self::forPayroll($payrollId)
            ->earnings()
            ->taxable()
            ->sum('amount');
    }

    /**
     * Get statutory deductions for a payroll
     */
    public static function getStatutoryDeductions($payrollId): float
    {
        return self::forPayroll($payrollId)
            ->deductions()
            ->statutory()
            ->sum('amount');
    }

    /**
     * Get components grouped by category
     */
    public static function getGroupedComponents($payrollId): array
    {
        $components = self::forPayroll($payrollId)
            ->visibleOnPayslip()
            ->orderedByDisplay()
            ->get()
            ->groupBy('category');

        return $components->toArray();
    }

    /**
     * Get component breakdown summary
     */
    public static function getBreakdown($payrollId): array
    {
        $earnings = self::forPayroll($payrollId)->earnings()->get();
        $deductions = self::forPayroll($payrollId)->deductions()->get();
        $benefits = self::forPayroll($payrollId)->benefits()->get();

        return [
            'earnings' => [
                'items' => $earnings,
                'total' => $earnings->sum('amount'),
                'count' => $earnings->count(),
            ],
            'deductions' => [
                'items' => $deductions,
                'total' => $deductions->sum('amount'),
                'count' => $deductions->count(),
                'statutory' => $deductions->where('is_statutory', true)->sum('amount'),
                'non_statutory' => $deductions->where('is_statutory', false)->sum('amount'),
            ],
            'benefits' => [
                'items' => $benefits,
                'total' => $benefits->sum('amount'),
                'count' => $benefits->count(),
            ],
        ];
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Check if this component is an earning
     */
    public function isEarning(): bool
    {
        return $this->component_type === self::TYPE_EARNING;
    }

    /**
     * Check if this component is a deduction
     */
    public function isDeduction(): bool
    {
        return $this->component_type === self::TYPE_DEDUCTION;
    }

    /**
     * Check if this component is a benefit
     */
    public function isBenefit(): bool
    {
        return $this->component_type === self::TYPE_BENEFIT;
    }

    /**
     * Get formatted amount with sign
     */
    public function getFormattedAmount(): string
    {
        $prefix = $this->isDeduction() ? '-' : '+';
        return $prefix . ' ' . number_format($this->amount, 2);
    }

    /**
     * Get component display label
     */
    public function getDisplayLabel(): string
    {
        return $this->component_name . ($this->component_code ? " ({$this->component_code})" : '');
    }
}
