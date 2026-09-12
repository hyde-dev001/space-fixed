<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Models\ShopOwnerSubscriptionRefund;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class SubscriptionManagementScaleTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_subscription_list_is_bounded_summary_and_history_is_loaded_separately(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $plan = $this->createPlan();
        $owner = ShopOwner::factory()->approved()->create();
        $subscription = $this->createSubscription($owner, $plan);
        $firstPayment = $this->createPayment($owner, $subscription, 'paid', 249);
        $secondPayment = $this->createPayment($owner, $subscription, 'failed', 0);

        $firstRefund = ShopOwnerSubscriptionRefund::create([
            'payment_id' => $firstPayment->id,
            'subscription_id' => $subscription->id,
            'local_reference' => 'phase-eight-refund-1',
            'amount' => 49,
            'currency' => 'PHP',
            'business_reason' => 'Phase 8 baseline refund',
            'provider_reason' => 'others',
            'status' => 'succeeded',
            'initiated_at' => now()->subHour(),
            'finalized_at' => now()->subHour(),
        ]);
        $secondRefund = ShopOwnerSubscriptionRefund::create([
            'payment_id' => $secondPayment->id,
            'subscription_id' => $subscription->id,
            'local_reference' => 'phase-eight-refund-2',
            'amount' => 10,
            'currency' => 'PHP',
            'business_reason' => 'Phase 8 unresolved refund',
            'provider_reason' => 'others',
            'status' => 'pending',
            'initiated_at' => now()->subMinutes(30),
        ]);

        $this->actingAsCompletedPrivileged($admin);

        $this->get(route('admin.subscriptions.index', ['per_page' => 1]))
            ->assertOk()
            ->assertInertia(function ($page) use ($subscription, $firstPayment, $secondPayment, $firstRefund, $secondRefund): void {
                $props = $page->toArray()['props'];
                self::assertSame(1, $props['subscriptions']['per_page']);
                $row = collect($props['subscriptions']['data'])->firstWhere('id', $subscription->id);

                self::assertIsArray($row);
                self::assertArrayNotHasKey('payments', $row);
                self::assertArrayNotHasKey('refund_attempts', $row);
                self::assertSame(249.0, (float) $row['amount_paid']);
                self::assertSame(49.0, (float) $row['refunded_amount']);
                self::assertSame(200.0, (float) $row['net_collected']);
                self::assertSame('reconciliation_required', $row['refund_block_reason']);
            });

        $historyResponse = $this->get(route('admin.subscriptions.history', [
            'subscription' => $subscription,
            'payment_page' => 1,
            'refund_page' => 1,
            'per_page' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('subscription_id', $subscription->id)
            ->assertJsonPath('payments.per_page', 1)
            ->assertJsonPath('refunds.per_page', 1)
            ->assertJsonPath('payments.data.0.id', $secondPayment->id)
            ->assertJsonPath('refunds.data.0.id', $secondRefund->id)
            ->assertJsonMissingPath('payments.data.0.metadata')
            ->assertJsonMissingPath('refunds.data.0.provider_response');

        $paymentRow = $historyResponse->json('payments.data.0');
        $refundRow = $historyResponse->json('refunds.data.0');
        self::assertSame([
            'id', 'payment_type', 'amount_due', 'amount_paid', 'currency', 'status', 'paid_at', 'created_at',
        ], array_keys($paymentRow ?? []));
        self::assertSame([
            'id', 'payment_id', 'local_reference', 'provider_refund_id', 'amount', 'currency', 'business_reason',
            'provider_reason', 'status', 'failure_code', 'initiated_at', 'finalized_at', 'reconciled_at', 'created_at',
        ], array_keys($refundRow ?? []));
    }

    public function test_subscription_cards_are_global_while_paginator_totals_follow_filters(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $plan = $this->createPlan();
        $owner = ShopOwner::factory()->approved()->create();

        $active = $this->createSubscription($owner, $plan, [
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
        ]);
        $expired = $this->createSubscription($owner, $plan, [
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);
        $this->createPayment($owner, $active, 'paid', 249);
        $this->createPayment($owner, $expired, 'paid', 249);

        $this->actingAsCompletedPrivileged($admin);

        $this->get(route('admin.subscriptions.index'))
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $stats = $props['stats'];

                self::assertSame(1, $stats['active']);
                self::assertSame(1, $stats['expired']);
                self::assertSame(498.0, (float) $stats['gross_collected']);
                self::assertSame(498.0, (float) $stats['total_revenue']);
                self::assertSame(2, $props['subscriptions']['total']);
            });
    }

    public function test_subscription_filters_and_pagination_are_allowlisted_and_capped(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $plan = $this->createPlan();
        $owner = ShopOwner::factory()->approved()->create(['business_name' => 'Bounded Shoes']);
        $this->createSubscription($owner, $plan);

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.subscriptions.index', [
                'search' => 'Bounded',
                'status' => 'ongoing',
                'change_type' => 'regular',
                'sort' => 'amount_low',
                'per_page' => 100,
                'ignored' => 'must-not-be-applied',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.search', 'Bounded')
                ->where('filters.status', 'ongoing')
                ->where('filters.change_type', 'regular')
                ->where('filters.sort', 'amount_low')
                ->where('subscriptions.per_page', 100));

        foreach ([
            ['status' => 'made-up'],
            ['change_type' => 'made-up'],
            ['sort' => 'made-up'],
            ['page' => 'abc'],
            ['page' => 0],
            ['per_page' => 'abc'],
            ['per_page' => 0],
            ['per_page' => 101],
        ] as $query) {
            $this->actingAsCompletedPrivileged($admin)
                ->getJson(route('admin.subscriptions.index', $query))
                ->assertUnprocessable();
        }
    }

    public function test_summary_query_count_does_not_grow_with_subscription_rows(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $plan = $this->createPlan();
        $owner = ShopOwner::factory()->approved()->create();
        $this->createSubscription($owner, $plan);

        $measure = function () use ($admin): int {
            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            $this->actingAsCompletedPrivileged($admin)
                ->get(route('admin.subscriptions.index'))
                ->assertOk();
            $count = count(DB::connection()->getQueryLog());
            DB::connection()->disableQueryLog();

            return $count;
        };

        $smallCount = $measure();

        foreach (range(1, 30) as $index) {
            $extraOwner = ShopOwner::factory()->approved()->create();
            $this->createSubscription($extraOwner, $plan, ['plan_code' => $plan->plan_code]);
        }

        $largeCount = $measure();

        self::assertLessThanOrEqual($smallCount + 2, $largeCount);
        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.subscriptions.index'))
            ->assertInertia(fn ($page) => $page
                ->where('subscriptions.per_page', 25)
                ->where('subscriptions.total', 31)
                ->where('subscriptions.to', 25));
    }

    private function createPlan(): PremiumPlan
    {
        return PremiumPlan::create([
            'plan_code' => 'phase-eight-scale-'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Phase 8 Scale Plan',
            'description' => 'Subscription scale fixture',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => 48,
            'benefits' => [],
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createSubscription(ShopOwner $owner, PremiumPlan $plan, array $overrides = []): ShopOwnerSubscription
    {
        return ShopOwnerSubscription::create(array_merge([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'active',
            'paid_amount' => 249,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ], $overrides));
    }

    private function createPayment(
        ShopOwner $owner,
        ShopOwnerSubscription $subscription,
        string $status,
        float $amount,
    ): ShopOwnerSubscriptionPayment {
        return ShopOwnerSubscriptionPayment::create([
            'shop_owner_id' => $owner->id,
            'subscription_id' => $subscription->id,
            'payment_type' => 'new_subscription',
            'gateway' => 'paymongo',
            'currency' => 'PHP',
            'paymongo_payment_id' => 'phase-eight-payment-'.fake()->unique()->numberBetween(1, 999999),
            'plan_price' => 249,
            'amount_due' => 249,
            'amount_paid' => $amount,
            'status' => $status,
            'paid_at' => $status === 'paid' ? now()->subDay() : null,
        ]);
    }
}
