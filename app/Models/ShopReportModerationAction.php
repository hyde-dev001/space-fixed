<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShopReportModerationAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'actor_id',
        'requested_action',
        'applied_action',
        'report_ids',
        'decision_key',
        'warning_strike_number',
        'source',
        'legacy_audit_log_id',
        'notes',
    ];

    protected $casts = [
        'shop_owner_id' => 'integer',
        'actor_id' => 'integer',
        'report_ids' => 'array',
        'warning_strike_number' => 'integer',
        'legacy_audit_log_id' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'actor_id');
    }
}
