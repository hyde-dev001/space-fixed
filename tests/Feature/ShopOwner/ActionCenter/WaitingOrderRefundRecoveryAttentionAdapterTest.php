<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\WaitingOrderRefundRecoveryAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class WaitingOrderRefundRecoveryAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 16, 12, 0, 0, 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_projects_current_owner_scoped_finance_and_payment_recovery_responsibility(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $finance = $this->failedRefund($owner, [
            'amount' => 1250,
            'currency' => 'PHP',
            'failed_at' => '2026-08-14 10:00:00',
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => '2026-08-15 09:00:00',
            'failure_reason' => 'private order refund reason',
        ]);
        $paymentRecovery = $this->failedRefund($owner, [
            'amount' => 800,
            'currency' => 'USD',
            'failed_at' => '2026-08-15 10:00:00',
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
            'recovery_assigned_at' => '2026-08-16 08:00:00',
        ]);
        $this->failedRefund($otherOwner, [
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => '2026-08-15 09:00:00',
        ]);

        $result = $this->adapter()->read($owner, $this->query());

        $this->assertSame(2, $result->qualifyingCount);
        $this->assertSame([$finance->id, $paymentRecovery->id], array_map(
            static fn ($item): int => $item->sourceId,
            $result->items,
        ));

        $financeItem = $result->items[0];
        $this->assertSame('order_refund:'.$finance->id.':refund_recovery_waiting', $financeItem->attentionKey);
        $this->assertSame('order_refund', $financeItem->sourceType);
        $this->assertSame('refund_recovery_waiting', $financeItem->category);
        $this->assertSame('waiting_on_others', $financeItem->primaryBucket);
        $this->assertSame('refunds', $financeItem->coverageSource);
        $this->assertSame('finance', $financeItem->waitingOn);
        $this->assertFalse($financeItem->ownerActionRequired);
        $this->assertSame('high', $financeItem->priorityTier);
        $this->assertSame('high', $financeItem->materialityTier);
        $this->assertSame(1250.0, $financeItem->comparableMonetaryExposure);
        $this->assertSame($finance->failed_at->toISOString(), $financeItem->urgencyAt);
        $this->assertSame($finance->recovery_assigned_at->toISOString(), $financeItem->actionableSince);
        $this->assertStringContainsString('refund_type=order&refund='.$finance->id, $financeItem->destinationUrl);
        $this->assertStringNotContainsString('private order refund reason', $financeItem->conciseSummary);

        $this->assertNull($result->items[1]->comparableMonetaryExposure);
        $this->assertSame('payment_recovery', $result->items[1]->waitingOn);
    }

    public function test_owner_decision_and_other_recovery_states_exit_waiting_without_overlap(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->failedRefund($owner, [
            'shop_owner_status' => 'pending',
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => '2026-08-15 09:00:00',
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => OrderRefund::RECOVERY_STATUS_UNRESOLVED,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_NONE,
            'recovery_assigned_at' => null,
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => OrderRefund::RECOVERY_STATUS_RESOLVED,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_NONE,
            'recovery_assigned_at' => null,
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => OrderRefund::RECOVERY_STATUS_SUPERSEDED,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_NONE,
            'recovery_assigned_at' => null,
        ]);
        $this->failedRefund($owner, [
            'status' => 'succeeded',
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => null,
        ]);

        $result = $this->adapter()->read($owner, $this->query());

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->qualifyingCount);
    }

    #[DataProvider('invalidAssignmentEvidenceProvider')]
    public function test_invalid_current_assignment_evidence_fails_health_without_an_item(array $overrides): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->failedRefund($owner, array_merge([
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'failed_at' => '2026-08-15 10:00:00',
            'recovery_assigned_at' => '2026-08-16 09:00:00',
        ], $overrides));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('order_refund_recovery_assignment_inconsistent');

        $this->adapter()->read($owner, $this->query());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidAssignmentEvidenceProvider(): array
    {
        return [
            'missing assignment time' => [['recovery_assigned_at' => null]],
            'assignment before failure' => [[
                'failed_at' => '2026-08-15 10:00:00',
                'recovery_assigned_at' => '2026-08-14 10:00:00',
            ]],
            'assignment in the future' => [['recovery_assigned_at' => '2026-08-17 10:00:00']],
        ];
    }

    public function test_read_query_count_is_bounded_and_candidate_limit_is_honored(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->failedRefund($owner, [
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => '2026-08-15 09:00:00',
        ]);

        DB::listen(static function (QueryExecuted $query): void {});
        DB::enableQueryLog();
        DB::flushQueryLog();
        $oneRow = $this->adapter()->read($owner, new OwnerAttentionQuery(
            bucket: 'waiting_on_others',
            coverage: 'refunds',
            candidateLimit: 1,
        ));
        $oneRowQueries = count(DB::getQueryLog());

        $this->failedRefund($owner, [
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => '2026-08-15 09:00:00',
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => '2026-08-15 09:00:00',
        ]);
        DB::flushQueryLog();
        $manyRow = $this->adapter()->read($owner, $this->query());
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $oneRow->qualifyingCount);
        $this->assertCount(1, $oneRow->items);
        $this->assertSame(3, $manyRow->qualifyingCount);
        $this->assertSame($oneRowQueries, $manyRowQueries);
    }

    private function adapter(): WaitingOrderRefundRecoveryAttentionAdapter
    {
        return app(WaitingOrderRefundRecoveryAttentionAdapter::class);
    }

    private function query(): OwnerAttentionQuery
    {
        return new OwnerAttentionQuery(bucket: 'waiting_on_others', coverage: 'refunds');
    }

    /** @param array<string, mixed> $overrides */
    private function failedRefund(ShopOwner $owner, array $overrides = []): OrderRefund
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);

        return OrderRefund::factory()->create(array_merge([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $owner->id,
            'flow_type' => 'request_approval',
            'status' => 'failed',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'amount' => 1000,
            'currency' => 'PHP',
            'failure_reason' => 'Refund gateway failed.',
            'failed_at' => CarbonImmutable::now()->subDay(),
            'recovery_status' => OrderRefund::RECOVERY_STATUS_UNRESOLVED,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_NONE,
            'recovery_attempt_count' => 0,
        ], $overrides));
    }
}
