<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;

class StockMovement extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'movement_type',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'reference_type',
        'reference_id',
        'notes',
        'performed_by',
        'performed_at',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'quantity_before' => 'integer',
        'quantity_after' => 'integer',
        'performed_at' => 'datetime',
    ];

    /**
     * Get the inventory item
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * Get the user who performed the movement
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Get the reference model (polymorphic)
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to stock in movements
     */
    public function scopeStockIn(Builder $query): void
    {
        $query->where('movement_type', 'stock_in');
    }

    /**
     * Scope to stock out movements
     */
    public function scopeStockOut(Builder $query): void
    {
        $query->where('movement_type', 'stock_out');
    }

    /**
     * Scope by movement type
     */
    public function scopeByType(Builder $query, string $type): void
    {
        $query->where('movement_type', $type);
    }

    /**
     * Scope by date range
     */
    public function scopeByDateRange(Builder $query, $start, $end): void
    {
        $query->whereBetween('performed_at', [$start, $end]);
    }

    /**
     * Scope by inventory item
     */
    public function scopeByInventoryItem(Builder $query, int $itemId): void
    {
        $query->where('inventory_item_id', $itemId);
    }
}
