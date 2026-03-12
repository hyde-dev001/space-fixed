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
        'sku_suffix',
    ];

    protected $casts = [
        'quantity' => 'integer',
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
}
