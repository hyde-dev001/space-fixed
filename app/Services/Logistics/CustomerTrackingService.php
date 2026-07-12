<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;

class CustomerTrackingService
{
    public function customerOwnsShipment(Shipment $shipment, int $customerId): bool
    {
        return match ($shipment->source_type) {
            'order' => Order::query()
                ->whereKey($shipment->source_id)
                ->where('customer_id', $customerId)
                ->exists(),
            'order_refund' => OrderRefund::query()
                ->whereKey($shipment->source_id)
                ->where('customer_id', $customerId)
                ->exists(),
            'repair_request' => RepairRequest::query()
                ->whereKey($shipment->source_id)
                ->where('user_id', $customerId)
                ->exists(),
            default => false,
        };
    }

    public function payload(Shipment $shipment): array
    {
        $shipment->load([
            'legs' => fn ($query) => $query->orderBy('sequence'),
            'events' => fn ($query) => $query->where('visibility', 'customer')->latest(),
        ]);

        return [
            'id' => $shipment->id,
            'purpose' => $shipment->purpose,
            'status' => $shipment->status->value,
            'source_type' => $shipment->source_type,
            'created_at' => optional($shipment->created_at)->toISOString(),
            'legs' => $shipment->legs->map(fn ($leg) => [
                'id' => $leg->id,
                'sequence' => $leg->sequence,
                'leg_type' => $leg->leg_type,
                'status' => $leg->status->value,
                'origin_snapshot' => $leg->origin_snapshot,
                'destination_snapshot' => $leg->destination_snapshot,
                'tracking_number' => $leg->tracking_number,
                'tracking_url' => $leg->tracking_url,
                'requires_delivery_proof' => (bool) $leg->requires_delivery_proof,
                'scheduled_pickup_at' => optional($leg->scheduled_pickup_at)->toISOString(),
                'picked_up_at' => optional($leg->picked_up_at)->toISOString(),
                'delivered_at' => optional($leg->delivered_at)->toISOString(),
            ])->values()->all(),
            'events' => $shipment->events->map(fn ($event) => [
                'id' => $event->id,
                'shipment_leg_id' => $event->shipment_leg_id,
                'event_type' => $event->event_type,
                'message' => $event->message,
                'created_at' => optional($event->created_at)->toISOString(),
            ])->values()->all(),
        ];
    }
}
