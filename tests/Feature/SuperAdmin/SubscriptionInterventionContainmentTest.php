<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
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

    public function test_super_admin_subscription_interventions_are_not_registered(): void
    {
        foreach ([
            'admin.subscriptions.cancel',
            'admin.subscriptions.upgrade',
            'admin.subscriptions.downgrade',
        ] as $routeName) {
            $this->assertNull(Route::getRoutes()->getByName($routeName), $routeName);
        }

        foreach (['cancel', 'upgrade', 'downgrade'] as $action) {
            $this->assertCount(
                0,
                collect(Route::getRoutes())->filter(fn ($route) => $route->uri() === "admin/subscriptions/{id}/{$action}"),
                "admin subscription {$action} route should be withdrawn",
            );
        }
    }

    public function test_old_subscription_post_uris_cannot_mutate_billing_or_audit_state(): void
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

        foreach (['cancel', 'upgrade', 'downgrade'] as $action) {
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
            'admin.subscription-management',
            'admin.premium-plans.store',
            'admin.premium-plans.update',
            'admin.premium-plans.archive',
            'admin.premium-plans.reactivate',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, $routeName);
            $this->assertContains('super_admin.auth', $route->middleware(), $routeName);
            $this->assertContains('privileged.capability:manage_plans', $route->middleware(), $routeName);
        }

        $subscriptionRoute = Route::getRoutes()->getByName('admin.subscription-management');
        $this->assertSame(['GET', 'HEAD'], $subscriptionRoute?->methods());

        $regularAdmin = SuperAdmin::factory()->admin()->create();
        $this->actingAsCompletedPrivileged($regularAdmin)
            ->get('/admin/subscription-management')
            ->assertForbidden();

        $superAdmin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($superAdmin)
            ->get('/admin/subscription-management')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('superAdmin/Shops/SubscriptionManagement')
                ->has('subscriptions')
                ->has('plans'));

        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('shop-owner.premium-success')?->methods());
        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('shop-owner.premium-cancel')?->methods());
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
