<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'pos_transaction_id',
        'receipt_no',
        'official_series',
        'issued_at',
        'print_payload',
        'digital_payload',
        'pdf_path',
        'sent_email_at',
        'sent_sms_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'print_payload' => 'array',
        'digital_payload' => 'array',
        'sent_email_at' => 'datetime',
        'sent_sms_at' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }
}
