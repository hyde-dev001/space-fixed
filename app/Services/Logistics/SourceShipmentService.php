<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SourceShipmentService
{
    public function __construct(
        private ShipmentRequestService $shipments,
        private DeliveryScheduleService $schedules,
        private DeliveryEventService $events,
    ) {}

    public function ensureRetailOrderShipment(Order $order): Shipment
    {
        return DB::transaction(function () use ($order) {
            ShopOwner::query()->whereKey($order->shop_owner_id)->lockForUpdate()->firstOrFail();
            $existing = $this->findExisting('order', (int) $order->id, 'retail_delivery');
            if ($existing) {
                return $existing->load('legs');
            }

            $order->loadMissing('shopOwner', 'address');
            $address = $order->address;
            $coverage = strtolower(trim((string) $order->carrier_company)) === 'shop-owned logistics'
                ? $this->schedules->coverage(
                    $order->shopOwner,
                    $address?->latitude !== null ? (float) $address->latitude : null,
                    $address?->longitude !== null ? (float) $address->longitude : null,
                )
                : null;
            $schedule = $coverage !== null
                ? $this->unscheduledSchedule($coverage['distance_km'] ?? null)
                : [];

            $shipment = $this->shipments->requestShipment([
                'shop_owner_id' => (int) $order->shop_owner_id,
                'source_type' => 'order',
                'source_id' => (int) $order->id,
                'purpose' => 'retail_delivery',
                'legs' => [[
                    'leg_type' => 'outbound',
                    'origin_snapshot' => [
                        'type' => 'shop',
                        'name' => (string) ($order->shopOwner?->business_name ?? 'Shop'),
                        'address' => (string) ($order->shopOwner?->business_address ?? ''),
                        'latitude' => $order->shopOwner?->shop_latitude !== null
                            ? (float) $order->shopOwner->shop_latitude
                            : null,
                        'longitude' => $order->shopOwner?->shop_longitude !== null
                            ? (float) $order->shopOwner->shop_longitude
                            : null,
                    ],
                    'destination_snapshot' => [
                        'type' => 'customer',
                        'name' => (string) ($order->customer_name ?? 'Customer'),
                        'phone' => (string) ($order->customer_phone ?? ''),
                        'address' => (string) ($order->full_shipping_address ?? $order->customer_address ?? ''),
                        'latitude' => $address?->latitude !== null ? (float) $address->latitude : null,
                        'longitude' => $address?->longitude !== null ? (float) $address->longitude : null,
                        'delivery_instructions' => $address?->delivery_instructions,
                    ],
                    ...$schedule,
                    'estimated_at' => ($schedule['schedule_status'] ?? null) === 'scheduled' ? now() : null,
                ]],
            ]);

            $leg = $shipment->legs->first();
            if (($schedule['schedule_status'] ?? null) === 'scheduled') {
                $this->events->record($shipment, $leg, ['event_type' => 'delivery_schedule_created', 'message' => 'Delivery scheduled.']);
                $this->events->record($shipment, $leg, [
                    'event_type' => 'delivery_estimated', 'visibility' => 'customer',
                    'message' => 'Estimated delivery scheduled.',
                ]);
            } elseif ($schedule) {
                $this->events->record($shipment, $leg, [
                    'event_type' => 'delivery_schedule_attention',
                    'message' => 'Delivery schedule requires dispatcher attention.',
                    'metadata' => ['schedule_status' => $schedule['schedule_status']],
                ]);
            }

            return $shipment->fresh(['legs', 'events']);
        });
    }

    public function ensureRefundReturnShipment(OrderRefund $refund): Shipment
    {
        $refund->loadMissing('order.shopOwner', 'order.address', 'customer');
        if ($refund->returnDeliveryMethod() === 'third_party') {
            throw ValidationException::withMessages([
                'delivery_method' => ['Third-party returns do not create Shop-owned logistics shipments.'],
            ]);
        }

        $existing = $this->findExisting(
            'order_refund',
            (int) $refund->id,
            'refund_return',
            (int) $refund->shop_owner_id,
            activeOnly: true,
        );
        if ($existing) {
            return $existing;
        }

        $order = $refund->order;
        $address = $order?->address;

        return $this->shipments->requestShipment([
            'shop_owner_id' => (int) $refund->shop_owner_id,
            'source_type' => 'order_refund',
            'source_id' => (int) $refund->id,
            'purpose' => 'refund_return',
            'legs' => [[
                'leg_type' => 'return_to_shop',
                'origin_snapshot' => [
                    'type' => 'customer',
                    'name' => (string) ($refund->customer?->name ?? $order?->customer_name ?? 'Customer'),
                    'phone' => (string) ($order?->customer_phone ?? ''),
                    'address' => (string) ($order?->full_shipping_address ?? $order?->customer_address ?? ''),
                    'latitude' => $address?->latitude !== null ? (float) $address->latitude : null,
                    'longitude' => $address?->longitude !== null ? (float) $address->longitude : null,
                ],
                'destination_snapshot' => [
                    'type' => 'shop',
                    'name' => (string) ($order?->shopOwner?->business_name ?? 'Shop'),
                    'address' => (string) ($order?->shopOwner?->business_address ?? ''),
                    'latitude' => $order?->shopOwner?->shop_latitude !== null
                        ? (float) $order->shopOwner->shop_latitude
                        : null,
                    'longitude' => $order?->shopOwner?->shop_longitude !== null
                        ? (float) $order->shopOwner->shop_longitude
                        : null,
                ],
            ]],
        ]);
    }

    public function ensureRepairInboundShipment(RepairRequest $repair): Shipment
    {
        return DB::transaction(function () use ($repair): Shipment {
            $lockedRepair = RepairRequest::query()
                ->with('shopOwner')
                ->whereKey($repair->id)
                ->lockForUpdate()
                ->firstOrFail();
            $shop = ShopOwner::query()->whereKey($lockedRepair->shop_owner_id)->lockForUpdate()->firstOrFail();
            $lockedRepair->setRelation('shopOwner', $shop);

            if ((string) $lockedRepair->intake_delivery_method !== 'shop_pickup') {
                throw ValidationException::withMessages([
                    'intake_delivery_method' => ['Only shop-owned repair pickups create Dispatcher shipments.'],
                ]);
            }

            $existing = $this->findExisting('repair_request', (int) $lockedRepair->id, 'repair_pickup');

            if ($existing && $existing->status->value !== 'cancelled') {
                return $existing->load('legs');
            }

            $snapshot = is_array($lockedRepair->intake_address)
                ? $lockedRepair->intake_address
                : (is_array($lockedRepair->pickup_address) ? $lockedRepair->pickup_address : []);
            $coverage = $this->schedules->coverage(
                $shop,
                isset($snapshot['latitude']) ? (float) $snapshot['latitude'] : null,
                isset($snapshot['longitude']) ? (float) $snapshot['longitude'] : null,
            );

            if (! ($coverage['available'] ?? false)) {
                throw ValidationException::withMessages([
                    'intake_address' => ['The repair pickup address is no longer covered by shop-owned logistics.'],
                ]);
            }

            $schedule = $this->unscheduledSchedule($coverage['distance_km'] ?? null);
            $legData = [
                'leg_type' => 'inbound',
                'origin_snapshot' => [
                    ...$snapshot,
                    'type' => 'customer',
                    'name' => (string) ($snapshot['name'] ?? $lockedRepair->customer_name ?? 'Customer'),
                    'phone' => (string) ($snapshot['phone'] ?? $lockedRepair->phone ?? ''),
                    'address' => $this->formatAddress($snapshot),
                    'accepted_delivery_fee' => round((float) $lockedRepair->intake_delivery_fee, 2),
                    'coverage' => $coverage,
                ],
                'destination_snapshot' => [
                    'type' => 'shop',
                    'name' => (string) ($shop->business_name ?? 'Shop'),
                    'address' => (string) ($shop->business_address ?? ''),
                    'latitude' => $shop->shop_latitude !== null ? (float) $shop->shop_latitude : null,
                    'longitude' => $shop->shop_longitude !== null ? (float) $shop->shop_longitude : null,
                ],
                ...$schedule,
                'estimated_at' => ($schedule['schedule_status'] ?? null) === 'scheduled' ? now() : null,
            ];

            if ($existing) {
                $cancelledAt = $existing->cancelled_at ?? $existing->updated_at;
                $sponsoredWarranty = (bool) ($lockedRepair->is_warranty_job ?? false)
                    || (string) ($lockedRepair->billing_mode ?? '') === 'warranty_no_charge';
                if ((! $sponsoredWarranty
                        && data_get($lockedRepair->logistics_payment_reconciliation, 'status') !== 'resolved')
                    || $lockedRepair->intake_logistics_locked_at === null
                    || $cancelledAt === null
                    || ! $lockedRepair->intake_logistics_locked_at->greaterThan($cancelledAt)) {
                    throw ValidationException::withMessages([
                        'intake' => [$sponsoredWarranty
                            ? 'A cancelled pickup can be retried only after a new sponsored pickup plan.'
                            : 'A cancelled pickup can be retried only after compensation and a new paid pickup plan.'],
                    ]);
                }

                $leg = $existing->legs()->create([
                    'sequence' => ((int) $existing->legs()->max('sequence')) + 1,
                    'leg_type' => $legData['leg_type'],
                    'status' => 'pending',
                    'origin_snapshot' => $legData['origin_snapshot'],
                    'destination_snapshot' => $legData['destination_snapshot'],
                    'requires_delivery_proof' => true,
                    'scheduled_delivery_date' => $legData['scheduled_delivery_date'] ?? null,
                    'delivery_window' => $legData['delivery_window'] ?? null,
                    'schedule_status' => $legData['schedule_status'] ?? null,
                    'distance_km' => $legData['distance_km'] ?? null,
                    'estimated_at' => $legData['estimated_at'],
                ]);
                $existing->update([
                    'status' => 'requested',
                    'completed_at' => null,
                    'cancelled_at' => null,
                ]);
                $this->events->record($existing, $leg, [
                    'event_type' => 'shipment_reactivated',
                    'message' => $sponsoredWarranty
                        ? 'Repair pickup requested again after sponsored delivery replanning.'
                        : 'Repair pickup requested again after compensation.',
                ]);
                $this->recordScheduleEvents($existing, $leg, $schedule);

                return $existing->fresh(['legs', 'events']);
            }

            $shipment = $this->shipments->requestShipment([
                'shop_owner_id' => (int) $lockedRepair->shop_owner_id,
                'source_type' => 'repair_request',
                'source_id' => (int) $lockedRepair->id,
                'purpose' => 'repair_pickup',
                'legs' => [$legData],
            ]);
            $this->recordScheduleEvents($shipment, $shipment->legs->first(), $schedule);

            return $shipment->fresh(['legs', 'events']);
        });
    }

    public function ensureRepairReturnShipment(RepairRequest $repair, ?array $requestedSchedule = null): Shipment
    {
        return DB::transaction(function () use ($repair, $requestedSchedule): Shipment {
            $lockedRepair = RepairRequest::query()
                ->with('shopOwner')
                ->whereKey($repair->id)
                ->lockForUpdate()
                ->firstOrFail();
            $shop = ShopOwner::query()->whereKey($lockedRepair->shop_owner_id)->lockForUpdate()->firstOrFail();
            $lockedRepair->setRelation('shopOwner', $shop);

            if ((string) $lockedRepair->return_delivery_method !== 'shop_delivery') {
                throw ValidationException::withMessages([
                    'return_delivery_method' => ['Only shop-owned repair returns create Dispatcher shipments.'],
                ]);
            }

            $existing = $this->findExisting('repair_request', (int) $lockedRepair->id, 'repair_return');
            if ($existing && $existing->status->value !== 'cancelled') {
                return $existing->load('legs');
            }

            $snapshot = is_array($lockedRepair->return_address) ? $lockedRepair->return_address : [];
            $coverage = $this->schedules->coverage(
                $shop,
                isset($snapshot['latitude']) ? (float) $snapshot['latitude'] : null,
                isset($snapshot['longitude']) ? (float) $snapshot['longitude'] : null,
            );
            if (! ($coverage['available'] ?? false)) {
                throw ValidationException::withMessages([
                    'return_address' => ['The repair return address is no longer covered by shop-owned logistics.'],
                ]);
            }

            $schedule = $this->unscheduledSchedule($coverage['distance_km'] ?? null);
            if (! empty($requestedSchedule['scheduled_delivery_date'])
                && in_array($requestedSchedule['delivery_window'] ?? null, ['morning', 'afternoon'], true)) {
                $schedule = [
                    ...$schedule,
                    'scheduled_delivery_date' => $requestedSchedule['scheduled_delivery_date'],
                    'delivery_window' => $requestedSchedule['delivery_window'],
                    'schedule_status' => 'scheduled',
                ];
            }
            $legData = [
                'leg_type' => 'outbound',
                'origin_snapshot' => [
                    'type' => 'shop',
                    'name' => (string) ($shop->business_name ?? 'Shop'),
                    'address' => (string) ($shop->business_address ?? ''),
                    'latitude' => $shop->shop_latitude !== null ? (float) $shop->shop_latitude : null,
                    'longitude' => $shop->shop_longitude !== null ? (float) $shop->shop_longitude : null,
                ],
                'destination_snapshot' => [
                    ...$snapshot,
                    'type' => 'customer',
                    'name' => (string) ($snapshot['name'] ?? $lockedRepair->customer_name ?? 'Customer'),
                    'phone' => (string) ($snapshot['phone'] ?? $lockedRepair->phone ?? ''),
                    'address' => $this->formatAddress($snapshot),
                    'accepted_delivery_fee' => round((float) $lockedRepair->return_delivery_fee, 2),
                    'coverage' => $coverage,
                ],
                ...$schedule,
                'estimated_at' => ($schedule['schedule_status'] ?? null) === 'scheduled' ? now() : null,
            ];

            if ($existing) {
                $cancelledAt = $existing->cancelled_at ?? $existing->updated_at;
                $sponsoredWarranty = (bool) ($lockedRepair->is_warranty_job ?? false)
                    || (string) ($lockedRepair->billing_mode ?? '') === 'warranty_no_charge';
                if ((! $sponsoredWarranty
                        && data_get($lockedRepair->logistics_payment_reconciliation, 'status') !== 'resolved')
                    || $lockedRepair->return_logistics_locked_at === null
                    || $cancelledAt === null
                    || ! $lockedRepair->return_logistics_locked_at->greaterThan($cancelledAt)) {
                    throw ValidationException::withMessages([
                        'return' => [$sponsoredWarranty
                            ? 'A cancelled return can be retried only after a new sponsored return plan.'
                            : 'A cancelled return can be retried only after compensation and a new paid return plan.'],
                    ]);
                }

                $leg = $existing->legs()->create([
                    'sequence' => ((int) $existing->legs()->max('sequence')) + 1,
                    'leg_type' => $legData['leg_type'],
                    'status' => 'pending',
                    'origin_snapshot' => $legData['origin_snapshot'],
                    'destination_snapshot' => $legData['destination_snapshot'],
                    'requires_delivery_proof' => true,
                    'scheduled_delivery_date' => $legData['scheduled_delivery_date'] ?? null,
                    'delivery_window' => $legData['delivery_window'] ?? null,
                    'schedule_status' => $legData['schedule_status'] ?? null,
                    'distance_km' => $legData['distance_km'] ?? null,
                    'estimated_at' => $legData['estimated_at'],
                ]);
                $existing->update([
                    'status' => 'requested',
                    'completed_at' => null,
                    'cancelled_at' => null,
                ]);
                $this->events->record($existing, $leg, [
                    'event_type' => 'shipment_reactivated',
                    'message' => $sponsoredWarranty
                        ? 'Repair return requested again after sponsored delivery replanning.'
                        : 'Repair return requested again after compensation.',
                ]);
                $this->recordScheduleEvents($existing, $leg, $schedule);

                return $existing->fresh(['legs', 'events']);
            }

            $shipment = $this->shipments->requestShipment([
                'shop_owner_id' => (int) $lockedRepair->shop_owner_id,
                'source_type' => 'repair_request',
                'source_id' => (int) $lockedRepair->id,
                'purpose' => 'repair_return',
                'legs' => [$legData],
            ]);
            $this->recordScheduleEvents($shipment, $shipment->legs->first(), $schedule);

            return $shipment->fresh(['legs', 'events']);
        });
    }

    private function findExisting(
        string $sourceType,
        int $sourceId,
        string $purpose,
        ?int $shopOwnerId = null,
        bool $activeOnly = false,
    ): ?Shipment
    {
        return Shipment::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('purpose', $purpose)
            ->when($shopOwnerId !== null && $shopOwnerId > 0, fn ($query) => $query->where('shop_owner_id', $shopOwnerId))
            ->when($activeOnly, fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->latest('id')
            ->first();
    }

    private function formatAddress(mixed $address): string
    {
        if (is_array($address)) {
            return collect([
                $address['address_line'] ?? null,
                $address['barangay'] ?? null,
                $address['city'] ?? null,
                $address['province'] ?? null,
                $address['region'] ?? null,
                $address['postal_code'] ?? null,
            ])->filter()->implode(', ');
        }

        return (string) ($address ?? '');
    }

    private function recordScheduleEvents(Shipment $shipment, $leg, array $schedule): void
    {
        if (($schedule['schedule_status'] ?? null) === 'scheduled') {
            $this->events->record($shipment, $leg, [
                'event_type' => 'delivery_schedule_created',
                'message' => 'Delivery scheduled.',
            ]);
            $this->events->record($shipment, $leg, [
                'event_type' => 'delivery_estimated',
                'visibility' => 'customer',
                'message' => 'Estimated pickup scheduled.',
            ]);
        } elseif ($schedule) {
            $this->events->record($shipment, $leg, [
                'event_type' => 'delivery_schedule_attention',
                'message' => 'Delivery schedule requires dispatcher attention.',
                'metadata' => ['schedule_status' => $schedule['schedule_status']],
            ]);
        }
    }

    private function unscheduledSchedule(?float $distance = null): array
    {
        return [
            'scheduled_delivery_date' => null,
            'delivery_window' => null,
            'schedule_status' => 'unscheduled',
            'distance_km' => $distance,
        ];
    }
}
