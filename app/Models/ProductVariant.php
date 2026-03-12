<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'size',
        'color',
        'image',
        'quantity',
        'sku',
        'is_active',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the product that owns the variant
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the variant identifier string
     */
    public function getIdentifierAttribute(): string
    {
        $parts = [];
        if ($this->size) $parts[] = "Size: {$this->size}";
        if ($this->color) $parts[] = "Color: {$this->color}";
        return implode(', ', $parts);
    }

    /**
     * Check if variant is in stock
     */
    public function isInStock(): bool
    {
        return $this->is_active && $this->quantity > 0;
    }

    /**
     * Decrease quantity by amount
     */
    public function decreaseQuantity(int $amount): bool
    {
        if ($this->quantity >= $amount) {
            $this->quantity -= $amount;
            return $this->save();
        }
        return false;
    }

    /**
     * Increase quantity by amount
     */
    public function increaseQuantity(int $amount): bool
    {
        $this->quantity += $amount;
        return $this->save();
    }
}
