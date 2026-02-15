<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RepairRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'customer_name',
        'email',
        'phone',
        'shoe_type',
        'brand',
        'description',
        'shop_owner_id',
        'images',
        'total',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total' => 'decimal:2',
    ];

    public function services()
    {
        return $this->belongsToMany(RepairService::class, 'repair_request_service');
    }

    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }
}
