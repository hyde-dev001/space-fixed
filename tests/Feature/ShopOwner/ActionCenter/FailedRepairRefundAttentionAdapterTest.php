<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\Adapters\FailedRepairRefundAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class FailedRepairRefundAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_material_unowned_recovery_is_projected_as_an_exception(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $unowned = $this->failedRefund($owner, [
            'requested_amount' => 5000,
            'failed_at' => CarbonImmutable::parse('2026-08-14 10:00:00'),
        ]);
        $this->failedRefund($owner, [
            'shop_owner_status' => 'pending',
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => PosRefund::RECOVERY_STATUS_SUPERSEDED,
        ]);
        $this->failedRefund($otherOwner);

        $result = $this->adapter()->read($owner, $this->query());

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame('repair_refund:'.$unowned->id.':failed_refund_recovery', $item->attentionKey);
        $this->assertSame('repair_refund', $item->sourceType);
        $this->assertSame('refunds', $item->coverageSource);
        $this->assertSame('urgent_exceptions', $item->primaryBucket);
        $this->assertSame('none', $item->waitingOn);
        $this->assertFalse($item->ownerActionRequired);
        $this->assertSame('high', $item->priorityTier);
        $this->assertSame('high', $item->materialityTier);
        $this->assertSame(5000.0, $item->comparableMonetaryExposure);
        $this->assertSame($unowned->failed_at->toISOString(), $item->urgencyAt);
        $this->assertSame($unowned->failed_at->toISOString(), $item->actionableSince);
        $this->assertStringContainsString('refund_type=repair&refund='.$unowned->id, $item->destinationUrl);
    }

    public function test_resolved_recovery_exits_on_the_next_read(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $refund = $this->failedRefund($owner);
        $adapter = $this->adapter();

        $this->assertCount(1, $adapter->read($owner, $this->query())->items);

        $refund->update([
            'recovery_status' => PosRefund::RECOVERY_STATUS_RESOLVED,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_NONE,
        ]);

        $this->assertSame(0, $adapter->read($owner, $this->query())->qualifyingCount);
    }

    public function test_ambiguous_recovery_metadata_fails_the_adapter_without_projecting_an_item(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->failedRefund($owner, [
            'recovery_responsible_party' => 'unknown_party',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('repair_refund_recovery_state_inconsistent');

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

    private function adapter(): FailedRepairRefundAttentionAdapter
    {
        return app(FailedRepairRefundAttentionAdapter::class);
    }

    private function query(): OwnerAttentionQuery
    {
        return new OwnerAttentionQuery(bucket: 'urgent_exceptions', coverage: 'refunds');
    }

    /** @param array<string, mixed> $overrides */
    private function failedRefund(ShopOwner $owner, array $overrides = []): PosRefund
    {
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'final_total' => 1000,
            'total_paid_amount' => 1000,
            'payment_status' => 'paid',
        ]);
        $transaction = PosTransaction::create([
            'transaction_no' => 'POS-FAILED-'.strtoupper(bin2hex(random_bytes(5))),
            'shop_owner_id' => $owner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Failed Refund Customer',
            'due_type' => 'full',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return PosRefund::create(array_merge([
            'refund_no' => 'RFD-FAILED-'.strtoupper(bin2hex(random_bytes(5))),
            'shop_owner_id' => $owner->id,
            'source_transaction_id' => $transaction->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'workflow_source' => 'online_myrepair',
            'request_type' => 'full',
            'requested_amount' => 1000,
            'reason_code' => 'execution_failure',
            'status' => 'failed',
            'finance_status' => 'approved',
            'shop_owner_status' => 'approved',
            'repairer_status' => 'approved',
            'requested_at' => now()->subDay(),
            'failure_reason' => 'Refund execution failed.',
            'failed_at' => now()->subDay(),
            'recovery_status' => PosRefund::RECOVERY_STATUS_UNRESOLVED,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_NONE,
            'recovery_attempt_count' => 0,
        ], $overrides));
    }
}
