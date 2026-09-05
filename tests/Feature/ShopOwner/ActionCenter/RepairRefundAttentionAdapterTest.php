<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\Adapters\RepairRefundAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RepairRefundAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_queue_requires_repair_source_owner_stage_and_finance_initial_approval(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $actionable = $this->createRefund($owner, [
            'requested_amount' => 1000,
            'requires_owner_approval' => true,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);
        $this->createRefund($owner, [
            'requested_amount' => 1000,
            'requires_owner_approval' => true,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'pending',
        ]);
        $this->createRefund($owner, [
            'requested_amount' => 1000,
            'requires_owner_approval' => true,
            'status' => 'requested',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved_initial',
        ]);
        $this->createRefund($owner, [
            'requested_amount' => 1000,
            'requires_owner_approval' => true,
            'status' => 'approved',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);
        $this->createRefund($owner, [
            'requested_amount' => 1000,
            'requires_owner_approval' => true,
            'module_type' => 'retail',
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);
        $this->createRefund($otherOwner, [
            'requested_amount' => 1000,
            'requires_owner_approval' => true,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame('repair_refund:'.$actionable->id.':refund_approval', $item->attentionKey);
        $this->assertSame('finance', $item->module);
        $this->assertSame(1000.0, $item->comparableMonetaryExposure);
        $this->assertSame(
            '/shop-owner/action-center?bucket=needs_my_decision&approval=repair_refund:'.$actionable->id,
            $item->destinationUrl,
        );
    }

    public function test_individual_queue_keeps_pending_and_completed_finance_stages_when_owner_is_pending(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
        ]);
        $pending = $this->createRefund($owner, ['finance_status' => 'pending', 'requires_owner_approval' => true]);
        $initial = $this->createRefund($owner, ['finance_status' => 'approved_initial', 'requires_owner_approval' => true]);
        $approved = $this->createRefund($owner, ['finance_status' => 'approved', 'requires_owner_approval' => true]);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(
            [$approved->id, $initial->id, $pending->id],
            array_map(static fn ($item): int => $item->sourceId, $result->items),
        );
    }

    public function test_live_policy_changes_do_not_hide_a_snapshotted_owner_required_refund(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $refund = $this->createRefund($owner, ['requested_amount' => 1000, 'requires_owner_approval' => true]);
        $before = [
            'status' => $refund->status,
            'shop_owner_status' => $refund->shop_owner_status,
            'finance_status' => $refund->finance_status,
            'requested_amount' => (string) $refund->requested_amount,
            'updated_at' => $refund->updated_at?->toISOString(),
        ];

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery());

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertSame([$refund->id], array_map(
            static fn ($item): int => $item->sourceId,
            $result->items,
        ));
        $fresh = $refund->fresh();
        $this->assertSame($before['status'], $fresh->status);
        $this->assertSame($before['shop_owner_status'], $fresh->shop_owner_status);
        $this->assertSame($before['finance_status'], $fresh->finance_status);
        $this->assertSame($before['requested_amount'], (string) $fresh->requested_amount);
        $this->assertSame($before['updated_at'], $fresh->updated_at?->toISOString());
    }

    public function test_read_query_count_does_not_grow_with_qualifying_rows(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->createRefund($owner, ['requires_owner_approval' => true]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $oneRowQueries = count(DB::getQueryLog());

        $this->createRefund($owner);
        $this->createRefund($owner);
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $oneRowQueries);
        $this->assertSame($oneRowQueries, $manyRowQueries);
    }

    public function test_candidates_are_deterministically_ordered_by_requested_time_then_id(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $older = $this->createRefund($owner, ['requested_at' => now()->subDays(2), 'requires_owner_approval' => true]);
        $newer = $this->createRefund($owner, ['requested_at' => now()->subDay(), 'requires_owner_approval' => true]);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 10));

        $this->assertSame([$newer->id, $older->id], array_map(
            static fn ($item): int => $item->sourceId,
            $result->items,
        ));
    }

    private function adapter(): RepairRefundAttentionAdapter
    {
        return app(RepairRefundAttentionAdapter::class);
    }

    private function createRefund(ShopOwner $owner, array $overrides = []): PosRefund
    {
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'final_total' => 1000,
            'total_paid_amount' => 1000,
            'payment_status' => 'paid',
        ]);
        $transaction = PosTransaction::create([
            'transaction_no' => 'POS-ADAPTER-'.uniqid(),
            'shop_owner_id' => $owner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Adapter Customer',
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
            'refund_no' => 'RFD-ADAPTER-'.uniqid(),
            'shop_owner_id' => $owner->id,
            'source_transaction_id' => $transaction->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'request_type' => 'full',
            'requested_amount' => 1000,
            'reason_code' => 'adapter_test',
            'status' => 'requested',
            'finance_status' => 'approved_initial',
            'shop_owner_status' => 'pending',
            'workflow_source' => 'shop_pos_repair',
            'requested_at' => now(),
            'requires_owner_approval' => true,
        ], $overrides));
    }
}
