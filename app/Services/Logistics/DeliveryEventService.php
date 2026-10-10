<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Support\Facades\DB;

class DeliveryEventService
{
    public function __construct(
        private ?LogisticsNotificationService $notifications = null
    ) {
        $this->notifications ??= app(LogisticsNotificationService::class);
    }

    public function record(Shipment $shipment, ?ShipmentLeg $leg, array $payload): DeliveryEvent
    {
        $event = DeliveryEvent::create([
            'shipment_id' => $shipment->id,
            'shipment_leg_id' => $leg?->id,
            'event_type' => (string) $payload['event_type'],
            'visibility' => (string) ($payload['visibility'] ?? 'internal'),
            'message' => $payload['message'] ?? null,
            'metadata' => $payload['metadata'] ?? [],
            'created_by_type' => $payload['created_by_type'] ?? null,
            'created_by_id' => $payload['created_by_id'] ?? null,
        ]);

        $eventId = $event->id;
        $notify = function () use ($eventId): void {
            $committedEvent = DeliveryEvent::query()->find($eventId);

            if ($committedEvent) {
                $this->notifications->notifyForEvent($committedEvent);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($notify);
        } else {
            $notify();
        }

        return $event;
    }
}
