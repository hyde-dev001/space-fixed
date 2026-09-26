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

    public static function normalizePath(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        return preg_replace('#^/?(?:storage|public)/#i', '', $path) ?: null;
    }

    public static function existingPublicPath(?string $path): ?string
    {
        $normalizedPath = self::normalizePath($path);

        return $normalizedPath && Storage::disk('public')->exists($normalizedPath)
            ? $normalizedPath
            : null;
    }

    /**
     * Get the full URL for the image
     */
    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url(self::normalizePath($this->image_path) ?? $this->image_path);
    }
}
