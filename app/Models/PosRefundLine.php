<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosRefundLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'pos_refund_id',
        'source_payment_line_id',
        'refunded_amount',
    ];

    protected $casts = [
        'refunded_amount' => 'decimal:2',
    ];

    public function refund()
    {
        return $this->belongsTo(PosRefund::class, 'pos_refund_id');
    }

    public function sourcePaymentLine()
    {
        return $this->belongsTo(PosPaymentLine::class, 'source_payment_line_id');
    }
}
