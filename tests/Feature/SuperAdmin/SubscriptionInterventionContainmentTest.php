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
use Illuminate\Support\Facades\Route;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class SubscriptionInterventionContainmentTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_super_admin_plan_swap_interventions_remain_withdrawn(): void
    {
        foreach (['admin.subscriptions.upgrade', 'admin.subscriptions.downgrade'] as $routeName) {
            $this->assertNull(Route::getRoutes()->getByName($routeName), $routeName);
        }

        foreach (['upgrade', 'downgrade'] as $action) {
            $this->assertCount(
                0,
                collect(Route::getRoutes())->filter(fn ($route) => $route->uri() === "admin/subscriptions/{id}/{$action}"),
                "admin subscription {$action} route should be withdrawn",
            );
        }
    }

    public function test_old_subscription_plan_swap_post_uris_cannot_mutate_billing_or_audit_state(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $plan = $this->createPlan();
        $owner = ShopOwner::factory()->approved()->create();
        $subscription = ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'active',
            'paid_amount' => 249,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);
        $payment = ShopOwnerSubscriptionPayment::query()->create([
            'shop_owner_id' => $owner->id,
            'subscription_id' => $subscription->id,
            'payment_type' => 'new_subscription',
            'plan_price' => 249,
            'amount_due' => 249,
            'amount_paid' => 249,
            'status' => 'paid',
            'paid_at' => now()->subDay(),
        ]);

        $subscriptionBefore = $subscription->fresh()->getAttributes();
        $paymentBefore = $payment->fresh()->getAttributes();
        $planBefore = $plan->fresh()->getAttributes();
        $auditCountBefore = DB::table('activity_log')->count();

        foreach (['upgrade', 'downgrade'] as $action) {
            $this->actingAsCompletedPrivileged($admin)
                ->postJson("/admin/subscriptions/{$subscription->id}/{$action}", [
                    'target_plan_id' => $plan->id,
                ])
                ->assertStatus(404);
        }

        $this->assertSame($subscriptionBefore, $subscription->fresh()->getAttributes());
        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
        $this->assertSame($planBefore, $plan->fresh()->getAttributes());
        $this->assertSame($auditCountBefore, DB::table('activity_log')->count());
    }

    public function test_plan_management_and_read_only_subscription_inspection_remain_scoped(): void
    {
        foreach ([
            'admin.subscriptions.index',
            'admin.plans.store',
            'admin.plans.update',
            'admin.plans.archive',
            'admin.plans.reactivate',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, $routeName);
            $this->assertContains('super_admin.auth', $route->middleware(), $routeName);
            $this->assertContains('privileged.capability:manage_plans', $route->middleware(), $routeName);
        }

        $subscriptionRoute = Route::getRoutes()->getByName('admin.subscriptions.index');
        $this->assertSame(['GET', 'HEAD'], $subscriptionRoute?->methods());

        $regularAdmin = SuperAdmin::factory()->admin()->create();
        $this->actingAsCompletedPrivileged($regularAdmin)
            ->get('/admin/subscriptions')
            ->assertForbidden();

        $superAdmin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($superAdmin)
            ->get('/admin/subscriptions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('superAdmin/Shops/SubscriptionManagement')
                ->has('subscriptions')
                ->has('plans'));

        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('shop-owner.premium-success')?->methods());
        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('shop-owner.premium-cancel')?->methods());
    }

    public function test_billing_payload_uses_ledger_revenue_and_declares_only_server_eligible_controls(): void
    {
        $superAdmin = SuperAdmin::factory()->superAdmin()->create();
        $plan = $this->createPlan();
        $owner = ShopOwner::factory()->approved()->create();
        $subscription = ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'active',
            'paid_amount' => 999,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);
        $payment = ShopOwnerSubscriptionPayment::query()->create([
            'shop_owner_id' => $owner->id,
            'subscription_id' => $subscription->id,
            'payment_type' => 'new_subscription',
            'gateway' => 'paymongo',
            'currency' => 'PHP',
            'paymongo_payment_id' => 'payload-payment-1',
            'amount_due' => 249,
            'amount_paid' => 249,
            'status' => 'paid',
            'paid_at' => now()->subDay(),
        ]);
        ShopOwnerSubscriptionRefund::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'local_reference' => (string) fake()->uuid(),
            'provider_refund_id' => 'payload-refund-1',
            'amount' => 249,
            'currency' => 'PHP',
            'business_reason' => 'Payload test refund.',
            'provider_reason' => 'others',
            'status' => 'succeeded',
            'initiated_at' => now()->subHour(),
            'finalized_at' => now()->subHour(),
        ]);

        $legacy = ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'deactivated',
            'paid_amount' => 777,
        ]);

        $this->actingAsCompletedPrivileged($superAdmin)
            ->get('/admin/subscriptions')
            ->assertOk()
            ->assertInertia(function ($page) use ($subscription, $legacy): void {
                $props = $page->toArray()['props'];
                $rows = collect($props['subscriptions'])->keyBy('id');

                $this->assertSame(249.0, (float) $props['stats']['total_revenue']);
                $this->assertSame(249.0, (float) $props['stats']['gross_collected']);
                $this->assertSame(249.0, (float) $props['stats']['refunded_amount']);
                $this->assertSame(0.0, (float) $props['stats']['net_collected']);
                $this->assertSame(0.0, (float) $rows[$legacy->id]['amount_paid']);
                $this->assertTrue($rows[$legacy->id]['legacy_correction_available']);
                $this->assertFalse($rows[$legacy->id]['eligible_for_refund']);
                $this->assertSame(249.0, (float) $rows[$subscription->id]['amount_paid']);
                $this->assertSame(249.0, (float) $rows[$subscription->id]['refunded_amount']);
                $this->assertSame(0.0, (float) $rows[$subscription->id]['net_collected']);
                $this->assertFalse($rows[$subscription->id]['eligible_for_refund']);
                $this->assertTrue($rows[$subscription->id]['can_cancel']);
                $this->assertCount(1, $rows[$subscription->id]['payments']);
                $this->assertCount(1, $rows[$subscription->id]['refund_attempts']);
            });

        $this->assertSame('deactivated', $legacy->fresh()->status);
    }

    private function createPlan(): PremiumPlan
    {
        return PremiumPlan::query()->create([
            'plan_code' => 'containment-'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Containment Plan',
            'description' => 'Test plan',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => 48,
            'benefits' => [],
            'status' => 'active',
        ]);
    }
}
