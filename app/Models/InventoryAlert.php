<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class InventoryAlert extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'alert_type',
        'threshold_value',
        'current_value',
        'is_resolved',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'threshold_value' => 'integer',
        'current_value' => 'integer',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    /**
     * Get the inventory item
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * Get the user who resolved the alert
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Scope to unresolved alerts
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->where('is_resolved', false);
    }

    /**
     * Scope by alert type
     */
    public function scopeByType(Builder $query, string $type): void
    {
        $query->where('alert_type', $type);
    }

    /**
     * Resolve the alert
     */
    public function resolve(?int $userId = null): void
    {
        $this->update([
            'is_resolved' => true,
            'resolved_at' => now(),
            'resolved_by' => $userId,
        ]);
    }
}
