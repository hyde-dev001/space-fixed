<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Http\Middleware\AttachPrivilegedCorrelationId;
use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PhaseFiveBillingBoundaryTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_phase_five_billing_routes_are_canonical_and_protected(): void
    {
        $routes = [
            'admin.subscriptions.cancel' => [
                'POST',
                'admin/subscriptions/{subscription}/cancel',
            ],
            'admin.subscriptions.legacy-correction' => [
                'PATCH',
                'admin/subscriptions/{subscription}/legacy-correction',
            ],
            'admin.subscription-payments.refunds.store' => [
                'POST',
                'admin/subscription-payments/{payment}/refunds',
            ],
        ];

        foreach ($routes as $name => [$method, $uri]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertSame([$method, ...($method === 'GET' ? ['HEAD'] : [])], $route->methods(), $name);
            $this->assertSame($uri, $route->uri(), $name);
            $this->assertContains('web', $route->middleware(), $name);
            $this->assertContains(AttachPrivilegedCorrelationId::class, $route->middleware(), $name);
            $this->assertContains('super_admin.auth', $route->middleware(), $name);
            $this->assertContains('privileged.active', $route->middleware(), $name);
            $this->assertContains('privileged.mfa', $route->middleware(), $name);
            $this->assertContains('privileged.capability:intervene_subscriptions', $route->middleware(), $name);
            $this->assertContains('privileged.recent', $route->middleware(), $name);
        }

        $refundRoute = Route::getRoutes()->getByName('admin.subscription-payments.refunds.store');
        $this->assertContains('throttle:privileged-subscription-refund', $refundRoute?->middleware() ?? []);

        foreach (['admin.subscriptions.upgrade', 'admin.subscriptions.downgrade'] as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name), $name);
        }
    }

    public function test_regular_admin_is_denied_before_billing_mutation_runs(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $plan = $this->createPlan();
        $owner = ShopOwner::factory()->approved()->create();
        $subscription = ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.subscriptions.cancel', $subscription), [
                'reason' => 'customer requested cancellation',
            ])
            ->assertForbidden();

        $this->assertSame('active', $subscription->fresh()->status);
    }

    private function createPlan(): PremiumPlan
    {
        return PremiumPlan::query()->create([
            'plan_code' => 'phase-five-'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Phase Five Plan',
            'description' => 'Billing boundary test plan',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => 48,
            'benefits' => [],
            'status' => 'active',
        ]);
    }
}
