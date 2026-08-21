<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\LogisticsResponsibilityProjection;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class ActiveLogisticsRecoveryAttentionAdapter implements OwnerAttentionAdapter
{
    private const ACTIVE_ASSIGNMENT_STATUSES = ['assigned', 'accepted'];

    public function __construct(
        private readonly LogisticsResponsibilityProjection $responsibility,
    ) {}

    public function adapterKey(): string
    {
        return 'active_logistics_recovery';
    }

    public function coverageSource(): string
    {
        return 'logistics';
    }

    public function primaryBucket(): string
    {
        return 'waiting_on_others';
    }

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
    {
        $baseQuery = $this->baseQuery($owner);
        $qualifyingCount = (clone $baseQuery)->count();
        $legs = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = [];
        foreach ($legs as $leg) {
            $projection = $this->responsibility->project($leg);
            if (! $projection->isHealthy()) {
                throw new RuntimeException('logistics_responsibility_inconsistent');
            }

            if (! $projection->materialExceptionActive
                || $projection->ownerActionRequired
                || ! $projection->recoveryPathActive
                || $projection->recoveryPathExhausted
                || ! in_array($projection->deterministicResponsibleParty, ['rider', 'dispatcher'], true)) {
                continue;
            }

            $items[] = $this->toItem($leg, $projection->deterministicResponsibleParty);
        }

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }

    /** @return Builder<ShipmentLeg> */
    private function baseQuery(ShopOwner $owner): Builder
    {
        return ShipmentLeg::query()
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
                'returnLeg.attempts',
                'returnLeg.deliveryBatch.riderProfile',
                'returnLeg.proofs',
                'returnLeg.shipment.shopOwner.logisticsSetting',
                'proofs',
            ])
            ->whereHas('shipment', static function (Builder $shipmentQuery) use ($owner): void {
                $shipmentQuery->where('shop_owner_id', (int) $owner->getKey());
            })
            ->where('leg_type', '!=', 'return_to_shop')
            ->whereIn('status', ['delivery_attempted', 'needs_resolution', 'failed'])
            ->whereNotNull('failed_at')
            ->where(function (Builder $resolutionQuery): void {
                $resolutionQuery
                    ->whereNull('resolution_type')
                    ->orWhere('resolution_type', 'retry');
            })
            ->where(function (Builder $responsibilityQuery): void {
                $responsibilityQuery
                    ->whereHas('assignments', static function (Builder $assignmentQuery): void {
                        $assignmentQuery->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES);
                    })
                    ->orWhereHas('deliveryBatch');
            })
            ->orderBy('failed_at')
            ->orderBy('id');
    }

    private function toItem(ShipmentLeg $leg, string $party): OwnerAttentionItem
    {
        if (! $leg->failed_at instanceof DateTimeInterface) {
            throw new RuntimeException('logistics_responsibility_inconsistent');
        }

        $timezone = (string) config('app.shop_timezone', 'Asia/Manila');
        $failedAt = CarbonImmutable::instance($leg->failed_at)->setTimezone($timezone);
        $responsibilityAt = $this->responsibilityStartedAt($leg, $timezone);
        $actionableSince = $responsibilityAt->greaterThan($failedAt) ? $responsibilityAt : $failedAt;

        return new OwnerAttentionItem(
            sourceType: 'logistics_failure',
            sourceId: (int) $leg->getKey(),
            category: 'logistics_recovery_waiting',
            primaryBucket: $this->primaryBucket(),
            module: 'logistics',
            title: 'Delivery recovery',
            conciseSummary: 'Delivery recovery is active with a Rider or Dispatcher.',
            priorityTier: 'high',
            materialityTier: 'high',
            comparableMonetaryExposure: null,
            urgencyAt: $failedAt->toISOString(),
            actionableSince: $actionableSince->toISOString(),
            waitingOn: $party,
            ownerActionRequired: false,
            coverageSource: $this->coverageSource(),
            destinationUrl: route('shop-owner.logistics.shipments', [], false)
                .'?shipment='.$leg->shipment_id.'&leg='.$leg->getKey(),
        );
    }

    private function responsibilityStartedAt(ShipmentLeg $leg, string $timezone): CarbonImmutable
    {
        $activeAssignments = $leg->assignments
            ->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES)
            ->values();

        if ($activeAssignments->count() === 1) {
            /** @var DeliveryAssignment $assignment */
            $assignment = $activeAssignments->first();
            $boundary = $assignment->assigned_at
                ?? $assignment->accepted_at
                ?? $assignment->created_at;

            if ($boundary instanceof DateTimeInterface) {
                return CarbonImmutable::instance($boundary)->setTimezone($timezone);
            }
        }

        $batch = $leg->deliveryBatch;
        if ($batch) {
            $boundary = $batch->offered_at
                ?? $batch->accepted_at
                ?? $batch->started_at
                ?? $batch->created_at;

            if ($boundary instanceof DateTimeInterface) {
                return CarbonImmutable::instance($boundary)->setTimezone($timezone);
            }
        }

        throw new RuntimeException('logistics_responsibility_inconsistent');
    }
}
