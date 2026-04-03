<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosPaymentLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'pos_transaction_id',
        'tender_type',
        'provider_reference',
        'amount',
        'status',
        'verification_status',
        'paid_at',
        'verified_at',
        'verified_by',
        'manual_fallback_used',
        'verification_mode',
        'verification_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'verified_at' => 'datetime',
        'manual_fallback_used' => 'boolean',
    ];

    public function transaction()
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }

    public function refundLines()
    {
        return $this->hasMany(PosRefundLine::class, 'source_payment_line_id');
    }
}
