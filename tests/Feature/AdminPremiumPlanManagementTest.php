<?php

namespace Tests\Feature;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

class AdminPremiumPlanManagementTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    private function createPlan(array $overrides = []): PremiumPlan
    {
        return PremiumPlan::create(array_merge([
            'plan_code' => 'basic',
            'name' => 'Basic',
            'description' => 'Starter premium plan',
            'price' => 249,
            'duration_days' => 15,
            'showroom_slot_limit' => 48,
            'status' => 'active',
        ], $overrides));
    }

    private function createAdmin(): SuperAdmin
    {
        return SuperAdmin::factory()->superAdmin()->create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'phone' => '09170000000',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function createSubscription(PremiumPlan $plan, int $slots): ShopOwnerSubscription
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'individual',
        ]);

        return ShopOwnerSubscription::create([
            'shop_owner_id' => $shop->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $slots,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    /** @test */
    public function premium_plan_preserves_ordered_benefits(): void
    {
        $plan = $this->createPlan([
            'benefits' => ['360-degree images', 'Priority showroom placement'],
        ]);

        $this->assertSame(
            ['360-degree images', 'Priority showroom placement'],
            $plan->fresh()->benefits,
        );
    }

    /** @test */
    public function admin_can_create_archive_and_reactivate_a_plan(): void
    {
        $admin = $this->createAdmin();

        $this->actingAsCompletedPrivileged($admin)->post('/admin/plans', [
            'plan_code' => 'elite',
            'name' => 'Elite',
            'description' => 'Two-room showroom plan',
            'price' => 799,
            'duration_days' => 30,
            'showroom_slot_limit' => 100,
            'benefits' => ['Two connected rooms', 'Image-sequence uploads'],
        ])->assertRedirect('/admin/subscriptions');

        $plan = PremiumPlan::where('plan_code', 'elite')->firstOrFail();
        $this->assertSame(['Two connected rooms', 'Image-sequence uploads'], $plan->benefits);

        $this->actingAsCompletedPrivileged($admin)
            ->post("/admin/plans/{$plan->id}/archive")
            ->assertRedirect();
        $this->assertSame('inactive', $plan->fresh()->status);

        $this->actingAsCompletedPrivileged($admin)
            ->post("/admin/plans/{$plan->id}/reactivate")
            ->assertRedirect();
        $this->assertSame('active', $plan->fresh()->status);
    }

    /** @test */
    public function slot_increases_apply_now_but_decreases_wait_for_renewal(): void
    {
        $admin = $this->createAdmin();
        $plan = $this->createPlan(['showroom_slot_limit' => 84]);
        $subscription = $this->createSubscription($plan, 84);

        $payload = [
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => 299,
            'duration_days' => 30,
            'showroom_slot_limit' => 100,
            'benefits' => [],
        ];

        $this->actingAsCompletedPrivileged($admin)
            ->put("/admin/plans/{$plan->id}", $payload)
            ->assertRedirect();
        $this->assertSame(100, $subscription->fresh()->showroom_slot_limit);

        $payload['showroom_slot_limit'] = 60;
        $this->actingAsCompletedPrivileged($admin)
            ->put("/admin/plans/{$plan->id}", $payload)
            ->assertRedirect();

        $this->assertSame(60, $plan->fresh()->showroom_slot_limit);
        $this->assertSame(100, $subscription->fresh()->showroom_slot_limit);
        $this->assertSame('basic', $plan->fresh()->plan_code);
    }

    /** @test */
    public function admin_plan_validation_caps_showroom_slots_at_150(): void
    {
        $admin = $this->createAdmin();

        $this->actingAsCompletedPrivileged($admin)->from('/admin/subscriptions')
            ->post('/admin/plans', [
                'plan_code' => 'oversized',
                'name' => 'Oversized',
                'price' => 1,
                'duration_days' => 30,
                'showroom_slot_limit' => 151,
                'benefits' => [],
            ])
            ->assertSessionHasErrors('showroom_slot_limit');

        $this->assertDatabaseMissing('premium_plans', ['plan_code' => 'oversized']);
    }
}
