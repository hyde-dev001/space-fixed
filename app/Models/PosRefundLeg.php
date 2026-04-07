<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosRefundLeg extends Model
{
    use HasFactory;

    protected $fillable = [
        'pos_refund_id',
        'leg_type',
        'requested_amount',
        'approved_amount',
        'status',
        'source_transaction_id',
        'source_receipt_no',
        'meta',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'meta' => 'array',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(PosRefund::class, 'pos_refund_id');
    }
}
