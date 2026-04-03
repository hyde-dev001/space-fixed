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
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
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
