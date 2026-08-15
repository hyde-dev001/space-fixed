<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ProcurementSettings;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\OrderRefundAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrderRefundAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_queue_matches_current_tenant_flow_stage_and_threshold_predicates(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $this->enableRefundApproval($owner, 1000);

        $actionable = $this->createRefund($owner, [
            'amount' => 1000,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);
        $this->createRefund($owner, [
            'amount' => 999,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);
        $this->createRefund($owner, [
            'amount' => 1000,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'pending',
        ]);
        $this->createRefund($owner, [
            'amount' => 1000,
            'status' => 'rejected',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);
        $this->createRefund($owner, [
            'amount' => 1000,
            'flow_type' => 'cancel_auto',
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);
        $this->createRefund($otherOwner, [
            'amount' => 1000,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame('order_refund:'.$actionable->id.':refund_approval', $item->attentionKey);
        $this->assertSame('refund_approval', $item->category);
        $this->assertSame('finance', $item->module);
        $this->assertSame('order_refund', $item->sourceType);
        $this->assertSame(1000.0, $item->comparableMonetaryExposure);
        $this->assertStringContainsString('refund_type=order&refund='.$actionable->id, $item->destinationUrl);
    }

    public function test_individual_queue_keeps_pending_finance_stage(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
        ]);
        $this->enableRefundApproval($owner, 1);
        $pendingFinance = $this->createRefund($owner, [
            'amount' => 1000,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'pending',
        ]);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery());

        $this->assertSame([$pendingFinance->id], array_map(
            static fn ($item): int => $item->sourceId,
            $result->items,
        ));
    }

    public function test_disabled_or_not_required_owner_policy_returns_no_items_without_mutating_refunds(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $this->enableRefundApproval($owner, 1000);
        $notRequired = $this->createRefund($owner, [
            'amount' => 1000,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);
        $before = [
            'status' => $notRequired->status,
            'shop_owner_status' => $notRequired->shop_owner_status,
            'finance_status' => $notRequired->finance_status,
            'amount' => (string) $notRequired->amount,
            'currency' => $notRequired->currency,
            'updated_at' => $notRequired->updated_at?->toISOString(),
        ];

        ProcurementSettings::query()
            ->where('shop_owner_id', $owner->id)
            ->update([
                'settings_json' => [
                    'approval_pages' => [
                        'refund_approval' => ['enabled' => false, 'limit' => null],
                    ],
                ],
            ]);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery());

        $this->assertSame(0, $result->qualifyingCount);
        $this->assertSame([], $result->items);
        $fresh = $notRequired->fresh();
        $this->assertSame($before['status'], $fresh->status);
        $this->assertSame($before['shop_owner_status'], $fresh->shop_owner_status);
        $this->assertSame($before['finance_status'], $fresh->finance_status);
        $this->assertSame($before['amount'], (string) $fresh->amount);
        $this->assertSame($before['currency'], $fresh->currency);
        $this->assertSame($before['updated_at'], $fresh->updated_at?->toISOString());
    }

    public function test_non_php_currency_is_not_comparable_but_php_exposure_is_normalized(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $this->enableRefundApproval($owner, 1);
        $phpRefund = $this->createRefund($owner, [
            'amount' => 1250.75,
            'currency' => 'PHP',
            'requested_at' => now()->subDay(),
        ]);
        $foreignRefund = $this->createRefund($owner, [
            'amount' => 1250.75,
            'currency' => 'USD',
            'requested_at' => now(),
        ]);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 10));

        $byId = collect($result->items)->keyBy('sourceId');
        $this->assertSame(1250.75, $byId[$phpRefund->id]->comparableMonetaryExposure);
        $this->assertNull($byId[$foreignRefund->id]->comparableMonetaryExposure);
    }

    public function test_read_query_count_does_not_grow_with_qualifying_rows(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $this->enableRefundApproval($owner, 1);
        $this->createRefund($owner, ['amount' => 1000]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $oneRowQueries = count(DB::getQueryLog());

        $this->createRefund($owner, ['amount' => 1000]);
        $this->createRefund($owner, ['amount' => 1000]);
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
        $this->enableRefundApproval($owner, 1);
        $older = $this->createRefund($owner, [
            'amount' => 1000,
            'requested_at' => now()->subDays(2),
        ]);
        $newer = $this->createRefund($owner, [
            'amount' => 1000,
            'requested_at' => now()->subDay(),
        ]);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 10));

        $this->assertSame([$newer->id, $older->id], array_map(
            static fn ($item): int => $item->sourceId,
            $result->items,
        ));
    }

    private function adapter(): OrderRefundAttentionAdapter
    {
        return app(OrderRefundAttentionAdapter::class);
    }

    private function enableRefundApproval(ShopOwner $owner, float $limit): void
    {
        ProcurementSettings::query()->updateOrCreate(
            ['shop_owner_id' => $owner->id],
            [
                'settings_json' => [
                    'approval_pages' => [
                        'refund_approval' => ['enabled' => true, 'limit' => $limit],
                    ],
                ],
            ],
        );
    }

    private function createRefund(ShopOwner $owner, array $overrides = []): OrderRefund
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'payment_status' => 'paid',
        ]);

        return OrderRefund::factory()->create(array_merge([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $owner->id,
            'flow_type' => 'request_approval',
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
            'return_status' => 'awaiting_approval',
            'amount' => 1000,
            'currency' => 'PHP',
            'requested_at' => now(),
        ], $overrides));
    }
}
