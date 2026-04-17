<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyAcceptance extends Model
{
    protected $fillable = [
        'shop_owner_id',
        'shop_policy_version_id',
        'actor_guard',
        'actor_user_id',
        'context_type',
        'context_id',
        'accepted_at',
        'accepted_from_ip',
        'accepted_user_agent',
        'accepted_snapshot_hash',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function shopPolicyVersion(): BelongsTo
    {
        return $this->belongsTo(ShopPolicyVersion::class);
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
