<?php

namespace App\Services\Logistics;

use App\Enums\NotificationType;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\RiderProfile;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;
use App\Models\User;

class LogisticsNotificationService
{
    public function notifyForEvent(DeliveryEvent $event): void
    {
        if (!$this->shouldNotify($event)) {
            return;
        }

        $event->loadMissing('shipment');
        $type = $this->notificationType($event->event_type);
        if (!$type) {
            return;
        }

        if ($event->event_type === 'shipment_requested') {
            $this->notifyDispatchers($event, $type);
        }

        if ($event->event_type === 'leg_assigned') {
            $this->notifyRider($event, $type);
        }

        $customer = $this->resolveCustomer($event);
        if ($customer && $event->visibility === 'customer') {
            Notification::create([
                'user_id' => $customer->id,
                'shop_id' => $event->shipment->shop_owner_id,
                'type' => $type->value,
                'priority' => $event->event_type === 'delivery_attempt_failed' ? 'high' : 'medium',
                'title' => $type->label(),
                'message' => $event->message ?: $type->label(),
                'data' => [
                    'shipment_id' => $event->shipment_id,
                    'shipment_leg_id' => $event->shipment_leg_id,
                    'event_type' => $event->event_type,
                ],
                'action_url' => '/tracking/shipments/' . $event->shipment_id,
                'requires_action' => $event->event_type === 'delivery_attempt_failed',
            ]);
        }
    }

    private function shouldNotify(DeliveryEvent $event): bool
    {
        return $event->visibility === 'customer'
            || in_array($event->event_type, ['shipment_requested', 'leg_assigned', 'delivery_attempt_failed', 'proof_required'], true);
    }

    private function notifyDispatchers(DeliveryEvent $event, NotificationType $type): void
    {
        User::query()
            ->where('shop_owner_id', $event->shipment->shop_owner_id)
            ->whereHas('roles', fn ($query) => $query->where('name', 'Logistics Dispatcher'))
            ->each(function (User $dispatcher) use ($event, $type) {
                Notification::create([
                    'user_id' => $dispatcher->id,
                    'shop_id' => $event->shipment->shop_owner_id,
                    'type' => $type->value,
                    'priority' => 'high',
                    'title' => $type->label(),
                    'message' => 'A new shipment needs rider assignment.',
                    'data' => $this->eventData($event),
                    'action_url' => '/erp/logistics/shipments',
                    'requires_action' => true,
                ]);
            });
    }

    private function notifyRider(DeliveryEvent $event, NotificationType $type): void
    {
        $riderProfileId = (int) ($event->metadata['rider_profile_id'] ?? 0);
        $rider = RiderProfile::query()
            ->whereKey($riderProfileId)
            ->where('linked_type', User::class)
            ->first();

        if (!$rider || (int) $rider->shop_owner_id !== (int) $event->shipment->shop_owner_id) {
            return;
        }

        Notification::create([
            'user_id' => $rider->linked_id,
            'shop_id' => $event->shipment->shop_owner_id,
            'type' => $type->value,
            'priority' => 'high',
            'title' => $type->label(),
            'message' => 'A delivery has been assigned to you.',
            'data' => $this->eventData($event),
            'action_url' => '/erp/logistics/shipments',
            'requires_action' => true,
        ]);
    }

    private function eventData(DeliveryEvent $event): array
    {
        return [
            'shipment_id' => $event->shipment_id,
            'shipment_leg_id' => $event->shipment_leg_id,
            'event_type' => $event->event_type,
        ];
    }

    private function notificationType(string $eventType): ?NotificationType
    {
        return match ($eventType) {
            'shipment_requested' => NotificationType::LOGISTICS_SHIPMENT_REQUESTED,
            'leg_assigned' => NotificationType::LOGISTICS_ASSIGNED,
            'pickup_scheduled' => NotificationType::LOGISTICS_PICKUP_SCHEDULED,
            'in_transit' => NotificationType::LOGISTICS_IN_TRANSIT,
            'delivery_attempt_failed' => NotificationType::LOGISTICS_DELIVERY_FAILED,
            'proof_required' => NotificationType::LOGISTICS_PROOF_REQUIRED,
            'delivered' => NotificationType::LOGISTICS_DELIVERED,
            default => null,
        };
    }

    private function resolveCustomer(DeliveryEvent $event): ?User
    {
        $shipment = $event->shipment;

        return match ($shipment->source_type) {
            'order' => Order::query()->find($shipment->source_id)?->customer,
            'order_refund' => OrderRefund::query()->find($shipment->source_id)?->customer,
            'repair_request' => RepairRequest::query()->find($shipment->source_id)?->user,
            default => null,
        };
    }
}
