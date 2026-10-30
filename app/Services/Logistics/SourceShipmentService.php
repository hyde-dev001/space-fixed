<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
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
        private LogisticsMovementEligibility $movements,
    ) {}

    public function ensureRetailOrderShipment(Order $order): Shipment
    {
        return DB::transaction(function () use ($order) {
            $shop = ShopOwner::query()->whereKey($order->shop_owner_id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->with('address')->whereKey($order->id)
                ->where('shop_owner_id', $shop->id)->lockForUpdate()->firstOrFail();
            $order->setRelation('shopOwner', $shop);
            $deliveryMethod = $order->resolvedDeliveryMethod();
            $existing = $this->findExisting('order', (int) $order->id, 'retail_delivery', (int) $shop->id);
            if ($existing) {
                if ($deliveryMethod === 'third_party') {
                    $this->syncThirdPartyLeg($existing, $order);
                } else {
                    $this->movements->assertExistingShipmentAllowed($shop, $existing);
                }

                return $existing->load('legs');
            }

            $address = $order->address;
            $coverage = $deliveryMethod === 'shop_owned'
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
                    'tracking_number' => $deliveryMethod === 'third_party' ? $order->tracking_number : null,
                    'tracking_url' => $deliveryMethod === 'third_party' ? $order->tracking_link : null,
                    'provider_status' => $deliveryMethod === 'third_party' ? 'handoff_pending' : null,
                    'requires_delivery_proof' => $deliveryMethod !== 'third_party',
                ]],
            ]);

            $leg = $shipment->legs->first();
            if ($deliveryMethod === 'third_party') {
                $this->events->record($shipment, $leg, [
                    'event_type' => 'third_party_delivery_recorded',
                    'visibility' => 'customer',
                    'message' => 'Third-party courier delivery recorded.',
                ]);
            }
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

    private function syncThirdPartyLeg(Shipment $shipment, Order $order): void
    {
        $leg = $shipment->legs()->lockForUpdate()->first();
        if (! $leg) {
            return;
        }

        $changes = array_filter([
            'tracking_number' => $order->tracking_number,
            'tracking_url' => $order->tracking_link,
        ], static fn ($value) => filled($value));

        if ($changes !== []) {
            $leg->update($changes);
        }
    }

    public function requestRetailRedelivery(\App\Models\Logistics\DeliveryIncident $incident, \App\Models\User $actor, array $data): Shipment
    {
        return DB::transaction(function () use ($incident, $actor, $data) {
            $shop = ShopOwner::whereKey($incident->shop_owner_id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $actor->shop_owner_id === (int) $shop->id && $actor->can('resolve-logistics-exceptions'), 403);
            $this->movements->assertCanStart($shop);
            $original = $incident->leg->fresh('shipment');
            if (data_get($incident->recovery_context, 'source_outcome.shipment_id')) {
                throw ValidationException::withMessages(['disposition' => 'A linked fulfillment attempt already exists. Continue or resolve that movement.']);
            }
            if ($original->shipment->source_type !== 'order' || $original->shipment->purpose !== 'retail_delivery'
                || $original->status->value !== 'cancelled' || $original->resolution_type !== 'returned'
                || $original->custody_state !== 'shop' || ! $original->custody_confirmed_at) {
                throw ValidationException::withMessages(['disposition' => 'Original-parcel redelivery requires an evidenced terminal shop return.']);
            }
            $order = Order::with('items')->where('shop_owner_id', $shop->id)->lockForUpdate()->findOrFail($original->shipment->source_id);
            if ($order->resolvedDeliveryMethod() !== 'shop_owned' || in_array($order->payment_status, ['refunded', 'failed'], true)
                || $order->refunds()->whereNotIn('status', ['rejected', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['disposition' => 'Resolve conflicting refund, payment or courier work before redelivery.']);
            }
            $inspection = data_get($incident->recovery_context, 'inspection', []);
            $findings = [...data_get($incident->recovery_context, 'findings', []), ...($data['findings'] ?? []),
                'mitigation_plan' => $data['findings']['mitigation_plan'] ?? $data['notes']];
            $eligibility = app(DeliveryIncidentService::class)->eligibility($incident, $findings)['retry'];
            if (! $eligibility['allowed']) throw ValidationException::withMessages(['findings' => $eligibility['reason']]);
            $items = collect($inspection['items'] ?? []);
            if ($order->items->isEmpty() || ($inspection['quarantined'] ?? true) || $items->count() !== $order->items->count()
                || $order->items->contains(fn ($item) => ! $items->contains(fn ($row) => $row['reference'] === 'order-item:'.$item->id
                    && (int) $row['quantity'] === (int) $item->quantity && $row['condition'] === 'safe'))) {
                throw ValidationException::withMessages(['inspection' => 'Verify every original item and exact quantity as safe; discrepancies remain quarantined.']);
            }
            // This dispatch decision authorizes the inspected revision; no separate readiness approval.
            $incident->forceFill(['recovery_context' => [...($incident->recovery_context ?? []), 'findings' => $findings,
                'readiness' => ['inspected_revision' => $inspection['revision'], 'actor_id' => $actor->id, 'ready_at' => now()->toISOString()]]])->save();
            $starts = app(DeliveryStartEligibility::class);
            $starts->validateRecoveryDate($shop, $data['delivery_date'], $data['delivery_window']);
            $appointment = $starts->appointmentChanges($original, $data['delivery_date'], $data['delivery_window'],
                (bool) ($data['appointment_confirmed'] ?? false), $data['appointment_agreement_note'] ?? null, $actor);
            $attempt = (int) Shipment::where('shop_owner_id', $shop->id)->where('source_type', 'order')->where('source_id', $order->id)
                ->where('purpose', 'retail_delivery')->lockForUpdate()->max('source_attempt') + 1;
            $shipment = $this->shipments->requestShipment(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => $order->id,
                'purpose' => 'retail_delivery', 'source_attempt' => $attempt, 'legs' => [[
                    'leg_type' => 'outbound', 'origin_snapshot' => $original->origin_snapshot, 'destination_snapshot' => $original->destination_snapshot,
                    'scheduled_delivery_date' => $data['delivery_date'], 'delivery_window' => $data['delivery_window'], 'schedule_status' => 'scheduled',
                    'requires_pickup_proof' => false, 'requires_delivery_proof' => true,
                ]]]);
            $leg = $shipment->legs()->firstOrFail();
            $leg->update([...$appointment, 'custody_state' => 'shop', 'custody_confirmed_at' => $original->custody_confirmed_at,
                ...($appointment ? [] : ['customer_appointment_date' => $original->customer_appointment_date, 'customer_appointment_window' => $original->customer_appointment_window])]);
            $rider = \App\Models\Logistics\RiderProfile::where('shop_owner_id', $shop->id)->findOrFail($data['rider_profile_id']);
            app(AssignmentService::class)->assignInternalRider($leg, $rider, $shop, ['incident_id' => $incident->id, 'original_shipment_id' => $original->shipment_id,
                'immediate_recovery' => $starts->immediateRecovery($shop, $data['delivery_date'], $data['delivery_window'], $data)], $actor);
            app(ShipmentCustodyService::class)->request($leg->fresh(), $actor, 'rider', $rider->id, (string) \Illuminate\Support\Str::uuid());
            // Original allocation, charges and COD/payment obligations remain authoritative on the same Order.
            return $shipment->fresh('legs.assignments');
        }, 3);
    }

    public function ensureRefundReturnShipment(OrderRefund $refund): Shipment
    {
        return DB::transaction(function () use ($refund): Shipment {
            $refund = OrderRefund::query()
                ->with('order.shopOwner', 'order.address', 'customer')
                ->lockForUpdate()
                ->findOrFail($refund->id);

            if ($refund->shop_owner_status !== 'approved' || $refund->finance_status !== 'approved') {
                throw ValidationException::withMessages([
                    'finance_status' => ['Staff and Finance approvals are required before arranging a return shipment.'],
                ]);
            }
            if ($refund->returnDeliveryMethod() === 'third_party') {
                throw ValidationException::withMessages([
                    'delivery_method' => ['Third-party returns do not create Shop-owned logistics shipments.'],
                ]);
            }
            if ($refund->returnDeliveryMethod() !== 'shop_owned') {
                throw ValidationException::withMessages([
                    'delivery_method' => ['Staff must select Shop-owned logistics before requesting a return shipment.'],
                ]);
            }
            if ($refund->return_source !== 'staff'
                || ! in_array($refund->return_status, ['pending_staff_pickup', 'in_transit'], true)) {
                throw ValidationException::withMessages([
                    'return_status' => ['Staff must arrange the Shop-owned return after both approvals.'],
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
                $shop = ShopOwner::query()->whereKey($refund->shop_owner_id)->lockForUpdate()->firstOrFail();
                $this->movements->assertExistingShipmentAllowed($shop, $existing);
                return $existing;
            }

            $order = $refund->order;
            $address = $order?->address;
            $sourceAttempt = (int) Shipment::query()
                ->where('source_type', 'order_refund')
                ->where('source_id', (int) $refund->id)
                ->where('purpose', 'refund_return')
                ->max('source_attempt') + 1;

            return $this->shipments->requestShipment([
                'shop_owner_id' => (int) $refund->shop_owner_id,
                'source_type' => 'order_refund',
                'source_id' => (int) $refund->id,
                'purpose' => 'refund_return',
                'source_attempt' => $sourceAttempt,
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
        });
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

            $existing = $this->findExisting('repair_request', (int) $lockedRepair->id, 'repair_pickup', (int) $shop->id);

            if ($existing && $existing->status->value !== 'cancelled') {
                $this->movements->assertExistingShipmentAllowed($shop, $existing);
                return $existing->load('legs');
            }
            $this->movements->assertCanStart($shop);

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
                    'shop_owner_id' => $lockedRepair->shop_owner_id,
                    'delivery_number' => ShipmentLeg::nextDeliveryNumber((int) $lockedRepair->shop_owner_id),
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

            $existing = $this->findExisting('repair_request', (int) $lockedRepair->id, 'repair_return', (int) $shop->id);
            if ($existing && $existing->status->value !== 'cancelled') {
                $this->movements->assertExistingShipmentAllowed($shop, $existing);
                return $existing->load('legs');
            }
            $this->movements->assertCanStart($shop);

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
                if ((int) ($requestedSchedule['appointment_agreed_by_user_id'] ?? 0) === (int) $lockedRepair->user_id) {
                    $schedule += ['customer_appointment_date' => $requestedSchedule['scheduled_delivery_date'],
                        'customer_appointment_window' => $requestedSchedule['delivery_window'],
                        'appointment_agreed_by_user_id' => $lockedRepair->user_id, 'appointment_agreed_at' => now(),
                        'appointment_agreement_note' => 'Customer selected this appointment in repair return recovery.'];
                }
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
                    'shop_owner_id' => $lockedRepair->shop_owner_id,
                    'delivery_number' => ShipmentLeg::nextDeliveryNumber((int) $lockedRepair->shop_owner_id),
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
                $received = $existing->legs()->where('resolution_type', 'returned')->where('custody_state', 'shop')
                    ->whereNotNull('custody_confirmed_at')->whereHas('incidents', fn ($query) => $query->whereNotNull('recovery_context'))->latest('id')->first();
                if ($received) $leg->update(['custody_state' => 'shop', 'custody_confirmed_at' => $received->custody_confirmed_at]);
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
        int $shopOwnerId,
        bool $activeOnly = false,
    ): ?Shipment
    {
        $shipment = Shipment::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('purpose', $purpose)
            ->when($activeOnly, fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->latest('id')
            ->first();
        if ($shipment && (int) $shipment->shop_owner_id !== $shopOwnerId) {
            throw ValidationException::withMessages([
                'shipment' => ['The shipment source is not available in this shop.'],
            ]);
        }
        return $shipment;
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
        if (! empty($schedule['customer_appointment_date'])) {
            $leg->update(\Illuminate\Support\Arr::only($schedule, ['customer_appointment_date', 'customer_appointment_window',
                'appointment_agreed_by_user_id', 'appointment_agreed_at', 'appointment_agreement_note']));
        }
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
