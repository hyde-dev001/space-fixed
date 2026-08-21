<?php

declare(strict_types=1);

namespace Tests\Unit\Support\OwnerActionCenter;

use App\Enums\OwnerActionCenterDegradationStatus;
use App\Support\OwnerActionCenter\OwnerActionCenterResult;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

final class OwnerAttentionContractsTest extends TestCase
{
    public function test_attention_item_serializes_the_frozen_contract_and_derived_identity(): void
    {
        $item = $this->item();

        $this->assertSame([
            'attention_key' => 'order_refund:42:owner_approval',
            'source_type' => 'order_refund',
            'source_id' => 42,
            'category' => 'owner_approval',
            'primary_bucket' => 'needs_my_decision',
            'module' => 'retail',
            'title' => 'Refund request',
            'concise_summary' => 'Review the requested refund.',
            'priority_tier' => 'high',
            'materiality_tier' => 'medium',
            'comparable_monetary_exposure' => 125.5,
            'urgency_at' => null,
            'actionable_since' => '2026-08-15T09:00:00+08:00',
            'waiting_on' => 'shop_owner',
            'owner_action_required' => true,
            'coverage_source' => 'refunds',
            'destination_url' => '/shop-owner/refund-approvals?refund=42',
        ], $item->toArray());

        $this->assertArrayNotHasKey('key', $item->toArray());
        $this->assertTrue((new ReflectionClass($item))->isReadOnly());
    }

    public function test_attention_item_requires_explicit_classification_and_coverage(): void
    {
        $item = new OwnerAttentionItem(
            sourceType: 'order_refund',
            sourceId: 42,
            category: 'owner_approval',
            primaryBucket: 'needs_my_decision',
            module: 'retail',
            title: 'Refund request',
            conciseSummary: 'Review the requested refund.',
            priorityTier: 'critical',
            materialityTier: 'critical',
            comparableMonetaryExposure: 125.5,
            urgencyAt: null,
            actionableSince: '2026-08-15T09:00:00+08:00',
            waitingOn: 'shop_owner',
            ownerActionRequired: true,
            coverageSource: 'refunds',
            destinationUrl: '/shop-owner/refund-approvals?refund=42',
        );

        $this->assertSame('needs_my_decision', $item->primaryBucket);
        $this->assertSame('shop_owner', $item->waitingOn);
        $this->assertTrue($item->ownerActionRequired);
        $this->assertSame('refunds', $item->coverageSource);
        $this->assertSame('critical', $item->priorityTier);
        $this->assertSame('critical', $item->materialityTier);
        $this->assertSame('refunds', $item->toArray()['coverage_source']);
    }

    public function test_legacy_urgent_priority_is_not_supported(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OwnerAttentionItem(
            sourceType: 'expense',
            sourceId: 1,
            category: 'owner_approval',
            primaryBucket: 'needs_my_decision',
            module: 'finance',
            title: 'Expense',
            conciseSummary: 'Review expense.',
            priorityTier: 'urgent',
            materialityTier: 'high',
            comparableMonetaryExposure: 1.0,
            urgencyAt: null,
            actionableSince: '2026-08-15T09:00:00+08:00',
            waitingOn: 'shop_owner',
            ownerActionRequired: true,
            coverageSource: 'expenses',
            destinationUrl: '/shop-owner/expense-approvals',
        );
    }

    public function test_attention_item_rejects_inconsistent_classification_and_coverage(): void
    {
        $invalid = [
            ['needs_my_decision', 'none', true, 'expenses'],
            ['urgent_exceptions', 'shop_owner', false, 'expenses'],
            ['waiting_on_others', 'finance', true, 'expenses'],
            ['needs_my_decision', 'shop_owner', true, 'refunds'],
        ];

        foreach ($invalid as [$bucket, $waitingOn, $ownerActionRequired, $coverage]) {
            try {
                $this->classifiedExpense($bucket, $waitingOn, $ownerActionRequired, $coverage);
                $this->fail('Invalid owner attention classification was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_waiting_on_others_accepts_only_other_party_responsibility_values(): void
    {
        foreach (['super_admin', 'finance', 'payment_recovery', 'rider', 'dispatcher'] as $waitingOn) {
            $item = $this->classifiedExpense('waiting_on_others', $waitingOn, false, 'expenses');

            $this->assertSame('waiting_on_others', $item->primaryBucket);
            $this->assertSame($waitingOn, $item->waitingOn);
            $this->assertFalse($item->ownerActionRequired);
        }
    }

    #[DataProvider('invalidWaitingClassificationProvider')]
    public function test_waiting_on_others_rejects_owner_or_unowned_responsibility(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }

    /**
     * @return array<string, array{callable(): OwnerAttentionItem}>
     */
    public static function invalidWaitingClassificationProvider(): array
    {
        return [
            'owner responsibility' => [static fn (): OwnerAttentionItem => new OwnerAttentionItem(
                sourceType: 'expense',
                sourceId: 1,
                category: 'owner_approval',
                primaryBucket: 'waiting_on_others',
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'high',
                materialityTier: 'medium',
                comparableMonetaryExposure: 1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
                waitingOn: 'shop_owner',
                ownerActionRequired: false,
                coverageSource: 'expenses',
                destinationUrl: '/shop-owner/expense-approvals',
            )],
            'unowned responsibility' => [static fn (): OwnerAttentionItem => new OwnerAttentionItem(
                sourceType: 'expense',
                sourceId: 1,
                category: 'owner_approval',
                primaryBucket: 'waiting_on_others',
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'high',
                materialityTier: 'medium',
                comparableMonetaryExposure: 1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
                waitingOn: 'none',
                ownerActionRequired: false,
                coverageSource: 'expenses',
                destinationUrl: '/shop-owner/expense-approvals',
            )],
            'unknown responsibility' => [static fn (): OwnerAttentionItem => new OwnerAttentionItem(
                sourceType: 'expense',
                sourceId: 1,
                category: 'owner_approval',
                primaryBucket: 'waiting_on_others',
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'high',
                materialityTier: 'medium',
                comparableMonetaryExposure: 1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
                waitingOn: 'unknown_party',
                ownerActionRequired: false,
                coverageSource: 'expenses',
                destinationUrl: '/shop-owner/expense-approvals',
            )],
            'owner action required' => [static fn (): OwnerAttentionItem => new OwnerAttentionItem(
                sourceType: 'expense',
                sourceId: 1,
                category: 'owner_approval',
                primaryBucket: 'waiting_on_others',
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'high',
                materialityTier: 'medium',
                comparableMonetaryExposure: 1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
                waitingOn: 'payment_recovery',
                ownerActionRequired: true,
                coverageSource: 'expenses',
                destinationUrl: '/shop-owner/expense-approvals',
            )],
        ];
    }

    #[DataProvider('invalidAttentionItemProvider')]
    public function test_attention_item_rejects_invalid_values(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }

    /**
     * @return array<string, array{callable(): OwnerAttentionItem}>
     */
    public static function invalidAttentionItemProvider(): array
    {
        return [
            'unknown source type' => [static fn (): OwnerAttentionItem => new OwnerAttentionItem(
                sourceType: 'approval',
                sourceId: 1,
                category: 'owner_approval',
                primaryBucket: 'needs_my_decision',
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'high',
                materialityTier: 'medium',
                comparableMonetaryExposure: 1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: 'expenses',
                destinationUrl: '/shop-owner/expense-approvals',
            )],
            'external destination' => [static fn (): OwnerAttentionItem => new OwnerAttentionItem(
                sourceType: 'expense',
                sourceId: 1,
                category: 'owner_approval',
                primaryBucket: 'needs_my_decision',
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'high',
                materialityTier: 'medium',
                comparableMonetaryExposure: 1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: 'expenses',
                destinationUrl: 'https://example.test/expense',
            )],
            'negative exposure' => [static fn (): OwnerAttentionItem => new OwnerAttentionItem(
                sourceType: 'expense',
                sourceId: 1,
                category: 'owner_approval',
                primaryBucket: 'needs_my_decision',
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'high',
                materialityTier: 'medium',
                comparableMonetaryExposure: -1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: 'expenses',
                destinationUrl: '/shop-owner/expense-approvals',
            )],
            'invalid priority tier' => [static fn (): OwnerAttentionItem => new OwnerAttentionItem(
                sourceType: 'expense',
                sourceId: 1,
                category: 'owner_approval',
                primaryBucket: 'needs_my_decision',
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'routine',
                materialityTier: 'medium',
                comparableMonetaryExposure: 1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: 'expenses',
                destinationUrl: '/shop-owner/expense-approvals',
            )],
        ];
    }

    public function test_attention_identity_changes_only_when_source_identity_changes(): void
    {
        $first = $this->item();
        $second = new OwnerAttentionItem(
            sourceType: 'order_refund',
            sourceId: 42,
            category: 'verification',
            primaryBucket: 'needs_my_decision',
            module: 'retail',
            title: 'Refund request',
            conciseSummary: 'Verify the requested refund.',
            priorityTier: 'high',
            materialityTier: 'medium',
            comparableMonetaryExposure: 125.5,
            urgencyAt: null,
            actionableSince: '2026-08-15T09:00:00+08:00',
            waitingOn: 'shop_owner',
            ownerActionRequired: true,
            coverageSource: 'refunds',
            destinationUrl: '/shop-owner/refund-approvals?refund=42',
        );

        $this->assertNotSame($first->attentionKey, $second->attentionKey);
        $this->assertSame('order_refund:42:owner_approval', $first->attentionKey);
        $this->assertSame('order_refund:42:verification', $second->attentionKey);
    }

    public function test_query_accepts_only_bounded_coverage_and_pagination(): void
    {
        $query = new OwnerAttentionQuery(
            bucket: 'urgent_exceptions',
            coverage: 'compliance',
            page: 2,
            perPage: 10,
            candidateLimit: 20,
        );

        $this->assertSame('urgent_exceptions', $query->bucket);
        $this->assertSame('compliance', $query->coverage);
        $this->assertSame(2, $query->page);
        $this->assertSame(10, $query->perPage);
        $this->assertSame(20, $query->candidateLimit);
    }

    public function test_waiting_on_others_exposes_only_phase_three_c_coverage_families(): void
    {
        foreach (['all', 'compliance', 'refunds', 'logistics'] as $coverage) {
            $query = new OwnerAttentionQuery(
                bucket: 'waiting_on_others',
                coverage: $coverage,
            );

            $this->assertSame($coverage, $query->coverage);
        }
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_query_rejects_invalid_coverage_and_pagination(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }

    /**
     * @return array<string, array{callable(): OwnerAttentionQuery}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'unsupported coverage' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(coverage: 'audit')],
            'unsupported bucket' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(bucket: 'notifications')],
            'decision source in exception bucket' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(
                bucket: 'urgent_exceptions',
                coverage: 'expenses',
            )],
            'exception source in decision bucket' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(
                bucket: 'needs_my_decision',
                coverage: 'compliance',
            )],
            'expense source in waiting bucket' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(
                bucket: 'waiting_on_others',
                coverage: 'expenses',
            )],
            'purchase request source in waiting bucket' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(
                bucket: 'waiting_on_others',
                coverage: 'purchase_requests',
            )],
            'page below one' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(page: 0)],
            'page beyond bound' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(page: 101)],
            'per page below one' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(perPage: 0)],
            'per page beyond bound' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(perPage: 51)],
            'candidate bound below one' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(candidateLimit: 0)],
        ];
    }

    public function test_action_center_result_accepts_phase_three_c_adapter_keys(): void
    {
        $adapterKeys = [
            'pending_compliance_renewals',
            'waiting_order_refund_recovery',
            'waiting_repair_refund_recovery',
            'active_logistics_recovery',
        ];

        $result = new OwnerActionCenterResult(
            items: [],
            coverageCounts: [
                'compliance' => 0,
                'refunds' => 0,
                'logistics' => 0,
            ],
            enabledAdapterKeys: $adapterKeys,
            healthyAdapterKeys: $adapterKeys,
            failedAdapterKeys: [],
            degradationStatus: OwnerActionCenterDegradationStatus::None,
            bucket: 'waiting_on_others',
            coverage: 'all',
            page: 1,
            perPage: 20,
            total: 0,
            lastPage: 1,
        );

        $this->assertSame($adapterKeys, $result->toArray()['health']['enabled_adapter_keys']);
    }

    public function test_adapter_result_and_action_center_result_validate_immutable_shapes(): void
    {
        $item = $this->item();
        $adapterResult = new OwnerAttentionAdapterResult([$item], 3);
        $result = new OwnerActionCenterResult(
            items: [$item],
            coverageCounts: [
                'refunds' => 1,
                'expenses' => 0,
                'purchase_requests' => 0,
            ],
            enabledAdapterKeys: ['order_refunds'],
            healthyAdapterKeys: ['order_refunds'],
            failedAdapterKeys: [],
            degradationStatus: OwnerActionCenterDegradationStatus::None,
            bucket: 'needs_my_decision',
            coverage: 'all',
            page: 1,
            perPage: 20,
            total: 1,
            lastPage: 1,
        );

        $this->assertSame(3, $adapterResult->qualifyingCount);
        $this->assertSame(1, $result->total);
        $this->assertSame(1, $result->lastPage);
        $this->assertSame('none', $result->toArray()['degradation_status']);
        $this->assertTrue((new ReflectionClass($adapterResult))->isReadOnly());
        $this->assertTrue((new ReflectionClass($result))->isReadOnly());
    }

    public function test_action_center_result_rejects_a_page_outside_the_last_page(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OwnerActionCenterResult(
            items: [],
            coverageCounts: [],
            enabledAdapterKeys: [],
            healthyAdapterKeys: [],
            failedAdapterKeys: [],
            degradationStatus: OwnerActionCenterDegradationStatus::None,
            bucket: 'needs_my_decision',
            coverage: 'all',
            page: 2,
            perPage: 20,
            total: 1,
            lastPage: 1,
        );
    }

    private function item(): OwnerAttentionItem
    {
        return new OwnerAttentionItem(
            sourceType: 'order_refund',
            sourceId: 42,
            category: 'owner_approval',
            primaryBucket: 'needs_my_decision',
            module: 'retail',
            title: 'Refund request',
            conciseSummary: 'Review the requested refund.',
            priorityTier: 'high',
            materialityTier: 'medium',
            comparableMonetaryExposure: 125.5,
            urgencyAt: null,
            actionableSince: '2026-08-15T09:00:00+08:00',
            waitingOn: 'shop_owner',
            ownerActionRequired: true,
            coverageSource: 'refunds',
            destinationUrl: '/shop-owner/refund-approvals?refund=42',
        );
    }

    private function classifiedExpense(
        string $bucket,
        string $waitingOn,
        bool $ownerActionRequired,
        string $coverage,
    ): OwnerAttentionItem {
        return new OwnerAttentionItem(
            sourceType: 'expense',
            sourceId: 1,
            category: 'owner_approval',
            primaryBucket: $bucket,
            module: 'finance',
            title: 'Expense',
            conciseSummary: 'Review expense.',
            priorityTier: 'high',
            materialityTier: 'medium',
            comparableMonetaryExposure: 1.0,
            urgencyAt: null,
            actionableSince: '2026-08-15T09:00:00+08:00',
            waitingOn: $waitingOn,
            ownerActionRequired: $ownerActionRequired,
            coverageSource: $coverage,
            destinationUrl: '/shop-owner/expense-approvals',
        );
    }
}
