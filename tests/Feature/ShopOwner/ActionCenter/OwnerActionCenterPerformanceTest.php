<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\OwnerActionCenterService;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OwnerActionCenterPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const ADAPTERS = [
        ['class' => 'App\\Services\\OwnerActionCenter\\Adapters\\OrderRefundAttentionAdapter', 'key' => 'order_refunds', 'coverage' => 'refunds', 'source' => 'order_refund'],
        ['class' => 'App\\Services\\OwnerActionCenter\\Adapters\\RepairRefundAttentionAdapter', 'key' => 'repair_refunds', 'coverage' => 'refunds', 'source' => 'repair_refund'],
        ['class' => 'App\\Services\\OwnerActionCenter\\Adapters\\ExpenseAttentionAdapter', 'key' => 'expenses', 'coverage' => 'expenses', 'source' => 'expense'],
        ['class' => 'App\\Services\\OwnerActionCenter\\Adapters\\PurchaseRequestAttentionAdapter', 'key' => 'purchase_requests', 'coverage' => 'purchase_requests', 'source' => 'purchase_request'],
    ];

    private const WAITING_COMPLIANCE_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\PendingComplianceRenewalAttentionAdapter';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'owner_action_center.coverage.refunds' => true,
            'owner_action_center.coverage.expenses' => true,
            'owner_action_center.coverage.purchase_requests' => true,
            'owner_action_center.home_limit' => 3,
        ]);
    }

    public function test_home_and_full_queue_pass_only_bounded_candidate_limits_to_each_adapter(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $adapters = $this->bindAdapters(itemsPerAdapter: 50);
        $service = app(OwnerActionCenterService::class);

        $home = $service->summaryForHome($owner);

        $this->assertSame(3, $home->perPage);
        $this->assertCount(3, $home->items);
        foreach ($adapters as $adapter) {
            $this->assertSame([3], $adapter->candidateLimits);
        }

        foreach ($adapters as $adapter) {
            $adapter->candidateLimits = [];
        }

        $queue = $service->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(page: 4, perPage: 7),
        );

        $this->assertSame(7, $queue->perPage);
        foreach ($adapters as $adapter) {
            $this->assertSame([28], $adapter->candidateLimits);
        }
    }

    public function test_coordinator_reads_each_adapter_once_regardless_of_returned_candidate_count(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $adapters = $this->bindAdapters(itemsPerAdapter: 1);
        $service = app(OwnerActionCenterService::class);

        $service->queueForActionCenter($owner, new OwnerAttentionQuery(perPage: 10));
        $oneRowReads = array_map(static fn ($adapter): int => $adapter->readCalls, $adapters);

        $adapters = $this->bindAdapters(itemsPerAdapter: 40);
        $service->queueForActionCenter($owner, new OwnerAttentionQuery(perPage: 10));
        $manyRowReads = array_map(static fn ($adapter): int => $adapter->readCalls, $adapters);

        $this->assertSame([1, 1, 1, 1], $oneRowReads);
        $this->assertSame($oneRowReads, $manyRowReads);
    }

    public function test_exception_home_and_queue_use_the_same_bounded_adapter_contract(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.refunds' => false,
            'owner_action_center.buckets.urgent_exceptions.coverage.logistics' => false,
        ]);
        $adapter = $this->bindComplianceAdapter(items: 40);
        $service = app(OwnerActionCenterService::class);

        $home = $service->summaryForHome($owner, 'urgent_exceptions');
        $queue = $service->queueForActionCenter($owner, new OwnerAttentionQuery(
            bucket: 'urgent_exceptions',
            coverage: 'compliance',
            page: 1,
            perPage: 3,
        ));

        $this->assertSame([3, 3], $adapter->candidateLimits);
        $this->assertSame($home->coverageCounts, $queue->coverageCounts);
        $this->assertSame(
            array_map(static fn (OwnerAttentionItem $item): string => $item->attentionKey, $home->items),
            array_map(static fn (OwnerAttentionItem $item): string => $item->attentionKey, $queue->items),
        );
    }

    public function test_waiting_home_and_queue_use_the_same_bounded_adapter_contract(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        config([
            'owner_action_center.buckets.waiting_on_others.enabled' => true,
            'owner_action_center.buckets.waiting_on_others.coverage' => [
                'compliance' => true,
                'refunds' => false,
                'logistics' => false,
            ],
        ]);
        $adapter = $this->bindWaitingComplianceAdapter(items: 40);
        $service = app(OwnerActionCenterService::class);

        $home = $service->summaryForHome($owner, 'waiting_on_others');
        $queue = $service->queueForActionCenter($owner, new OwnerAttentionQuery(
            bucket: 'waiting_on_others',
            coverage: 'compliance',
            page: 2,
            perPage: 4,
        ));

        $this->assertSame([3, 8], $adapter->candidateLimits);
        $this->assertSame($home->coverageCounts, $queue->coverageCounts);
        $this->assertSame(40, $home->total);
        $this->assertSame(2, $queue->page);
    }

    /**
     * @return array<int, object>
     */
    private function bindAdapters(int $itemsPerAdapter): array
    {
        $adapters = [];
        foreach (self::ADAPTERS as $definition) {
            $items = [];
            for ($id = 1; $id <= $itemsPerAdapter; $id++) {
                $items[] = new OwnerAttentionItem(
                    sourceType: $definition['source'],
                    sourceId: $id,
                    category: 'owner_approval',
                    primaryBucket: 'needs_my_decision',
                    module: $definition['coverage'],
                    title: ucfirst(str_replace('_', ' ', $definition['source'])),
                    conciseSummary: 'Review this decision.',
                    priorityTier: 'normal',
                    materialityTier: 'low',
                    comparableMonetaryExposure: 10.0,
                    urgencyAt: null,
                    actionableSince: '2026-08-15T09:00:00+08:00',
                    waitingOn: 'shop_owner',
                    ownerActionRequired: true,
                    coverageSource: $definition['coverage'],
                    destinationUrl: '/shop-owner/action-center?source='.$definition['coverage'],
                );
            }

            $adapter = new class($definition['key'], $definition['coverage'], $items) implements OwnerAttentionAdapter {
                /** @var array<int, OwnerAttentionItem> */
                private readonly array $items;

                public int $readCalls = 0;

                /** @var array<int, int> */
                public array $candidateLimits = [];

                /** @param array<int, OwnerAttentionItem> $items */
                public function __construct(
                    private readonly string $key,
                    private readonly string $coverage,
                    array $items,
                ) {
                    $this->items = $items;
                }

                public function adapterKey(): string
                {
                    return $this->key;
                }

                public function coverageSource(): string
                {
                    return $this->coverage;
                }

                public function primaryBucket(): string
                {
                    return 'needs_my_decision';
                }

                public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
                {
                    $this->readCalls++;
                    $this->candidateLimits[] = $query->candidateLimit;

                    return new OwnerAttentionAdapterResult(
                        array_slice($this->items, 0, $query->candidateLimit),
                        count($this->items),
                    );
                }
            };

            app()->instance($definition['class'], $adapter);
            $adapters[] = $adapter;
        }

        return $adapters;
    }

    private function bindComplianceAdapter(int $items): object
    {
        $projected = [];
        for ($id = 1; $id <= $items; $id++) {
            $projected[] = new OwnerAttentionItem(
                sourceType: 'compliance_document',
                sourceId: $id,
                category: 'document_expiry',
                primaryBucket: 'urgent_exceptions',
                module: 'compliance',
                title: 'Compliance document expiry',
                conciseSummary: 'Renewal is required.',
                priorityTier: 'high',
                materialityTier: 'high',
                comparableMonetaryExposure: null,
                urgencyAt: '2026-08-20T00:00:00+08:00',
                actionableSince: '2026-08-01T00:00:00+08:00',
                waitingOn: 'none',
                ownerActionRequired: false,
                coverageSource: 'compliance',
                destinationUrl: '/shop-owner/settings/policies-compliance',
            );
        }

        $adapter = new class($projected) implements OwnerAttentionAdapter {
            /** @var array<int, OwnerAttentionItem> */
            private readonly array $items;

            /** @var array<int, int> */
            public array $candidateLimits = [];

            /** @param array<int, OwnerAttentionItem> $items */
            public function __construct(array $items)
            {
                $this->items = $items;
            }

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
                $this->candidateLimits[] = $query->candidateLimit;

                return new OwnerAttentionAdapterResult(
                    array_slice($this->items, 0, $query->candidateLimit),
                    count($this->items),
                );
            }
        };

        app()->instance(
            'App\\Services\\OwnerActionCenter\\Adapters\\ComplianceDocumentAttentionAdapter',
            $adapter,
        );

        return $adapter;
    }

    private function bindWaitingComplianceAdapter(int $items): object
    {
        $projected = [];
        for ($id = 1; $id <= $items; $id++) {
            $projected[] = new OwnerAttentionItem(
                sourceType: 'compliance_document',
                sourceId: $id,
                category: 'renewal_review_waiting',
                primaryBucket: 'waiting_on_others',
                module: 'compliance',
                title: 'Pending renewal review',
                conciseSummary: 'A compliance reviewer owns the next step.',
                priorityTier: 'normal',
                materialityTier: 'medium',
                comparableMonetaryExposure: null,
                urgencyAt: '2026-08-20T00:00:00+08:00',
                actionableSince: '2026-08-15T09:00:00+08:00',
                waitingOn: 'super_admin',
                ownerActionRequired: false,
                coverageSource: 'compliance',
                destinationUrl: '/shop-owner/settings/policies-compliance',
            );
        }

        $adapter = new class($projected) implements OwnerAttentionAdapter {
            /** @var array<int, OwnerAttentionItem> */
            private readonly array $items;

            /** @var array<int, int> */
            public array $candidateLimits = [];

            /** @param array<int, OwnerAttentionItem> $items */
            public function __construct(array $items)
            {
                $this->items = $items;
            }

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
                $this->candidateLimits[] = $query->candidateLimit;

                return new OwnerAttentionAdapterResult(
                    array_slice($this->items, 0, $query->candidateLimit),
                    count($this->items),
                );
            }
        };

        app()->instance(self::WAITING_COMPLIANCE_ADAPTER, $adapter);

        return $adapter;
    }
}
