<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\PremiumPlanManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PremiumPlanWorkflowTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_plan_lifecycle_is_normalized_audited_and_idempotent(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAsCompletedPrivileged($admin)
            ->post('/admin/premium-plans', [
                'plan_code' => '  elite-plan ',
                'name' => '  Elite  ',
                'description' => '  Premium access  ',
                'price' => '799.00',
                'duration_days' => '30',
                'showroom_slot_limit' => '100',
                'benefits' => ['  Priority placement ', 'Dedicated support'],
            ])
            ->assertRedirect('/admin/subscription-management');

        $plan = PremiumPlan::query()->where('plan_code', 'elite-plan')->firstOrFail();
        $this->assertSame('Elite', $plan->name);
        $this->assertSame(['Priority placement', 'Dedicated support'], $plan->benefits);
        $this->assertAuditEvent('premium_plan_created', $plan);

        $this->actingAsCompletedPrivileged($admin)
            ->put("/admin/premium-plans/{$plan->id}", [
                'name' => 'Elite Plus',
                'description' => 'Premium access plus support',
                'price' => 899,
                'duration_days' => 45,
                'showroom_slot_limit' => 110,
                'benefits' => ['Priority placement', 'Dedicated support', 'Analytics'],
            ])
            ->assertRedirect('/admin/subscription-management');

        $this->assertAuditEvent('premium_plan_updated', $plan);
        $updatedCount = DB::table('activity_log')
            ->where('description', 'premium_plan_updated')
            ->where('subject_id', $plan->id)
            ->count();

        $this->actingAsCompletedPrivileged($admin)
            ->put("/admin/premium-plans/{$plan->id}", [
                'name' => 'Elite Plus',
                'description' => 'Premium access plus support',
                'price' => 899,
                'duration_days' => 45,
                'showroom_slot_limit' => 110,
                'benefits' => ['Priority placement', 'Dedicated support', 'Analytics'],
            ])
            ->assertRedirect('/admin/subscription-management');

        $this->assertSame($updatedCount, DB::table('activity_log')
            ->where('description', 'premium_plan_updated')
            ->where('subject_id', $plan->id)
            ->count());

        $this->actingAsCompletedPrivileged($admin)
            ->post("/admin/premium-plans/{$plan->id}/archive")
            ->assertRedirect();
        $this->actingAsCompletedPrivileged($admin)
            ->post("/admin/premium-plans/{$plan->id}/archive")
            ->assertRedirect();
        $this->actingAsCompletedPrivileged($admin)
            ->post("/admin/premium-plans/{$plan->id}/reactivate")
            ->assertRedirect();
        $this->actingAsCompletedPrivileged($admin)
            ->post("/admin/premium-plans/{$plan->id}/reactivate")
            ->assertRedirect();

        $this->assertSame('active', $plan->fresh()->status);
        $this->assertSame(1, $this->auditCount('premium_plan_archived', $plan));
        $this->assertSame(1, $this->auditCount('premium_plan_reactivated', $plan));
    }

    public function test_plan_slot_increases_propagate_only_to_currently_entitled_subscriptions(): void
    {
        $admin = $this->createSuperAdmin();
        $plan = $this->createPlan(['showroom_slot_limit' => 48]);
        $active = $this->createSubscription($plan, 'active', now()->addDays(10));
        $cancelled = $this->createSubscription($plan, 'cancelled', now()->addDays(10));
        $expired = $this->createSubscription($plan, 'active', now()->subDay());

        $this->actingAsCompletedPrivileged($admin)
            ->put("/admin/premium-plans/{$plan->id}", [
                'name' => $plan->name,
                'description' => $plan->description,
                'price' => $plan->price,
                'duration_days' => $plan->duration_days,
                'showroom_slot_limit' => 100,
                'benefits' => [],
            ])
            ->assertRedirect('/admin/subscription-management');

        $this->assertSame(100, $active->fresh()->showroom_slot_limit);
        $this->assertSame(100, $cancelled->fresh()->showroom_slot_limit);
        $this->assertSame(48, $expired->fresh()->showroom_slot_limit);
    }

    public function test_audit_failure_rolls_back_plan_and_entitlement_mutation(): void
    {
        $admin = $this->createSuperAdmin();
        $plan = $this->createPlan(['showroom_slot_limit' => 48]);
        $subscription = $this->createSubscription($plan, 'active', now()->addDays(10));
        $request = Request::create('/admin/premium-plans/'.$plan->id, 'PUT');

        $audit = Mockery::mock(PrivilegedAudit::class);
        $audit->shouldReceive('premiumPlanUpdated')
            ->once()
            ->andThrow(new \RuntimeException('injected audit failure'));
        $this->instance(PrivilegedAudit::class, $audit);

        try {
            app(PremiumPlanManagementService::class)->update(
                plan: $plan,
                attributes: [
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'price' => 899,
                    'duration_days' => $plan->duration_days,
                    'showroom_slot_limit' => 100,
                    'benefits' => [],
                ],
                actor: $admin,
                request: $request,
            );
            $this->fail('The injected audit failure should escape the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('injected audit failure', $exception->getMessage());
        }

        $this->assertSame(48, $plan->fresh()->showroom_slot_limit);
        $this->assertSame('249.00', (string) $plan->fresh()->price);
        $this->assertSame(48, $subscription->fresh()->showroom_slot_limit);
        $this->assertSame(0, $this->auditCount('premium_plan_updated', $plan));
    }

    public function test_regular_admin_cannot_mutate_premium_plans(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->post('/admin/premium-plans', [
                'plan_code' => 'blocked',
                'name' => 'Blocked',
                'price' => 1,
                'duration_days' => 30,
                'showroom_slot_limit' => 1,
                'benefits' => [],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('premium_plans', ['plan_code' => 'blocked']);
    }

    private function createSuperAdmin(): SuperAdmin
    {
        return SuperAdmin::factory()->superAdmin()->create([
            'first_name' => 'Plan',
            'last_name' => 'Admin',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09170000005',
            'password' => 'password',
            'status' => SuperAdmin::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createPlan(array $overrides = []): PremiumPlan
    {
        return PremiumPlan::query()->create(array_merge([
            'plan_code' => 'basic-'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Basic',
            'description' => 'Starter plan',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => 48,
            'benefits' => [],
            'status' => 'active',
        ], $overrides));
    }

    private function createSubscription(PremiumPlan $plan, string $status, \Illuminate\Support\Carbon $endsAt): ShopOwnerSubscription
    {
        $owner = ShopOwner::factory()->approved()->create();

        return ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => 48,
            'status' => $status,
            'starts_at' => now()->subDay(),
            'ends_at' => $endsAt,
        ]);
    }

    private function assertAuditEvent(string $event, PremiumPlan $plan): void
    {
        $this->assertSame(1, $this->auditCount($event, $plan));
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'privileged',
            'description' => $event,
            'subject_type' => PremiumPlan::class,
            'subject_id' => $plan->id,
        ]);
    }

    private function auditCount(string $event, PremiumPlan $plan): int
    {
        return DB::table('activity_log')
            ->where('log_name', 'privileged')
            ->where('description', $event)
            ->where('subject_type', PremiumPlan::class)
            ->where('subject_id', $plan->id)
            ->count();
    }
}
