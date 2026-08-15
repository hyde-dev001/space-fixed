<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderRefund;

final class OrderOwnerProjection
{
    private const TERMINAL_FULFILLMENT_STATUSES = ['delivered', 'completed'];

    private const TERMINAL_REFUND_STATUSES = [
        'succeeded',
        'successful',
        'rejected',
        'cancelled',
        'canceled',
    ];

    private const TERMINAL_RETURN_STATUSES = [
        'not_required',
        'received',
        'rejected',
        'cancelled',
        'canceled',
    ];

    private const OPEN_PAYMENT_STATUSES = [
        'pending',
        'partially_paid',
        'failed',
        'expired',
    ];

    /**
     * @return array{fulfillment_status: string, business_closed: bool, blockers: list<string>}
     */
    public function project(Order $order): array
    {
        $fulfillmentStatus = $this->value($order->getAttribute('status'));
        $blockers = [];

        if (! in_array($fulfillmentStatus, self::TERMINAL_FULFILLMENT_STATUSES, true)) {
            $blockers[] = 'fulfillment';
        }

        foreach ($this->refunds($order) as $refund) {
            if ($this->refundIsOpen($refund)) {
                $blockers[] = 'refund';
            }

            if ($this->returnIsOpen($refund)) {
                $blockers[] = 'return';
            }

            if (in_array('refund', $blockers, true) && in_array('return', $blockers, true)) {
                break;
            }
        }

        if ($this->paymentIsOpen($order)) {
            $blockers[] = 'payment';
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'fulfillment_status' => $fulfillmentStatus,
            'business_closed' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /** @return iterable<OrderRefund> */
    private function refunds(Order $order): iterable
    {
        if ($order->relationLoaded('refunds')) {
            return $order->getRelation('refunds');
        }

        return $order->refunds()->get();
    }

    private function refundIsOpen(OrderRefund $refund): bool
    {
        $status = $this->value($refund->getAttribute('status'));

        if ($status === '') {
            return true;
        }

        return ! in_array($status, self::TERMINAL_REFUND_STATUSES, true);
    }

    private function returnIsOpen(OrderRefund $refund): bool
    {
        $status = $this->value($refund->getAttribute('return_status'));

        if ($status === '' || in_array($status, self::TERMINAL_RETURN_STATUSES, true)) {
            return false;
        }

        return true;
    }

    private function paymentIsOpen(Order $order): bool
    {
        return in_array($this->value($order->getAttribute('payment_status')), self::OPEN_PAYMENT_STATUSES, true);
    }

    private function value(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return strtolower(trim((string) $value));
    }
}
