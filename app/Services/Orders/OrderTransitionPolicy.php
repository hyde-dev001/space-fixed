<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;

final class OrderTransitionPolicy
{
    public function canMarkProcessing(Order $order): bool
    {
        return ! $this->paymentBlocksFulfillment($order)
            && $this->status($order) === OrderStatus::PENDING;
    }

    public function canMarkShipped(Order $order): bool
    {
        return ! $this->paymentBlocksFulfillment($order)
            && $this->status($order) === OrderStatus::PROCESSING;
    }

    public function canConfirmDelivered(Order $order): bool
    {
        return $this->status($order) === OrderStatus::SHIPPED;
    }

    public function canCompleteDirectly(Order $order, bool $hasAuthoritativeDirectFulfillment): bool
    {
        return ! $this->paymentBlocksFulfillment($order)
            && $hasAuthoritativeDirectFulfillment
            && in_array($this->status($order), [OrderStatus::PENDING, OrderStatus::PROCESSING], true);
    }

    private function paymentBlocksFulfillment(Order $order): bool
    {
        $paymentStatus = $order->getAttribute('payment_status');

        if ($paymentStatus instanceof \BackedEnum) {
            $paymentStatus = $paymentStatus->value;
        }

        $paymentStatus = strtolower(trim((string) $paymentStatus));

        if (in_array($paymentStatus, ['failed', 'expired'], true)
            || $order->getAttribute('payment_failed_at') !== null) {
            return true;
        }

        $paymentMethod = strtolower(trim((string) $order->getAttribute('payment_method')));

        return $paymentMethod !== ''
            && ! in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery', 'cash'], true)
            && in_array($paymentStatus, ['pending', 'unpaid'], true)
            && ! filled($order->getAttribute('paymongo_link_id'))
            && ! filled($order->getAttribute('paymongo_payment_id'));
    }

    private function status(Order $order): ?OrderStatus
    {
        $status = $order->getAttribute('status');

        return $status instanceof OrderStatus
            ? $status
            : OrderStatus::tryFrom((string) $status);
    }
}
