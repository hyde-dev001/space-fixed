<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodRemittanceSupplement extends Model
{
    use HasFactory;

    protected $fillable = [
        'cod_remittance_id',
        'submitted_by_user_id',
        'confirmed_by_user_id',
        'idempotency_key',
        'submitted_amount',
        'received_amount',
        'submitted_at',
        'confirmed_at',
        'dispute_reason',
    ];

    protected $casts = [
        'submitted_amount' => 'decimal:2',
        'received_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(CodRemittance::class, 'cod_remittance_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
