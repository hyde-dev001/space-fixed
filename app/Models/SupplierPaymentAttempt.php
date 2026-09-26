<?php

namespace App\Models;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class SupplierPaymentAttempt extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    public const EMAIL_STATUS_PENDING = 'pending';
    public const EMAIL_STATUS_READY_TO_SEND = 'ready_to_send';
    public const EMAIL_STATUS_QUEUED = 'queued';
    public const EMAIL_STATUS_DISPATCHED = 'dispatched';
    public const EMAIL_STATUS_FAILED = 'failed';

    public const STATUS_INITIATING = 'initiating';
    public const STATUS_AWAITING_VERIFICATION = 'awaiting_verification';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PENDING_COMPLIANCE = 'pending_compliance';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_METHOD_BANK_TRANSFER = 'manual_bank_transfer';
    public const PAYMENT_METHOD_E_WALLET = 'manual_e_wallet';
    public const PAYMENT_METHOD_XENDIT = 'xendit';
    public const MANUAL_PAYMENT_METHODS = [
        self::PAYMENT_METHOD_BANK_TRANSFER,
        self::PAYMENT_METHOD_E_WALLET,
    ];
    public const PAYMENT_METHODS = [
        ...self::MANUAL_PAYMENT_METHODS,
        self::PAYMENT_METHOD_XENDIT,
    ];

    protected $fillable = [
        'shop_owner_id',
        'expense_id',
        'supplier_id',
        'supplier_payment_profile_id',
        'amount',
        'currency',
        'provider',
        'payment_method',
        'internal_reference',
        'provider_reference',
        'idempotency_key',
        'destination_snapshot',
        'status',
        'failure_code',
        'failure_message',
        'initiated_by_user_id',
        'initiated_at',
        'externally_paid_at',
        'submitted_for_verification_at',
        'verified_by_shop_owner_id',
        'verified_at',
        'rejected_by_shop_owner_id',
        'rejected_at',
        'rejection_reason',
        'cancellation_reason',
        'cancelled_by_user_id',
        'cancelled_at',
        'finance_note',
        'processing_at',
        'succeeded_at',
        'failed_at',
        'reversed_at',
        'settled_at',
        'settlement_id',
        'supplier_email_to',
        'supplier_email_status',
        'supplier_email_sent_at',
        'supplier_email_failed_at',
        'supplier_email_failure_message',
    ];

    protected $hidden = [
        'destination_snapshot',
    ];

    protected $attributes = [
        'currency' => 'PHP',
        'provider' => 'paymongo',
        'status' => self::STATUS_INITIATING,
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'destination_snapshot' => 'encrypted:array',
        'externally_paid_at' => 'datetime',
        'submitted_for_verification_at' => 'datetime',
        'verified_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'initiated_at' => 'datetime',
        'processing_at' => 'datetime',
        'succeeded_at' => 'datetime',
        'failed_at' => 'datetime',
        'reversed_at' => 'datetime',
        'settled_at' => 'datetime',
        'supplier_email_sent_at' => 'datetime',
        'supplier_email_failed_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentProfile(): BelongsTo
    {
        return $this->belongsTo(SupplierPaymentProfile::class, 'supplier_payment_profile_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function verifiedByShopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'verified_by_shop_owner_id');
    }

    public function rejectedByShopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'rejected_by_shop_owner_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ExpenseSettlement::class, 'settlement_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('payment_proof')->useDisk('local');
    }

    public function externalTransactionReference(): ?string
    {
        return in_array((string) $this->provider, ['manual', 'xendit'], true)
            ? $this->provider_reference
            : null;
    }

    public function maskedSupplierEmail(): ?string
    {
        $email = trim((string) $this->supplier_email_to);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        [$localPart, $domain] = explode('@', $email, 2);

        return substr($localPart, 0, 1) . str_repeat('*', max(1, strlen($localPart) - 1)) . '@' . $domain;
    }

    /** @return array<string, mixed> */
    public function maskedDestination(): array
    {
        $destination = (array) $this->destination_snapshot;
        $isWallet = ($destination['destination_type'] ?? null) === SupplierPaymentProfile::DESTINATION_E_WALLET;

        return [
            'destination_type' => $destination['destination_type'] ?? null,
            'wallet_provider' => $isWallet ? ($destination['wallet_provider'] ?? null) : null,
            'bank_name' => $isWallet ? null : ($destination['bank_name'] ?? null),
            'bank_code' => $isWallet ? null : ($destination['bank_code'] ?? null),
            'account_name' => $destination['account_name'] ?? null,
            'masked_account_number' => $isWallet ? null : SupplierPaymentProfile::maskAccountNumber(
                isset($destination['account_number']) ? (string) $destination['account_number'] : null,
            ),
            'masked_account_identifier' => $isWallet ? SupplierPaymentProfile::maskAccountIdentifier(
                isset($destination['account_identifier']) ? (string) $destination['account_identifier'] : null,
            ) : null,
        ];
    }
}
