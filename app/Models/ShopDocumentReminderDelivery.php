<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShopDocumentReminderDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_document_id',
        'expiration_identity',
        'threshold_days',
        'recipient_type',
        'recipient_id',
        'notification_id',
    ];

    protected $casts = [
        'threshold_days' => 'integer',
        'recipient_id' => 'integer',
        'notification_id' => 'integer',
    ];

    public function shopDocument(): BelongsTo
    {
        return $this->belongsTo(ShopDocument::class);
    }
}
