<?php

namespace App\Services;

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\RepairPaymentSession;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Logistics\DeliveryEventService;
use App\Services\Logistics\DeliveryScheduleService;
use App\Services\Logistics\SourceShipmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RepairDeliveryService
{
    public function __construct(
        private DeliveryScheduleService $schedules,
        private ShippingEstimateService $shipping,
        private SourceShipmentService $sourceShipments,
        private NotificationService $notifications,
        private DeliveryEventService $events,
    ) {}

    public function snapshot(UserAddress $address, string $method): array
    {
        $snapshot = [
            'address_id' => (int) $address->id,
            'name' => (string) $address->name,
            'phone' => (string) $address->phone,
            'address_line' => (string) $address->address_line,
            'barangay' => (string) $address->barangay,
            'city' => (string) $address->city,
            'province' => (string) $address->province,
            'region' => (string) $address->region,
            'postal_code' => $address->postal_code,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'delivery_instructions' => $address->delivery_instructions,
            'method' => $method,
        ];
        $snapshot['version'] = $this->version($snapshot, $method);

        return $snapshot;
    }

    public function version(array $snapshot, string $method): string
    {
        unset($snapshot['version']);
        $snapshot['method'] = $method;
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function quote(ShopOwner $shop, UserAddress $address): array
    {
        $coverage = $this->schedules->coverage($shop, $address->latitude, $address->longitude);
        if (! $coverage['available']) {
            return [...$coverage, 'fee' => null, 'estimate' => null];
        }

        $estimate = $this->shipping->calculate((float) $coverage['distance_km']);

        return [...$coverage, 'fee' => $estimate['max_fee'], 'estimate' => $estimate];
    }

    public function paymentDetails(RepairRequest $repair, string $leg): array
    {
        $isIntake = $leg === 'intake';
        $method = (string) ($isIntake ? $repair->intake_delivery_method : $repair->return_delivery_method);
        $snapshot = $isIntake ? $repair->intake_address : $repair->return_address;
        $storedFee = round((float) ($isIntake ? $repair->intake_delivery_fee : $repair->return_delivery_fee), 2);
        $shopOwned = $method === ($isIntake ? 'shop_pickup' : 'shop_delivery');

        if (! $shopOwned) {
            return [
                'leg' => $leg,
                'method' => $method,
                'snapshot_version' => is_array($snapshot) ? ($snapshot['version'] ?? null) : null,
                'delivery_amount' => 0.0,
                'quote' => null,
            ];
        }

        $field = $isIntake ? 'intake_address' : 'return_address';
        $addressId = is_array($snapshot) ? (int) ($snapshot['address_id'] ?? 0) : 0;
        $address = UserAddress::query()
            ->whereKey($addressId)
            ->where('user_id', $repair->user_id)
            ->first();

        if (! $address || ! $repair->shopOwner) {
            throw ValidationException::withMessages([
                $field => ['The selected delivery address is no longer available. Please review the delivery plan.'],
            ]);
        }

        $currentSnapshot = $this->snapshot($address, $method);
        if (! hash_equals((string) ($snapshot['version'] ?? ''), (string) $currentSnapshot['version'])) {
            throw ValidationException::withMessages([
                $field => ['The delivery address changed. Please review and confirm the latest pinned address before paying.'],
            ]);
        }

        $quote = $this->quote($repair->shopOwner, $address);
        if (! ($quote['available'] ?? false)) {
            throw ValidationException::withMessages([
                $field => [($quote['reason'] ?? null) === 'outside_coverage'
                    ? 'The address is now outside shop delivery coverage. Choose another delivery method.'
                    : 'Shop-owned delivery is currently unavailable for this address.'],
            ]);
        }

        $currentFee = round((float) ($quote['fee'] ?? 0), 2);
        if ($currentFee !== $storedFee) {
            throw ValidationException::withMessages([
                $field => ['The delivery fee changed. Please refresh the delivery plan before paying.'],
            ]);
        }

        return [
            'leg' => $leg,
            'method' => $method,
            'snapshot_version' => $currentSnapshot['version'],
            'delivery_amount' => $currentFee,
            'quote' => $quote,
        ];
    }

    public function recordPickupRecovery(RepairRequest $repair, int $shipmentId, int $failedLegId): ?array
    {
        if (! $this->isSponsoredWarranty($repair)) {
            return null;
        }

        $reconciliation = is_array($repair->logistics_payment_reconciliation)
            ? $repair->logistics_payment_reconciliation
            : [];
        $entries = collect(data_get($reconciliation, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry))
            ->values();
        $existing = $entries->first(fn (array $entry): bool =>
            (string) ($entry['type'] ?? '') === 'pickup_recovery'
            && (int) ($entry['shipment_id'] ?? 0) === $shipmentId
            && (int) ($entry['failed_leg_id'] ?? 0) === $failedLegId
        );
        if ($existing) {
            return $existing;
        }

        $entry = [
            'type' => 'pickup_recovery',
            'status' => 'awaiting_arrangement',
            'shipment_id' => $shipmentId,
            'failed_leg_id' => $failedLegId,
            'created_at' => now()->toISOString(),
        ];
        $entries->push($entry);
        $repair->update([
            'logistics_payment_reconciliation' => [
                ...$reconciliation,
                'status' => (string) data_get($reconciliation, 'status', 'resolved'),
                'entries' => $entries->all(),
            ],
        ]);

        return $entry;
    }

    public function activePickupRecovery(RepairRequest $repair, ?string $status = null): ?array
    {
        return collect(data_get($repair->logistics_payment_reconciliation, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry)
                && (string) ($entry['type'] ?? '') === 'pickup_recovery'
                && ($status === null || (string) ($entry['status'] ?? '') === $status))
            ->sortByDesc('updated_at')
            ->sortByDesc('created_at')
            ->first();
    }

    public function markPickupRecoveryPaid(RepairRequest $repair, string $recoveryKey, string $planKey): RepairRequest
    {
        $current = is_array($repair->logistics_payment_reconciliation)
            ? $repair->logistics_payment_reconciliation
            : [];
        $entries = collect(data_get($current, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry))
            ->values();
        $index = $entries->search(fn (array $entry): bool =>
            (string) ($entry['type'] ?? '') === 'pickup_recovery'
            && (string) ($entry['status'] ?? '') === 'awaiting_payment'
            && (string) ($entry['recovery_key'] ?? '') === $recoveryKey
            && (string) ($entry['plan_key'] ?? '') === $planKey
        );
        if ($index === false) {
            throw ValidationException::withMessages([
                'payment' => ['This pickup request is no longer awaiting payment.'],
            ]);
        }

        $entries->put($index, [
            ...$entries->get($index),
            'status' => 'paid',
            'paid_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ]);
        $repair->update([
            'status' => 'repairer_accepted',
            'payment_enabled' => false,
            'payment_enabled_at' => null,
            'logistics_payment_reconciliation' => [
                ...$current,
                'status' => 'resolved',
                'entries' => $entries->all(),
            ],
        ]);

        return $repair->fresh();
    }

    public function resolvePickupRecovery(
        RepairRequest $repair,
        string $method,
        string $actorType,
        int $actorId,
        ?int $addressId = null,
        ?string $deliveryDate = null,
        ?string $deliveryWindow = null,
    ): array {
        if (! in_array($method, ['shop_pickup', 'walk_in', 'customer_delivery'], true)) {
            throw ValidationException::withMessages(['method' => ['Choose shop pickup, walk-in, or your own courier.']]);
        }

        $result = DB::transaction(function () use (
            $repair,
            $method,
            $actorType,
            $actorId,
            $addressId,
            $deliveryDate,
            $deliveryWindow,
        ): array {
            $locked = RepairRequest::query()
                ->with('shopOwner')
                ->whereKey($repair->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($actorType !== User::class || $actorId !== (int) $locked->user_id) {
                throw ValidationException::withMessages(['actor' => ['Only the customer can choose the pickup arrangement.']]);
            }
            if (! $this->isSponsoredWarranty($locked)) {
                throw ValidationException::withMessages(['status' => ['Pickup recovery is available only for warranty repairs.']]);
            }

            $reconciliation = is_array($locked->logistics_payment_reconciliation)
                ? $locked->logistics_payment_reconciliation
                : [];
            $entries = collect(data_get($reconciliation, 'entries', []))
                ->filter(fn ($entry): bool => is_array($entry))
                ->values();
            $index = $entries->search(fn (array $entry): bool =>
                (string) ($entry['type'] ?? '') === 'pickup_recovery'
            );
            if ($index === false) {
                throw ValidationException::withMessages(['status' => ['This repair is not awaiting a pickup arrangement.']]);
            }
            $entry = $entries->get($index);
            $address = null;
            $snapshot = null;
            $quote = null;
            $fee = 0.0;
            if ($method !== 'walk_in') {
                $address = UserAddress::query()
                    ->whereKey($addressId)
                    ->where('user_id', $actorId)
                    ->first();
                if (! $address) {
                    throw ValidationException::withMessages(['address_id' => ['Choose one of your saved addresses.']]);
                }
                $snapshot = $this->snapshot($address, $method);
            }
            if ($method === 'shop_pickup') {
                $quote = $this->quote($locked->shopOwner, $address);
                if (! ($quote['available'] ?? false)) {
                    throw ValidationException::withMessages(['address_id' => [
                        ($quote['reason'] ?? null) === 'outside_coverage'
                            ? 'This address is outside shop pickup coverage.'
                            : 'Shop pickup is not available for this address.',
                    ]]);
                }
                $fee = round((float) ($quote['fee'] ?? 0), 2);
            }

            $recoveryKey = (string) ($entry['recovery_key'] ?? "pickup-recovery:{$entry['failed_leg_id']}");
            $planKey = hash('sha256', json_encode([
                'method' => $method,
                'address_version' => data_get($snapshot, 'version'),
                'delivery_date' => $method === 'shop_pickup' ? $deliveryDate : null,
                'delivery_window' => $method === 'shop_pickup' ? $deliveryWindow : null,
                'fee' => $fee,
            ], JSON_UNESCAPED_SLASHES));
            $currentStatus = (string) ($entry['status'] ?? 'awaiting_arrangement');
            if ((string) ($entry['plan_key'] ?? '') === $planKey) {
                return ['repair' => $locked, 'recovery' => $entry, 'notify' => false];
            }
            if (in_array($currentStatus, ['resolved', 'paid'], true)) {
                abort(409, 'This pickup recovery plan is already final.');
            }

            RepairPaymentSession::query()
                ->where('repair_request_id', $locked->id)
                ->where('phase', 'pickup_retry')
                ->where('status', 'pending')
                ->update(['status' => 'invalidated', 'invalidated_at' => now()]);

            $updatedEntry = [
                ...$entry,
                'status' => $method === 'shop_pickup' ? 'awaiting_payment' : 'resolved',
                'action' => $method,
                'recovery_key' => $recoveryKey,
                'plan_key' => $planKey,
                'address_version' => data_get($snapshot, 'version'),
                'delivery_amount' => $fee,
                'quote' => $quote,
                'scheduled_delivery_date' => $method === 'shop_pickup' ? $deliveryDate : null,
                'delivery_window' => $method === 'shop_pickup' ? $deliveryWindow : null,
                'selected_by_type' => $actorType,
                'selected_by_id' => $actorId,
                'updated_at' => now()->toISOString(),
            ];
            $entries->put($index, $updatedEntry);
            $shipment = Shipment::query()->find((int) ($entry['shipment_id'] ?? 0));
            $lockAt = now();
            if ($shipment?->cancelled_at && $lockAt->timestamp <= $shipment->cancelled_at->timestamp) {
                $lockAt = $shipment->cancelled_at->copy()->addSecond();
            }
            $updates = [
                'delivery_method' => $method === 'walk_in' ? 'walk_in' : 'pickup',
                'intake_delivery_method' => $method,
                'intake_address' => $snapshot,
                'pickup_address' => $snapshot,
                'intake_delivery_fee' => $fee,
                'intake_logistics_quote' => $quote,
                'intake_logistics_locked_at' => $method === 'shop_pickup' ? null : $lockAt,
                'payment_enabled' => $method === 'shop_pickup',
                'payment_enabled_at' => $method === 'shop_pickup' ? now() : null,
                'paymongo_link_id' => null,
                'status' => $method === 'shop_pickup' ? 'cancelled' : 'repairer_accepted',
                'logistics_payment_reconciliation' => [
                    ...$reconciliation,
                    'status' => 'resolved',
                    'entries' => $entries->all(),
                ],
            ];
            $locked->update($updates);

            return [
                'repair' => $locked->fresh(),
                'recovery' => $updatedEntry,
                'notify' => true,
            ];
        }, 3);

        if ($result['notify']) {
            $this->notifications->notifyRepairPickupRecovery(
                $result['repair'],
                (string) $result['recovery']['status'],
                (string) $result['recovery']['plan_key'],
            );
        }

        return $result;
    }

    public function tryCreateIntakeShipment(RepairRequest $repair): ?Shipment
    {
        $createdCompensation = null;
        $shipment = DB::transaction(function () use ($repair, &$createdCompensation): ?Shipment {
            $lockedRepair = RepairRequest::query()
                ->with('shopOwner')
                ->whereKey($repair->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedRepair->intake_delivery_method !== 'shop_pickup'
                || ! $this->hasAcceptedIntake($lockedRepair)
                || $lockedRepair->intake_logistics_locked_at === null) {
                return null;
            }

            $existing = Shipment::query()
                ->where('source_type', 'repair_request')
                ->where('source_id', $lockedRepair->id)
                ->where('purpose', 'repair_pickup')
                ->first();

            if ($existing && $existing->status->value !== 'cancelled') {
                return $existing->load('legs');
            }

            $sponsoredWarranty = $this->isSponsoredWarranty($lockedRepair);
            $reconciliationStatus = data_get($lockedRepair->logistics_payment_reconciliation, 'status');
            if (! $sponsoredWarranty
                && ($reconciliationStatus === 'pending'
                    || ! $this->hasFreshIntakePaymentAfterCompensation($lockedRepair))) {
                return null;
            }
            if ($existing) {
                $cancelledAt = $existing->cancelled_at ?? $existing->updated_at;
                if ((! $sponsoredWarranty && $reconciliationStatus !== 'resolved')
                    || $cancelledAt === null
                    || ! $lockedRepair->intake_logistics_locked_at->greaterThan($cancelledAt)) {
                    return null;
                }
            }

            $snapshot = is_array($lockedRepair->intake_address) ? $lockedRepair->intake_address : [];
            $coverage = $this->schedules->coverage(
                $lockedRepair->shopOwner,
                isset($snapshot['latitude']) ? (float) $snapshot['latitude'] : null,
                isset($snapshot['longitude']) ? (float) $snapshot['longitude'] : null,
            );

            if (! ($coverage['available'] ?? false)) {
                $createdCompensation = $this->startIntakeCompensation($lockedRepair, $coverage);
                if ($sponsoredWarranty) {
                    $lockedRepair->update(['intake_logistics_locked_at' => null]);
                }

                return null;
            }

            if ($existing) {
                $recovery = $this->activePickupRecovery($lockedRepair, 'paid');
                $address = is_array($lockedRepair->intake_address) ? $lockedRepair->intake_address : [];
                $leg = $existing->legs()->create([
                    'sequence' => ((int) $existing->legs()->max('sequence')) + 1,
                    'leg_type' => 'inbound',
                    'status' => 'pending',
                    'origin_snapshot' => [
                        'type' => 'customer',
                        'name' => (string) ($lockedRepair->customer_name ?? 'Customer'),
                        'phone' => (string) ($lockedRepair->phone ?? ''),
                        'address' => collect([
                            $address['address_line'] ?? null,
                            $address['barangay'] ?? null,
                            $address['city'] ?? null,
                            $address['province'] ?? $address['region'] ?? null,
                            $address['postal_code'] ?? null,
                        ])->filter()->implode(', '),
                        'latitude' => $address['latitude'] ?? null,
                        'longitude' => $address['longitude'] ?? null,
                        'delivery_instructions' => $address['delivery_instructions'] ?? null,
                    ],
                    'destination_snapshot' => $existing->legs()->first()?->destination_snapshot,
                    'requires_pickup_proof' => false,
                    'requires_delivery_proof' => true,
                    'scheduled_delivery_date' => $recovery['scheduled_delivery_date'] ?? null,
                    'delivery_window' => $recovery['delivery_window'] ?? null,
                    'schedule_status' => 'scheduled',
                    'distance_km' => data_get($recovery, 'quote.distance_km'),
                    'estimated_at' => now(),
                ]);
                $existing->update([
                    'status' => 'requested',
                    'cancelled_at' => null,
                    'completed_at' => null,
                ]);
                $this->events->record($existing, $leg, [
                    'event_type' => 'shipment_pickup_retry_requested',
                    'visibility' => 'customer',
                    'message' => 'Your new pickup has been scheduled.',
                ]);

                return $existing->fresh(['legs', 'events']);
            }

            return $this->sourceShipments->ensureRepairInboundShipment($lockedRepair);
        }, 5);

        $this->notifyNewCompensation($repair, $createdCompensation);

        return $shipment;
    }

    public function tryCreateReturnShipment(RepairRequest $repair): ?Shipment
    {
        $createdCompensation = null;
        $shipment = DB::transaction(function () use ($repair, &$createdCompensation): ?Shipment {
            $lockedRepair = RepairRequest::query()
                ->with('shopOwner')
                ->whereKey($repair->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedRepair->return_delivery_method !== 'shop_delivery'
                || ! in_array((string) $lockedRepair->status, ['ready_for_pickup', 'ready-for-pickup', 'shipped'], true)
                || $lockedRepair->return_logistics_locked_at === null
                || (string) $lockedRepair->payment_status !== 'completed'
                || (string) $lockedRepair->return_address_confirmed_version === ''
                || (string) $lockedRepair->return_address_confirmed_version
                    !== (string) data_get($lockedRepair->return_address, 'version', '')) {
                return null;
            }

            $existing = Shipment::query()
                ->where('source_type', 'repair_request')
                ->where('source_id', $lockedRepair->id)
                ->where('purpose', 'repair_return')
                ->first();
            if ($existing && $existing->status->value !== 'cancelled') {
                if ((string) $lockedRepair->status !== 'shipped') {
                    $lockedRepair->update(['status' => 'shipped', 'shipped_at' => now()]);
                }

                return $existing->load('legs');
            }

            $sponsoredWarranty = $this->isSponsoredWarranty($lockedRepair);
            $reconciliationStatus = data_get($lockedRepair->logistics_payment_reconciliation, 'status');
            $recovery = $existing ? $this->returnRecoveryState($lockedRepair) : null;
            $paidRecovery = $existing
                ? $this->activeRedeliveryRequirement($lockedRepair, 'paid')
                : null;
            if (($recovery && ! $paidRecovery)
                || (! $paidRecovery
                && ! $sponsoredWarranty
                && ($reconciliationStatus === 'pending'
                    || ! $this->hasFreshReturnPaymentAfterCompensation($lockedRepair)))) {
                return null;
            }
            if ($existing) {
                $cancelledAt = $existing->cancelled_at ?? $existing->updated_at;
                if ((! $paidRecovery && ! $sponsoredWarranty && $reconciliationStatus !== 'resolved')
                    || $cancelledAt === null
                    || ! $lockedRepair->return_logistics_locked_at->greaterThan($cancelledAt)) {
                    return null;
                }
            }

            $snapshot = is_array($lockedRepair->return_address) ? $lockedRepair->return_address : [];
            $coverage = $this->schedules->coverage(
                $lockedRepair->shopOwner,
                isset($snapshot['latitude']) ? (float) $snapshot['latitude'] : null,
                isset($snapshot['longitude']) ? (float) $snapshot['longitude'] : null,
            );
            if (! ($coverage['available'] ?? false)) {
                $createdCompensation = $this->startReturnCompensation($lockedRepair, $coverage);
                if ($sponsoredWarranty) {
                    $lockedRepair->update([
                        'return_logistics_locked_at' => null,
                        'return_address_confirmed_at' => null,
                        'return_address_confirmed_version' => null,
                    ]);
                }

                return null;
            }

            $shipment = $this->sourceShipments->ensureRepairReturnShipment(
                $lockedRepair,
                $paidRecovery
                    ? [
                        'scheduled_delivery_date' => $paidRecovery['scheduled_delivery_date'] ?? null,
                        'delivery_window' => $paidRecovery['delivery_window'] ?? null,
                    ]
                    : null,
            );
            $lockedRepair->update([
                'status' => 'shipped',
                'shipped_at' => $lockedRepair->shipped_at ?? now(),
                'pickup_enabled' => false,
                'pickup_enabled_at' => null,
                'pickup_enabled_by' => null,
            ]);

            return $shipment;
        }, 5);

        $this->notifyNewCompensation($repair, $createdCompensation);

        return $shipment;
    }

    public function hasApprovedProof(RepairRequest $repair, string $purpose): bool
    {
        return (bool) ($this->handoffState($repair, $purpose)['approved'] ?? false);
    }

    public function cancelPaidDeliveryLeg(
        RepairRequest $repair,
        string $phase,
        string $reason,
        int $actorId,
        ?int $targetShipmentLegId,
        string $targetPlanToken,
        bool $requireFailedPickup = false,
    ): array {
        $createdCompensation = null;
        $result = DB::transaction(function () use (
            $repair,
            $phase,
            $reason,
            $actorId,
            $targetShipmentLegId,
            $targetPlanToken,
            $requireFailedPickup,
            &$createdCompensation,
        ): array {
            $lockedRepair = RepairRequest::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $sponsoredWarranty = $this->isSponsoredWarranty($lockedRepair);
            $isIntake = $phase === 'intake';
            $method = (string) ($isIntake
                ? $lockedRepair->intake_delivery_method
                : $lockedRepair->return_delivery_method);
            $lockField = $isIntake ? 'intake_logistics_locked_at' : 'return_logistics_locked_at';
            $feeField = $isIntake ? 'intake_delivery_fee' : 'return_delivery_fee';
            $snapshot = $isIntake ? $lockedRepair->intake_address : $lockedRepair->return_address;
            $expectedMethod = $isIntake ? 'shop_pickup' : 'shop_delivery';
            $shipment = Shipment::query()
                ->where('source_type', 'repair_request')
                ->where('source_id', $lockedRepair->id)
                ->where('purpose', $isIntake ? 'repair_pickup' : 'repair_return')
                ->lockForUpdate()
                ->first();
            $activeLeg = $shipment
                ? ShipmentLeg::query()
                    ->where('shipment_id', $shipment->id)
                    ->where('status', '!=', 'cancelled')
                    ->latest('sequence')
                    ->lockForUpdate()
                    ->first()
                : null;
            $currentPlanToken = $this->cancellationPlanToken($lockedRepair, $phase);

            if (
                $currentPlanToken === null
                || ! hash_equals($currentPlanToken, $targetPlanToken)
                || $activeLeg?->id !== $targetShipmentLegId
            ) {
                return [
                    'repair' => $lockedRepair->fresh(),
                    'reconciliation' => $lockedRepair->logistics_payment_reconciliation,
                    'created' => false,
                    'replayed' => true,
                ];
            }

            if ($method !== $expectedMethod || $lockedRepair->{$lockField} === null || (float) $lockedRepair->{$feeField} <= 0) {
                throw ValidationException::withMessages([
                    $phase => ['Only a paid, locked shop-owned delivery leg can be cancelled.'],
                ]);
            }

            $existingEntry = $this->currentPendingCompensation($lockedRepair, $phase);
            if ($existingEntry) {
                return [
                    'repair' => $lockedRepair->fresh(),
                    'reconciliation' => $lockedRepair->logistics_payment_reconciliation,
                    'created' => false,
                    'replayed' => true,
                ];
            }

            $failedPickup = $isIntake
                && $activeLeg?->status->value === 'needs_resolution'
                && $activeLeg->resolution_type === 'pickup_failed'
                && $activeLeg->picked_up_at === null;
            if ($requireFailedPickup && ! $failedPickup) {
                throw ValidationException::withMessages([
                    'status' => ['This failed pickup was already changed. Refresh and try again.'],
                ]);
            }
            if ($activeLeg
                && ! in_array($activeLeg->status->value, ['pending', 'assigned', 'pickup_scheduled'], true)
                && ! $failedPickup) {
                throw ValidationException::withMessages([
                    'status' => ['This delivery can no longer be cancelled because rider custody or delivery processing already started.'],
                ]);
            }

            if ($activeLeg?->delivery_batch_id) {
                $batch = DeliveryBatch::query()->whereKey($activeLeg->delivery_batch_id)->lockForUpdate()->first();
                if ($batch?->status === 'in_progress') {
                    throw ValidationException::withMessages([
                        'status' => ['This delivery can no longer be cancelled because its rider batch already started.'],
                    ]);
                }
            }

            if ($activeLeg) {
                $activeLeg->assignments()
                    ->whereIn('status', ['assigned', 'accepted'])
                    ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                $batchId = $activeLeg->delivery_batch_id;
                $activeLeg->update([
                    'status' => 'cancelled',
                    'delivery_batch_id' => null,
                    'stop_sequence' => null,
                ]);

                if ($batchId) {
                    $batch = DeliveryBatch::query()->whereKey($batchId)->lockForUpdate()->first();
                    $remaining = $batch?->legs()->where('status', '!=', 'cancelled')->count() ?? 0;
                    $batch?->update($remaining > 0
                        ? ['assigned_stop_count' => $remaining]
                        : [
                            'status' => 'cancelled',
                            'assigned_stop_count' => 0,
                            'cancelled_at' => now(),
                            'cancellation_reason' => 'All delivery stops were cancelled before pickup.',
                        ]);
                }

                if ($failedPickup) {
                    $this->events->record($shipment, $activeLeg, [
                        'event_type' => 'pickup_cancelled',
                        'visibility' => 'customer',
                        'message' => 'The scheduled pickup was cancelled.',
                    ]);
                }
            }

            if ($shipment) {
                $shipment->update([
                    'status' => 'cancelled',
                    'completed_at' => null,
                    'cancelled_at' => now(),
                ]);
            }

            if (! $isIntake && (string) $lockedRepair->status === 'shipped') {
                $lockedRepair->update([
                    'status' => 'ready_for_pickup',
                    'shipped_at' => null,
                    'pickup_enabled' => false,
                    'pickup_enabled_at' => null,
                    'pickup_enabled_by' => null,
                ]);
            }

            if ($sponsoredWarranty) {
                $lockedRepair->update([
                    $lockField => null,
                    ...(! $isIntake ? [
                        'return_address_confirmed_at' => null,
                        'return_address_confirmed_version' => null,
                    ] : []),
                ]);
            }

            $createdCompensation = $this->recordCompensation(
                repair: $lockedRepair->fresh(),
                phase: $phase,
                reason: "{$phase}_staff_cancelled",
                details: [
                    'cancelled_by' => $actorId,
                    'cancellation_reason' => trim($reason),
                    'shipment_id' => $shipment?->id,
                    'shipment_leg_id' => $activeLeg?->id,
                ],
            );
            $lockedRepair->refresh();

            return [
                'repair' => $lockedRepair,
                'reconciliation' => $lockedRepair->logistics_payment_reconciliation,
                'created' => $createdCompensation !== null,
                'replayed' => false,
            ];
        }, 5);

        $this->notifyNewCompensation($repair, $createdCompensation);

        return $result;
    }

    public function intakeHandoff(RepairRequest $repair, bool $paymentSatisfied): array
    {
        $method = (string) ($repair->intake_delivery_method ?: 'customer_delivery');
        $state = $method === 'shop_pickup'
            ? $this->handoffState($repair, 'repair_pickup')
            : [
                'shipment' => null,
                'leg' => null,
                'proof' => null,
                'approved' => false,
                'events' => collect(),
            ];
        $canConfirm = in_array((string) $repair->status, ['pending', 'repairer_accepted'], true)
            && $paymentSatisfied
            && ($method !== 'shop_pickup' || $state['approved']);

        $blockedReason = null;
        if (! in_array((string) $repair->status, ['pending', 'repairer_accepted'], true)) {
            $blockedReason = 'This repair is not awaiting physical intake receipt.';
        } elseif (! $paymentSatisfied) {
            $blockedReason = 'Initial payment must be settled before physical receipt.';
        } elseif ($method === 'shop_pickup' && ! $state['shipment']) {
            $blockedReason = 'Waiting for the shop pickup to be dispatched.';
        } elseif ($method === 'shop_pickup' && ! $state['approved']) {
            $blockedReason = 'Waiting for Dispatcher approval of the rider delivery proof.';
        }

        return [
            'shipment_id' => $state['shipment']?->id,
            'shipment_status' => $state['shipment']?->status?->value,
            'leg_id' => $state['leg']?->id,
            'leg_status' => $state['leg']?->status?->value,
            'proof_status' => $state['proof']?->review_status,
            'can_confirm_receipt' => $canConfirm,
            'blocked_reason' => $blockedReason,
            'scheduled_delivery_date' => $state['leg']?->scheduled_delivery_date?->toDateString(),
            'delivery_window' => $state['leg']?->delivery_window,
            'cancellation_target' => $this->cancellationTarget($repair, 'intake', $state['leg']),
            'events' => $state['events']->map(fn ($event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'label' => $event->message ?: str_replace('_', ' ', ucfirst($event->event_type)),
                'message' => $event->message,
                'occurred_at' => optional($event->created_at)->toISOString(),
                'created_at' => optional($event->created_at)->toISOString(),
            ])->values()->all(),
        ];
    }

    public function returnHandoff(RepairRequest $repair, bool $paymentSatisfied): array
    {
        $recovery = $this->returnRecoveryState($repair);
        $visible = ! (
            (string) $repair->status === 'cancelled'
            && $repair->received_at === null
            && ! $this->hasApprovedProof($repair, 'repair_pickup')
        );
        $method = match ((string) ($repair->return_delivery_method ?: 'customer_pickup')) {
            'pickup' => 'customer_pickup',
            default => (string) ($repair->return_delivery_method ?: 'customer_pickup'),
        };
        $state = $method === 'shop_delivery'
            ? $this->handoffState($repair, 'repair_return')
            : [
                'shipment' => null,
                'leg' => null,
                'proof' => null,
                'approved' => false,
                'events' => collect(),
            ];
        $expectedStatuses = $method === 'walk_in'
            ? ['ready_for_pickup', 'ready-for-pickup']
            : ($method === 'customer_pickup'
                ? ['ready_for_pickup', 'ready-for-pickup']
                : ['shipped']);
        $sponsoredNonShopPlan = $method !== 'shop_delivery' && $this->isSponsoredWarranty($repair);
        $canRelease = in_array((string) $repair->status, $expectedStatuses, true)
            && $paymentSatisfied
            && ! (bool) $repair->pickup_enabled
            && ($method === 'shop_delivery' || $repair->return_logistics_locked_at === null || $sponsoredNonShopPlan)
            && ($method !== 'shop_delivery' || $state['approved']);

        $blockedReason = null;
        if ((bool) $repair->pickup_enabled
            || ($method !== 'shop_delivery'
                && $repair->return_logistics_locked_at !== null
                && ! $sponsoredNonShopPlan)) {
            $blockedReason = 'Customer receipt confirmation is already active.';
        } elseif (! in_array((string) $repair->status, $expectedStatuses, true)) {
            $blockedReason = $method === 'shop_delivery'
                ? 'Waiting for the repaired item to be dispatched.'
                : 'This repair is not ready for release.';
        } elseif (! $paymentSatisfied) {
            $blockedReason = 'Final payment must be settled before release.';
        } elseif ($method === 'shop_delivery' && ! $state['shipment']) {
            $blockedReason = 'Waiting for the shop return delivery to be dispatched.';
        } elseif ($method === 'shop_delivery' && ! $state['approved']) {
            $blockedReason = 'Waiting for Dispatcher approval of the rider delivery proof.';
        }

        return [
            'visible' => $visible,
            'recovery' => $visible ? $recovery : null,
            'method' => $method,
            'can_release' => $canRelease,
            'can_confirm_receipt' => (bool) $repair->pickup_enabled
                && $repair->return_logistics_locked_at !== null
                && ($method !== 'shop_delivery' || $state['approved']),
            'action_label' => match ($method) {
                'walk_in' => 'Release to customer',
                'shop_delivery' => 'Confirm delivered handoff',
                default => 'Confirm courier handoff',
            },
            'blocked_reason' => $blockedReason,
            'external_tracking' => data_get($repair->return_address, 'external_tracking'),
            'shipment_id' => $state['shipment']?->id,
            'shipment_status' => $state['shipment']?->status?->value,
            'leg_id' => $state['leg']?->id,
            'leg_status' => $state['leg']?->status?->value,
            'proof_status' => $state['proof']?->review_status,
            'cancellation_target' => $this->cancellationTarget($repair, 'return', $state['leg']),
            'events' => $state['events']->map(fn ($event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'label' => $event->message ?: str_replace('_', ' ', ucfirst($event->event_type)),
                'message' => $event->message,
                'occurred_at' => optional($event->created_at)->toISOString(),
                'created_at' => optional($event->created_at)->toISOString(),
            ])->values()->all(),
        ];
    }

    public function resolveReturnRecovery(
        RepairRequest $repair,
        string $action,
        string $actorType,
        int $actorId,
        ?string $scheduledDeliveryDate = null,
        ?string $deliveryWindow = null,
    ): array {
        if (! in_array($action, ['schedule_redelivery', 'shop_pickup'], true)) {
            throw ValidationException::withMessages([
                'action' => ['Choose re-delivery or shop pickup.'],
            ]);
        }

        $result = DB::transaction(function () use (
            $repair,
            $action,
            $actorType,
            $actorId,
            $scheduledDeliveryDate,
            $deliveryWindow,
        ): array {
            $locked = RepairRequest::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            if ($actorType !== User::class || $actorId !== (int) $locked->user_id) {
                throw ValidationException::withMessages([
                    'actor' => ['Only the customer can choose the return arrangement.'],
                ]);
            }

            $recovery = $this->returnRecoveryState($locked);
            if (! $recovery) {
                throw ValidationException::withMessages([
                    'status' => ['This repair is not awaiting a return arrangement.'],
                ]);
            }

            $key = (string) $recovery['key'];
            $current = is_array($locked->logistics_payment_reconciliation)
                ? $locked->logistics_payment_reconciliation
                : [];
            if ((string) data_get($current, 'status') === 'pending') {
                throw ValidationException::withMessages([
                    'payment' => ['Resolve the current payment reconciliation before changing the return plan.'],
                ]);
            }
            $entries = collect(data_get($current, 'entries', []))
                ->filter(fn ($entry): bool => is_array($entry))
                ->values();
            $entryIndex = $entries->search(
                fn (array $entry): bool => (string) ($entry['type'] ?? '') === 'return_recovery'
                    && (string) ($entry['recovery_key'] ?? '') === $key
            );
            $entry = $entryIndex === false ? null : $entries->get($entryIndex);

            if ($action === 'schedule_redelivery') {
                if ((string) ($entry['status'] ?? '') === 'shop_pickup_selected') {
                    throw ValidationException::withMessages([
                        'status' => ['This repair is already set for shop pickup.'],
                    ]);
                }

                $updatedEntry = [
                    ...($entry ?? []),
                    'type' => 'return_recovery',
                    'phase' => 'return',
                    'action' => 'collect_redelivery_fee',
                    'status' => 'awaiting_payment',
                    'recovery_key' => $key,
                    'selected_by_type' => $actorType,
                    'selected_by_id' => $actorId,
                    'scheduled_delivery_date' => $scheduledDeliveryDate,
                    'delivery_window' => $deliveryWindow,
                    'created_at' => $entry['created_at'] ?? now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ];
                if ($entryIndex === false) {
                    $entries->push($updatedEntry);
                } else {
                    $entries->put($entryIndex, $updatedEntry);
                }

                $locked->update([
                    'status' => 'ready_for_pickup',
                    'return_delivery_method' => 'shop_delivery',
                    'return_logistics_locked_at' => null,
                    'return_address_confirmed_at' => null,
                    'return_address_confirmed_version' => null,
                    'payment_enabled' => true,
                    'logistics_payment_reconciliation' => [
                        ...$current,
                        'status' => 'resolved',
                        'entries' => $entries->values()->all(),
                    ],
                ]);
            } else {
                $paid = RepairPaymentSession::query()
                    ->where('repair_request_id', $locked->id)
                    ->where('phase', 'redelivery')
                    ->where('status', 'paid')
                    ->get()
                    ->contains(fn (RepairPaymentSession $session): bool => (string) data_get(
                        $session->quote,
                        'recovery_key',
                    ) === $key);
                if ($paid) {
                    throw ValidationException::withMessages([
                        'payment' => ['This re-delivery fee is already paid and cannot be switched to shop pickup.'],
                    ]);
                }

                RepairPaymentSession::query()
                    ->where('repair_request_id', $locked->id)
                    ->where('phase', 'redelivery')
                    ->where('status', 'pending')
                    ->get()
                    ->filter(fn (RepairPaymentSession $session): bool => (string) data_get(
                        $session->quote,
                        'recovery_key',
                    ) === $key)
                    ->each->update(['status' => 'invalidated', 'invalidated_at' => now()]);

                $updatedEntry = [
                    ...($entry ?? []),
                    'type' => 'return_recovery',
                    'phase' => 'return',
                    'action' => 'shop_pickup',
                    'status' => 'shop_pickup_selected',
                    'recovery_key' => $key,
                    'selected_by_type' => $actorType,
                    'selected_by_id' => $actorId,
                    'scheduled_delivery_date' => null,
                    'delivery_window' => null,
                    'created_at' => $entry['created_at'] ?? now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ];
                if ($entryIndex === false) {
                    $entries->push($updatedEntry);
                } else {
                    $entries->put($entryIndex, $updatedEntry);
                }

                $locked->update([
                    'status' => 'ready_for_pickup',
                    'return_delivery_method' => 'walk_in',
                    'return_delivery_fee' => 0,
                    'return_logistics_quote' => null,
                    'return_logistics_locked_at' => null,
                    'return_address_confirmed_at' => null,
                    'return_address_confirmed_version' => null,
                    'logistics_payment_reconciliation' => [
                        ...$current,
                        'status' => 'resolved',
                        'entries' => $entries->values()->all(),
                    ],
                ]);
            }

            $updated = $locked->fresh();

            return [
                'repair' => $updated,
                'recovery' => $this->returnRecoveryState($updated),
                'notification_state' => $action === 'schedule_redelivery'
                    ? 'awaiting_payment'
                    : 'shop_pickup',
                'recovery_key' => $key,
            ];
        }, 3);

        $this->notifications->notifyRepairReturnRecovery(
            $result['repair'],
            $result['notification_state'],
            $result['recovery_key'],
        );

        return $result;
    }

    public function activeRedeliveryRequirement(RepairRequest $repair, string $status = 'awaiting_payment'): ?array
    {
        return collect(data_get($repair->logistics_payment_reconciliation, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry)
                && (string) ($entry['type'] ?? '') === 'return_recovery'
                && (string) ($entry['action'] ?? '') === 'collect_redelivery_fee'
                && (string) ($entry['status'] ?? '') === $status)
            ->sortByDesc('updated_at')
            ->first();
    }

    public function markRedeliveryPaid(RepairRequest $repair, string $recoveryKey): RepairRequest
    {
        $current = is_array($repair->logistics_payment_reconciliation)
            ? $repair->logistics_payment_reconciliation
            : [];
        $entries = collect(data_get($current, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry))
            ->values();
        $index = $entries->search(fn (array $entry): bool => (string) ($entry['type'] ?? '') === 'return_recovery'
            && (string) ($entry['action'] ?? '') === 'collect_redelivery_fee'
            && (string) ($entry['status'] ?? '') === 'awaiting_payment'
            && (string) ($entry['recovery_key'] ?? '') === $recoveryKey
        );
        if ($index === false) {
            throw ValidationException::withMessages([
                'payment' => ['This re-delivery request is no longer awaiting payment.'],
            ]);
        }

        $entries->put($index, [
            ...$entries->get($index),
            'status' => 'paid',
            'paid_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ]);
        $repair->update([
            'logistics_payment_reconciliation' => [
                ...$current,
                'status' => 'resolved',
                'entries' => $entries->all(),
            ],
        ]);

        return $repair->fresh();
    }

    private function returnRecoveryState(RepairRequest $repair): ?array
    {
        if ((bool) $repair->pickup_enabled || (string) $repair->status === 'picked_up') {
            return null;
        }

        $shipment = $repair->relationLoaded('logisticsShipments')
            ? $repair->logisticsShipments->firstWhere('purpose', 'repair_return')
            : Shipment::query()
                ->with('legs.proofs')
                ->where('source_type', 'repair_request')
                ->where('source_id', $repair->id)
                ->where('purpose', 'repair_return')
                ->first();
        if (! $shipment) {
            return null;
        }

        $return = $shipment->legs
            ->where('leg_type', 'return_to_shop')
            ->filter(fn ($leg): bool => $leg->status->value === 'delivered')
            ->sortByDesc('sequence')
            ->first();
        if (! $return || ! $return->proofs->contains(
            fn ($proof): bool => $proof->handoff_type === 'receive'
                && $proof->review_status === 'approved'
        )) {
            return null;
        }

        $original = $shipment->legs->firstWhere('id', $return->return_for_leg_id);
        $newerOutbound = $shipment->legs->contains(
            fn ($leg): bool => $leg->leg_type === 'outbound'
                && (int) $leg->sequence > (int) $return->sequence
                && $leg->status->value !== 'cancelled'
        );
        if (! $original
            || $original->status->value !== 'cancelled'
            || $original->resolution_type !== 'returned'
            || $newerOutbound) {
            return null;
        }

        $key = "return-to-shop:{$return->id}";
        $entry = collect(data_get($repair->logistics_payment_reconciliation, 'entries', []))
            ->filter(fn ($item): bool => is_array($item))
            ->first(fn (array $item): bool => (string) ($item['type'] ?? '') === 'return_recovery'
                && (string) ($item['recovery_key'] ?? '') === $key);
        $state = match ((string) ($entry['status'] ?? '')) {
            'awaiting_payment' => 'awaiting_payment',
            'shop_pickup_selected' => 'shop_pickup',
            'paid' => 'ready_for_dispatch',
            default => 'awaiting_arrangement',
        };

        return [
            'code' => 'returned_to_shop_awaiting_arrangement',
            'label' => 'Returned to shop—awaiting customer arrangement',
            'state' => $state,
            'key' => $key,
            'scheduled_delivery_date' => $entry['scheduled_delivery_date'] ?? null,
            'delivery_window' => $entry['delivery_window'] ?? null,
            'can_schedule_redelivery' => $state === 'awaiting_arrangement',
            'can_set_shop_pickup' => (string) ($entry['status'] ?? '') !== 'paid',
        ];
    }

    private function cancellationTarget(RepairRequest $repair, string $phase, ?ShipmentLeg $leg): ?array
    {
        $isIntake = $phase === 'intake';
        $method = (string) ($isIntake ? $repair->intake_delivery_method : $repair->return_delivery_method);
        $fee = (float) ($isIntake ? $repair->intake_delivery_fee : $repair->return_delivery_fee);
        $token = $this->cancellationPlanToken($repair, $phase);

        if ($method !== ($isIntake ? 'shop_pickup' : 'shop_delivery') || $fee <= 0 || $token === null) {
            return null;
        }

        return [
            'shipment_leg_id' => $leg?->id,
            'plan_token' => $token,
        ];
    }

    private function cancellationPlanToken(RepairRequest $repair, string $phase): ?string
    {
        $isIntake = $phase === 'intake';
        $snapshot = $isIntake ? $repair->intake_address : $repair->return_address;
        $lock = $isIntake ? $repair->intake_logistics_locked_at : $repair->return_logistics_locked_at;

        if ($lock === null) {
            return null;
        }

        return hash('sha256', implode('|', [
            $phase,
            (string) data_get($snapshot, 'version', ''),
            $lock->toISOString(),
        ]));
    }

    private function handoffState(RepairRequest $repair, string $purpose): array
    {
        $shipment = $repair->relationLoaded('logisticsShipments')
            ? $repair->logisticsShipments->firstWhere('purpose', $purpose)
            : Shipment::query()
                ->with(['legs.proofs', 'events'])
                ->where('source_type', 'repair_request')
                ->where('source_id', $repair->id)
                ->where('purpose', $purpose)
                ->first();
        $leg = $shipment?->legs
            ->reject(fn ($candidate): bool => $candidate->status->value === 'cancelled')
            ->sortByDesc('sequence')
            ->first();
        $proof = $leg?->proofs
            ->whereIn('handoff_type', ['delivery', 'receive'])
            ->sortByDesc('id')
            ->first();

        return [
            'shipment' => $shipment,
            'leg' => $leg,
            'proof' => $proof,
            'approved' => $shipment?->status?->value === 'completed'
                && $leg?->status?->value === 'delivered'
                && $proof?->review_status === 'approved',
            'events' => $shipment?->events
                ->filter(fn ($event): bool => $event->shipment_leg_id === null || $event->shipment_leg_id === $leg?->id)
                ->sortBy('created_at')
                ->values() ?? collect(),
        ];
    }

    private function hasAcceptedIntake(RepairRequest $repair): bool
    {
        if ((string) $repair->status === 'repairer_accepted') {
            return true;
        }

        return $repair->conversation_id !== null
            && in_array((string) $repair->status, [
                'waiting_customer_confirmation',
                'confirmed',
                'pending',
                'received',
                'in_progress',
                'in-progress',
                'awaiting_parts',
                'completed',
                'ready_for_pickup',
                'ready-for-pickup',
                'shipped',
                'picked_up',
            ], true);
    }

    private function startIntakeCompensation(RepairRequest $repair, array $coverage): ?array
    {
        return $this->recordCompensation(
            $repair,
            'intake',
            'intake_'.(string) ($coverage['reason'] ?? 'logistics_unavailable'),
            ['coverage' => $coverage],
        );
    }

    private function recordCompensation(
        RepairRequest $repair,
        string $phase,
        string $reason,
        array $details = [],
    ): ?array {
        if ($this->isSponsoredWarranty($repair)) {
            return null;
        }

        $isIntake = $phase === 'intake';
        $snapshot = $isIntake ? $repair->intake_address : $repair->return_address;
        $lock = $isIntake ? $repair->intake_logistics_locked_at : $repair->return_logistics_locked_at;
        $amount = round((float) ($isIntake ? $repair->intake_delivery_fee : $repair->return_delivery_fee), 2);
        $snapshotVersion = (string) data_get($snapshot, 'version', 'unknown');
        $lockReference = $lock?->toISOString() ?? 'unlocked';
        $entry = [
            'type' => 'delivery_compensation',
            'compensation_key' => "{$phase}:{$snapshotVersion}:{$lockReference}",
            'status' => 'pending',
            'reason' => $reason,
            'phase' => $phase,
            'action' => 'refund_delivery_fee',
            'snapshot_version' => $snapshotVersion,
            'payment_lock' => $lockReference,
            'delivery_amount' => $amount,
            'reconciliation_amount' => $amount,
            ...$details,
            'created_at' => now()->toISOString(),
        ];
        $current = is_array($repair->logistics_payment_reconciliation)
            ? $repair->logistics_payment_reconciliation
            : [];
        $currentStatus = (string) data_get($current, 'status', 'pending');
        $entries = collect(data_get($current, 'entries', []))
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => isset($item['status'])
                ? $item
                : [...$item, 'status' => $currentStatus === 'resolved' ? 'resolved' : 'pending']);

        if ($entries->isEmpty() && data_get($current, 'reason')) {
            $legacy = collect($current)->except(['status', 'entries', 'total_reconciliation_amount'])->all();
            $entries->push([...$legacy, 'status' => $currentStatus === 'resolved' ? 'resolved' : 'pending']);
        }
        if ($entries->contains(
            fn (array $item): bool => (string) ($item['compensation_key'] ?? '') === $entry['compensation_key']
        )) {
            return null;
        }

        $entries->push($entry);
        $repair->update([
            'logistics_payment_reconciliation' => [
                ...$entry,
                'status' => 'pending',
                'entries' => $entries->values()->all(),
                'total_reconciliation_amount' => round((float) $entries
                    ->where('status', 'pending')
                    ->sum(fn (array $item): float => (float) ($item['reconciliation_amount'] ?? 0)), 2),
            ],
        ]);

        return $entry;
    }

    private function isSponsoredWarranty(RepairRequest $repair): bool
    {
        return (bool) ($repair->is_warranty_job ?? false)
            || (string) ($repair->billing_mode ?? '') === 'warranty_no_charge';
    }

    private function currentPendingCompensation(RepairRequest $repair, string $phase): ?array
    {
        $current = is_array($repair->logistics_payment_reconciliation)
            ? $repair->logistics_payment_reconciliation
            : [];
        if ((string) data_get($current, 'status') !== 'pending') {
            return null;
        }
        $snapshot = $phase === 'intake' ? $repair->intake_address : $repair->return_address;
        $snapshotVersion = (string) data_get($snapshot, 'version', 'unknown');

        return collect(data_get($current, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry))
            ->first(fn (array $entry): bool => (string) ($entry['phase'] ?? '') === $phase
                && (string) ($entry['snapshot_version'] ?? '') === $snapshotVersion
                && in_array((string) ($entry['status'] ?? 'pending'), ['pending', 'processing'], true));
    }

    private function notifyNewCompensation(RepairRequest $repair, ?array $entry): void
    {
        if (! $entry) {
            return;
        }

        $this->notifications->notifyRepairDeliveryReconciliation(
            $repair->fresh(),
            (string) $entry['phase'],
            'created',
            (float) $entry['reconciliation_amount'],
            (string) $entry['compensation_key'],
        );
    }

    private function hasFreshIntakePaymentAfterCompensation(RepairRequest $repair): bool
    {
        $reconciliation = is_array($repair->logistics_payment_reconciliation)
            ? $repair->logistics_payment_reconciliation
            : [];
        if (data_get($reconciliation, 'status') !== 'resolved') {
            return true;
        }

        $entries = collect(data_get($reconciliation, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry)
                && data_get($entry, 'type') === 'delivery_compensation'
                && data_get($entry, 'phase') === 'intake');
        if (data_get($reconciliation, 'type') === 'delivery_compensation'
            && data_get($reconciliation, 'phase') === 'intake') {
            $entries->push($reconciliation);
        }

        $latestCompensationAt = $entries
            ->map(fn (array $entry) => $entry['resolved_at'] ?? $entry['created_at'] ?? null)
            ->filter()
            ->map(fn ($timestamp) => CarbonImmutable::parse((string) $timestamp))
            ->sortDesc()
            ->first();

        return $latestCompensationAt === null
            || $repair->intake_logistics_locked_at->greaterThan($latestCompensationAt);
    }

    private function startReturnCompensation(RepairRequest $repair, array $coverage): ?array
    {
        return $this->recordCompensation(
            $repair,
            'return',
            'return_'.(string) ($coverage['reason'] ?? 'logistics_unavailable'),
            ['coverage' => $coverage],
        );
    }

    private function hasFreshReturnPaymentAfterCompensation(RepairRequest $repair): bool
    {
        $reconciliation = is_array($repair->logistics_payment_reconciliation)
            ? $repair->logistics_payment_reconciliation
            : [];
        if (data_get($reconciliation, 'status') !== 'resolved') {
            return true;
        }

        $entries = collect(data_get($reconciliation, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry)
                && data_get($entry, 'type') === 'delivery_compensation'
                && data_get($entry, 'phase') === 'return');
        if (data_get($reconciliation, 'type') === 'delivery_compensation'
            && data_get($reconciliation, 'phase') === 'return') {
            $entries->push($reconciliation);
        }

        if ($entries->isEmpty()) {
            return false;
        }

        $latestCompensationAt = $entries
            ->map(fn (array $entry) => $entry['resolved_at'] ?? $entry['created_at'] ?? null)
            ->filter()
            ->map(fn ($timestamp) => CarbonImmutable::parse((string) $timestamp))
            ->sortDesc()
            ->first();

        return $latestCompensationAt !== null
            && $repair->return_logistics_locked_at?->greaterThan($latestCompensationAt);
    }
}
