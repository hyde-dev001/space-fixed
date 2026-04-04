<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RepairMaterialTemplateItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'inventory_item_id',
        'template_type',
        'template_id',
        'default_quantity',
        'is_critical',
        'tolerance_percent',
        'created_by',
    ];

    protected $casts = [
        'default_quantity' => 'float',
        'is_critical' => 'boolean',
        'tolerance_percent' => 'float',
    ];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
