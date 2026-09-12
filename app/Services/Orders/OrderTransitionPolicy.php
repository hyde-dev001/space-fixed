<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;

final class OrderTransitionPolicy
{
    public function canMarkProcessing(Order $order): bool
    {
        return $this->status($order) === OrderStatus::PENDING;
    }

    public function canMarkShipped(Order $order): bool
    {
        return $this->status($order) === OrderStatus::PROCESSING;
    }

    public function canConfirmDelivered(Order $order): bool
    {
        return $this->status($order) === OrderStatus::SHIPPED;
    }

    public function canCompleteDirectly(Order $order, bool $hasAuthoritativeDirectFulfillment): bool
    {
        return $hasAuthoritativeDirectFulfillment
            && in_array($this->status($order), [OrderStatus::PENDING, OrderStatus::PROCESSING], true);
    }

    private function status(Order $order): ?OrderStatus
    {
        $status = $order->getAttribute('status');

        return $status instanceof OrderStatus
            ? $status
            : OrderStatus::tryFrom((string) $status);
    }
}
