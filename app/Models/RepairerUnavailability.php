<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairerUnavailability extends Model
{
    use HasFactory;

    protected $table = 'repairer_unavailability';

    protected $fillable = [
        'repairer_id',
        'shop_owner_id',
        'month_key',
        'unavailable_dates',
    ];

    protected $casts = [
        'unavailable_dates' => 'array',
    ];

    public function repairer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repairer_id');
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }
}
