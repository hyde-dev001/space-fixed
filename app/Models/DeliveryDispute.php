<?php

namespace App\Models;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryDispute extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'order_id',
        'order_refund_id',
        'shipment_id',
        'shipment_leg_id',
        'customer_id',
        'status',
        'reason',
        'notes',
        'evidence_media',
        'reported_at',
        'investigated_at',
        'investigated_by_type',
        'investigated_by_id',
        'resolution',
        'resolution_note',
        'resolved_by_type',
        'resolved_by_id',
        'resolved_at',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'evidence_media' => 'array',
        'investigated_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function orderRefund(): BelongsTo
    {
        return $this->belongsTo(OrderRefund::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function shipmentLeg(): BelongsTo
    {
        return $this->belongsTo(ShipmentLeg::class);
    }

    public function isActive(): bool
    {
        return in_array((string) $this->status, ['open', 'investigating'], true);
    }
}
