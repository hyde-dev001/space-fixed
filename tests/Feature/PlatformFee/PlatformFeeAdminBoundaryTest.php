<?php

namespace Tests\Feature\PlatformFee;

use App\Models\PlatformFeeCharge;
use App\Models\PlatformFeeAdjustment;
use App\Models\PlatformFeePayment;
use App\Models\PlatformFeePaymentAllocation;
use App\Models\PlatformCreditApplication;
use App\Models\PlatformFeeRecommendation;
use App\Models\Notification;
use App\Models\SuperAdmin;
use App\Models\ShopOwner;
use App\Enums\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

class PlatformFeeAdminBoundaryTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    #[Test]
    public function platform_fee_admin_routes_are_capability_and_recent_auth_protected(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin/platform-fees'));

        $this->assertCount(8, $routes);
        foreach ($routes as $route) {
            $this->assertContains('super_admin.auth', $route->middleware());
            $this->assertContains('privileged.active', $route->middleware());
            $this->assertContains('privileged.mfa', $route->middleware());
            $this->assertContains('privileged.capability:manage_platform_fees', $route->middleware());
        }

        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/platform-fees')
            ->assertOk();

        $superAdmin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($superAdmin)
            ->get('/admin/platform-fees')
            ->assertOk();
    }

    #[Test]
    public function an_admin_adjustment_is_append_only_and_idempotent(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $shop = ShopOwner::factory()->approved()->create();
        $payload = [
            'adjustment_type' => 'credit_adjustment',
            'amount' => '10.00',
            'reason' => 'Approved billing correction',
        ];

        $this->actingAsCompletedPrivileged($admin)
            ->withSession([
                'privileged_reauthenticated_at' => now()->timestamp,
                'privileged_reauthenticated_security_version' => $admin->security_version,
            ])
            ->withHeader('Idempotency-Key', 'admin-adjustment-test-1')
            ->post("/admin/platform-fees/shops/{$shop->id}/adjustments", $payload)
            ->assertRedirect();

        $this->actingAsCompletedPrivileged($admin)
            ->withSession([
                'privileged_reauthenticated_at' => now()->timestamp,
                'privileged_reauthenticated_security_version' => $admin->security_version,
            ])
            ->withHeader('Idempotency-Key', 'admin-adjustment-test-1')
            ->post("/admin/platform-fees/shops/{$shop->id}/adjustments", $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('platform_fee_adjustments', 1);
        $this->assertDatabaseHas('platform_fee_adjustments', [
            'shop_id' => $shop->id,
            'adjustment_type' => 'credit_adjustment',
            'total_delta' => '-10.00',
            'created_by' => $admin->id,
        ]);
    }

    #[Test]
    public function an_admin_can_override_a_shop_balance_limit(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $shop = ShopOwner::factory()->approved()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->withSession([
                'privileged_reauthenticated_at' => now()->timestamp,
                'privileged_reauthenticated_security_version' => $admin->security_version,
            ])
            ->post("/admin/platform-fees/shops/{$shop->id}/limit", [
                'balance_limit' => '40000.00',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('shop_platform_fee_settings', [
            'shop_owner_id' => $shop->id,
            'balance_limit' => '40000.00',
            'status' => 'approved',
            'approved_by' => $admin->id,
        ]);
    }

    #[Test]
    public function platform_fee_admin_page_includes_aggregate_payment_cards(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $shop = ShopOwner::factory()->approved()->create();

        $charge = PlatformFeeCharge::create([
            'shop_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => 101,
            'source_origin' => 'marketplace',
            'fee_base' => '1000.00',
            'fee_rate' => '5.000000',
            'platform_fee_amount' => '50.00',
            'vat_enabled' => true,
            'vat_rate' => '12.000000',
            'vat_amount' => '6.00',
            'total_charge' => '56.00',
            'status' => 'outstanding',
            'finalized_at' => now(),
        ]);
        $payment = PlatformFeePayment::create([
            'shop_id' => $shop->id,
            'amount' => '56.00',
            'balance_snapshot' => '56.00',
            'credit_snapshot' => '0.00',
            'net_payable_snapshot' => '56.00',
            'status' => 'paid',
            'idempotency_key' => 'admin-metrics-payment',
            'paid_at' => now(),
        ]);
        PlatformFeePaymentAllocation::create([
            'platform_fee_payment_id' => $payment->id,
            'platform_fee_charge_id' => $charge->id,
            'allocation_type' => 'payment',
            'amount' => '56.00',
            'idempotency_key' => 'admin-metrics-allocation',
        ]);

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/platform-fees')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('metrics.platform_fee_earned', '50.00')
                ->where('metrics.total_billed', '56.00')
                ->where('metrics.collected', '56.00')
                ->where('metrics.outstanding', '0.00')
                ->where('metrics.pending_payments', 0));
    }

    #[Test]
    public function generated_platform_fees_exclude_unfinalized_charges_and_include_finalized_reversals(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $shop = ShopOwner::factory()->approved()->create();

        $finalizedCharge = PlatformFeeCharge::create([
            'shop_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => 301,
            'source_origin' => 'marketplace',
            'fee_base' => '1000.00',
            'fee_rate' => '5.000000',
            'platform_fee_amount' => '50.00',
            'vat_enabled' => true,
            'vat_rate' => '12.000000',
            'vat_amount' => '6.00',
            'total_charge' => '56.00',
            'status' => 'outstanding',
            'finalized_at' => now(),
        ]);

        PlatformFeeCharge::create([
            'shop_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => 302,
            'source_origin' => 'marketplace',
            'fee_base' => '2000.00',
            'fee_rate' => '5.000000',
            'platform_fee_amount' => '100.00',
            'vat_enabled' => true,
            'vat_rate' => '12.000000',
            'vat_amount' => '12.00',
            'total_charge' => '112.00',
            'status' => 'outstanding',
            'finalized_at' => null,
        ]);

        PlatformFeeAdjustment::create([
            'shop_id' => $shop->id,
            'platform_fee_charge_id' => $finalizedCharge->id,
            'adjustment_type' => 'refund_reversal',
            'source_type' => 'order_refund',
            'source_id' => 301,
            'fee_base_delta' => '-200.00',
            'platform_fee_delta' => '-10.00',
            'vat_delta' => '-1.20',
            'total_delta' => '-11.20',
            'reason' => 'Finalized marketplace refund reversal.',
            'idempotency_key' => 'admin-generated-fee-reversal',
        ]);

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/platform-fees')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('metrics.platform_fee_earned', '40.00'));
    }

    #[Test]
    public function platform_fee_admin_page_exposes_all_credit_movements_for_the_history_modal(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $shop = ShopOwner::factory()->approved()->create();
        PlatformCreditApplication::create([
            'shop_id' => $shop->id,
            'source_type' => 'order_refund',
            'source_id' => 202,
            'source_origin' => 'marketplace',
            'credit_amount' => '18.00',
            'status' => 'available',
            'reason' => 'Refund credit',
            'idempotency_key' => 'admin-credit-history-test',
        ]);

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/platform-fees')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('shops.0.credit_movements.0.source_type', 'order_refund')
                ->where('shops.0.credit_movements.0.source_id', 202)
                ->where('shops.0.credit_movements.0.credit_amount', '18.00'));
    }

    #[Test]
    public function platform_fee_changes_notify_the_affected_shop_and_record_audit_details(): void
    {
        Mail::fake();
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create([
            'first_name' => 'Security',
            'last_name' => 'Admin',
        ]);
        $otherAdmin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $individual = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_name' => 'Individual Fee Shop',
        ]);
        $business = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_name' => 'Business Fee Shop',
        ]);

        $session = [
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => $admin->security_version,
        ];

        $this->actingAsCompletedPrivileged($admin)
            ->withSession($session)
            ->post('/admin/platform-fees/settings', [
                'scope' => 'shop_type',
                'shop_type' => 'individual',
                'platform_fee_vat_rate' => '15.000000',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'shop_owner_id' => $individual->id,
            'type' => NotificationType::PLATFORM_BALANCE_ALERT->value,
            'title' => 'Platform Fee VAT rate increased',
        ]);

        $adminNotification = Notification::query()
            ->where('super_admin_id', $admin->id)
            ->where('title', 'Platform Fee settings changed')
            ->latest('id')
            ->firstOrFail();
        $this->assertStringContainsString('Security Admin', $adminNotification->message);
        $this->assertStringContainsString($admin->email, $adminNotification->message);
        $this->assertSame($admin->id, $adminNotification->data['actor_id']);
        $this->assertSame('Security Admin', $adminNotification->data['actor_name']);
        $this->assertSame($admin->email, $adminNotification->data['actor_email']);
        $this->assertDatabaseHas('notifications', [
            'super_admin_id' => $otherAdmin->id,
            'type' => NotificationType::PLATFORM_BALANCE_ALERT->value,
            'title' => 'Platform Fee settings changed',
        ]);

        $shopNotification = Notification::query()
            ->where('shop_owner_id', $individual->id)
            ->where('title', 'Platform Fee VAT rate increased')
            ->latest('id')
            ->firstOrFail();
        $this->assertArrayNotHasKey('actor_id', $shopNotification->data);
        $this->assertArrayNotHasKey('actor_name', $shopNotification->data);
        $this->assertArrayNotHasKey('actor_email', $shopNotification->data);
        $this->assertStringNotContainsString($admin->email, $shopNotification->message);
        $this->assertDatabaseMissing('notifications', [
            'shop_owner_id' => $business->id,
            'title' => 'Platform Fee VAT rate increased',
        ]);
        $this->assertDatabaseHas('activity_log', ['description' => 'platform_fee_settings_updated']);
        $settingsAudit = DB::table('activity_log')->where('description', 'platform_fee_settings_updated')->latest('id')->value('properties');
        $this->assertStringContainsString('platform_fee_vat_rate', (string) $settingsAudit);
        $this->assertStringContainsString('15.000000', (string) $settingsAudit);

        $this->actingAsCompletedPrivileged($admin)
            ->withSession($session)
            ->post("/admin/platform-fees/shops/{$individual->id}/limit", [
                'balance_limit' => '40000.00',
            ])
            ->assertRedirect();

        $limitNotification = Notification::query()
            ->where('shop_owner_id', $individual->id)
            ->where('title', 'Platform Balance limit updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('25000.00', $limitNotification->data['old_limit']);
        $this->assertSame('40000.00', $limitNotification->data['new_limit']);
        $this->assertDatabaseHas('activity_log', ['description' => 'platform_fee_shop_limit_updated']);

        $this->actingAsCompletedPrivileged($admin)
            ->withSession($session)
            ->post("/admin/platform-fees/shops/{$individual->id}/limit", [
                'balance_limit' => '20000.00',
            ])
            ->assertRedirect();

        $decreaseNotification = Notification::query()
            ->where('shop_owner_id', $individual->id)
            ->where('title', 'Platform Balance limit updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('decreased', $decreaseNotification->data['direction']);
        $limitAudit = DB::table('activity_log')->where('description', 'platform_fee_shop_limit_updated')->latest('id')->value('properties');
        $this->assertStringContainsString('20000.00', (string) $limitAudit);

        $recommendation = PlatformFeeRecommendation::create([
            'shop_owner_id' => $individual->id,
            'tier' => 'tier_2',
            'current_limit' => '20000.00',
            'recommended_limit' => '30000.00',
            'status' => 'pending',
            'rationale' => ['score' => '80.00'],
            'idempotency_key' => 'admin-limit-approval-test',
        ]);
        $this->actingAsCompletedPrivileged($admin)
            ->withSession($session)
            ->post("/admin/platform-fees/recommendations/{$recommendation->id}/approve", [])
            ->assertRedirect();

        $approvalNotification = Notification::query()
            ->where('shop_owner_id', $individual->id)
            ->where('title', 'Platform Balance limit updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('30000.00', $approvalNotification->data['new_limit']);
        $this->assertSame($recommendation->id, $approvalNotification->data['recommendation_id']);
    }

    #[Test]
    public function reliability_weights_must_use_the_known_factors(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->withSession([
                'privileged_reauthenticated_at' => now()->timestamp,
                'privileged_reauthenticated_security_version' => $admin->security_version,
            ])
            ->post('/admin/platform-fees/settings', [
                'scope' => 'shop_type',
                'shop_type' => 'individual',
                'reliability_weights' => json_encode(['payment_history' => 100]),
            ])
            ->assertSessionHasErrors('reliability_weights');
    }

    #[Test]
    public function reliability_tiers_must_include_a_key_and_score_threshold(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->withSession([
                'privileged_reauthenticated_at' => now()->timestamp,
                'privileged_reauthenticated_security_version' => $admin->security_version,
            ])
            ->post('/admin/platform-fees/settings', [
                'scope' => 'shop_type',
                'shop_type' => 'individual',
                'reliability_tiers' => json_encode([['key' => 'base']]),
            ])
            ->assertSessionHasErrors('reliability_tiers');
    }
}
