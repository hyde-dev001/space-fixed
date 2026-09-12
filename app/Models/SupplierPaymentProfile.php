<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPaymentProfile extends Model
{
    use HasFactory;

    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'shop_owner_id',
        'supplier_id',
        'destination_type',
        'bank_name',
        'bank_code',
        'account_name',
        'account_number',
        'status',
        'verified_by',
        'verified_at',
    ];

    protected $hidden = [
        'account_number',
    ];

    protected $casts = [
        'account_number' => 'encrypted',
        'verified_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(SupplierPaymentAttempt::class);
    }

    public static function maskAccountNumber(?string $accountNumber): ?string
    {
        if ($accountNumber === null || $accountNumber === '') {
            return null;
        }

        $visibleDigits = substr($accountNumber, -4);

        return str_repeat('*', max(0, strlen($accountNumber) - 4)) . $visibleDigits;
    }

    public function maskedAccountNumber(): ?string
    {
        return self::maskAccountNumber($this->account_number);
    }

    /** @return array<string, mixed> */
    public function toMaskedArray(): array
    {
        return [
            'id' => $this->getKey(),
            'destination_type' => $this->destination_type,
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'account_name' => $this->account_name,
            'masked_account_number' => $this->maskedAccountNumber(),
            'status' => $this->status,
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toISOString(),
        ];
    }
}
