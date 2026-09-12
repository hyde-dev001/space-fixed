<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PremiumSubscriptionCancellationTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_shop_owner_cancellation_preserves_paid_history_and_original_end_date(): void
    {
        [$owner, $subscription, $payment] = $this->activePaidSubscription();
        $endsAt = $subscription->ends_at?->toISOString();

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/cancel', [
                'subscription_id' => $subscription->id,
                'cancellation_reason' => 'reduce_costs',
                'cancellation_notes' => 'Owner requested period-end cancellation.',
            ])
            ->assertOk()
            ->assertJsonPath('subscription.status', 'cancelled');

        $this->assertSame('cancelled', $subscription->fresh()->status);
        $this->assertSame($endsAt, $subscription->fresh()->ends_at?->toISOString());
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('249.00', (string) $payment->fresh()->amount_paid);
    }

    public function test_pending_checkout_returns_conflict_and_remains_pending(): void
    {
        [$owner, $subscription] = $this->activePaidSubscription();
        $subscription->update(['status' => 'pending']);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/cancel', [
                'subscription_id' => $subscription->id,
                'cancellation_reason' => 'reduce_costs',
            ])
            ->assertStatus(409);

        $this->assertSame('pending', $subscription->fresh()->status);
    }

    public function test_super_admin_cancellation_requires_fixed_reason_and_recent_reauthentication(): void
    {
        [, $subscription] = $this->activePaidSubscription();
        $admin = SuperAdmin::factory()->superAdmin()->create();

        $this->actingAsCompletedPrivileged($admin);
        session()->put([
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => $admin->security_version,
        ]);

        $this->postJson(route('admin.subscriptions.cancel', $subscription), [])
            ->assertUnprocessable();

        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertDatabaseMissing('activity_log', [
            'description' => 'subscription_cancelled',
        ]);
    }

    public function test_super_admin_cancellation_is_audited_and_exact_replay_is_inert(): void
    {
        [, $subscription, $payment] = $this->activePaidSubscription();
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($admin);
        session()->put([
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => $admin->security_version,
        ]);

        $payload = [
            'cancellation_reason' => 'operator_correction',
            'cancellation_notes' => 'Verified by billing support.',
        ];

        $this->postJson(route('admin.subscriptions.cancel', $subscription), $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $auditCount = DB::table('activity_log')
            ->where('description', 'subscription_cancelled')
            ->count();

        $this->postJson(route('admin.subscriptions.cancel', $subscription), $payload)
            ->assertOk()
            ->assertJsonPath('replayed', true);

        $this->assertSame($auditCount, DB::table('activity_log')->where('description', 'subscription_cancelled')->count());
        $this->assertSame('cancelled', $subscription->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_cancellation_rejects_unresolved_pending_renewal(): void
    {
        [$owner, $subscription] = $this->activePaidSubscription();
        ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $subscription->premium_plan_id,
            'plan_code' => $subscription->plan_code,
            'showroom_slot_limit' => $subscription->showroom_slot_limit,
            'status' => 'pending',
            'renewal_of_subscription_id' => $subscription->id,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/cancel', [
                'subscription_id' => $subscription->id,
                'cancellation_reason' => 'reduce_costs',
            ])
            ->assertStatus(409);

        $this->assertSame('active', $subscription->fresh()->status);
    }

    /** @return array{0: ShopOwner, 1: ShopOwnerSubscription, 2: ShopOwnerSubscriptionPayment} */
    private function activePaidSubscription(): array
    {
        $owner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'individual',
        ]);
        $plan = PremiumPlan::query()->create([
            'plan_code' => 'cancel-'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Cancellation Plan',
            'description' => 'Cancellation test plan',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => 48,
            'benefits' => [],
            'status' => 'active',
        ]);
        $subscription = ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'active',
            'auto_renew' => true,
            'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED,
            'paid_amount' => 249,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);
        $payment = ShopOwnerSubscriptionPayment::query()->create([
            'shop_owner_id' => $owner->id,
            'subscription_id' => $subscription->id,
            'payment_type' => 'new_subscription',
            'gateway' => 'paymongo',
            'currency' => 'PHP',
            'plan_price' => 249,
            'amount_due' => 249,
            'amount_paid' => 249,
            'status' => 'paid',
            'paid_at' => now()->subDay(),
        ]);

        return [$owner, $subscription, $payment];
    }
}
