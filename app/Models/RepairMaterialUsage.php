<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairMaterialUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'repair_request_id',
        'inventory_item_id',
        'quantity_used',
        'notes',
        'used_by',
        'used_at',
        'stock_movement_id',
    ];

    protected $casts = [
        'quantity_used' => 'integer',
        'used_at' => 'datetime',
    ];

    public function repairRequest(): BelongsTo
    {
        return $this->belongsTo(RepairRequest::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
