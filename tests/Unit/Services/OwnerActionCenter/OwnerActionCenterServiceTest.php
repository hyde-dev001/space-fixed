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

    private const COMPLIANCE_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\ComplianceDocumentAttentionAdapter';

    private const FAILED_ORDER_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\FailedOrderRefundAttentionAdapter';

    private const FAILED_REPAIR_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\FailedRepairRefundAttentionAdapter';

    private const LOGISTICS_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\UnownedLogisticsFailureAttentionAdapter';

    private const WAITING_COMPLIANCE_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\PendingComplianceRenewalAttentionAdapter';

    private const WAITING_ORDER_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\WaitingOrderRefundRecoveryAttentionAdapter';

    private const WAITING_REPAIR_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\WaitingRepairRefundRecoveryAttentionAdapter';

    private const WAITING_LOGISTICS_ADAPTER = 'App\\Services\\OwnerActionCenter\\Adapters\\ActiveLogisticsRecoveryAttentionAdapter';

    public function test_registry_resolves_adapters_by_bucket_and_coverage(): void
    {
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.refunds' => false,
            'owner_action_center.buckets.urgent_exceptions.coverage.logistics' => false,
        ]);
        $compliance = $this->bindFake(
            self::COMPLIANCE_ADAPTER,
            'compliance_documents',
            'compliance',
            bucket: 'urgent_exceptions',
        );

        $adapters = app(OwnerAttentionAdapterRegistry::class)->adaptersFor('urgent_exceptions', 'compliance');

        $this->assertSame([$compliance], $adapters);
    }

    public function test_decisions_and_exceptions_have_independent_counts_and_pages(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableOnly('expenses');
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
        ]);
        $this->bindFake(
            self::EXPENSE_ADAPTER,
            'expenses',
            'expenses',
            [$this->item('expense', 1), $this->item('expense', 2)],
        );
        $this->bindFake(
            self::COMPLIANCE_ADAPTER,
            'compliance_documents',
            'compliance',
            [$this->exceptionItem(10), $this->exceptionItem(11), $this->exceptionItem(12)],
            bucket: 'urgent_exceptions',
        );

        $decisions = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(bucket: 'needs_my_decision', coverage: 'expenses', perPage: 1),
        );
        $exceptions = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(bucket: 'urgent_exceptions', coverage: 'compliance', page: 2, perPage: 2),
        );

        $this->assertSame('needs_my_decision', $decisions->bucket);
        $this->assertSame(2, $decisions->total);
        $this->assertSame(1, $decisions->page);
        $this->assertSame([
            'refunds' => 0,
            'prices' => 0,
            'payslips' => 0,
            'salary_changes' => 0,
            'purchase_requests' => 0,
            'expenses' => 2,
            'repair_rejections' => 0,
        ], $decisions->coverageCounts);
        $this->assertSame('urgent_exceptions', $exceptions->bucket);
        $this->assertSame(3, $exceptions->total);
        $this->assertSame(2, $exceptions->page);
        $this->assertSame(['compliance' => 3, 'refunds' => 0, 'logistics' => 0], $exceptions->coverageCounts);
        $this->assertSame(['compliance_document:12:compliance_expiry'], array_map(
            static fn (OwnerAttentionItem $item): string => $item->attentionKey,
            $exceptions->items,
        ));
    }

    public function test_exception_adapter_failure_does_not_degrade_decisions(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableOnly('expenses');
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
        ]);
        $this->bindFake(
            self::EXPENSE_ADAPTER,
            'expenses',
            'expenses',
            [$this->item('expense', 1)],
        );
        $this->bindFake(
            self::COMPLIANCE_ADAPTER,
            'compliance_documents',
            'compliance',
            failure: new RuntimeException('compliance unavailable'),
            bucket: 'urgent_exceptions',
        );

        $decisions = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(bucket: 'needs_my_decision', coverage: 'expenses'),
        );
        $exceptions = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(bucket: 'urgent_exceptions', coverage: 'compliance'),
        );

        $this->assertSame(OwnerActionCenterDegradationStatus::None, $decisions->degradationStatus);
        $this->assertSame(1, $decisions->total);
        $this->assertSame(OwnerActionCenterDegradationStatus::Unavailable, $exceptions->degradationStatus);
        $this->assertSame(['compliance_documents'], $exceptions->failedAdapterKeys);
    }

    public function test_refunds_coverage_resolves_both_refund_adapters(): void
    {
        config([
            'owner_action_center.coverage.refunds' => true,
            'owner_action_center.coverage.expenses' => false,
            'owner_action_center.coverage.purchase_requests' => false,
        ]);
        $order = $this->bindFake(self::ORDER_ADAPTER, 'order_refunds', 'refunds');
        $repair = $this->bindFake(self::REPAIR_ADAPTER, 'repair_refunds', 'refunds');

        $adapters = app(OwnerAttentionAdapterRegistry::class)->adaptersFor('needs_my_decision', 'refunds');

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

        app(OwnerAttentionAdapterRegistry::class)->adaptersFor('needs_my_decision', 'refunds');
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

    public function test_cross_source_exception_ordering_and_page_boundaries_are_deterministic(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.refunds' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.logistics' => true,
        ]);

        $this->bindFake(
            self::COMPLIANCE_ADAPTER,
            'compliance_documents',
            'compliance',
            [
                $this->exceptionItem(1, 'compliance_document', 'critical', 'critical', '2026-08-17T00:00:00+08:00', '2026-08-10T09:00:00+08:00'),
                $this->exceptionItem(4, 'compliance_document', 'normal', 'medium', '2026-08-20T00:00:00+08:00', '2026-08-06T09:00:00+08:00'),
            ],
            bucket: 'urgent_exceptions',
        );
        $this->bindFake(
            self::FAILED_ORDER_ADAPTER,
            'failed_order_refunds',
            'refunds',
            [$this->exceptionItem(2, 'order_refund', 'high', 'high', '2026-08-18T00:00:00+08:00', '2026-08-08T09:00:00+08:00', 'failed_refund_recovery')],
            bucket: 'urgent_exceptions',
        );
        $this->bindFake(
            self::FAILED_REPAIR_ADAPTER,
            'failed_repair_refunds',
            'refunds',
            [],
            bucket: 'urgent_exceptions',
        );
        $this->bindFake(
            self::LOGISTICS_ADAPTER,
            'unowned_logistics_failures',
            'logistics',
            [$this->exceptionItem(3, 'logistics_failure', 'high', 'high', '2026-08-19T00:00:00+08:00', '2026-08-07T09:00:00+08:00', 'unowned_delivery_failure')],
            bucket: 'urgent_exceptions',
        );

        $pageOne = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(bucket: 'urgent_exceptions', page: 1, perPage: 2),
        );
        $pageTwo = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(bucket: 'urgent_exceptions', page: 2, perPage: 2),
        );
        $logisticsOnly = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(bucket: 'urgent_exceptions', coverage: 'logistics', perPage: 10),
        );

        $this->assertSame([
            'compliance_document:1:compliance_expiry',
            'order_refund:2:failed_refund_recovery',
        ], array_map(static fn (OwnerAttentionItem $item): string => $item->attentionKey, $pageOne->items));
        $this->assertSame([
            'logistics_failure:3:unowned_delivery_failure',
            'compliance_document:4:compliance_expiry',
        ], array_map(static fn (OwnerAttentionItem $item): string => $item->attentionKey, $pageTwo->items));
        $this->assertSame(4, $pageOne->total);
        $this->assertSame(2, $pageTwo->page);
        $this->assertSame(['compliance' => 2, 'refunds' => 1, 'logistics' => 1], $pageOne->coverageCounts);
        $this->assertSame(['logistics_failure:3:unowned_delivery_failure'], array_map(
            static fn (OwnerAttentionItem $item): string => $item->attentionKey,
            $logisticsOnly->items,
        ));
    }

    public function test_waiting_bucket_has_independent_pages_and_interleaves_phase_three_c_sources(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        config([
            'owner_action_center.buckets.waiting_on_others.enabled' => true,
            'owner_action_center.buckets.waiting_on_others.coverage' => [
                'compliance' => true,
                'refunds' => true,
                'logistics' => true,
            ],
        ]);

        $this->bindFake(
            self::WAITING_COMPLIANCE_ADAPTER,
            'pending_compliance_renewals',
            'compliance',
            [$this->waitingItem('compliance_document', 1, 'super_admin', 'high')],
            bucket: 'waiting_on_others',
        );
        $this->bindFake(
            self::WAITING_ORDER_ADAPTER,
            'waiting_order_refund_recovery',
            'refunds',
            [$this->waitingItem('order_refund', 2, 'finance', 'high')],
            bucket: 'waiting_on_others',
        );
        $this->bindFake(
            self::WAITING_REPAIR_ADAPTER,
            'waiting_repair_refund_recovery',
            'refunds',
            [$this->waitingItem('repair_refund', 3, 'payment_recovery', 'normal')],
            bucket: 'waiting_on_others',
        );
        $this->bindFake(
            self::WAITING_LOGISTICS_ADAPTER,
            'active_logistics_recovery',
            'logistics',
            [$this->waitingItem('logistics_failure', 4, 'dispatcher', 'low')],
            bucket: 'waiting_on_others',
        );

        $service = app(OwnerActionCenterService::class);
        $pageOne = $service->queueForActionCenter($owner, new OwnerAttentionQuery(
            bucket: 'waiting_on_others',
            page: 1,
            perPage: 2,
        ));
        $pageTwo = $service->queueForActionCenter($owner, new OwnerAttentionQuery(
            bucket: 'waiting_on_others',
            page: 2,
            perPage: 2,
        ));
        $complianceOnly = $service->queueForActionCenter($owner, new OwnerAttentionQuery(
            bucket: 'waiting_on_others',
            coverage: 'compliance',
            page: 4,
            perPage: 1,
        ));

        $this->assertSame(4, $pageOne->total);
        $this->assertSame([
            'compliance_document:1:renewal_review_waiting',
            'order_refund:2:refund_recovery_waiting',
        ], array_map(static fn (OwnerAttentionItem $item): string => $item->attentionKey, $pageOne->items));
        $this->assertSame([
            'repair_refund:3:refund_recovery_waiting',
            'logistics_failure:4:logistics_recovery_waiting',
        ], array_map(static fn (OwnerAttentionItem $item): string => $item->attentionKey, $pageTwo->items));
        $this->assertSame(1, $complianceOnly->page);
        $this->assertSame(['compliance_document:1:renewal_review_waiting'], array_map(
            static fn (OwnerAttentionItem $item): string => $item->attentionKey,
            $complianceOnly->items,
        ));
        $this->assertSame(['compliance' => 1, 'refunds' => 2, 'logistics' => 1], $pageOne->coverageCounts);
    }

    public function test_waiting_adapter_failures_are_partial_or_unavailable_without_zero_success(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        config([
            'owner_action_center.buckets.waiting_on_others.enabled' => true,
            'owner_action_center.buckets.waiting_on_others.coverage' => [
                'compliance' => true,
                'refunds' => true,
                'logistics' => true,
            ],
        ]);

        $healthy = $this->bindFake(
            self::WAITING_COMPLIANCE_ADAPTER,
            'pending_compliance_renewals',
            'compliance',
            [$this->waitingItem('compliance_document', 1, 'super_admin')],
            bucket: 'waiting_on_others',
        );
        $this->bindFake(
            self::WAITING_ORDER_ADAPTER,
            'waiting_order_refund_recovery',
            'refunds',
            failure: new RuntimeException('order waiting unavailable'),
            bucket: 'waiting_on_others',
        );
        $this->bindFake(
            self::WAITING_REPAIR_ADAPTER,
            'waiting_repair_refund_recovery',
            'refunds',
            failure: new RuntimeException('repair waiting unavailable'),
            bucket: 'waiting_on_others',
        );
        $this->bindFake(
            self::WAITING_LOGISTICS_ADAPTER,
            'active_logistics_recovery',
            'logistics',
            failure: new RuntimeException('logistics waiting unavailable'),
            bucket: 'waiting_on_others',
        );

        $partial = app(OwnerActionCenterService::class)->queueForActionCenter($owner, new OwnerAttentionQuery(
            bucket: 'waiting_on_others',
        ));

        $this->assertSame(OwnerActionCenterDegradationStatus::Partial, $partial->degradationStatus);
        $this->assertSame(['pending_compliance_renewals'], $partial->healthyAdapterKeys);
        $this->assertSame([
            'waiting_order_refund_recovery',
            'waiting_repair_refund_recovery',
            'active_logistics_recovery',
        ], $partial->failedAdapterKeys);
        $this->assertSame(1, $partial->total);
        $this->assertCount(1, $partial->items);
        $this->assertNotNull($healthy);

        $this->bindFake(
            self::WAITING_COMPLIANCE_ADAPTER,
            'pending_compliance_renewals',
            'compliance',
            failure: new RuntimeException('compliance waiting unavailable'),
            bucket: 'waiting_on_others',
        );

        $unavailable = app(OwnerActionCenterService::class)->queueForActionCenter($owner, new OwnerAttentionQuery(
            bucket: 'waiting_on_others',
        ));

        $this->assertSame(OwnerActionCenterDegradationStatus::Unavailable, $unavailable->degradationStatus);
        $this->assertSame(0, $unavailable->total);
        $this->assertSame([], $unavailable->items);
    }

    public function test_disabling_waiting_keeps_the_bucket_as_an_explicit_no_enabled_state(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        config(['owner_action_center.buckets.waiting_on_others.enabled' => false]);

        $result = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(bucket: 'waiting_on_others'),
        );

        $this->assertSame([], $result->items);
        $this->assertSame([], $result->enabledAdapterKeys);
        $this->assertSame(OwnerActionCenterDegradationStatus::NoEnabledAdapters, $result->degradationStatus);
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
            'owner_action_center.coverage.prices' => $coverage === 'prices',
            'owner_action_center.coverage.payslips' => $coverage === 'payslips',
            'owner_action_center.coverage.salary_changes' => $coverage === 'salary_changes',
            'owner_action_center.coverage.repair_rejections' => $coverage === 'repair_rejections',
            'owner_action_center.home_limit' => 3,
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
        string $bucket = 'needs_my_decision',
    ): object {
        $adapter = new class($adapterKey, $coverage, $items, $qualifyingCount, $failure, $bucket) implements OwnerAttentionAdapter {
            /** @var array<int, OwnerAttentionQuery> */
            public array $queries = [];

            /** @param array<int, OwnerAttentionItem> $items */
            public function __construct(
                private readonly string $adapterKeyValue,
                private readonly string $coverageValue,
                private readonly array $items,
                private readonly ?int $qualifyingCount,
                private readonly ?Throwable $failure,
                private readonly string $bucketValue,
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
                return $this->bucketValue;
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
            destinationUrl: '/shop-owner/action-center?bucket=needs_my_decision&approval='.$sourceType.':'.$sourceId,
        );
    }

    private function waitingItem(
        string $sourceType,
        int $sourceId,
        string $waitingOn,
        string $priority = 'normal',
    ): OwnerAttentionItem {
        [$module, $coverage, $destination] = match ($sourceType) {
            'compliance_document' => ['compliance', 'compliance', '/shop-owner/settings/policies-compliance'],
            'order_refund' => ['refunds', 'refunds', '/shop-owner/refund-approvals?refund='.$sourceId],
            'repair_refund' => ['refunds', 'refunds', '/shop-owner/refund-approvals?refund_type=repair&refund='.$sourceId],
            'logistics_failure' => ['logistics', 'logistics', '/shop-owner/logistics/shipments?shipment=8&leg='.$sourceId],
        };

        return new OwnerAttentionItem(
            sourceType: $sourceType,
            sourceId: $sourceId,
            category: match ($sourceType) {
                'compliance_document' => 'renewal_review_waiting',
                'order_refund', 'repair_refund' => 'refund_recovery_waiting',
                'logistics_failure' => 'logistics_recovery_waiting',
            },
            primaryBucket: 'waiting_on_others',
            module: $module,
            title: 'Waiting item',
            conciseSummary: 'Another legitimate party owns the next step.',
            priorityTier: $priority,
            materialityTier: $priority === 'low' ? 'low' : 'high',
            comparableMonetaryExposure: null,
            urgencyAt: '2026-08-20T00:00:00+08:00',
            actionableSince: '2026-08-15T09:00:00+08:00',
            waitingOn: $waitingOn,
            ownerActionRequired: false,
            coverageSource: $coverage,
            destinationUrl: $destination,
        );
    }

    private function exceptionItem(
        int $sourceId,
        string $sourceType = 'compliance_document',
        string $priority = 'high',
        string $materiality = 'high',
        string $urgencyAt = '2026-08-20T00:00:00+08:00',
        string $actionableSince = '2026-08-01T00:00:00+08:00',
        string $category = 'compliance_expiry',
    ): OwnerAttentionItem
    {
        [$module, $coverage, $title, $summary, $destination] = match ($sourceType) {
            'compliance_document' => [
                'compliance',
                'compliance',
                'Compliance document expiring',
                'Review the current document lifecycle.',
                '/shop-owner/settings/policies-compliance',
            ],
            'order_refund' => [
                'refunds',
                'refunds',
                'Failed refund needs recovery',
                'Review the current refund recovery state.',
                '/shop-owner/refund-approvals?refund='.$sourceId,
            ],
            'logistics_failure' => [
                'logistics',
                'logistics',
                'Failed delivery needs escalation',
                'Review the current delivery recovery state.',
                '/shop-owner/logistics/shipments?shipment=8&leg='.$sourceId,
            ],
            default => throw new InvalidArgumentException('Unsupported exception test source.'),
        };

        return new OwnerAttentionItem(
            sourceType: $sourceType,
            sourceId: $sourceId,
            category: $category,
            primaryBucket: 'urgent_exceptions',
            module: $module,
            title: $title,
            conciseSummary: $summary,
            priorityTier: $priority,
            materialityTier: $materiality,
            comparableMonetaryExposure: null,
            urgencyAt: $urgencyAt,
            actionableSince: $actionableSince,
            waitingOn: 'none',
            ownerActionRequired: false,
            coverageSource: $coverage,
            destinationUrl: $destination,
        );
    }
}
