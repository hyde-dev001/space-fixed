<?php

namespace App\Models;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPaymentAttempt extends Model
{
    use HasFactory;

    public const STATUS_INITIATING = 'initiating';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'shop_owner_id',
        'expense_id',
        'supplier_id',
        'supplier_payment_profile_id',
        'amount',
        'currency',
        'provider',
        'internal_reference',
        'provider_reference',
        'idempotency_key',
        'destination_snapshot',
        'status',
        'failure_code',
        'failure_message',
        'initiated_by_user_id',
        'initiated_at',
        'processing_at',
        'succeeded_at',
        'failed_at',
        'settled_at',
        'settlement_id',
    ];

    protected $hidden = [
        'destination_snapshot',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'destination_snapshot' => 'encrypted:array',
        'initiated_at' => 'datetime',
        'processing_at' => 'datetime',
        'succeeded_at' => 'datetime',
        'failed_at' => 'datetime',
        'settled_at' => 'datetime',
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

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ExpenseSettlement::class, 'settlement_id');
    }

    /** @return array<string, mixed> */
    public function maskedDestination(): array
    {
        $destination = (array) $this->destination_snapshot;

        return [
            'destination_type' => $destination['destination_type'] ?? null,
            'bank_name' => $destination['bank_name'] ?? null,
            'bank_code' => $destination['bank_code'] ?? null,
            'account_name' => $destination['account_name'] ?? null,
            'masked_account_number' => SupplierPaymentProfile::maskAccountNumber(
                isset($destination['account_number']) ? (string) $destination['account_number'] : null
            ),
        ];
    }
}
