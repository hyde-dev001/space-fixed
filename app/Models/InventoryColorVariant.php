<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryColorVariant extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'color_name',
        'color_code',
        'quantity',
        'auto_stock_request_enabled',
        'reorder_level',
        'reorder_quantity',
        'sku_suffix',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'auto_stock_request_enabled' => 'boolean',
        'reorder_level' => 'integer',
        'reorder_quantity' => 'integer',
    ];

    /**
     * Get the inventory item that owns this color variant
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * Get all images for this color variant
     */
    public function images(): HasMany
    {
        return $this->hasMany(InventoryImage::class);
    }

    /**
     * Get all sizes for this color variant
     */
    public function sizes(): HasMany
    {
        return $this->hasMany(InventorySize::class, 'inventory_color_variant_id');
    }
}
