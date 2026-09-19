<?php

namespace App\Models;

use App\Models\Logistics\RiderProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CodRemittance extends Model
{
    use HasFactory;

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_DISPUTED = 'disputed';

    protected $fillable = [
        'shop_owner_id',
        'rider_profile_id',
        'rider_user_id',
        'reference',
        'expected_amount',
        'submitted_amount',
        'received_amount',
        'variance_amount',
        'status',
        'idempotency_key',
        'submitted_at',
        'submitted_by_user_id',
        'confirmed_at',
        'confirmed_by_user_id',
        'dispute_reason',
    ];

    protected $attributes = [
        'status' => self::STATUS_SUBMITTED,
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'submitted_amount' => 'decimal:2',
        'received_amount' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function riderProfile(): BelongsTo
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function riderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CodRemittanceItem::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(
            CodCollection::class,
            'cod_remittance_items',
            'cod_remittance_id',
            'cod_collection_id',
        )->withPivot('expected_amount')->withTimestamps();
    }
}
