<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\WaitingRepairRefundRecoveryAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class WaitingRepairRefundRecoveryAttentionAdapterTest extends TestCase
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
            'requested_amount' => 2250,
            'failed_at' => '2026-08-14 10:00:00',
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => '2026-08-15 09:00:00',
            'failure_reason' => 'private repair refund reason',
        ]);
        $paymentRecovery = $this->failedRefund($owner, [
            'requested_amount' => 800,
            'failed_at' => '2026-08-15 10:00:00',
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
            'recovery_assigned_at' => '2026-08-16 08:00:00',
        ]);
        $this->failedRefund($otherOwner, [
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => '2026-08-15 09:00:00',
        ]);

        $result = $this->adapter()->read($owner, $this->query());

        $this->assertSame(2, $result->qualifyingCount);
        $this->assertSame([$finance->id, $paymentRecovery->id], array_map(
            static fn ($item): int => $item->sourceId,
            $result->items,
        ));

        $financeItem = $result->items[0];
        $this->assertSame('repair_refund:'.$finance->id.':refund_recovery_waiting', $financeItem->attentionKey);
        $this->assertSame('repair_refund', $financeItem->sourceType);
        $this->assertSame('refund_recovery_waiting', $financeItem->category);
        $this->assertSame('waiting_on_others', $financeItem->primaryBucket);
        $this->assertSame('refunds', $financeItem->coverageSource);
        $this->assertSame('finance', $financeItem->waitingOn);
        $this->assertFalse($financeItem->ownerActionRequired);
        $this->assertSame('high', $financeItem->priorityTier);
        $this->assertSame('high', $financeItem->materialityTier);
        $this->assertSame(2250.0, $financeItem->comparableMonetaryExposure);
        $this->assertSame($finance->failed_at->toISOString(), $financeItem->urgencyAt);
        $this->assertSame($finance->recovery_assigned_at->toISOString(), $financeItem->actionableSince);
        $this->assertStringContainsString('refund_type=repair&refund='.$finance->id, $financeItem->destinationUrl);
        $this->assertStringNotContainsString('private repair refund reason', $financeItem->conciseSummary);

        $this->assertSame('payment_recovery', $result->items[1]->waitingOn);
    }

    public function test_owner_decision_and_other_recovery_states_exit_waiting_without_overlap(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->failedRefund($owner, [
            'shop_owner_status' => 'pending',
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => '2026-08-15 09:00:00',
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => PosRefund::RECOVERY_STATUS_UNRESOLVED,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_NONE,
            'recovery_assigned_at' => null,
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => PosRefund::RECOVERY_STATUS_RESOLVED,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_NONE,
            'recovery_assigned_at' => null,
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => PosRefund::RECOVERY_STATUS_SUPERSEDED,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_NONE,
            'recovery_assigned_at' => null,
        ]);
        $this->failedRefund($owner, [
            'status' => 'succeeded',
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
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
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'failed_at' => '2026-08-15 10:00:00',
            'recovery_assigned_at' => '2026-08-16 09:00:00',
        ], $overrides));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('repair_refund_recovery_assignment_inconsistent');

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

    public function test_order_refund_state_failure_does_not_hide_healthy_repair_items(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $repair = $this->failedRefund($owner, [
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => '2026-08-15 09:00:00',
        ]);
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $owner->id,
            'status' => 'failed',
            'recovery_status' => 'unknown_state',
        ]);

        $result = $this->adapter()->read($owner, $this->query());

        $this->assertSame([$repair->id], array_map(
            static fn ($item): int => $item->sourceId,
            $result->items,
        ));
    }

    public function test_read_query_count_is_bounded_and_candidate_limit_is_honored(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->failedRefund($owner, [
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
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
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => '2026-08-15 09:00:00',
        ]);
        $this->failedRefund($owner, [
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
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

    private function adapter(): WaitingRepairRefundRecoveryAttentionAdapter
    {
        return app(WaitingRepairRefundRecoveryAttentionAdapter::class);
    }

    private function query(): OwnerAttentionQuery
    {
        return new OwnerAttentionQuery(bucket: 'waiting_on_others', coverage: 'refunds');
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
            'transaction_no' => 'POS-WAITING-'.strtoupper(bin2hex(random_bytes(5))),
            'shop_owner_id' => $owner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Waiting Refund Customer',
            'due_type' => 'full',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return PosRefund::query()->create(array_merge([
            'refund_no' => 'RFD-WAITING-'.strtoupper(bin2hex(random_bytes(5))),
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
            'failed_at' => CarbonImmutable::now()->subDay(),
            'recovery_status' => PosRefund::RECOVERY_STATUS_UNRESOLVED,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_NONE,
            'recovery_attempt_count' => 0,
        ], $overrides));
    }
}
