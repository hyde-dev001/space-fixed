<?php

namespace App\Services;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Logistics\DeliveryBatch;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\UserAddress;
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
    ) {
    }

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

            $reconciliationStatus = data_get($lockedRepair->logistics_payment_reconciliation, 'status');
            if ($reconciliationStatus === 'pending') {
                return null;
            }
            if (! $this->hasFreshIntakePaymentAfterCompensation($lockedRepair)) {
                return null;
            }
            if ($existing) {
                $cancelledAt = $existing->cancelled_at ?? $existing->updated_at;
                if ($reconciliationStatus !== 'resolved'
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

                return null;
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

            $reconciliationStatus = data_get($lockedRepair->logistics_payment_reconciliation, 'status');
            if ($reconciliationStatus === 'pending'
                || ! $this->hasFreshReturnPaymentAfterCompensation($lockedRepair)) {
                return null;
            }
            if ($existing) {
                $cancelledAt = $existing->cancelled_at ?? $existing->updated_at;
                if ($reconciliationStatus !== 'resolved'
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

                return null;
            }

            $shipment = $this->sourceShipments->ensureRepairReturnShipment($lockedRepair);
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
    ): array {
        $createdCompensation = null;
        $result = DB::transaction(function () use ($repair, $phase, $reason, $actorId, &$createdCompensation): array {
            $lockedRepair = RepairRequest::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $isIntake = $phase === 'intake';
            $method = (string) ($isIntake
                ? $lockedRepair->intake_delivery_method
                : $lockedRepair->return_delivery_method);
            $lockField = $isIntake ? 'intake_logistics_locked_at' : 'return_logistics_locked_at';
            $feeField = $isIntake ? 'intake_delivery_fee' : 'return_delivery_fee';
            $snapshot = $isIntake ? $lockedRepair->intake_address : $lockedRepair->return_address;
            $expectedMethod = $isIntake ? 'shop_pickup' : 'shop_delivery';

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
                ];
            }

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

            if ($activeLeg && ! in_array($activeLeg->status->value, ['pending', 'assigned', 'pickup_scheduled'], true)) {
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
        $canConfirm = (string) $repair->status === 'pending'
            && $paymentSatisfied
            && ($method !== 'shop_pickup' || $state['approved']);

        $blockedReason = null;
        if ((string) $repair->status !== 'pending') {
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
        $canRelease = in_array((string) $repair->status, $expectedStatuses, true)
            && $paymentSatisfied
            && ! (bool) $repair->pickup_enabled
            && $repair->return_logistics_locked_at === null
            && ($method !== 'shop_delivery' || $state['approved']);

        $blockedReason = null;
        if ((bool) $repair->pickup_enabled || $repair->return_logistics_locked_at !== null) {
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

    private function handoffState(RepairRequest $repair, string $purpose): array
    {
        $shipment = Shipment::query()
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
