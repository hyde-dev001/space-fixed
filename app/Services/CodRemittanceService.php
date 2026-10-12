<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\CodCollection;
use App\Models\CodRemittance;
use App\Models\CodRemittanceItem;
use App\Models\Finance\Invoice;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Logistics\LogisticsActorPolicy;
use App\Support\Finance\FinanceDomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CodRemittanceService
{
    public function __construct(
        private readonly LogisticsActorPolicy $logisticsActorPolicy,
        private readonly CodCollectionService $codCollections,
        private readonly InvoicePaymentService $invoicePayments,
        private readonly OrderRefundService $orderRefunds,
    ) {}

    /** @return array{remittance: array<string, mixed>, replayed: bool} */
    public function submit(User $actor, array $data): array
    {
        $shopId = (int) ($actor->shop_owner_id ?? 0);
        $collectionIds = collect($data['collection_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $idempotencyKey = $this->resolveRequestKey($data['idempotency_key'] ?? null);
        $shop = ShopOwner::query()->find($shopId);
        $profile = $shop ? $this->logisticsActorPolicy->resolveRiderProfile($actor, $shop) : null;

        if ($shopId <= 0 || ! $profile || $collectionIds === []) {
            throw new FinanceDomainException('A rider shop and at least one COD collection are required.', 'INVALID_STATE', 422);
        }

        return DB::transaction(function () use ($actor, $shopId, $profile, $collectionIds, $idempotencyKey): array {
            $existing = CodRemittance::query()
                ->where('shop_owner_id', $shopId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $existingIds = $existing->items()->orderBy('cod_collection_id')->pluck('cod_collection_id')->map(fn ($id): int => (int) $id)->all();
                if (
                    (int) $existing->rider_user_id !== (int) $actor->id
                    || (int) $existing->rider_profile_id !== (int) $profile->id
                    || $existingIds !== $collectionIds
                ) {
                    throw new FinanceDomainException('The remittance idempotency key was already used with different collections.', 'DUPLICATE_SUBMISSION', 409);
                }

                return [
                    'remittance' => $this->remittancePayload($existing),
                    'replayed' => true,
                ];
            }

            $collections = CodCollection::query()
                ->where('shop_owner_id', $shopId)
                ->whereIn('id', $collectionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($collections->count() !== count($collectionIds)) {
                throw new FinanceDomainException('One or more COD collections are not available in this shop.', 'FORBIDDEN', 403);
            }

            if (CodRemittanceItem::query()->whereIn('cod_collection_id', $collectionIds)->exists()) {
                throw new FinanceDomainException('One or more COD collections are already submitted for remittance.', 'INVALID_STATE', 422);
            }

            foreach ($collections as $collection) {
                if (
                    (int) $collection->rider_user_id !== (int) $actor->id
                    || (int) $collection->rider_profile_id !== (int) $profile->id
                    || (string) $collection->status !== CodCollection::STATUS_CASH_COLLECTED
                    || $this->toCents($collection->expected_amount) !== $this->toCents($collection->collected_amount)
                ) {
                    throw new FinanceDomainException('Only your exact, cash-collected COD records can be remitted.', 'INVALID_STATE', 422);
                }
            }

            $expectedAmount = $this->fromCents(
                $collections->sum(fn (CodCollection $collection): int => $this->toCents($collection->expected_amount)),
            );
            $remittance = CodRemittance::query()->create([
                'shop_owner_id' => $shopId,
                'rider_profile_id' => $profile->id,
                'rider_user_id' => $actor->id,
                'reference' => 'COD-REM-'.Str::upper(Str::random(12)),
                'expected_amount' => $expectedAmount,
                'submitted_amount' => $expectedAmount,
                'status' => CodRemittance::STATUS_SUBMITTED,
                'idempotency_key' => $idempotencyKey,
                'submitted_at' => now(),
                'submitted_by_user_id' => $actor->id,
            ]);

            foreach ($collections as $collection) {
                $remittance->items()->create([
                    'cod_collection_id' => $collection->id,
                    'expected_amount' => $collection->expected_amount,
                ]);
            }

            activity()
                ->withProperties([
                    'shop_owner_id' => $shopId,
                    'rider_user_id' => $actor->id,
                    'remittance_id' => $remittance->id,
                    'collection_count' => $collections->count(),
                    'expected_amount' => $expectedAmount,
                ])
                ->log('COD remittance submitted');

            $this->notifyRemittance($remittance, 'submitted');

            return [
                'remittance' => $this->remittancePayload($remittance->fresh()),
                'replayed' => false,
            ];
        }, 3);
    }

    /** @return array{data: array<int, array<string, mixed>>, total: int} */
    public function financeIndex(User $actor): array
    {
        $shopId = (int) ($actor->shop_owner_id ?? 0);
        if ($shopId <= 0) {
            throw new FinanceDomainException('A Finance shop context is required.', 'TENANT_CONTEXT_REQUIRED', 403);
        }

        $remittances = CodRemittance::query()
            ->where('shop_owner_id', $shopId)
            ->with([
                'riderUser:id,name',
                'items.collection:id,order_id,expected_amount,collected_amount,status',
                'items.collection.order:id,order_number,invoice_id',
            ])
            ->latest('id')
            ->get();

        $data = $remittances
            ->map(fn (CodRemittance $remittance): array => $this->remittancePayload($remittance))
            ->values()
            ->all();

        return ['data' => $data, 'total' => count($data)];
    }

    /** @return array{remittance: array<string, mixed>, replayed: bool} */
    public function confirm(CodRemittance $remittance, User $actor, array $data): array
    {
        $shopId = (int) ($actor->shop_owner_id ?? 0);
        $receivedAmount = $this->normalizeAmount($data['received_amount'] ?? null);
        $disputeReason = trim((string) ($data['dispute_reason'] ?? ''));

        return DB::transaction(function () use ($remittance, $actor, $shopId, $receivedAmount, $disputeReason): array {
            if ($shopId <= 0) {
                throw new FinanceDomainException('A Finance shop context is required.', 'TENANT_CONTEXT_REQUIRED', 403);
            }

            $lockedRemittance = CodRemittance::query()
                ->whereKey($remittance->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $lockedRemittance->shop_owner_id !== $shopId) {
                throw new FinanceDomainException('The COD remittance is not available in this shop.', 'FORBIDDEN', 403);
            }

            if (in_array((string) $lockedRemittance->status, [CodRemittance::STATUS_SETTLED, CodRemittance::STATUS_DISPUTED], true)) {
                if ($this->toCents($lockedRemittance->received_amount) === $this->toCents($receivedAmount)) {
                    return [
                        'remittance' => $this->remittancePayload($lockedRemittance),
                        'replayed' => true,
                    ];
                }

                throw new FinanceDomainException('This COD remittance has already reached a final state.', 'INVALID_STATE', 409);
            }

            $items = CodRemittanceItem::query()
                ->where('cod_remittance_id', $lockedRemittance->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($items->isEmpty()) {
                throw new FinanceDomainException('The COD remittance has no collections to confirm.', 'INVALID_STATE', 422);
            }

            $collectionIds = $items->pluck('cod_collection_id')->map(fn ($id): int => (int) $id)->sort()->values();
            $collections = CodCollection::query()
                ->where('shop_owner_id', $shopId)
                ->whereIn('id', $collectionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($collections->count() !== $collectionIds->count()) {
                throw new FinanceDomainException('The COD remittance contains an unavailable collection.', 'INVALID_STATE', 422);
            }

            $expectedCents = 0;
            foreach ($items as $item) {
                $collection = $collections->get($item->cod_collection_id);
                if ((string) $collection->status !== CodCollection::STATUS_CASH_COLLECTED) {
                    throw new FinanceDomainException('Only cash-collected records can be confirmed.', 'INVALID_STATE', 422);
                }

                $expectedCents += $this->toCents($item->expected_amount);
            }

            $receivedCents = $this->toCents($receivedAmount);
            $varianceCents = $receivedCents - $expectedCents;
            if ($varianceCents !== 0) {
                $lockedRemittance->update([
                    'received_amount' => $receivedAmount,
                    'variance_amount' => $this->fromCents($varianceCents),
                    'status' => CodRemittance::STATUS_DISPUTED,
                    'confirmed_at' => now(),
                    'confirmed_by_user_id' => $actor->id,
                    'dispute_reason' => $disputeReason !== '' ? $disputeReason : 'Received amount did not match the expected remittance amount.',
                ]);
                activity()
                    ->withProperties([
                        'shop_owner_id' => $shopId,
                        'remittance_id' => $lockedRemittance->id,
                        'received_amount' => $receivedAmount,
                        'variance_amount' => $lockedRemittance->variance_amount,
                    ])
                    ->log('COD remittance disputed');
                $this->notifyRemittance($lockedRemittance, 'disputed');

                return [
                    'remittance' => $this->remittancePayload($lockedRemittance->fresh()),
                    'replayed' => false,
                ];
            }

            $orders = Order::query()
                ->whereIn('id', $collections->pluck('order_id')->unique()->sort()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $invoiceIds = $orders->pluck('invoice_id')->filter()->unique()->sort()->values();
            $invoices = Invoice::query()
                ->whereIn('id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($collections->sortBy('id') as $collection) {
                $order = $orders->get($collection->order_id);
                $invoice = $order ? $invoices->get($order->invoice_id) : null;
                if (! $order || ! $invoice || ! $this->codCollections->isCodOrder($order)) {
                    throw new FinanceDomainException('The COD order is not ready for Finance settlement.', 'INVALID_STATE', 422);
                }

                $this->invoicePayments->recordCodOrderPayment($order, $lockedRemittance, $collection, $actor);
            }

            $now = now();
            foreach ($collections as $collection) {
                $collection->update([
                    'status' => CodCollection::STATUS_SETTLED,
                    'settled_at' => $now,
                    'settled_by_user_id' => $actor->id,
                ]);
            }
            $lockedRemittance->update([
                'received_amount' => $receivedAmount,
                'variance_amount' => '0.00',
                'status' => CodRemittance::STATUS_SETTLED,
                'confirmed_at' => $now,
                'confirmed_by_user_id' => $actor->id,
                'dispute_reason' => null,
            ]);

            $refundIds = OrderRefund::query()
                ->whereIn('order_id', $collections->pluck('order_id')->unique()->values())
                ->where('flow_type', 'request_approval')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            DB::afterCommit(function () use ($refundIds): void {
                foreach ($refundIds as $refundId) {
                    $refund = OrderRefund::query()->find($refundId);
                    if ($refund) {
                        $this->orderRefunds->notifyPayoutReadyIfEligible($refund);
                    }
                }
            });

            activity()
                ->withProperties([
                    'shop_owner_id' => $shopId,
                    'remittance_id' => $lockedRemittance->id,
                    'received_amount' => $receivedAmount,
                    'collection_count' => $collections->count(),
                ])
                ->log('COD remittance settled');
            $this->notifyRemittance($lockedRemittance, 'settled');

            return [
                'remittance' => $this->remittancePayload($lockedRemittance->fresh()),
                'replayed' => false,
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function remittancePayload(CodRemittance $remittance): array
    {
        $remittance->loadMissing([
            'riderUser:id,name',
            'items.collection:id,order_id,expected_amount,collected_amount,status',
            'items.collection.order:id,order_number,invoice_id',
        ]);

        return [
            'id' => $remittance->id,
            'reference' => $remittance->reference,
            'rider_user_id' => $remittance->rider_user_id,
            'rider_name' => $remittance->riderUser?->name,
            'expected_amount' => (string) $remittance->expected_amount,
            'submitted_amount' => (string) $remittance->submitted_amount,
            'received_amount' => $remittance->received_amount !== null ? (string) $remittance->received_amount : null,
            'variance_amount' => $remittance->variance_amount !== null ? (string) $remittance->variance_amount : null,
            'status' => $remittance->status,
            'submitted_at' => optional($remittance->submitted_at)->toISOString(),
            'confirmed_at' => optional($remittance->confirmed_at)->toISOString(),
            'dispute_reason' => $remittance->dispute_reason,
            'items' => $remittance->items->map(fn (CodRemittanceItem $item): array => [
                'id' => $item->id,
                'cod_collection_id' => $item->cod_collection_id,
                'expected_amount' => (string) $item->expected_amount,
                'collected_amount' => (string) ($item->collection?->collected_amount ?? $item->expected_amount),
                'collection_status' => $item->collection?->status,
                'order_id' => $item->collection?->order_id,
                'order_number' => $item->collection?->order?->order_number,
            ])->values()->all(),
        ];
    }

    private function notifyRemittance(CodRemittance $remittance, string $event): void
    {
        try {
            $type = match ($event) {
                'submitted' => NotificationType::COD_REMITTANCE_SUBMITTED,
                'disputed' => NotificationType::COD_REMITTANCE_DISPUTED,
                default => NotificationType::COD_REMITTANCE_SETTLED,
            };
            $groupKey = "cod-remittance:{$remittance->id}:{$event}";
            $title = $type->label();
            $message = match ($event) {
                'submitted' => 'A rider submitted a COD remittance for Finance confirmation.',
                'disputed' => 'A COD remittance has a physical cash variance and needs review.',
                default => 'A COD remittance was confirmed and settled.',
            };
            $data = [
                'remittance_id' => $remittance->id,
                'reference' => $remittance->reference,
                'status' => $remittance->status,
            ];

            Notification::firstOrCreate(
                ['shop_owner_id' => $remittance->shop_owner_id, 'group_key' => $groupKey],
                [
                    'type' => $type,
                    'priority' => 'high',
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'action_url' => '/finance/cod-remittances',
                    'requires_action' => $event !== 'settled',
                    'shop_id' => $remittance->shop_owner_id,
                ],
            );

            User::query()
                ->where('shop_owner_id', $remittance->shop_owner_id)
                ->where('status', 'active')
                ->get()
                ->filter(fn (User $user): bool => $user->can('access-cod-remittances'))
                ->each(function (User $user) use ($type, $title, $message, $data, $groupKey, $event, $remittance): void {
                    Notification::firstOrCreate(
                        ['user_id' => $user->id, 'group_key' => $groupKey],
                        [
                            'type' => $type,
                            'priority' => 'high',
                            'title' => $title,
                            'message' => $message,
                            'data' => $data,
                            'action_url' => '/finance/cod-remittances',
                            'requires_action' => $event !== 'settled',
                            'shop_id' => $remittance->shop_owner_id,
                        ],
                    );
                });

            if ($remittance->rider_user_id) {
                Notification::firstOrCreate(
                    ['user_id' => $remittance->rider_user_id, 'group_key' => $groupKey],
                    [
                        'type' => $type,
                        'priority' => 'high',
                        'title' => $title,
                        'message' => $message,
                        'data' => $data,
                        'action_url' => '/erp/logistics/cod-collections',
                        'requires_action' => $event === 'disputed',
                        'shop_id' => $remittance->shop_owner_id,
                    ],
                );
            }
        } catch (\Throwable) {
            // Notification failure must not roll back a financial state change.
        }
    }

    private function resolveRequestKey(mixed $key): string
    {
        $key = trim((string) $key);
        if ($key !== '') {
            return $key;
        }

        $requestKey = function_exists('request') ? trim((string) request()->header('X-Request-ID')) : '';

        return $requestKey !== '' ? $requestKey : Str::uuid()->toString();
    }

    private function normalizeAmount(mixed $amount): string
    {
        if (! is_numeric($amount) || (float) $amount < 0) {
            throw new FinanceDomainException('Received amount must be a valid non-negative decimal.', 'INVALID_STATE', 422);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function toCents(mixed $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
