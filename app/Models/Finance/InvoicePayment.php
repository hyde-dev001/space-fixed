<?php

namespace App\Models\Finance;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InvoicePayment extends Model
{
    public const ENTRY_PAYMENT = 'payment';
    public const ENTRY_REVERSAL = 'reversal';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_LEGACY_MIGRATION = 'legacy_migration';

    protected $table = 'finance_invoice_payments';

    protected $fillable = [
        'shop_owner_id',
        'invoice_id',
        'entry_type',
        'amount',
        'payment_method',
        'reference',
        'received_at',
        'recorded_by_user_id',
        'idempotency_key',
        'reverses_payment_id',
        'reversal_reason',
        'source',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $payment): void {
            if ($payment->exists) {
                throw new \LogicException('Finance payment history is append-only.');
            }
        });

        static::deleting(function (self $payment): void {
            if ($payment->exists) {
                throw new \LogicException('Finance payment history is append-only.');
            }
        });
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function reversedPayment()
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_payment_id');
    }

    public function scopePayments(Builder $query): Builder
    {
        return $query->where('entry_type', self::ENTRY_PAYMENT);
    }

    public static function validPaidAmountForInvoice(int $invoiceId): string
    {
        $rows = static::query()->where('invoice_id', $invoiceId)
            ->get(['id', 'entry_type', 'amount', 'reverses_payment_id']);
        $totalCents = 0;

        foreach ($rows as $row) {
            $cents = self::toCents($row->amount);
            if ((string) $row->entry_type === self::ENTRY_PAYMENT) {
                $totalCents += $cents;
            } elseif ((string) $row->entry_type === self::ENTRY_REVERSAL && $row->reverses_payment_id) {
                $totalCents -= $cents;
            }
        }

        return self::fromCents(max(0, $totalCents));
    }

    private static function toCents(mixed $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
