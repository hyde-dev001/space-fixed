<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ShopOwnerUpgradeRequestDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_upgrade_request_id',
        'source_shop_document_id',
        'document_type',
        'disk',
        'path',
        'checksum_sha256',
        'mime_type',
        'size',
        'source_status',
    ];

    protected $hidden = [
        'path',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function upgradeRequest(): BelongsTo
    {
        return $this->belongsTo(ShopOwnerUpgradeRequest::class, 'shop_owner_upgrade_request_id');
    }

    public function sourceShopDocument(): BelongsTo
    {
        return $this->belongsTo(ShopDocument::class, 'source_shop_document_id');
    }
}
