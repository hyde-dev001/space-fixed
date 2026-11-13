<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Services\ShopDocumentValidityService;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class PendingComplianceRenewalAttentionAdapter implements OwnerAttentionAdapter
{
    public function __construct(
        private readonly ShopDocumentValidityService $validity,
    ) {}

    public function adapterKey(): string
    {
        return 'pending_compliance_renewals';
    }

    public function coverageSource(): string
    {
        return 'compliance';
    }

    public function primaryBucket(): string
    {
        return 'waiting_on_others';
    }

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
    {
        $this->assertLifecycleIsConsistent($owner);

        $timezone = (string) config('app.shop_timezone', 'Asia/Manila');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $windowEnd = $today->addDays(ShopDocumentValidityService::RENEWAL_WINDOW_DAYS)->toDateString();

        $baseQuery = ShopDocument::query()
            ->select([
                'id',
                'shop_owner_id',
                'document_type',
                'logical_slot',
                'version_number',
                'status',
                'is_current',
                'expiration_mode',
                'expires_on',
                'reviewed_by_super_admin_id',
                'reviewed_at',
            ])
            ->where('shop_owner_id', (int) $owner->getKey())
            ->currentApproved()
            ->where('expiration_mode', 'dated')
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $windowEnd)
            ->whereHas('successors', static function (Builder $successorQuery) use ($owner): void {
                $successorQuery
                    ->where('shop_owner_id', (int) $owner->getKey())
                    ->pendingRenewals();
            })
            ->with([
                'successors' => static function ($successorQuery) use ($owner): void {
                    $successorQuery
                        ->select([
                            'id',
                            'shop_owner_id',
                            'document_type',
                            'logical_slot',
                            'version_number',
                            'predecessor_document_id',
                            'status',
                            'is_current',
                            'created_at',
                        ])
                        ->where('shop_owner_id', (int) $owner->getKey())
                        ->pendingRenewals();
                },
            ])
            ->orderBy('expires_on')
            ->orderBy('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $documents = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = $documents
            ->map(function (ShopDocument $document) use ($today, $timezone): OwnerAttentionItem {
                $successors = $document->successors;
                if ($successors->count() !== 1) {
                    throw new RuntimeException('compliance_document_lifecycle_inconsistent');
                }

                return $this->toItem($document, $successors->first(), $today, $timezone);
            })
            ->all();

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }

    private function toItem(
        ShopDocument $predecessor,
        ShopDocument $successor,
        CarbonImmutable $today,
        string $timezone,
    ): OwnerAttentionItem {
        $window = $this->validity->expiryWindow($predecessor, $today);
        [$priority, $materiality] = match ($window) {
            ShopDocumentValidityService::RENEWAL_WINDOW => ['normal', 'medium'],
            ShopDocumentValidityService::URGENT_WINDOW => ['high', 'high'],
            ShopDocumentValidityService::EXPIRES_TODAY,
            ShopDocumentValidityService::EXPIRED => ['critical', 'critical'],
            default => throw new RuntimeException('compliance_document_lifecycle_inconsistent'),
        };

        $expirationDate = CarbonImmutable::parse(
            $predecessor->expires_on->toDateString(),
            $timezone,
        )->startOfDay();
        $windowOpenedAt = $expirationDate->subDays(ShopDocumentValidityService::RENEWAL_WINDOW_DAYS);
        if (! $successor->created_at instanceof DateTimeInterface) {
            throw new RuntimeException('compliance_document_lifecycle_inconsistent');
        }

        $submittedAt = CarbonImmutable::instance($successor->created_at)->setTimezone($timezone);
        $actionableSince = $submittedAt->greaterThan($windowOpenedAt) ? $submittedAt : $windowOpenedAt;

        return new OwnerAttentionItem(
            sourceType: 'compliance_document',
            sourceId: (int) $successor->getKey(),
            category: 'renewal_review_waiting',
            primaryBucket: $this->primaryBucket(),
            module: 'compliance',
            title: $predecessor->document_type_name.' renewal review',
            conciseSummary: 'This renewal submission is awaiting Compliance Review.',
            priorityTier: $priority,
            materialityTier: $materiality,
            comparableMonetaryExposure: null,
            urgencyAt: $expirationDate->toISOString(),
            actionableSince: $actionableSince->toISOString(),
            waitingOn: 'super_admin',
            ownerActionRequired: false,
            coverageSource: $this->coverageSource(),
            destinationUrl: route('shop-owner.shell.settings.policies-compliance', [], false),
        );
    }

    private function assertLifecycleIsConsistent(ShopOwner $owner): void
    {
        $ownerId = (int) $owner->getKey();

        $invalidCurrentExists = ShopDocument::query()
            ->where('shop_owner_id', $ownerId)
            ->where('is_current', true)
            ->where('status', 'approved')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('reviewed_by_super_admin_id')
                    ->orWhereNull('reviewed_at')
                    ->orWhereNotIn('expiration_mode', ['dated', 'none'])
                    ->orWhere(function (Builder $datedQuery): void {
                        $datedQuery
                            ->where('expiration_mode', 'dated')
                            ->whereNull('expires_on');
                    });
            })
            ->exists();

        $crossTenantSuccessorExists = ShopDocument::query()
            ->pendingRenewals()
            ->where('shop_owner_id', '!=', $ownerId)
            ->whereHas('predecessor', static fn (Builder $query): Builder => $query
                ->where('shop_owner_id', $ownerId))
            ->exists();

        $multiplePendingSuccessorsExists = ShopDocument::query()
            ->pendingRenewals()
            ->where('shop_owner_id', $ownerId)
            ->whereHas('predecessor', static fn (Builder $query): Builder => $query
                ->where('shop_owner_id', $ownerId))
            ->select('predecessor_document_id')
            ->groupBy('predecessor_document_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($invalidCurrentExists || $crossTenantSuccessorExists || $multiplePendingSuccessorsExists) {
            throw new RuntimeException('compliance_document_lifecycle_inconsistent');
        }
    }
}
