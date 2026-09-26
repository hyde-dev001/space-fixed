<?php

namespace App\Services;

use App\Enums\Logistics\ShipmentLegStatus;
use App\Models\CodCollection;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\LogisticsActorPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CodCollectionService
{
    public function __construct(
        private readonly LogisticsActorPolicy $logisticsActorPolicy,
    ) {}

    public function ensureForOrder(Order $order): CodCollection
    {
        return CodCollection::query()->firstOrCreate(
            ['order_id' => $order->id],
            [
                'shop_owner_id' => $order->shop_owner_id,
                'expected_amount' => $this->expectedAmount($order),
                'status' => CodCollection::STATUS_PENDING,
            ],
        );
    }

    public function expectedAmount(Order $order): string
    {
        return number_format(
            round(
                (float) $order->total_amount
                + (float) ($order->shipping_fee ?? 0)
                + (float) ($order->vat_amount ?? 0),
                2,
            ),
            2,
            '.',
            '',
        );
    }

    /** @return array<string, mixed> */
    public function projection(Order $order): array
    {
        if (! $this->isCodOrder($order)) {
            return [
                'payment_method' => $order->payment_method,
                'cod_expected_amount' => null,
                'cod_collection_status' => null,
                'cod_collected_amount' => null,
                'cod_collection_reference' => null,
                'cod_collected_at' => null,
                'cod_rider_name' => null,
                'cod_remittance_status' => null,
                'cod_remittance_reference' => null,
            ];
        }

        $collection = $order->relationLoaded('codCollection')
            ? $order->getRelation('codCollection')
            : null;
        $remittance = $collection?->relationLoaded('remittanceItem')
            ? $collection->remittanceItem?->remittance
            : null;

        return [
            'payment_method' => $order->payment_method,
            'cod_expected_amount' => $this->expectedAmount($order),
            'cod_collection_status' => $collection?->status,
            'cod_collected_amount' => $collection?->collected_amount !== null
                ? (string) $collection->collected_amount
                : null,
            'cod_collection_reference' => $collection?->collection_reference,
            'cod_collected_at' => optional($collection?->collected_at)->toISOString(),
            'cod_rider_name' => $collection?->riderUser?->name,
            'cod_remittance_status' => $remittance?->status,
            'cod_remittance_reference' => $remittance?->reference,
        ];
    }

    public function cashCollected(Order $order, User $actor, array $data): CodCollection
    {
        $amountCents = $this->toCents($data['amount'] ?? null);
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        $idempotencyKey = $idempotencyKey !== ''
            ? $idempotencyKey
            : 'cod-collection-order-'.$order->id;

        return DB::transaction(function () use ($order, $actor, $amountCents, $idempotencyKey): CodCollection {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedOrder->shop_owner_id !== (int) $actor->shop_owner_id) {
                throw new AuthorizationException('The COD order is not available in this shop.');
            }

            if (! $this->isCodOrder($lockedOrder)) {
                throw ValidationException::withMessages([
                    'order' => ['Only COD orders can record a cash collection.'],
                ]);
            }

            if ($this->orderStatus($lockedOrder) === 'cancelled') {
                throw ValidationException::withMessages([
                    'order' => ['Cancelled orders cannot be collected.'],
                ]);
            }

            $collection = CodCollection::query()
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->first();

            if ($collection && $collection->status !== CodCollection::STATUS_PENDING) {
                if ($this->isEquivalentReplay($collection, $actor, $amountCents)) {
                    return $collection->fresh();
                }

                throw ValidationException::withMessages([
                    'collection' => ['This COD collection has already been recorded.'],
                ]);
            }

            $shopOwner = ShopOwner::query()->findOrFail($lockedOrder->shop_owner_id);
            [$leg, $profile] = $this->resolveAssignedLeg($lockedOrder, $actor, $shopOwner);
            $expectedCents = $this->toCents($collection?->expected_amount ?? $this->expectedAmount($lockedOrder));

            if ($amountCents !== $expectedCents) {
                throw ValidationException::withMessages([
                    'amount' => ['Collected amount must exactly match the COD amount due.'],
                ]);
            }

            $collection ??= CodCollection::query()->create([
                'shop_owner_id' => $lockedOrder->shop_owner_id,
                'order_id' => $lockedOrder->id,
                'expected_amount' => $this->fromCents($expectedCents),
                'status' => CodCollection::STATUS_PENDING,
            ]);

            $now = now();
            $collection->fill([
                'shipment_id' => $leg->shipment_id,
                'shipment_leg_id' => $leg->id,
                'rider_profile_id' => $profile->id,
                'rider_user_id' => $actor->id,
                'collected_amount' => $this->fromCents($amountCents),
                'status' => CodCollection::STATUS_CASH_COLLECTED,
                'collection_reference' => 'COD-COLLECTION-'.$lockedOrder->order_number,
                'collection_idempotency_key' => $idempotencyKey,
                'collected_at' => $now,
                'collected_by_user_id' => $actor->id,
            ])->save();

            activity()
                ->performedOn($lockedOrder)
                ->withProperties([
                    'order_id' => $lockedOrder->id,
                    'order_number' => $lockedOrder->order_number,
                    'collection_id' => $collection->id,
                    'amount' => $collection->collected_amount,
                    'rider_user_id' => $actor->id,
                    'shipment_leg_id' => $leg->id,
                ])
                ->log('COD cash collected');

            return $collection->fresh();
        }, 3);
    }

    public function riderCollections(User $actor): array
    {
        $shopOwner = ShopOwner::query()->find($actor->shop_owner_id);
        if (! $shopOwner
            || ! $actor->can('operate-logistics-deliveries')
            || ! $this->logisticsActorPolicy->resolveRiderProfile($actor, $shopOwner)) {
            throw new AuthorizationException('An active rider profile is required to view COD collections.');
        }

        $collections = CodCollection::query()
            ->where('shop_owner_id', $shopOwner->id)
            ->where('rider_user_id', $actor->id)
            ->with(['order:id,order_number', 'remittanceItem.remittance'])
            ->latest('id')
            ->get();

        $pending = $collections->filter(fn (CodCollection $collection): bool =>
            $collection->status === CodCollection::STATUS_CASH_COLLECTED
            && ! $collection->remittanceItem
        );
        $held = $collections->filter(function (CodCollection $collection): bool {
            if ($collection->status === CodCollection::STATUS_REFUND_PENDING) {
                return true;
            }

            if ($collection->status !== CodCollection::STATUS_CASH_COLLECTED) {
                return false;
            }

            return ! $collection->remittanceItem
                || in_array($collection->remittanceItem->remittance?->status, ['submitted', 'disputed'], true);
        });
        $submittedRemittances = $collections
            ->map(fn (CodCollection $collection) => $collection->remittanceItem?->remittance)
            ->filter(fn ($remittance): bool => in_array($remittance?->status, ['submitted', 'disputed'], true))
            ->unique('id')
            ->values();

        return [
            'summary' => [
                'cash_currently_held' => $this->fromCents(
                    $held->sum(fn (CodCollection $collection): int => $this->toCents($collection->collected_amount)),
                ),
                'pending_remittance_count' => $pending->count(),
            ],
            'pending_remittance' => $pending->map(fn (CodCollection $collection): array => $this->collectionPayload($collection))->values()->all(),
            'submitted_remittances' => $submittedRemittances->map(fn ($remittance): array => [
                'id' => $remittance->id,
                'reference' => $remittance->reference,
                'expected_amount' => (string) $remittance->expected_amount,
                'submitted_amount' => (string) $remittance->submitted_amount,
                'received_amount' => $remittance->received_amount !== null ? (string) $remittance->received_amount : null,
                'variance_amount' => $remittance->variance_amount !== null ? (string) $remittance->variance_amount : null,
                'status' => $remittance->status,
                'submitted_at' => optional($remittance->submitted_at)->toISOString(),
                'dispute_reason' => $remittance->dispute_reason,
            ])->all(),
            'settled_history' => $collections
                ->filter(fn (CodCollection $collection): bool => $collection->status === CodCollection::STATUS_SETTLED)
                ->map(fn (CodCollection $collection): array => $this->collectionPayload($collection))
                ->values()
                ->all(),
        ];
    }

    public function assertCollectedBeforeDelivery(Order $order): void
    {
        if (! $this->isCodOrder($order)) {
            return;
        }

        $collected = CodCollection::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [
                CodCollection::STATUS_CASH_COLLECTED,
                CodCollection::STATUS_SETTLED,
            ])
            ->exists();

        if (! $collected) {
            throw ValidationException::withMessages([
                'payment' => ['COD payment must be collected before delivery can be completed.'],
            ]);
        }
    }

    /** @return array{0: ShipmentLeg, 1: RiderProfile} */
    private function resolveAssignedLeg(Order $order, User $actor, ShopOwner $shopOwner): array
    {
        $profile = $this->logisticsActorPolicy->resolveRiderProfile($actor, $shopOwner);
        if (! $profile) {
            throw new AuthorizationException('An active rider profile is required to collect COD cash.');
        }

        $leg = ShipmentLeg::query()
            ->with('shipment')
            ->whereHas('shipment', function ($query) use ($order): void {
                $query->where('shop_owner_id', $order->shop_owner_id)
                    ->where('source_type', 'order')
                    ->where('source_id', $order->id)
                    ->where('purpose', 'retail_delivery');
            })
            ->whereHas('assignments', function ($query) use ($profile): void {
                $query->where('rider_profile_id', $profile->id)
                    ->whereIn('status', ['assigned', 'accepted']);
            })
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if (! $leg) {
            throw new AuthorizationException('The rider is not assigned to this COD delivery.');
        }

        $decision = $this->logisticsActorPolicy->decideCustody(
            $actor,
            $shopOwner,
            $leg,
            'operate-logistics-deliveries',
        );

        if (! $decision['allowed']) {
            throw new AuthorizationException('This COD delivery is not eligible for the rider action.');
        }

        $status = $leg->status instanceof ShipmentLegStatus
            ? $leg->status->value
            : (string) $leg->status;
        if (! in_array($status, [
            ShipmentLegStatus::IN_TRANSIT->value,
            ShipmentLegStatus::DELIVERY_ATTEMPTED->value,
            ShipmentLegStatus::AWAITING_PROOF_APPROVAL->value,
            ShipmentLegStatus::PROOF_CORRECTION_REQUIRED->value,
        ], true)) {
            throw ValidationException::withMessages([
                'shipment_leg' => ['Cash can only be collected during an eligible delivery stop.'],
            ]);
        }

        return [$leg, $profile];
    }

    private function isEquivalentReplay(CodCollection $collection, User $actor, int $amountCents): bool
    {
        return (int) $collection->rider_user_id === (int) $actor->id
            && $this->toCents($collection->collected_amount) === $amountCents;
    }

    /** @return array<string, mixed> */
    private function collectionPayload(CodCollection $collection): array
    {
        return [
            'id' => $collection->id,
            'order_id' => $collection->order_id,
            'order_number' => $collection->order?->order_number,
            'expected_amount' => (string) $collection->expected_amount,
            'collected_amount' => (string) $collection->collected_amount,
            'status' => $collection->status,
            'collected_at' => optional($collection->collected_at)->toISOString(),
            'remittance_reference' => $collection->remittanceItem?->remittance?->reference,
        ];
    }

    public function isCodOrder(Order $order): bool
    {
        return in_array(strtolower(trim((string) $order->payment_method)), [
            'cod',
            'cash_on_delivery',
            'cash on delivery',
            'cash',
        ], true);
    }

    private function orderStatus(Order $order): string
    {
        return $order->status instanceof \BackedEnum
            ? $order->status->value
            : (string) $order->status;
    }

    private function toCents(mixed $amount): int
    {
        if (! is_numeric($amount)) {
            throw ValidationException::withMessages([
                'amount' => ['Collected amount must be a valid decimal.'],
            ]);
        }

        $normalized = number_format((float) $amount, 2, '.', '');
        if ((float) $normalized < 0) {
            throw ValidationException::withMessages([
                'amount' => ['Collected amount cannot be negative.'],
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
