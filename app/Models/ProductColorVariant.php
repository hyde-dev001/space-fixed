<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductColorVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'color_name',
        'color_code',
        'sku_prefix',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get the product that owns this color variant.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get all images for this color variant.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductColorVariantImage::class)
                    ->orderBy('sort_order');
    }

    /**
     * Get the thumbnail image for this color variant.
     */
    public function thumbnailImage()
    {
        return $this->hasOne(ProductColorVariantImage::class)
                    ->where('is_thumbnail', true);
    }

    /**
     * Get all size variants for this color.
     * This connects to existing product_variants table.
     */
    public function sizeVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id', 'product_id')
                    ->where('color', $this->color_name);
    }

    /**
     * Scope for active color variants.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get formatted color display name.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->color_name;
    }

    /**
     * Get the full SKU prefix with product info.
     */
    public function getFullSkuPrefixAttribute(): string
    {
        $productSku = $this->product->sku ?? 'PROD';
        return $this->sku_prefix ?? ($productSku . '-' . strtoupper(str_replace(' ', '', $this->color_name)));
    }
}