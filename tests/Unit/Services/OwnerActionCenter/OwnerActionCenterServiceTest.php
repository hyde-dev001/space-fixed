<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OwnerActionCenter;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Enums\OwnerActionCenterDegradationStatus;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\OwnerActionCenterService;
use App\Services\OwnerActionCenter\OwnerAttentionAdapterRegistry;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class OwnerActionCenterServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\OrderRefundAttentionAdapter';

    private const REPAIR_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\RepairRefundAttentionAdapter';

    private const EXPENSE_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\ExpenseAttentionAdapter';

    private const PURCHASE_REQUEST_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\PurchaseRequestAttentionAdapter';

    public function test_refunds_coverage_resolves_both_refund_adapters(): void
    {
        config([
            'owner_action_center.coverage.refunds' => true,
            'owner_action_center.coverage.expenses' => false,
            'owner_action_center.coverage.purchase_requests' => false,
        ]);
        $order = $this->bindFake(self::ORDER_ADAPTER, 'order_refunds', 'refunds');
        $repair = $this->bindFake(self::REPAIR_ADAPTER, 'repair_refunds', 'refunds');

        $adapters = app(OwnerAttentionAdapterRegistry::class)->adaptersFor('refunds');

        $this->assertSame([$order, $repair], $adapters);
    }

    public function test_registry_rejects_an_adapter_that_reports_the_wrong_identity(): void
    {
        config([
            'owner_action_center.coverage.refunds' => true,
            'owner_action_center.coverage.expenses' => false,
            'owner_action_center.coverage.purchase_requests' => false,
        ]);
        $this->bindFake(self::ORDER_ADAPTER, 'expenses', 'expenses');
        $this->expectException(InvalidArgumentException::class);

        app(OwnerAttentionAdapterRegistry::class)->adaptersFor('refunds');
    }

    public function test_source_filter_is_applied_before_candidate_limits(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableOnly('expenses');
        $expense = $this->bindFake(
            self::EXPENSE_ADAPTER,
            'expenses',
            'expenses',
            [$this->item('expense', 7)],
        );

        $result = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(coverage: 'expenses', page: 2, perPage: 2),
        );

        $this->assertCount(1, $expense->queries);
        $this->assertSame('expenses', $expense->queries[0]->coverage);
        $this->assertSame(4, $expense->queries[0]->candidateLimit);
        $this->assertSame(['expense:7:owner_approval'], array_map(
            static fn (OwnerAttentionItem $item): string => $item->attentionKey,
            $result->items,
        ));
    }

    public function test_duplicate_attention_keys_are_removed_before_owner_facing_counts(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableOnly('refunds');
        $duplicate = $this->item('order_refund', 9);
        $this->bindFake(
            self::ORDER_ADAPTER,
            'order_refunds',
            'refunds',
            [$duplicate, $duplicate],
            2,
        );
        $this->bindFake(
            self::REPAIR_ADAPTER,
            'repair_refunds',
            'refunds',
            [$duplicate, $this->item('repair_refund', 10)],
            2,
        );

        $result = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(perPage: 10),
        );

        $this->assertSame(2, $result->total);
        $this->assertSame(2, $result->coverageCounts['refunds']);
        $this->assertCount(2, $result->items);
    }

    public function test_full_qualifying_counts_extend_beyond_the_bounded_candidate_list(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableOnly('expenses');
        $this->bindFake(
            self::EXPENSE_ADAPTER,
            'expenses',
            'expenses',
            [$this->item('expense', 1)],
            5,
        );

        $result = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(perPage: 2),
        );

        $this->assertSame(5, $result->total);
        $this->assertSame(3, $result->lastPage);
        $this->assertSame(5, $result->coverageCounts['expenses']);
    }

    public function test_items_are_globally_ordered_by_priority_materiality_exposure_and_age(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableOnly('expenses');
        $this->bindFake(
            self::EXPENSE_ADAPTER,
            'expenses',
            'expenses',
            [
                $this->item('expense', 1, 'normal', 'high', 500.0, null, '2026-08-10T09:00:00+08:00'),
                $this->item('expense', 2, 'critical', 'low', 1.0, '2026-08-16T09:00:00+08:00'),
                $this->item('expense', 3, 'high', 'high', 300.0, '2026-08-14T09:00:00+08:00'),
                $this->item('expense', 4, 'high', 'high', 100.0, '2026-08-14T09:00:00+08:00'),
                $this->item('expense', 5, 'normal', 'high', 800.0, '2026-08-14T09:00:00+08:00'),
            ],
        );

        $result = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(perPage: 10),
        );

        $this->assertSame([
            'expense:2:owner_approval',
            'expense:3:owner_approval',
            'expense:4:owner_approval',
            'expense:5:owner_approval',
            'expense:1:owner_approval',
        ], array_map(
            static fn (OwnerAttentionItem $item): string => $item->attentionKey,
            $result->items,
        ));
    }

    public function test_page_candidate_bound_is_page_times_per_page_and_results_are_interleaved(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableOnly('refunds');
        $order = $this->bindFake(
            self::ORDER_ADAPTER,
            'order_refunds',
            'refunds',
            [
                $this->item('order_refund', 1, 'critical'),
                $this->item('order_refund', 2, 'normal'),
                $this->item('order_refund', 3, 'low'),
            ],
        );
        $repair = $this->bindFake(
            self::REPAIR_ADAPTER,
            'repair_refunds',
            'refunds',
            [
                $this->item('repair_refund', 1, 'high'),
                $this->item('repair_refund', 2, 'normal'),
                $this->item('repair_refund', 3, 'low'),
            ],
        );

        $result = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(page: 2, perPage: 2),
        );

        $this->assertSame(4, $order->queries[0]->candidateLimit);
        $this->assertSame(4, $repair->queries[0]->candidateLimit);
        $this->assertSame([
            'order_refund:2:owner_approval',
            'repair_refund:2:owner_approval',
        ], array_map(
            static fn (OwnerAttentionItem $item): string => $item->attentionKey,
            $result->items,
        ));
    }

    public function test_refreshing_a_page_that_is_now_past_the_last_page_normalizes_to_the_last_page(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableOnly('purchase_requests');
        $this->bindFake(
            self::PURCHASE_REQUEST_ADAPTER,
            'purchase_requests',
            'purchase_requests',
            [$this->item('purchase_request', 1)],
        );

        $result = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(page: 3, perPage: 1),
        );

        $this->assertSame(1, $result->page);
        $this->assertSame(1, $result->lastPage);
        $this->assertSame(['purchase_request:1:owner_approval'], array_map(
            static fn (OwnerAttentionItem $item): string => $item->attentionKey,
            $result->items,
        ));
    }

    public function test_one_failed_adapter_preserves_healthy_data_and_marks_partial_degradation(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableOnly('refunds');
        $this->bindFake(
            self::ORDER_ADAPTER,
            'order_refunds',
            'refunds',
            [$this->item('order_refund', 1)],
        );
        $this->bindFake(
            self::REPAIR_ADAPTER,
            'repair_refunds',
            'refunds',
            failure: new RuntimeException('repair adapter unavailable'),
        );

        $result = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(),
        );

        $this->assertSame(OwnerActionCenterDegradationStatus::Partial, $result->degradationStatus);
        $this->assertSame(['order_refunds'], $result->healthyAdapterKeys);
        $this->assertSame(['repair_refunds'], $result->failedAdapterKeys);
        $this->assertSame(['order_refund:1:owner_approval'], array_map(
            static fn (OwnerAttentionItem $item): string => $item->attentionKey,
            $result->items,
        ));
    }

    public function test_all_enabled_adapters_failing_is_unavailable_and_never_zero_success(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableOnly('expenses');
        $this->bindFake(
            self::EXPENSE_ADAPTER,
            'expenses',
            'expenses',
            failure: new RuntimeException('expense adapter unavailable'),
        );

        $result = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(),
        );

        $this->assertSame(OwnerActionCenterDegradationStatus::Unavailable, $result->degradationStatus);
        $this->assertSame(0, $result->total);
        $this->assertSame([], $result->items);
        $this->assertSame(['expenses'], $result->failedAdapterKeys);
    }

    public function test_no_enabled_adapters_is_configuration_degradation_and_never_zero_success(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableOnly();

        $result = app(OwnerActionCenterService::class)->summaryForHome($owner);

        $this->assertSame(OwnerActionCenterDegradationStatus::NoEnabledAdapters, $result->degradationStatus);
        $this->assertSame(0, $result->total);
        $this->assertSame([], $result->items);
        $this->assertSame([], $result->toArray()['health']['enabled_adapter_keys']);
    }

    private function enableOnly(?string $coverage = null): void
    {
        config([
            'owner_action_center.coverage.refunds' => $coverage === 'refunds',
            'owner_action_center.coverage.expenses' => $coverage === 'expenses',
            'owner_action_center.coverage.purchase_requests' => $coverage === 'purchase_requests',
            'owner_action_center.home_limit' => 5,
        ]);
    }

    /**
     * @param array<int, OwnerAttentionItem> $items
     */
    private function bindFake(
        string $class,
        string $adapterKey,
        string $coverage,
        array $items = [],
        ?int $qualifyingCount = null,
        ?Throwable $failure = null,
    ): object {
        $adapter = new class($adapterKey, $coverage, $items, $qualifyingCount, $failure) implements OwnerAttentionAdapter {
            /** @var array<int, OwnerAttentionQuery> */
            public array $queries = [];

            /** @param array<int, OwnerAttentionItem> $items */
            public function __construct(
                private readonly string $adapterKeyValue,
                private readonly string $coverageValue,
                private readonly array $items,
                private readonly ?int $qualifyingCount,
                private readonly ?Throwable $failure,
            ) {}

            public function adapterKey(): string
            {
                return $this->adapterKeyValue;
            }

            public function coverageSource(): string
            {
                return $this->coverageValue;
            }

            public function primaryBucket(): string
            {
                return 'needs_my_decision';
            }

            public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
            {
                $this->queries[] = $query;

                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return new OwnerAttentionAdapterResult(
                    $this->items,
                    $this->qualifyingCount ?? count($this->items),
                );
            }
        };

        app()->instance($class, $adapter);

        return $adapter;
    }

    private function item(
        string $sourceType,
        int $sourceId,
        string $priority = 'normal',
        string $materiality = 'medium',
        ?float $exposure = 100.0,
        ?string $urgencyAt = null,
        string $actionableSince = '2026-08-15T09:00:00+08:00',
        string $category = 'owner_approval',
    ): OwnerAttentionItem {
        return new OwnerAttentionItem(
            sourceType: $sourceType,
            sourceId: $sourceId,
            category: $category,
            primaryBucket: 'needs_my_decision',
            module: match ($sourceType) {
                'order_refund', 'repair_refund' => 'refunds',
                'expense' => 'finance',
                'purchase_request' => 'procurement',
            },
            title: ucfirst(str_replace('_', ' ', $sourceType)),
            conciseSummary: 'Review this decision.',
            priorityTier: $priority,
            materialityTier: $materiality,
            comparableMonetaryExposure: $exposure,
            urgencyAt: $urgencyAt,
            actionableSince: $actionableSince,
            waitingOn: 'shop_owner',
            ownerActionRequired: true,
            coverageSource: match ($sourceType) {
                'order_refund', 'repair_refund' => 'refunds',
                'expense' => 'expenses',
                'purchase_request' => 'purchase_requests',
            },
            destinationUrl: '/shop-owner/action-center?source='.$sourceType.'&id='.$sourceId,
        );
    }
}
