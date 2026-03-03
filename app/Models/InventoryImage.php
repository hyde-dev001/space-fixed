<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class InventoryImage extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'inventory_color_variant_id',
        'image_path',
        'is_thumbnail',
        'sort_order',
    ];

    protected $casts = [
        'is_thumbnail' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $appends = ['url'];

    /**
     * Get the inventory item that owns this image
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * Get the color variant that owns this image
     */
    public function colorVariant(): BelongsTo
    {
        return $this->belongsTo(InventoryColorVariant::class, 'inventory_color_variant_id');
    }

    /**
     * Get the full URL for the image
     */
    public function getUrlAttribute(): string
    {
        return Storage::url($this->image_path);
    }
}
