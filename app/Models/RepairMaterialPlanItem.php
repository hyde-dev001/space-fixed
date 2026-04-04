<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RepairMaterialPlanItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'repair_request_id',
        'inventory_item_id',
        'planned_quantity',
        'actual_quantity',
        'is_critical',
        'tolerance_percent',
        'variance_status',
        'variance_note',
    ];

    protected $casts = [
        'planned_quantity' => 'float',
        'actual_quantity' => 'float',
        'is_critical' => 'boolean',
        'tolerance_percent' => 'float',
    ];

    public function repairRequest()
    {
        return $this->belongsTo(RepairRequest::class, 'repair_request_id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
