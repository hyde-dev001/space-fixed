<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\DeliveryDispute;
use App\Models\Logistics\Shipment;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderReceiptService
{
    public function confirm(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($this->hasActiveDispute($lockedOrder)) {
                return $this->invalid($lockedOrder, 'Receipt confirmation is unavailable while this order is under investigation.');
            }

            $receiptStatus = (string) ($lockedOrder->customer_receipt_status ?? 'pending');
            if ($receiptStatus === 'confirmed') {
                return $this->success($lockedOrder, false, 'Receipt was already confirmed.');
            }

            if ($receiptStatus === 'disputed') {
                return $this->invalid($lockedOrder, 'Receipt confirmation is unavailable while this order is disputed.');
            }

            $isShopOwned = $this->isShopOwned($lockedOrder);
            $currentLegStatus = $this->currentLegStatus((int) $lockedOrder->id);
            $isEarlyShopOwnedReceipt = $isShopOwned && $currentLegStatus === 'awaiting_proof_approval';
            $isDeliveredShopOwnedReceipt = $isShopOwned && $this->orderStatus($lockedOrder) === OrderStatus::DELIVERED->value;
            $isLegacyThirdPartyReceipt = ! $isShopOwned
                && $this->orderStatus($lockedOrder) === OrderStatus::SHIPPED->value;

            if (! $isEarlyShopOwnedReceipt && ! $isDeliveredShopOwnedReceipt && ! $isLegacyThirdPartyReceipt) {
                return $this->invalid($lockedOrder, 'This order is not currently eligible for receipt confirmation.');
            }

            $deliveryCompleted = false;
            if ($isLegacyThirdPartyReceipt) {
                $lockedOrder->status = OrderStatus::DELIVERED;
                $this->markCodPaymentPaid($lockedOrder);
                $deliveryCompleted = true;
            }

            $lockedOrder->customer_receipt_status = 'confirmed';
            $lockedOrder->customer_received_at = $lockedOrder->customer_received_at ?? now();
            $lockedOrder->save();

            return $this->success(
                $lockedOrder,
                $deliveryCompleted,
                $deliveryCompleted
                    ? 'Order confirmed as delivered.'
                    : 'Receipt confirmed. Official delivery status remains subject to dispatcher approval.',
            );
        });
    }

    public function canConfirm(Order $order, ?string $currentLegStatus = null): bool
    {
        if ((string) ($order->customer_receipt_status ?? 'pending') !== 'pending') {
            return false;
        }

        if ($this->hasActiveDispute($order)) {
            return false;
        }

        if (! $this->isShopOwned($order)) {
            return $this->orderStatus($order) === OrderStatus::SHIPPED->value
                && (bool) ($order->pickup_enabled ?? false);
        }

        return $this->orderStatus($order) === OrderStatus::DELIVERED->value
            || ($currentLegStatus ?? $this->currentLegStatus((int) $order->id)) === 'awaiting_proof_approval';
    }

    public function isShopOwned(Order $order): bool
    {
        return strtolower(trim((string) ($order->carrier_company ?? ''))) === 'shop-owned logistics';
    }

    private function currentLegStatus(int $orderId): ?string
    {
        $shipment = Shipment::query()
            ->where('source_type', 'order')
            ->where('purpose', 'retail_delivery')
            ->where('source_id', $orderId)
            ->latest('id')
            ->with(['legs' => fn ($query) => $query->orderBy('sequence')->orderBy('id')])
            ->first();

        return $shipment?->legs->last()?->status?->value;
    }

    private function hasActiveDispute(Order $order): bool
    {
        if ($order->relationLoaded('deliveryDisputes')) {
            return $order->deliveryDisputes->contains(fn (DeliveryDispute $dispute): bool => $dispute->isActive());
        }

        return DeliveryDispute::query()
            ->where('order_id', $order->id)
            ->whereIn('status', ['open', 'investigating'])
            ->exists();
    }

    private function markCodPaymentPaid(Order $order): void
    {
        $paymentMethod = strtolower((string) ($order->payment_method ?? ''));
        $isCodOrder = in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery'], true);

        if ($isCodOrder && ! in_array((string) ($order->payment_status ?? 'pending'), ['paid', 'completed'], true)) {
            $order->payment_status = 'paid';
            $order->paid_at = now();
            $order->payment_failed_at = null;
            $order->payment_failure_reason = null;
            $order->payment_expired_at = null;
        }
    }

    private function orderStatus(Order $order): string
    {
        return $order->status instanceof OrderStatus
            ? $order->status->value
            : (string) $order->status;
    }

    private function success(Order $order, bool $deliveryCompleted, string $message): array
    {
        return [
            'result' => 'confirmed',
            'message' => $message,
            'order' => $order,
            'delivery_completed' => $deliveryCompleted,
        ];
    }

    private function invalid(Order $order, string $message): array
    {
        return [
            'result' => 'invalid_state',
            'message' => $message,
            'order' => $order,
            'delivery_completed' => false,
        ];
    }
}
