<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodRemittanceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cod_remittance_id',
        'cod_collection_id',
        'expected_amount',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
    ];

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(CodRemittance::class, 'cod_remittance_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CodCollection::class, 'cod_collection_id');
    }
}
