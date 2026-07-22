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

        if (in_array($event->event_type, ['shipment_requested', 'batch_rejected', 'proof_required', 'delivery_attempt_failed'], true)) {
            $this->notifyDispatchers($event, $type);
        }

        if ($event->event_type === 'leg_assigned' && empty($event->metadata['delivery_batch_id'])) {
            $this->notifyRider($event, $type);
        }

        if ($event->event_type === 'batch_offered') {
            $this->notifyRider($event, $type);
        }

        if ($event->event_type === 'proof_rejected') {
            $this->notifyRider($event, $type);
        }

        if ($event->event_type === 'delivery_cancelled'
            && $event->visibility === 'customer'
            && $event->shipment->source_type === 'order') {
            $this->notifyOrderStaff($event, $type);
        }

        $customer = $this->resolveCustomer($event);
        if ($customer && $event->visibility === 'customer') {
            $this->createOnce($event, $customer->id, [
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
            || in_array($event->event_type, ['shipment_requested', 'leg_assigned', 'batch_offered', 'batch_rejected', 'delivery_attempt_failed', 'proof_required', 'proof_rejected'], true);
    }

    private function notifyDispatchers(DeliveryEvent $event, NotificationType $type): void
    {
        User::query()
            ->where('shop_owner_id', $event->shipment->shop_owner_id)
            ->whereHas('roles', fn ($query) => $query->where('name', 'Logistics Dispatcher'))
            ->each(function (User $dispatcher) use ($event, $type) {
                $this->createOnce($event, $dispatcher->id, [
                    'user_id' => $dispatcher->id,
                    'shop_id' => $event->shipment->shop_owner_id,
                    'type' => $type->value,
                    'priority' => 'high',
                    'title' => $type->label(),
                    'message' => match ($event->event_type) {
                        'batch_rejected' => "{$event->metadata['rider_name']} rejected the batch offer: {$event->metadata['rejection_reason']}",
                        'proof_required' => 'Delivery proof is awaiting your approval.',
                        'delivery_attempt_failed' => 'A delivery attempt failed and needs review.',
                        default => 'A new shipment needs rider assignment.',
                    },
                    'data' => $this->eventData($event) + ($event->event_type === 'batch_rejected' ? [
                        'delivery_batch_id' => $event->metadata['delivery_batch_id'],
                        'rejection_reason' => $event->metadata['rejection_reason'],
                    ] : []),
                    'action_url' => match ($event->event_type) {
                        'batch_rejected' => '/erp/logistics/batches',
                        'proof_required' => '/erp/logistics/shipments?status=awaiting_proof_approval',
                        'delivery_attempt_failed' => '/erp/logistics/shipments?status=failed_attempts',
                        default => '/erp/logistics/shipments',
                    },
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

        $batchOffer = $event->event_type === 'batch_offered';
        $proofRejected = $event->event_type === 'proof_rejected';
        $stopCount = (int) ($event->metadata['stop_count'] ?? 0);

        $this->createOnce($event, $rider->linked_id, [
            'user_id' => $rider->linked_id,
            'shop_id' => $event->shipment->shop_owner_id,
            'type' => $type->value,
            'priority' => 'high',
            'title' => $type->label(),
            'message' => $batchOffer
                ? "A delivery batch with {$stopCount} stops has been offered to you."
                : ($proofRejected
                    ? "Delivery proof rejected: {$event->metadata['rejection_reason']} Submit a replacement proof."
                    : 'A delivery has been assigned to you.'),
            'data' => $this->eventData($event) + array_filter([
                'delivery_batch_id' => $event->metadata['delivery_batch_id'] ?? null,
                'stop_count' => $event->metadata['stop_count'] ?? null,
                'rejection_reason' => $event->metadata['rejection_reason'] ?? null,
            ], fn ($value) => $value !== null),
            'action_url' => '/erp/logistics/deliveries',
            'requires_action' => true,
        ]);
    }

    private function notifyOrderStaff(DeliveryEvent $event, NotificationType $type): void
    {
        User::query()
            ->where('shop_owner_id', $event->shipment->shop_owner_id)
            ->each(function (User $staff) use ($event, $type) {
                if (! $staff->can('access-staff-job-orders')) {
                    return;
                }

                $this->createOnce($event, $staff->id, [
                    'user_id' => $staff->id,
                    'shop_id' => $event->shipment->shop_owner_id,
                    'type' => $type->value,
                    'priority' => 'high',
                    'title' => 'Delivery Cancelled',
                    'message' => $event->message ?: 'Delivery cancelled.',
                    'data' => $this->eventData($event) + ['order_id' => $event->shipment->source_id],
                    'action_url' => '/erp/staff/job-orders',
                    'requires_action' => true,
                ]);
            });
    }

    private function eventData(DeliveryEvent $event): array
    {
        return [
            'shipment_id' => $event->shipment_id,
            'shipment_leg_id' => $event->shipment_leg_id,
            'event_type' => $event->event_type,
        ];
    }

    private function createOnce(DeliveryEvent $event, int $userId, array $data): Notification
    {
        $groupKey = implode(':', array_filter([
            'logistics', $userId, $event->shipment_id, $event->shipment_leg_id ?: 0, $event->event_type,
            in_array($event->event_type, ['batch_rejected', 'proof_rejected'], true) ? $event->id : null,
        ], fn ($value) => $value !== null));
        return Notification::firstOrCreate(['user_id' => $userId, 'group_key' => $groupKey], [...$data, 'group_key' => $groupKey]);
    }

    private function notificationType(string $eventType): ?NotificationType
    {
        return match ($eventType) {
            'shipment_requested' => NotificationType::LOGISTICS_SHIPMENT_REQUESTED,
            'leg_assigned' => NotificationType::LOGISTICS_ASSIGNED,
            'batch_offered' => NotificationType::LOGISTICS_BATCH_OFFERED,
            'batch_rejected' => NotificationType::LOGISTICS_BATCH_REJECTED,
            'pickup_scheduled' => NotificationType::LOGISTICS_PICKUP_SCHEDULED,
            'in_transit' => NotificationType::LOGISTICS_IN_TRANSIT,
            'delivery_attempt_failed' => NotificationType::LOGISTICS_DELIVERY_FAILED,
            'delivery_cancelled' => NotificationType::LOGISTICS_DELIVERY_FAILED,
            'proof_required' => NotificationType::LOGISTICS_PROOF_REQUIRED,
            'proof_rejected' => NotificationType::LOGISTICS_PROOF_REQUIRED,
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
