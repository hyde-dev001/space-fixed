<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\FailedOrderRefundAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class FailedOrderRefundAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_material_unowned_recovery_is_projected_as_an_exception(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $unowned = $this->failedRefund($owner, [
            'amount' => 5000,
            'failed_at' => CarbonImmutable::parse('2026-08-14 10:00:00'),
        ]);
        $this->failedRefund($owner, [
            'shop_owner_status' => 'pending',
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => OrderRefund::RECOVERY_STATUS_RESOLVED,
        ]);
        $this->failedRefund($otherOwner);

        $result = $this->adapter()->read($owner, $this->query());

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame('order_refund:'.$unowned->id.':failed_refund_recovery', $item->attentionKey);
        $this->assertSame('order_refund', $item->sourceType);
        $this->assertSame('refunds', $item->coverageSource);
        $this->assertSame('urgent_exceptions', $item->primaryBucket);
        $this->assertSame('none', $item->waitingOn);
        $this->assertFalse($item->ownerActionRequired);
        $this->assertSame('high', $item->priorityTier);
        $this->assertSame('high', $item->materialityTier);
        $this->assertSame(5000.0, $item->comparableMonetaryExposure);
        $this->assertSame($unowned->failed_at->toISOString(), $item->urgencyAt);
        $this->assertSame($unowned->failed_at->toISOString(), $item->actionableSince);
        $this->assertStringContainsString('refund_type=order&refund='.$unowned->id, $item->destinationUrl);
    }

    public function test_resolved_and_superseded_recovery_exits_on_the_next_read(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $refund = $this->failedRefund($owner);
        $adapter = $this->adapter();

        $this->assertCount(1, $adapter->read($owner, $this->query())->items);

        $refund->update([
            'recovery_status' => OrderRefund::RECOVERY_STATUS_RESOLVED,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_NONE,
        ]);
        $this->assertSame(0, $adapter->read($owner, $this->query())->qualifyingCount);

        $refund->update([
            'recovery_status' => OrderRefund::RECOVERY_STATUS_SUPERSEDED,
        ]);
        $this->assertSame(0, $adapter->read($owner, $this->query())->qualifyingCount);
    }

    public function test_ambiguous_recovery_metadata_fails_the_adapter_without_projecting_an_item(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->failedRefund($owner, [
            'recovery_status' => 'unknown_state',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('order_refund_recovery_state_inconsistent');

        $this->adapter()->read($owner, $this->query());
    }

    public function test_read_query_count_is_bounded_as_failed_rows_grow(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->failedRefund($owner);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->adapter()->read($owner, $this->query());
        $oneRowQueries = count(DB::getQueryLog());

        $this->failedRefund($owner);
        $this->failedRefund($owner);
        DB::flushQueryLog();
        $this->adapter()->read($owner, $this->query());
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $oneRowQueries);
        $this->assertSame($oneRowQueries, $manyRowQueries);
    }

    private function adapter(): FailedOrderRefundAttentionAdapter
    {
        return app(FailedOrderRefundAttentionAdapter::class);
    }

    private function query(): OwnerAttentionQuery
    {
        return new OwnerAttentionQuery(bucket: 'urgent_exceptions', coverage: 'refunds');
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
            'failed_at' => now()->subDay(),
            'recovery_status' => OrderRefund::RECOVERY_STATUS_UNRESOLVED,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_NONE,
            'recovery_attempt_count' => 0,
        ], $overrides));
    }
}
