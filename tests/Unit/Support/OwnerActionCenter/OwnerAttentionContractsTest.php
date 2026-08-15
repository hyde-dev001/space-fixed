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
            'destination_url' => '/shop-owner/refund-approvals?refund=42',
        ], $item->toArray());

        $this->assertArrayNotHasKey('key', $item->toArray());
        $this->assertTrue((new ReflectionClass($item))->isReadOnly());
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
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'high',
                materialityTier: 'medium',
                comparableMonetaryExposure: 1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
                destinationUrl: '/shop-owner/expense-approvals',
            )],
            'external destination' => [static fn (): OwnerAttentionItem => new OwnerAttentionItem(
                sourceType: 'expense',
                sourceId: 1,
                category: 'owner_approval',
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'high',
                materialityTier: 'medium',
                comparableMonetaryExposure: 1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
                destinationUrl: 'https://example.test/expense',
            )],
            'negative exposure' => [static fn (): OwnerAttentionItem => new OwnerAttentionItem(
                sourceType: 'expense',
                sourceId: 1,
                category: 'owner_approval',
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'high',
                materialityTier: 'medium',
                comparableMonetaryExposure: -1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
                destinationUrl: '/shop-owner/expense-approvals',
            )],
            'invalid priority tier' => [static fn (): OwnerAttentionItem => new OwnerAttentionItem(
                sourceType: 'expense',
                sourceId: 1,
                category: 'owner_approval',
                module: 'finance',
                title: 'Expense',
                conciseSummary: 'Review expense.',
                priorityTier: 'routine',
                materialityTier: 'medium',
                comparableMonetaryExposure: 1.0,
                urgencyAt: null,
                actionableSince: '2026-08-15T09:00:00+08:00',
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
            module: 'retail',
            title: 'Refund request',
            conciseSummary: 'Verify the requested refund.',
            priorityTier: 'high',
            materialityTier: 'medium',
            comparableMonetaryExposure: 125.5,
            urgencyAt: null,
            actionableSince: '2026-08-15T09:00:00+08:00',
            destinationUrl: '/shop-owner/refund-approvals?refund=42',
        );

        $this->assertNotSame($first->attentionKey, $second->attentionKey);
        $this->assertSame('order_refund:42:owner_approval', $first->attentionKey);
        $this->assertSame('order_refund:42:verification', $second->attentionKey);
    }

    public function test_query_accepts_only_bounded_coverage_and_pagination(): void
    {
        $query = new OwnerAttentionQuery(
            coverage: 'refunds',
            page: 2,
            perPage: 10,
            candidateLimit: 20,
        );

        $this->assertSame('refunds', $query->coverage);
        $this->assertSame(2, $query->page);
        $this->assertSame(10, $query->perPage);
        $this->assertSame(20, $query->candidateLimit);
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
            'page below one' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(page: 0)],
            'page beyond bound' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(page: 101)],
            'per page below one' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(perPage: 0)],
            'per page beyond bound' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(perPage: 51)],
            'candidate bound below one' => [static fn (): OwnerAttentionQuery => new OwnerAttentionQuery(candidateLimit: 0)],
        ];
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
            module: 'retail',
            title: 'Refund request',
            conciseSummary: 'Review the requested refund.',
            priorityTier: 'high',
            materialityTier: 'medium',
            comparableMonetaryExposure: 125.5,
            urgencyAt: null,
            actionableSince: '2026-08-15T09:00:00+08:00',
            destinationUrl: '/shop-owner/refund-approvals?refund=42',
        );
    }
}
