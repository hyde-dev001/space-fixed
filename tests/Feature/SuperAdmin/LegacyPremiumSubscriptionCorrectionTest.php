<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class LegacyPremiumSubscriptionCorrectionTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_only_unresolved_deactivated_rows_can_be_corrected_and_replay_is_inert(): void
    {
        $subscription = $this->legacySubscription();
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $effectiveEndsAt = now()->subDay()->startOfMinute();
        $payload = [
            'target_status' => 'expired',
            'effective_ends_at' => $effectiveEndsAt->toISOString(),
            'correction_reason' => 'Verified from archived billing record.',
            'correction_notes' => 'Legacy provider record confirms access ended before migration.',
        ];

        $this->actingAsCompletedPrivileged($admin);
        $this->markRecentlyReauthenticated($admin);

        $this->patchJson(route('admin.subscriptions.legacy-correction', $subscription), $payload)
            ->assertOk()
            ->assertJsonPath('corrected', true)
            ->assertJsonPath('subscription.status', 'expired');

        $corrected = $subscription->fresh();
        $this->assertSame('expired', $corrected->status);
        $this->assertSame($effectiveEndsAt->toISOString(), $corrected->ends_at?->toISOString());
        $this->assertSame('Verified from archived billing record.', $corrected->cancellation_reason);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'privileged',
            'description' => 'legacy_subscription_corrected',
            'subject_id' => $subscription->id,
        ]);

        $auditCount = DB::table('activity_log')
            ->where('description', 'legacy_subscription_corrected')
            ->count();

        $this->patchJson(route('admin.subscriptions.legacy-correction', $subscription), $payload)
            ->assertOk()
            ->assertJsonPath('corrected', false)
            ->assertJsonPath('replayed', true);

        $this->assertSame($auditCount, DB::table('activity_log')
            ->where('description', 'legacy_subscription_corrected')
            ->count());
    }

    public function test_future_effective_date_can_only_produce_cancelled_and_past_date_can_only_produce_expired(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($admin);
        $this->markRecentlyReauthenticated($admin);

        $future = $this->legacySubscription();
        $this->patchJson(route('admin.subscriptions.legacy-correction', $future), [
            'target_status' => 'expired',
            'effective_ends_at' => now()->addDay()->toISOString(),
            'correction_reason' => 'Invalid date/status pair.',
        ])->assertUnprocessable();
        $this->assertSame('deactivated', $future->fresh()->status);

        $past = $this->legacySubscription();
        $this->patchJson(route('admin.subscriptions.legacy-correction', $past), [
            'target_status' => 'cancelled',
            'effective_ends_at' => now()->subDay()->toISOString(),
            'correction_reason' => 'Invalid date/status pair.',
        ])->assertUnprocessable();
        $this->assertSame('deactivated', $past->fresh()->status);
    }

    public function test_regular_admin_and_stale_reauthentication_are_denied(): void
    {
        $subscription = $this->legacySubscription();
        $admin = SuperAdmin::factory()->admin()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.subscriptions.legacy-correction', $subscription), [
                'target_status' => 'expired',
                'effective_ends_at' => now()->subDay()->toISOString(),
                'correction_reason' => 'Denied correction.',
            ])
            ->assertForbidden();

        $superAdmin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($superAdmin)
            ->patchJson(route('admin.subscriptions.legacy-correction', $subscription), [
                'target_status' => 'expired',
                'effective_ends_at' => now()->subDay()->toISOString(),
                'correction_reason' => 'Stale correction.',
            ])
            ->assertStatus(423);

        $this->assertSame('deactivated', $subscription->fresh()->status);
    }

    public function test_correction_cannot_change_financial_provider_owner_plan_or_start_fields(): void
    {
        $subscription = $this->legacySubscription();
        $original = $subscription->fresh()->getAttributes();
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($admin);
        $this->markRecentlyReauthenticated($admin);

        $this->patchJson(route('admin.subscriptions.legacy-correction', $subscription), [
            'target_status' => 'expired',
            'effective_ends_at' => now()->subDay()->toISOString(),
            'correction_reason' => 'Attempted financial correction.',
            'paid_amount' => 0,
            'premium_plan_id' => $original['premium_plan_id'] + 1,
            'shop_owner_id' => $original['shop_owner_id'] + 1,
            'starts_at' => now()->subYears(5)->toISOString(),
            'paymongo_payment_id' => 'fake-provider-id',
        ])->assertUnprocessable();

        $this->assertSame($original, $subscription->fresh()->getAttributes());
        $this->assertDatabaseMissing('activity_log', [
            'description' => 'legacy_subscription_corrected',
        ]);
    }

    public function test_non_legacy_and_conflicting_corrections_are_rejected_without_mutation(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($admin);
        $this->markRecentlyReauthenticated($admin);

        $active = $this->legacySubscription(['status' => 'active']);
        $this->patchJson(route('admin.subscriptions.legacy-correction', $active), [
            'target_status' => 'expired',
            'effective_ends_at' => now()->subDay()->toISOString(),
            'correction_reason' => 'Not a legacy row.',
        ])->assertStatus(409);
        $this->assertSame('active', $active->fresh()->status);

        $ordinaryExpiredEndsAt = now()->subDay()->startOfMinute();
        $ordinaryExpired = $this->legacySubscription([
            'status' => 'expired',
            'ends_at' => $ordinaryExpiredEndsAt,
            'cancellation_reason' => 'First classification.',
        ]);
        $this->patchJson(route('admin.subscriptions.legacy-correction', $ordinaryExpired), [
            'target_status' => 'expired',
            'effective_ends_at' => $ordinaryExpiredEndsAt->toISOString(),
            'correction_reason' => 'First classification.',
        ])->assertStatus(409);

        $legacy = $this->legacySubscription();
        $payload = [
            'target_status' => 'expired',
            'effective_ends_at' => now()->subDay()->toISOString(),
            'correction_reason' => 'First classification.',
        ];
        $this->patchJson(route('admin.subscriptions.legacy-correction', $legacy), $payload)->assertOk();

        $this->patchJson(route('admin.subscriptions.legacy-correction', $legacy), [
            ...$payload,
            'target_status' => 'cancelled',
            'effective_ends_at' => now()->addDay()->toISOString(),
            'correction_reason' => 'Conflicting classification.',
        ])->assertStatus(409);

        $this->assertSame('expired', $legacy->fresh()->status);
    }

    /** @param array<string, mixed> $overrides */
    private function legacySubscription(array $overrides = []): ShopOwnerSubscription
    {
        $owner = ShopOwner::factory()->approved()->create();
        $plan = PremiumPlan::query()->create([
            'plan_code' => 'legacy-correction-'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Legacy Correction Plan',
            'description' => 'Legacy correction test plan',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => 48,
            'benefits' => [],
            'status' => 'active',
        ]);

        return ShopOwnerSubscription::query()->create(array_merge([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'deactivated',
            'paid_amount' => 249,
            'paymongo_payment_id' => 'legacy-provider-'.fake()->unique()->numberBetween(1, 999999),
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            'ends_at' => null,
        ], $overrides));
    }

    private function markRecentlyReauthenticated(SuperAdmin $admin): void
    {
        session()->put([
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => (int) $admin->security_version,
        ]);
    }
}
