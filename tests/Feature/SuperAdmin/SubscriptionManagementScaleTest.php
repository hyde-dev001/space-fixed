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
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class SubscriptionManagementScaleTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_phase_seven_subscription_payload_hydrates_complete_payment_and_refund_history(): void
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

        $this->get(route('admin.subscriptions.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($subscription, $firstPayment, $secondPayment, $firstRefund, $secondRefund): void {
                $props = $page->toArray()['props'];
                $row = collect($props['subscriptions'])->firstWhere('id', $subscription->id);

                self::assertIsArray($row);
                self::assertCount(2, $row['payments']);
                self::assertCount(2, $row['refund_attempts']);
                self::assertSame($firstPayment->id, $row['payments'][1]['id']);
                self::assertSame($secondPayment->id, $row['payments'][0]['id']);
                self::assertSame($firstRefund->id, $row['refund_attempts'][1]['id']);
                self::assertSame($secondRefund->id, $row['refund_attempts'][0]['id']);
                self::assertSame(249.0, (float) $row['amount_paid']);
                self::assertSame(49.0, (float) $row['refunded_amount']);
                self::assertSame(200.0, (float) $row['net_collected']);
                self::assertSame('reconciliation_required', $row['refund_block_reason']);
            });
    }

    public function test_phase_seven_subscription_cards_are_derived_from_the_full_collection(): void
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
                $stats = $page->toArray()['props']['stats'];

                self::assertSame(1, $stats['active']);
                self::assertSame(1, $stats['expired']);
                self::assertSame(498.0, (float) $stats['gross_collected']);
                self::assertSame(498.0, (float) $stats['total_revenue']);
            });
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
