<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventorySize extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'inventory_color_variant_id',
        'size',
        'size_system',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    /**
     * Get the inventory item that owns this size
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * Get the color variant that owns this size.
     */
    public function colorVariant(): BelongsTo
    {
        return $this->belongsTo(InventoryColorVariant::class, 'inventory_color_variant_id');
    }
}
