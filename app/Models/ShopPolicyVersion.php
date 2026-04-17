<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPolicyVersion extends Model
{
    protected $fillable = [
        'shop_owner_id',
        'version_number',
        'status',
        'business_type_scope',
        'registration_clause_mode',
        'policy_sections_json',
        'content_hash',
        'published_at',
        'published_by',
    ];

    protected $casts = [
        'policy_sections_json' => 'array',
        'published_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }
}
