<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReconcileLegacyPremiumBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_reliable_and_ambiguous_rows_without_mutating_them(): void
    {
        [$plan, $owner] = $this->planAndOwner();
        $future = $this->legacySubscription($owner, $plan, [
            'status' => 'deactivated',
            'ends_at' => now()->addDays(10),
            'paymongo_session_id' => 'cs_legacy_future',
            'paymongo_payment_id' => 'pay_legacy_future',
            'paid_amount' => 249,
        ]);
        $ambiguous = $this->legacySubscription($owner, $plan, [
            'status' => 'deactivated',
            'ends_at' => null,
            'paymongo_session_id' => null,
            'paymongo_payment_id' => null,
        ]);

        $this->artisan('premium-billing:reconcile-legacy', ['--limit' => 100])
            ->assertExitCode(0)
            ->expectsOutputToContain('dry-run');

        $this->assertSame('deactivated', $future->fresh()->status);
        $this->assertSame('deactivated', $ambiguous->fresh()->status);
        $this->assertDatabaseCount('shop_owner_subscription_payments', 0);
    }

    public function test_apply_maps_reliable_state_and_creates_only_proven_payment_ledger_rows(): void
    {
        [$plan, $owner] = $this->planAndOwner();
        $future = $this->legacySubscription($owner, $plan, [
            'status' => 'deactivated',
            'ends_at' => now()->addDays(10),
            'paymongo_session_id' => 'cs_legacy_future_apply',
            'paymongo_payment_id' => 'pay_legacy_future_apply',
            'paid_amount' => 249,
        ]);
        $past = $this->legacySubscription($owner, $plan, [
            'status' => 'deactivated',
            'ends_at' => now()->subDay(),
            'paymongo_session_id' => 'cs_legacy_past_apply',
            'paymongo_payment_id' => 'pay_legacy_past_apply',
            'paid_amount' => 249,
        ]);
        $ambiguous = $this->legacySubscription($owner, $plan, [
            'status' => 'deactivated',
            'ends_at' => null,
            'paymongo_session_id' => null,
            'paymongo_payment_id' => null,
        ]);

        $this->artisan('premium-billing:reconcile-legacy', ['--apply' => true, '--limit' => 100])
            ->assertExitCode(0)
            ->expectsOutputToContain('applied');

        $this->assertSame('cancelled', $future->fresh()->status);
        $this->assertSame('expired', $past->fresh()->status);
        $this->assertFalse((bool) $future->fresh()->auto_renew);
        $this->assertSame('disabled', $future->fresh()->auto_renew_status);
        $this->assertSame('deactivated', $ambiguous->fresh()->status);
        $this->assertSame('249.00', (string) $future->fresh()->paid_amount);
        $this->assertSame('pay_legacy_future_apply', $future->fresh()->paymongo_payment_id);

        $payment = ShopOwnerSubscriptionPayment::query()->where('subscription_id', $future->id)->sole();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('premium_subscription', $payment->payment_type);
        $this->assertSame('pay_legacy_future_apply', $payment->paymongo_payment_id);
        $this->assertSame('cs_legacy_future_apply', $payment->paymongo_session_id);
        $this->assertSame('249.00', (string) $payment->amount_paid);
        $this->assertSame(2, DB::table('activity_log')->where('description', 'premium_payment_reconciled')->count());
    }

    public function test_apply_is_idempotent(): void
    {
        [$plan, $owner] = $this->planAndOwner();
        $subscription = $this->legacySubscription($owner, $plan, [
            'status' => 'active',
            'ends_at' => now()->addDays(10),
            'paymongo_session_id' => 'cs_legacy_idempotent',
            'paymongo_payment_id' => 'pay_legacy_idempotent',
            'paid_amount' => 249,
        ]);

        $this->artisan('premium-billing:reconcile-legacy', ['--apply' => true])
            ->assertExitCode(0);
        $first = ShopOwnerSubscriptionPayment::query()->where('subscription_id', $subscription->id)->sole();
        $firstUpdatedAt = $first->updated_at?->toISOString();

        $this->artisan('premium-billing:reconcile-legacy', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(1, ShopOwnerSubscriptionPayment::query()->where('subscription_id', $subscription->id)->count());
        $this->assertSame($firstUpdatedAt, ShopOwnerSubscriptionPayment::query()->whereKey($first->id)->firstOrFail()->updated_at?->toISOString());
    }

    public function test_conflicting_provider_references_remain_ambiguous_without_creating_a_ledger_row(): void
    {
        [$plan, $owner] = $this->planAndOwner();
        $source = $this->legacySubscription($owner, $plan, [
            'status' => 'active',
            'paymongo_session_id' => 'cs_conflicting_reference',
            'paymongo_payment_id' => 'pay_conflicting_reference',
            'paid_amount' => 249,
        ]);
        $conflict = $this->legacySubscription($owner, $plan, [
            'status' => 'active',
            'paymongo_session_id' => 'cs_conflicting_reference',
            'paymongo_payment_id' => 'pay_conflicting_reference',
            'paid_amount' => 249,
        ]);

        $this->artisan('premium-billing:reconcile-legacy', ['--apply' => true, '--limit' => 100])
            ->assertExitCode(0)
            ->expectsOutputToContain('Ambiguous: 2');

        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame('active', $conflict->fresh()->status);
        $this->assertDatabaseCount('shop_owner_subscription_payments', 0);
    }

    private function planAndOwner(): array
    {
        $plan = PremiumPlan::query()->create([
            'plan_code' => 'reconcile-'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Reconcile Plan',
            'description' => 'Reconcile test plan',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => 48,
            'benefits' => [],
            'status' => 'active',
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'individual',
        ]);

        return [$plan, $owner];
    }

    private function legacySubscription(ShopOwner $owner, PremiumPlan $plan, array $overrides): ShopOwnerSubscription
    {
        return ShopOwnerSubscription::query()->create(array_merge([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'deactivated',
            'paid_amount' => $plan->price,
        ], $overrides));
    }
}
