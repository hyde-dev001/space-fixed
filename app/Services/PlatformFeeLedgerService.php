<?php

namespace App\Services;

use App\Models\Finance\Invoice;
use App\Models\Order;
use App\Models\PlatformFeeCharge;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PlatformFeeLedgerService
{
    public function __construct(
        private readonly PlatformFeeSettingsResolver $settings,
    ) {}

    public function finalizeOrder(Order $order): ?PlatformFeeCharge
    {
        if (! $this->isEligibleOrder($order)) {
            return null;
        }

        return $this->finalize(
            shopId: (int) $order->shop_owner_id,
            sourceType: 'order',
            sourceId: (int) $order->getKey(),
            sourceOrigin: 'marketplace',
            feeBase: (string) ($order->total_amount ?? '0.00'),
            metadata: [
                'order_number' => $order->order_number,
                'payment_method' => $order->payment_method,
                'shipping_excluded' => true,
            ],
        );
    }

    public function finalizeRepair(RepairRequest $repair): ?PlatformFeeCharge
    {
        if (! $this->isEligibleRepair($repair)) {
            return null;
        }

        return $this->finalize(
            shopId: (int) $repair->shop_owner_id,
            sourceType: 'repair',
            sourceId: (int) $repair->getKey(),
            sourceOrigin: 'marketplace',
            feeBase: (string) ($repair->final_total ?? $repair->total ?? '0.00'),
            metadata: [
                'request_id' => $repair->request_id,
                'payment_method' => $repair->paymongo_payment_id ? 'paymongo' : 'marketplace',
                'delivery_excluded' => true,
            ],
        );
    }

    private function finalize(
        int $shopId,
        string $sourceType,
        int $sourceId,
        string $sourceOrigin,
        string $feeBase,
        array $metadata,
    ): ?PlatformFeeCharge {
        if ($shopId <= 0 || $this->decimal($feeBase, 2)->isLessThanOrEqualTo(0)) {
            return null;
        }

        $charge = DB::transaction(function () use ($shopId, $sourceType, $sourceId, $sourceOrigin, $feeBase, $metadata): ?PlatformFeeCharge {
            $existing = PlatformFeeCharge::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('source_origin', $sourceOrigin)
                ->first();
            if ($existing) {
                return $existing;
            }

            $shop = \App\Models\ShopOwner::query()->lockForUpdate()->findOrFail($shopId);
            $config = $this->settings->forShop($shop);
            $effectiveFrom = $config['effective_from'] instanceof \DateTimeInterface
                ? $config['effective_from']
                : ($config['effective_from'] ? new \DateTimeImmutable((string) $config['effective_from']) : null);
            $sourceDate = $this->sourceDate($sourceType, $sourceId);
            if ($effectiveFrom !== null && $sourceDate !== null && $sourceDate < $effectiveFrom) {
                return null;
            }
            $base = $this->decimal($feeBase, 2);
            $feeRate = $this->decimal((string) $config['platform_fee_rate'], 6);
            $fee = $base
                ->multipliedBy($feeRate)
                ->dividedBy('100', 8, RoundingMode::HALF_UP)
                ->toScale(2, RoundingMode::HALF_UP);
            $vatRate = $this->decimal((string) $config['platform_fee_vat_rate'], 6);
            $vat = $config['platform_fee_vat_enabled']
                ? $fee->multipliedBy($vatRate)->dividedBy('100', 8, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP)
                : BigDecimal::zero()->toScale(2);
            $total = $fee->plus($vat)->toScale(2, RoundingMode::HALF_UP);

            try {
                return PlatformFeeCharge::query()->create([
                    'shop_id' => $shopId,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'source_origin' => $sourceOrigin,
                    'fee_base' => $base->__toString(),
                    'fee_rate' => $feeRate->__toString(),
                    'platform_fee_amount' => $fee->__toString(),
                    'vat_enabled' => (bool) $config['platform_fee_vat_enabled'],
                    'vat_rate' => $vatRate->__toString(),
                    'vat_amount' => $vat->__toString(),
                    'total_charge' => $total->__toString(),
                    'status' => 'outstanding',
                    'finalized_at' => now(),
                    'metadata' => $metadata,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                return PlatformFeeCharge::query()
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->where('source_origin', $sourceOrigin)
                    ->first();
            }
        });

        if ($charge) {
            try {
                activity()
                    ->performedOn($charge)
                    ->withProperties([
                        'shop_id' => $charge->shop_id,
                        'source_type' => $charge->source_type,
                        'source_id' => $charge->source_id,
                        'source_origin' => $charge->source_origin,
                        'total_charge' => $charge->total_charge,
                    ])
                    ->log('platform_fee_created');
            } catch (\Throwable $exception) {
                Log::warning('Platform Fee audit logging failed', ['exception_class' => $exception::class]);
            }
            $payments = app(PlatformFeePaymentService::class);
            $payments->applyAvailableCredits($shopId);
            $payments->invalidateIfBalanceChanged($shopId);
            app(PlatformFeeThresholdService::class)->evaluate($shopId);
        }

        return $charge;
    }

    private function isEligibleOrder(Order $order): bool
    {
        return $this->originForOrder($order) === 'marketplace'
            && in_array($this->value($order->status), ['delivered', 'completed'], true)
            && in_array(strtolower((string) ($order->payment_status ?? '')), ['paid', 'completed'], true)
            && $this->decimal((string) ($order->total_amount ?? '0.00'), 2)->isGreaterThan(0);
    }

    private function isEligibleRepair(RepairRequest $repair): bool
    {
        if ($this->originForRepair($repair) !== 'marketplace'
            || in_array(strtolower((string) ($repair->billing_mode ?? '')), ['warranty_no_charge', 'warranty'], true)
            || ! in_array(strtolower((string) $repair->status), ['completed', 'ready-for-pickup'], true)
        ) {
            return false;
        }

        $total = $this->decimal((string) ($repair->final_total ?? $repair->total ?? '0.00'), 2);
        $paid = $this->decimal((string) ($repair->total_paid_amount ?? '0.00'), 2);

        return $total->isGreaterThan(0)
            && in_array(strtolower((string) ($repair->payment_status ?? '')), ['paid', 'completed'], true)
            && $paid->isGreaterThanOrEqualTo($total);
    }

    private function originForOrder(Order $order): string
    {
        $origin = strtolower(trim((string) ($order->origin_channel ?? '')));
        if ($origin !== '') {
            return $origin;
        }

        if (PosTransaction::query()
            ->where('module_type', 'retail')
            ->where('module_reference_id', $order->getKey())
            ->exists()) {
            return 'pos';
        }

        if ($order->invoice_id) {
            $meta = Invoice::query()->whereKey($order->invoice_id)->value('meta');
            $meta = is_string($meta) ? json_decode($meta, true) : $meta;
            $source = data_get($meta, 'source');
            if ($source === 'retail_pos') {
                return 'pos';
            }
        }

        return 'marketplace';
    }

    private function originForRepair(RepairRequest $repair): string
    {
        $origin = strtolower(trim((string) ($repair->origin_channel ?? '')));
        if ($origin !== '') {
            return $origin;
        }

        $mode = strtolower(trim((string) data_get($repair->pricing_breakdown, 'mode', '')));
        if ($mode === 'manual_pos' || str_starts_with(strtoupper((string) $repair->request_id), 'REP-POS-')) {
            return 'pos';
        }

        return 'marketplace';
    }

    private function value(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : strtolower(trim((string) $value));
    }

    private function decimal(string $value, int $scale): BigDecimal
    {
        return BigDecimal::of(trim($value) === '' ? '0' : trim($value))->toScale($scale, RoundingMode::HALF_UP);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }

    private function sourceDate(string $sourceType, int $sourceId): ?\DateTimeInterface
    {
        $model = match ($sourceType) {
            'order' => Order::query()->find($sourceId),
            'repair' => RepairRequest::query()->find($sourceId),
            default => null,
        };

        return $model?->created_at;
    }
}
