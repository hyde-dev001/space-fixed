<?php

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ShopOwner;
use Carbon\Carbon;

class TaxBracket extends Model
{
    protected $table = 'hr_tax_brackets';

    protected $fillable = [
        'shop_owner_id',
        'bracket_name',
        'description',
        'min_amount',
        'max_amount',
        'tax_rate',
        'fixed_amount',
        'calculation_type',
        'standard_deduction',
        'personal_allowance',
        'tax_type',
        'filing_status',
        'tax_year',
        'is_active',
        'priority',
        'effective_from',
        'effective_to',
        'metadata',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'fixed_amount' => 'decimal:2',
        'standard_deduction' => 'decimal:2',
        'personal_allowance' => 'decimal:2',
        'tax_year' => 'integer',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Tax type constants
    public const TAX_INCOME = 'income_tax';
    public const TAX_SOCIAL_SECURITY = 'social_security';
    public const TAX_PENSION = 'pension';
    public const TAX_OTHER = 'other';

    // Calculation type constants
    public const CALC_PROGRESSIVE = 'progressive';
    public const CALC_FLAT = 'flat';

    // Filing status constants
    public const STATUS_SINGLE = 'single';
    public const STATUS_MARRIED = 'married';
    public const STATUS_ALL = 'all';

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

    public function scopeByTaxType($query, $taxType)
    {
        return $query->where('tax_type', $taxType);
    }

    public function scopeByFilingStatus($query, $status)
    {
        return $query->where(function ($q) use ($status) {
            $q->where('filing_status', $status)
              ->orWhere('filing_status', self::STATUS_ALL);
        });
    }

    public function scopeForTaxYear($query, $year)
    {
        return $query->where(function ($q) use ($year) {
            $q->whereNull('tax_year')
              ->orWhere('tax_year', $year);
        });
    }

    public function scopeEffectiveOn($query, $date = null)
    {
        $date = $date ?? now();
        
        return $query->where(function ($q) use ($date) {
            $q->where(function ($q2) use ($date) {
                $q2->whereNull('effective_from')
                   ->orWhere('effective_from', '<=', $date);
            })->where(function ($q2) use ($date) {
                $q2->whereNull('effective_to')
                   ->orWhere('effective_to', '>=', $date);
            });
        });
    }

    public function scopeOrderedByPriority($query)
    {
        return $query->orderBy('priority', 'asc')->orderBy('min_amount', 'asc');
    }

    public function scopeForIncome($query, $income)
    {
        return $query->where('min_amount', '<=', $income)
            ->where(function ($q) use ($income) {
                $q->whereNull('max_amount')
                  ->orWhere('max_amount', '>=', $income);
            });
    }

    // ==================== STATIC HELPER METHODS ====================

    /**
     * Calculate tax for a given income using progressive brackets
     */
    public static function calculateTax($shopOwnerId, $income, $options = []): array
    {
        $taxType = $options['tax_type'] ?? self::TAX_INCOME;
        $filingStatus = $options['filing_status'] ?? self::STATUS_SINGLE;
        $taxYear = $options['tax_year'] ?? date('Y');
        $date = $options['date'] ?? now();

        // Get applicable brackets
        $brackets = self::forShopOwner($shopOwnerId)
            ->active()
            ->byTaxType($taxType)
            ->byFilingStatus($filingStatus)
            ->forTaxYear($taxYear)
            ->effectiveOn($date)
            ->orderedByPriority()
            ->get();

        if ($brackets->isEmpty()) {
            return [
                'total_tax' => 0,
                'effective_rate' => 0,
                'breakdown' => [],
                'message' => 'No tax brackets found',
            ];
        }

        // Apply deductions
        $standardDeduction = $brackets->first()->standard_deduction ?? 0;
        $personalAllowance = $brackets->first()->personal_allowance ?? 0;
        $taxableIncome = max(0, $income - $standardDeduction - $personalAllowance);

        $totalTax = 0;
        $breakdown = [];
        $remainingIncome = $taxableIncome;

        foreach ($brackets as $bracket) {
            if ($remainingIncome <= 0) {
                break;
            }

            // Determine income in this bracket
            $bracketMin = $bracket->min_amount;
            $bracketMax = $bracket->max_amount ?? PHP_FLOAT_MAX;
            
            if ($taxableIncome <= $bracketMin) {
                continue; // Income doesn't reach this bracket
            }

            $incomeInBracket = min($remainingIncome, $bracketMax - $bracketMin);
            
            if ($bracket->calculation_type === self::CALC_PROGRESSIVE) {
                // Progressive: tax only the portion in this bracket
                $bracketTax = ($incomeInBracket * $bracket->tax_rate / 100) + $bracket->fixed_amount;
                $remainingIncome -= $incomeInBracket;
            } else {
                // Flat: apply rate to entire income
                $bracketTax = ($taxableIncome * $bracket->tax_rate / 100) + $bracket->fixed_amount;
                $remainingIncome = 0;
            }

            $totalTax += $bracketTax;

            $breakdown[] = [
                'bracket_name' => $bracket->bracket_name,
                'min_amount' => $bracketMin,
                'max_amount' => $bracket->max_amount,
                'income_in_bracket' => $incomeInBracket,
                'tax_rate' => $bracket->tax_rate,
                'tax_amount' => $bracketTax,
            ];

            if ($bracket->calculation_type === self::CALC_FLAT) {
                break; // Flat rate applies to all, no need to continue
            }
        }

        return [
            'gross_income' => $income,
            'standard_deduction' => $standardDeduction,
            'personal_allowance' => $personalAllowance,
            'taxable_income' => $taxableIncome,
            'total_tax' => round($totalTax, 2),
            'effective_rate' => $income > 0 ? round(($totalTax / $income) * 100, 2) : 0,
            'net_income' => $income - $totalTax,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Get active brackets for display
     */
    public static function getActiveBrackets($shopOwnerId, $taxType = null): array
    {
        $query = self::forShopOwner($shopOwnerId)
            ->active()
            ->effectiveOn()
            ->orderedByPriority();

        if ($taxType) {
            $query->byTaxType($taxType);
        }

        return $query->get()->map(function ($bracket) {
            return [
                'id' => $bracket->id,
                'name' => $bracket->bracket_name,
                'range' => number_format($bracket->min_amount, 2) . ' - ' . 
                          ($bracket->max_amount ? number_format($bracket->max_amount, 2) : 'Unlimited'),
                'rate' => $bracket->tax_rate . '%',
                'type' => $bracket->tax_type,
                'calculation' => $bracket->calculation_type,
            ];
        })->toArray();
    }

    /**
     * Calculate social security contribution
     */
    public static function calculateSocialSecurity($shopOwnerId, $income, $options = []): array
    {
        return self::calculateTax($shopOwnerId, $income, array_merge($options, [
            'tax_type' => self::TAX_SOCIAL_SECURITY,
        ]));
    }

    /**
     * Calculate pension contribution
     */
    public static function calculatePension($shopOwnerId, $income, $options = []): array
    {
        return self::calculateTax($shopOwnerId, $income, array_merge($options, [
            'tax_type' => self::TAX_PENSION,
        ]));
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Check if bracket is currently effective
     */
    public function isEffective($date = null): bool
    {
        $date = $date ?? now();

        if ($this->effective_from && Carbon::parse($this->effective_from)->greaterThan($date)) {
            return false;
        }

        if ($this->effective_to && Carbon::parse($this->effective_to)->lessThan($date)) {
            return false;
        }

        return true;
    }

    /**
     * Check if income falls in this bracket
     */
    public function coversIncome($income): bool
    {
        if ($income < $this->min_amount) {
            return false;
        }

        if ($this->max_amount !== null && $income > $this->max_amount) {
            return false;
        }

        return true;
    }

    /**
     * Get bracket range display
     */
    public function getRangeDisplay(): string
    {
        $min = number_format($this->min_amount, 2);
        $max = $this->max_amount ? number_format($this->max_amount, 2) : 'Unlimited';
        
        return "{$min} - {$max}";
    }
}
