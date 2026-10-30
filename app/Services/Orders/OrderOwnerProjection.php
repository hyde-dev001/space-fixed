<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderRefund;

final class OrderOwnerProjection
{
    /**
     * Return fulfillment actions that the current order state can expose to the owner UI.
     *
     * The mutation service remains authoritative and revalidates every action.
     *
     * @return list<string>
     */
    public function availableActions(Order $order): array
    {
        $policy = new OrderTransitionPolicy();
        $actions = [];

        if ($policy->canMarkProcessing($order)) {
            $actions[] = 'processing';
        }

        if ($policy->canMarkShipped($order)) {
            $actions[] = 'shipped';
        }

        if ($policy->canCompleteDirectly($order, (bool) $order->getAttribute('pickup_enabled'))) {
            $actions[] = 'completed';
        }

        return $actions;
    }

    /**
     * @return array{fulfillment_status: string, parcel_recovery_label?: ?string, business_closed: bool, blockers: list<string>}
     */
    public function project(Order $order): array
    {
        $fulfillmentStatus = $this->value($order->getAttribute('status'));
        $blockers = [];

        if (! in_array($fulfillmentStatus, Order::TERMINAL_FULFILLMENT_STATUSES, true)) {
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
            ...(($label = $this->parcelRecoveryLabel($order)) ? ['parcel_recovery_label' => $label] : []),
            'business_closed' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    private function parcelRecoveryLabel(Order $order): ?string
    {
        if (! $order->relationLoaded('logisticsShipments')) return null;
        $incident = $order->logisticsShipments->flatMap(fn ($shipment) => $shipment->legs)
            ->flatMap(fn ($leg) => $leg->incidents)->sortByDesc('id')->first();
        if (! $incident) return null;
        $context = $incident->recovery_context ?? [];
        return match (true) {
            (bool) data_get($context, 'recovered_loss') && ! $incident->decided_at => 'Parcel recovery reported; Dispatcher review pending',
            $incident->resolution === 'return_required' && $incident->status === 'resolved' => 'Physical return completed; source and financial work remain separate',
            $incident->resolution === 'return_required' && (bool) data_get($context, 'receipt') => 'Returned parcel received; item handling pending',
            $incident->resolution === 'return_required' => 'Return to shop authorized; physical receipt pending',
            $incident->resolution === 'loss_confirmed' => 'Parcel loss confirmed; financial processing remains separate',
            $incident->type === 'lost' => 'Parcel loss reported; delivery held',
            $incident->resolution === 'retry' && $incident->status === 'resolved' => 'Recovery delivery completed',
            $incident->resolution === 'retry' => 'Recovery delivery scheduled',
            $incident->resolution === 'dismissed' => 'Delivery continuation authorized',
            default => 'Delivery incident awaiting Dispatcher action',
        };
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

        return ! in_array($status, Order::TERMINAL_REFUND_STATUSES, true);
    }

    private function returnIsOpen(OrderRefund $refund): bool
    {
        $status = $this->value($refund->getAttribute('return_status'));

        if ($status === '' || in_array($status, Order::TERMINAL_RETURN_STATUSES, true)) {
            return false;
        }

        return true;
    }

    private function paymentIsOpen(Order $order): bool
    {
        return in_array($this->value($order->getAttribute('payment_status')), Order::OPEN_PAYMENT_STATUSES, true);
    }

    private function value(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return strtolower(trim((string) $value));
    }
}
