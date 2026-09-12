<?php

namespace App\Models\Finance;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExpenseSettlement extends Model
{
    public const ENTRY_SETTLEMENT = 'settlement';
    public const ENTRY_REVERSAL = 'reversal';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_PROCUREMENT = 'procurement';
    public const SOURCE_PAYROLL = 'payroll';
    public const SOURCE_LEGACY_MIGRATION = 'legacy_migration';

    protected $table = 'finance_expense_settlements';

    protected $fillable = [
        'shop_owner_id',
        'expense_id',
        'entry_type',
        'amount',
        'payment_method',
        'reference',
        'paid_at',
        'recorded_by_user_id',
        'idempotency_key',
        'reverses_settlement_id',
        'reversal_reason',
        'source',
        'source_reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $settlement): void {
            if ($settlement->exists) {
                throw new \LogicException('Finance settlement history is append-only.');
            }
        });

        static::deleting(function (self $settlement): void {
            if ($settlement->exists) {
                throw new \LogicException('Finance settlement history is append-only.');
            }
        });
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }

    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function reversedSettlement()
    {
        return $this->belongsTo(self::class, 'reverses_settlement_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_settlement_id');
    }

    public function scopeSettlements(Builder $query): Builder
    {
        return $query->where('entry_type', self::ENTRY_SETTLEMENT);
    }

    public static function validSettledAmountForExpense(int $expenseId): string
    {
        $rows = static::query()->where('expense_id', $expenseId)
            ->get(['id', 'entry_type', 'amount', 'reverses_settlement_id']);
        $totalCents = 0;

        foreach ($rows as $row) {
            $cents = self::toCents($row->amount);
            if ((string) $row->entry_type === self::ENTRY_SETTLEMENT) {
                $totalCents += $cents;
            } elseif ((string) $row->entry_type === self::ENTRY_REVERSAL && $row->reverses_settlement_id) {
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
