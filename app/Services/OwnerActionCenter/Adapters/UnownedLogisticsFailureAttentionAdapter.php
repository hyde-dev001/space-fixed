<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\LogisticsResponsibilityProjection;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class UnownedLogisticsFailureAttentionAdapter implements OwnerAttentionAdapter
{
    public function __construct(
        private readonly LogisticsResponsibilityProjection $responsibility,
    ) {}

    public function adapterKey(): string
    {
        return 'unowned_logistics_failures';
    }

    public function coverageSource(): string
    {
        return 'logistics';
    }

    public function primaryBucket(): string
    {
        return 'urgent_exceptions';
    }

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
    {
        $maxAttempts = max(1, (int) (LogisticsSetting::query()
            ->where('shop_owner_id', (int) $owner->getKey())
            ->value('max_delivery_attempts') ?? 2));

        $baseQuery = ShipmentLeg::query()
            ->select([
                'id',
                'shipment_id',
                'leg_type',
                'status',
                'failed_at',
                'resolution_type',
                'delivery_batch_id',
            ])
            ->with([
                'shipment.shopOwner.logisticsSetting',
                'assignments.riderProfile.linked',
                'attempts',
                'deliveryBatch.riderProfile',
                'returnForLeg',
                'returnLeg.assignments.riderProfile.linked',
                'returnLeg.proofs',
                'proofs',
            ])
            ->whereHas('shipment', static function (Builder $shipmentQuery) use ($owner): void {
                $shipmentQuery->where('shop_owner_id', (int) $owner->getKey());
            })
            ->where('leg_type', '!=', 'return_to_shop')
            ->whereIn('status', ['delivery_attempted', 'needs_resolution', 'failed'])
            ->whereNotNull('failed_at')
            ->orderBy('failed_at')
            ->orderBy('id');

        $qualifyingCount = (clone $baseQuery)
            ->where('status', 'needs_resolution')
            ->whereNull('resolution_type')
            ->whereNull('delivery_batch_id')
            ->whereDoesntHave('returnLeg')
            ->whereDoesntHave('assignments', static function (Builder $assignmentQuery): void {
                $assignmentQuery->whereIn('status', ['assigned', 'accepted']);
            })
            ->whereHas('attempts', static function (Builder $attemptQuery): void {
                $attemptQuery->where('attempt_type', 'delivery');
            }, '>=', $maxAttempts)
            ->count();

        $legs = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = [];
        foreach ($legs as $leg) {
            $projection = $this->responsibility->project($leg);
            if (! $projection->isHealthy()) {
                throw new RuntimeException('logistics_responsibility_inconsistent');
            }

            if ($projection->isUnownedMaterialException()) {
                $items[] = $this->toItem($leg);
            }
        }

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }

    private function toItem(ShipmentLeg $leg): OwnerAttentionItem
    {
        $failedAt = $leg->failed_at?->toISOString() ?? now()->toISOString();

        return new OwnerAttentionItem(
            sourceType: 'logistics_failure',
            sourceId: (int) $leg->getKey(),
            category: 'unowned_delivery_failure',
            primaryBucket: $this->primaryBucket(),
            module: 'logistics',
            title: 'Failed delivery needs escalation',
            conciseSummary: 'Delivery recovery is exhausted and has no active responsible party.',
            priorityTier: 'high',
            materialityTier: 'high',
            comparableMonetaryExposure: null,
            urgencyAt: $failedAt,
            actionableSince: $failedAt,
            waitingOn: 'none',
            ownerActionRequired: false,
            coverageSource: $this->coverageSource(),
            destinationUrl: route('shop-owner.logistics.shipments', [], false)
                .'?shipment='.$leg->shipment_id.'&leg='.$leg->getKey(),
        );
    }
}
