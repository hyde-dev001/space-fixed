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
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class ComplianceDocumentAttentionAdapter implements OwnerAttentionAdapter
{
    public function __construct(
        private readonly ShopDocumentValidityService $validity,
    ) {}

    public function adapterKey(): string
    {
        return 'compliance_documents';
    }

    public function coverageSource(): string
    {
        return 'compliance';
    }

    public function primaryBucket(): string
    {
        return 'urgent_exceptions';
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
            ->whereDoesntHave('successors', static function (Builder $successorQuery) use ($owner): void {
                $successorQuery
                    ->where('shop_owner_id', (int) $owner->getKey())
                    ->pendingRenewals();
            })
            ->orderBy('expires_on')
            ->orderBy('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $documents = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = $documents
            ->map(fn (ShopDocument $document): OwnerAttentionItem => $this->toItem($document, $today, $timezone))
            ->all();

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }

    private function toItem(
        ShopDocument $document,
        CarbonImmutable $today,
        string $timezone,
    ): OwnerAttentionItem {
        $window = $this->validity->expiryWindow($document, $today);
        [$priority, $materiality] = match ($window) {
            ShopDocumentValidityService::RENEWAL_WINDOW => ['normal', 'medium'],
            ShopDocumentValidityService::URGENT_WINDOW => ['high', 'high'],
            ShopDocumentValidityService::EXPIRES_TODAY,
            ShopDocumentValidityService::EXPIRED => ['critical', 'critical'],
            default => throw new RuntimeException('compliance_document_lifecycle_inconsistent'),
        };

        $expirationDate = CarbonImmutable::parse(
            $document->expires_on->toDateString(),
            $timezone,
        )->startOfDay();
        $windowOpenedAt = $expirationDate->subDays(ShopDocumentValidityService::RENEWAL_WINDOW_DAYS);
        $reviewedAt = CarbonImmutable::instance($document->reviewed_at)
            ->setTimezone($timezone)
            ->startOfDay();
        $actionableSince = $reviewedAt->greaterThan($windowOpenedAt) ? $reviewedAt : $windowOpenedAt;

        return new OwnerAttentionItem(
            sourceType: 'compliance_document',
            sourceId: (int) $document->getKey(),
            category: 'document_expiry',
            primaryBucket: $this->primaryBucket(),
            module: 'compliance',
            title: $document->document_type_name.' expiry',
            conciseSummary: 'This document is within its renewal window and has no pending renewal.',
            priorityTier: $priority,
            materialityTier: $materiality,
            comparableMonetaryExposure: null,
            urgencyAt: $expirationDate->toISOString(),
            actionableSince: $actionableSince->toISOString(),
            waitingOn: 'none',
            ownerActionRequired: false,
            coverageSource: $this->coverageSource(),
            destinationUrl: route('shop-owner.shell.settings.policies-compliance', [], false),
        );
    }

    private function assertLifecycleIsConsistent(ShopOwner $owner): void
    {
        $invalidCurrentExists = ShopDocument::query()
            ->where('shop_owner_id', (int) $owner->getKey())
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
            ->where('shop_owner_id', '!=', (int) $owner->getKey())
            ->whereHas('predecessor', static fn (Builder $query): Builder => $query
                ->where('shop_owner_id', (int) $owner->getKey()))
            ->exists();

        if ($invalidCurrentExists || $crossTenantSuccessorExists) {
            throw new RuntimeException('compliance_document_lifecycle_inconsistent');
        }
    }
}
