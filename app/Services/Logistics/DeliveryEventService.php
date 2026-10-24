<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'notification_requested_at' => now(),
        ]);

        $eventId = $event->id;
        $notify = function () use ($eventId): void {
            try {
                $this->deliverNotifications($eventId);
            } catch (\Throwable $exception) {
                // Business state is already committed; the persisted event is retried by the scheduler.
                Log::warning('Logistics notification processing deferred.', ['event_id' => $eventId, 'exception_class' => $exception::class]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($notify);
        } else {
            $notify();
        }

        return $event;
    }

    public function deliverNotifications(int $eventId): void
    {
        try {
            DB::transaction(function () use ($eventId) {
                $event = DeliveryEvent::lockForUpdate()->find($eventId);
                if (! $event || $event->notifications_processed_at) return;
                $this->notifications->notifyForEvent($event);
                $event->update(['notifications_processed_at' => now(), 'notification_last_error' => null,
                    'notification_attempts' => $event->notification_attempts + 1]);
            });
        } catch (\Throwable $exception) {
            DeliveryEvent::whereKey($eventId)->whereNull('notifications_processed_at')->update([
                'notification_attempts' => DB::raw('notification_attempts + 1'),
                'notification_last_error' => $exception::class,
            ]);
            throw $exception;
        }
    }
}
