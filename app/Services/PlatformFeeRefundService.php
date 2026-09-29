<?php

namespace App\Services;

use App\Models\OrderRefund;
use App\Models\PlatformCreditApplication;
use App\Models\PlatformFeeAdjustment;
use App\Models\PlatformFeeCharge;
use App\Models\PlatformFeePaymentAllocation;
use App\Models\PosRefund;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PlatformFeeRefundService
{
    public function __construct(
        private readonly PlatformFeePaymentService $payments,
        private readonly PlatformFeeThresholdService $thresholds,
    ) {}

    public function reverseOrderRefund(OrderRefund $refund): void
    {
        $order = $refund->order()->with('items')->first();
        if (! $order) {
            return;
        }

        $charge = PlatformFeeCharge::query()
            ->where('shop_id', $refund->shop_owner_id ?: $order->shop_owner_id)
            ->where('source_type', 'order')
            ->where('source_id', $order->id)
            ->where('source_origin', 'marketplace')
            ->first();
        if (! $charge) {
            return;
        }

        $this->applyRefund(
            charge: $charge,
            refundId: (int) $refund->id,
            refundSourceType: 'order_refund',
            refundAmount: $this->eligibleOrderRefundAmount($refund, $order),
        );
    }

    public function reverseRepairRefund(PosRefund $refund): void
    {
        if ((string) $refund->module_type !== 'repair') {
            return;
        }

        $charge = PlatformFeeCharge::query()
            ->where('shop_id', $refund->shop_owner_id)
            ->where('source_type', 'repair')
            ->where('source_id', $refund->module_reference_id)
            ->where('source_origin', 'marketplace')
            ->first();
        if (! $charge) {
            return;
        }

        $this->applyRefund(
            charge: $charge,
            refundId: (int) $refund->id,
            refundSourceType: 'repair_refund',
            refundAmount: (string) ($refund->approved_amount ?? $refund->execution_amount ?? $refund->requested_amount ?? '0'),
        );
    }

    private function applyRefund(
        PlatformFeeCharge $charge,
        int $refundId,
        string $refundSourceType,
        string $refundAmount,
    ): void {
        if ($refundId <= 0) {
            return;
        }

        DB::transaction(function () use ($charge, $refundId, $refundSourceType, $refundAmount): void {
            $lockedCharge = PlatformFeeCharge::query()->lockForUpdate()->findOrFail($charge->id);
            $originalBase = $this->decimal((string) $lockedCharge->fee_base);
            $alreadyReversedBase = $this->sum(
                PlatformFeeAdjustment::query()
                    ->where('platform_fee_charge_id', $lockedCharge->id)
                    ->where('adjustment_type', 'refund_reversal'),
                'fee_base_delta',
            )->abs();
            $creditedAmount = $this->sum(
                PlatformCreditApplication::query()
                    ->where('platform_fee_charge_id', $lockedCharge->id),
                'credit_amount',
            );
            $originalTotal = $this->decimal((string) $lockedCharge->total_charge);
            $creditedBase = $originalTotal->isGreaterThan(0)
                ? $creditedAmount
                    ->multipliedBy($originalBase)
                    ->dividedBy($originalTotal, 12, RoundingMode::HALF_UP)
                : BigDecimal::zero();
            $remainingBase = $this->maxZero($originalBase->minus($alreadyReversedBase)->minus($creditedBase));
            $eligibleRefundBase = $this->min($this->decimal($refundAmount), $remainingBase)->toScale(2, RoundingMode::HALF_UP);
            if ($eligibleRefundBase->isLessThanOrEqualTo(0)) {
                return;
            }

            $ratio = $eligibleRefundBase->dividedBy($originalBase, 12, RoundingMode::HALF_UP);
            $reversalFee = $this->decimal((string) $lockedCharge->platform_fee_amount)
                ->multipliedBy($ratio)
                ->toScale(2, RoundingMode::HALF_UP);
            $reversalVat = $this->decimal((string) $lockedCharge->vat_amount)
                ->multipliedBy($ratio)
                ->toScale(2, RoundingMode::HALF_UP);
            $reversalTotal = $reversalFee->plus($reversalVat)->toScale(2, RoundingMode::HALF_UP);
            $paidAmount = $this->sum(
                PlatformFeePaymentAllocation::query()
                    ->where('platform_fee_charge_id', $lockedCharge->id)
                    ->where('allocation_type', 'payment')
                    ->whereHas('payment', fn ($query) => $query->where('status', 'paid')),
                'amount',
            );
            $creditAmount = $this->min(
                $reversalTotal,
                $this->maxZero($paidAmount->minus($creditedAmount)),
            )->toScale(2, RoundingMode::HALF_UP);
            $unpaidAmount = $reversalTotal->minus($creditAmount)->toScale(2, RoundingMode::HALF_UP);

            if ($unpaidAmount->isGreaterThan(0)) {
                $unpaidRatio = $unpaidAmount->dividedBy($reversalTotal, 12, RoundingMode::HALF_UP);
                PlatformFeeAdjustment::query()->firstOrCreate([
                    'idempotency_key' => "{$refundSourceType}:{$refundId}:reversal",
                ], [
                    'shop_id' => $lockedCharge->shop_id,
                    'platform_fee_charge_id' => $lockedCharge->id,
                    'adjustment_type' => 'refund_reversal',
                    'source_type' => $refundSourceType,
                    'source_id' => $refundId,
                    'fee_base_delta' => $eligibleRefundBase->multipliedBy($unpaidRatio)->toScale(2, RoundingMode::HALF_UP)->negated()->__toString(),
                    'platform_fee_delta' => $reversalFee->multipliedBy($unpaidRatio)->toScale(2, RoundingMode::HALF_UP)->negated()->__toString(),
                    'vat_delta' => $reversalVat->multipliedBy($unpaidRatio)->toScale(2, RoundingMode::HALF_UP)->negated()->__toString(),
                    'total_delta' => $unpaidAmount->negated()->__toString(),
                    'reason' => 'Marketplace refund reversal.',
                ]);
            }

            if ($creditAmount->isGreaterThan(0)) {
                PlatformCreditApplication::query()->firstOrCreate([
                    'idempotency_key' => "{$refundSourceType}:{$refundId}:credit",
                ], [
                    'shop_id' => $lockedCharge->shop_id,
                    'platform_fee_charge_id' => $lockedCharge->id,
                    'source_type' => $refundSourceType,
                    'source_id' => $refundId,
                    'source_origin' => 'marketplace',
                    'credit_amount' => $creditAmount->__toString(),
                    'status' => 'available',
                    'reason' => 'Platform Fee paid before a marketplace refund.',
                ]);
            }
        });

        try {
            activity()
                ->performedOn($charge)
                ->withProperties([
                    'shop_id' => $charge->shop_id,
                    'refund_id' => $refundId,
                    'refund_source_type' => $refundSourceType,
                    'refund_amount' => $refundAmount,
                ])
                ->log('platform_fee_refund_processed');
        } catch (\Throwable $exception) {
            Log::warning('Platform Fee refund audit logging failed', ['exception_class' => $exception::class]);
        }

        $this->payments->applyAvailableCredits((int) $charge->shop_id);
        $this->payments->invalidateIfBalanceChanged((int) $charge->shop_id);
        $this->thresholds->evaluate((int) $charge->shop_id);
    }

    private function eligibleOrderRefundAmount(OrderRefund $refund, object $order): string
    {
        $lineAmount = BigDecimal::zero();
        if ($refund->relationLoaded('items')) {
            foreach ($refund->items as $item) {
                $lineAmount = $lineAmount->plus((string) ($item->line_amount ?? '0'));
            }
        }
        if ($lineAmount->isGreaterThan(0)) {
            return $lineAmount->toScale(2, RoundingMode::HALF_UP)->__toString();
        }

        $amount = $this->maxZero($this->decimal((string) ($refund->amount ?? '0')));
        $shipping = $this->maxZero($this->decimal((string) ($order->shipping_fee ?? '0')));

        return $amount
            ->minus($this->min($shipping, $amount))
            ->toScale(2, RoundingMode::HALF_UP)
            ->__toString();
    }

    private function sum($query, string $column): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($query->pluck($column) as $value) {
            $total = $total->plus((string) ($value ?? '0'));
        }

        return $total;
    }

    private function decimal(string $value): BigDecimal
    {
        return BigDecimal::of(trim($value) === '' ? '0' : trim($value));
    }

    private function min(BigDecimal $left, BigDecimal $right): BigDecimal
    {
        return $left->isLessThanOrEqualTo($right) ? $left : $right;
    }

    private function maxZero(BigDecimal $value): BigDecimal
    {
        return $value->isLessThan(0) ? BigDecimal::zero() : $value;
    }
}
