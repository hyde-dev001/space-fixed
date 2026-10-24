<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairPaymentSession extends Model
{
    protected $fillable = [
        'repair_request_id',
        'provider',
        'provider_link_id',
        'phase',
        'status',
        'snapshot_version',
        'delivery_method',
        'service_amount',
        'delivery_amount',
        'quote',
        'invalidated_at',
        'resolved_at',
    ];

    protected $casts = [
        'service_amount' => 'decimal:2',
        'delivery_amount' => 'decimal:2',
        'quote' => 'array',
        'invalidated_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function repairRequest(): BelongsTo
    {
        return $this->belongsTo(RepairRequest::class);
    }
}
