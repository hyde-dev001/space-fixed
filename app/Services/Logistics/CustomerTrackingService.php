<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;
use Illuminate\Support\Facades\Storage;

class CustomerTrackingService
{
    private const ATTEMPT_REASON_LABELS = [
        'recipient_unavailable' => 'Recipient unavailable',
        'wrong_or_incomplete_address' => 'Wrong or incomplete address',
        'recipient_refused' => 'Recipient refused',
        'vehicle_or_delivery_problem' => 'Vehicle or delivery problem',
        'other' => 'Other delivery issue',
    ];

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
            'legs' => fn ($query) => $query->orderBy('sequence')->orderBy('id'),
            'legs.attempts' => fn ($query) => $query
                ->where('attempt_type', 'delivery')
                ->where('status', 'failed')
                ->latest('attempted_at')
                ->latest('id'),
            'events' => fn ($query) => $query->where('visibility', 'customer')->latest(),
        ]);

        return [
            'id' => $shipment->id,
            'purpose' => $shipment->purpose,
            'status' => $shipment->status->value,
            'source_type' => $shipment->source_type,
            'source_summary' => $this->repairSourceSummary($shipment),
            'created_at' => optional($shipment->created_at)->toISOString(),
            'legs' => $shipment->legs->map(function ($leg) use ($shipment) {
                $attempt = $leg->attempts->first();

                return [
                    'id' => $leg->id,
                    'sequence' => $leg->sequence,
                    'leg_type' => $leg->leg_type,
                    'status' => $leg->status->value,
                    'origin_snapshot' => $this->safeSnapshot($leg->origin_snapshot),
                    'destination_snapshot' => $this->safeSnapshot($leg->destination_snapshot),
                    'tracking_number' => $leg->tracking_number,
                    'tracking_url' => $leg->tracking_url,
                    'requires_delivery_proof' => (bool) $leg->requires_delivery_proof,
                    'scheduled_pickup_at' => optional($leg->scheduled_pickup_at)->toISOString(),
                    'picked_up_at' => optional($leg->picked_up_at)->toISOString(),
                    'delivered_at' => optional($leg->delivered_at)->toISOString(),
                    'scheduled_delivery_date' => optional($leg->scheduled_delivery_date)->toDateString(),
                    'delivery_window' => $leg->delivery_window,
                    'schedule_status' => $leg->schedule_status,
                    'latest_failed_attempt' => $attempt ? [
                        'id' => $attempt->id,
                        'reason' => self::ATTEMPT_REASON_LABELS[$attempt->reason_code] ?? 'Delivery could not be completed',
                        'attempted_at' => optional($attempt->attempted_at)->toISOString(),
                        'proof_url' => $attempt->file_path && Storage::disk('public')->exists($attempt->file_path)
                            ? route('customer.tracking.attempt-proof', [$shipment, $attempt])
                            : null,
                    ] : null,
                ];
            })->values()->all(),
            'events' => $shipment->events->map(fn ($event) => [
                'id' => $event->id,
                'shipment_leg_id' => $event->shipment_leg_id,
                'event_type' => $event->event_type,
                'message' => $event->message,
                'created_at' => optional($event->created_at)->toISOString(),
            ])->values()->all(),
        ];
    }

    private function safeSnapshot(?array $snapshot): ?array
    {
        return $snapshot ? collect($snapshot)->except(['phone', 'rider_name', 'rider_phone', 'internal_notes'])->all() : null;
    }

    private function repairSourceSummary(Shipment $shipment): ?array
    {
        if ($shipment->source_type !== 'repair_request') {
            return null;
        }

        $repair = RepairRequest::query()
            ->with('user:id,name,first_name,last_name')
            ->where('shop_owner_id', $shipment->shop_owner_id)
            ->find($shipment->source_id);
        if (! $repair) {
            return null;
        }

        $customer = $repair->customer_name
            ?: $repair->user?->name
            ?: trim("{$repair->user?->first_name} {$repair->user?->last_name}");
        $shoe = trim(implode(' ', array_filter([$repair->brand, $repair->shoe_type])));

        return [
            'request_number' => $repair->request_id ?: (string) $repair->id,
            'customer_name' => $customer ?: 'Customer not provided',
            'shoe_summary' => $shoe ?: ($repair->description ?: 'Repair item'),
        ];
    }
}
