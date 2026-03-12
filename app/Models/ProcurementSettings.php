<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'auto_pr_approval_threshold',
        'require_finance_approval',
        'default_payment_terms',
        'auto_generate_po',
        'notification_emails',
        'settings_json',
    ];

    protected $casts = [
        'auto_pr_approval_threshold' => 'decimal:2',
        'require_finance_approval' => 'boolean',
        'auto_generate_po' => 'boolean',
        'notification_emails' => 'array',
        'settings_json' => 'array',
    ];

    // Relationships

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    // Methods

    public static function getForShopOwner(int $shopOwnerId): self
    {
        return self::firstOrCreate(
            ['shop_owner_id' => $shopOwnerId],
            [
                'auto_pr_approval_threshold' => 10000.00,
                'require_finance_approval' => true,
                'default_payment_terms' => 'Net 30',
                'auto_generate_po' => false,
            ]
        );
    }

    public function shouldRequireFinanceApproval(float $amount): bool
    {
        if (!$this->require_finance_approval) {
            return false;
        }

        return $amount >= $this->auto_pr_approval_threshold;
    }

    public function canAutoGeneratePO(): bool
    {
        return $this->auto_generate_po;
    }
}
