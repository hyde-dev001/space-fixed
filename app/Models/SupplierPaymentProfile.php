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
    public const RECIPIENT_BUSINESS = 'business';
    public const RECIPIENT_INDIVIDUAL = 'individual';
    public const RECIPIENT_TYPES = [
        self::RECIPIENT_BUSINESS,
        self::RECIPIENT_INDIVIDUAL,
    ];
    public const DESTINATION_BANK_ACCOUNT = 'bank_account';
    public const DESTINATION_E_WALLET = 'e_wallet';
    public const DESTINATION_TYPES = [
        self::DESTINATION_BANK_ACCOUNT,
        self::DESTINATION_E_WALLET,
    ];

    protected $fillable = [
        'shop_owner_id',
        'supplier_id',
        'recipient_type',
        'business_name',
        'given_name',
        'surname',
        'recipient_country',
        'recipient_province_state',
        'recipient_city',
        'recipient_street_line_1',
        'recipient_street_line_2',
        'recipient_postal_code',
        'destination_type',
        'wallet_provider',
        'bank_name',
        'bank_code',
        'account_name',
        'account_number',
        'account_identifier',
        'status',
        'verified_by',
        'verified_at',
    ];

    protected $hidden = [
        'account_number',
        'account_identifier',
    ];

    protected $casts = [
        'account_number' => 'encrypted',
        'account_identifier' => 'encrypted',
        'verified_at' => 'datetime',
    ];

    protected $attributes = [
        'recipient_type' => self::RECIPIENT_BUSINESS,
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

    public static function supportedDestinationTypes(): array
    {
        return self::DESTINATION_TYPES;
    }

    public static function maskAccountIdentifier(?string $accountIdentifier): ?string
    {
        return self::maskAccountNumber($accountIdentifier);
    }

    public function maskedAccountNumber(): ?string
    {
        return self::maskAccountNumber($this->account_number);
    }

    public function maskedAccountIdentifier(): ?string
    {
        return self::maskAccountIdentifier($this->account_identifier);
    }

    /** @return array<string, mixed> */
    public function toRevealedArray(): array
    {
        $data = $this->toMaskedArray();

        if ($this->destination_type === self::DESTINATION_E_WALLET) {
            $data['account_identifier'] = $this->account_identifier;
        } else {
            $data['account_number'] = $this->account_number;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function toMaskedArray(): array
    {
        return [
            'id' => $this->getKey(),
            'recipient_type' => $this->recipient_type,
            'business_name' => $this->recipient_type === self::RECIPIENT_BUSINESS ? $this->business_name : null,
            'given_name' => $this->recipient_type === self::RECIPIENT_INDIVIDUAL ? $this->given_name : null,
            'surname' => $this->recipient_type === self::RECIPIENT_INDIVIDUAL ? $this->surname : null,
            'recipient_country' => $this->recipient_country,
            'recipient_province_state' => $this->recipient_province_state,
            'recipient_city' => $this->recipient_city,
            'recipient_street_line_1' => $this->recipient_street_line_1,
            'recipient_street_line_2' => $this->recipient_street_line_2,
            'recipient_postal_code' => $this->recipient_postal_code,
            'destination_type' => $this->destination_type,
            'wallet_provider' => $this->wallet_provider,
            'bank_name' => $this->destination_type === self::DESTINATION_BANK_ACCOUNT ? $this->bank_name : null,
            'bank_code' => $this->destination_type === self::DESTINATION_BANK_ACCOUNT ? $this->bank_code : null,
            'account_name' => $this->account_name,
            'masked_account_number' => $this->destination_type === self::DESTINATION_BANK_ACCOUNT ? $this->maskedAccountNumber() : null,
            'masked_account_identifier' => $this->destination_type === self::DESTINATION_E_WALLET ? $this->maskedAccountIdentifier() : null,
            'status' => $this->status,
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toISOString(),
        ];
    }
}
