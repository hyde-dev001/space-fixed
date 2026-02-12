<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxRate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'finance_tax_rates';

    protected $fillable = [
        'name',
        'code',
        'rate',
        'type',
        'fixed_amount',
        'description',
        'applies_to',
        'is_default',
        'is_inclusive',
        'is_active',
        'effective_from',
        'effective_to',
        'region',
        'shop_id',
        'meta',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'fixed_amount' => 'decimal:2',
        'is_default' => 'boolean',
        'is_inclusive' => 'boolean',
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'meta' => 'array',
    ];

    /**
     * Calculate tax amount based on a subtotal
     */
    public function calculateTax(float $subtotal): float
    {
        if ($this->type === 'fixed') {
            return (float) $this->fixed_amount;
        }

        // Percentage-based tax
        $taxAmount = ($subtotal * $this->rate) / 100;
        return round($taxAmount, 2);
    }

    /**
     * Calculate subtotal from total when tax is inclusive
     */
    public function calculateSubtotalFromTotal(float $total): float
    {
        if ($this->is_inclusive) {
            if ($this->type === 'fixed') {
                return $total - (float) $this->fixed_amount;
            }
            
            // Reverse percentage calculation
            $subtotal = $total / (1 + ($this->rate / 100));
            return round($subtotal, 2);
        }

        return $total;
    }

    /**
     * Calculate total including tax
     */
    public function calculateTotal(float $subtotal): float
    {
        $taxAmount = $this->calculateTax($subtotal);
        
        if ($this->is_inclusive) {
            return $subtotal; // Tax already included
        }

        return round($subtotal + $taxAmount, 2);
    }

    /**
     * Check if tax rate is currently effective
     */
    public function isEffective(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $today = now()->startOfDay();

        if ($this->effective_from && $today->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to && $today->gt($this->effective_to)) {
            return false;
        }

        return true;
    }

    /**
     * Scope to get active tax rates
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get effective tax rates
     */
    public function scopeEffective($query)
    {
        $today = now()->startOfDay();
        
        return $query->where('is_active', true)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_from')
                  ->orWhere('effective_from', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $today);
            });
    }

    /**
     * Scope to filter by shop
     */
    public function scopeForShop($query, $shopId)
    {
        return $query->where('shop_id', $shopId);
    }

    /**
     * Scope to get default tax rate
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope to filter by applies_to
     */
    public function scopeAppliesTo($query, string $type)
    {
        return $query->where(function ($q) use ($type) {
            $q->where('applies_to', 'all')
              ->orWhere('applies_to', $type);
        });
    }
}
