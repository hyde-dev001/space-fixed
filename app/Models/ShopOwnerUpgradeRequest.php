<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class ShopOwnerUpgradeRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'shop_owner_id',
        'current_registration_type',
        'current_business_type',
        'requested_registration_type',
        'requested_business_type',
        'status',
        'required_document_set',
        'decision_reason',
        'reviewed_by_super_admin_id',
        'reviewed_at',
    ];

    protected $casts = [
        'required_document_set' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function reviewedBySuperAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'reviewed_by_super_admin_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ShopOwnerUpgradeRequestDocument::class, 'shop_owner_upgrade_request_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
