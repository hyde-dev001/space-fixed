<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PremiumPlan;
use App\Models\ReviewReport;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopReport;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\Concerns\BuildsPhaseTwoWorkflowFixtures;
use Tests\TestCase;

final class PhaseEightScaleBoundaryTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use BuildsPhaseTwoWorkflowFixtures;
    use RefreshDatabase;

    public function test_phase_seven_baseline_unbounded_pages_are_characterized(): void
    {
        $viewer = $this->phaseTwoSuperAdmin();
        $this->phaseTwoSuperAdmin();
        $this->phaseTwoAdmin();

        $pendingRegistration = ShopOwner::factory()->pending()->create();
        $shop = $this->approvedPhaseTwoShop();
        $user = $this->activePhaseTwoUser();
        $this->openPhaseTwoShopReports($shop, 1);

        ReviewReport::create([
            'review_type' => 'product',
            'review_id' => 1,
            'shop_owner_id' => $shop->id,
            'user_id' => $user->id,
            'reason' => 'fake_review',
            'notes' => 'Phase 8 baseline fixture',
            'status' => ReviewReport::STATUS_PENDING_REVIEW,
            'review_snapshot' => ['fixture' => true],
        ]);

        SuspensionAppeal::create([
            'account_type' => 'customer',
            'account_id' => $user->id,
            'account_name' => $user->name,
            'recipient_email' => $user->email,
            'suspension_reason' => 'Phase 8 baseline fixture',
            'status' => 'rejected',
            'appeal_token' => 'phase-eight-baseline-token',
            'appeal_message' => 'Phase 8 baseline appeal',
            'reviewer_notes' => 'Recorded for characterization.',
            'reviewed_at' => now(),
        ]);

        $plan = $this->createPlan();
        ShopOwnerSubscription::create([
            'shop_owner_id' => $shop->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'active',
            'paid_amount' => 249,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);

        $this->actingAsCompletedPrivileged($viewer);

        $adminProps = $this->inertiaProps($this->get(route('admin.administrators.index')));
        self::assertIsArray($adminProps['admins']);
        self::assertSame(25, $adminProps['admins']['per_page']);
        self::assertCount(2, $adminProps['admins']['data']);
        self::assertArrayHasKey('stats', $adminProps);

        $registrationProps = $this->inertiaProps($this->get(route('admin.registrations.index')));
        self::assertIsArray($registrationProps['registrations']);
        self::assertCount(2, $registrationProps['registrations']);
        self::assertContains(
            $pendingRegistration->id,
            array_column($registrationProps['registrations'], 'id'),
        );

        $shopProps = $this->inertiaProps($this->get(route('admin.shops.index')));
        self::assertIsArray($shopProps['shops']);
        self::assertSame(25, $shopProps['shops']['per_page']);
        self::assertCount(1, $shopProps['shops']['data']);
        self::assertArrayHasKey('stats', $shopProps);

        $reportProps = $this->inertiaProps($this->get(route('admin.shop-reports')));
        self::assertIsArray($reportProps['shopGroups']);
        self::assertCount(1, $reportProps['shopGroups']);
        self::assertCount(1, $reportProps['shopGroups'][0]['reports']);

        $flaggedProps = $this->inertiaProps($this->get(route('admin.flagged-accounts.index')));
        self::assertIsArray($flaggedProps['flaggedAccounts']);
        self::assertCount(1, $flaggedProps['flaggedAccounts']);

        $appealProps = $this->inertiaProps($this->get(route('admin.suspension-appeals')));
        self::assertIsArray($appealProps['appeals']);
        self::assertCount(1, $appealProps['appeals']);
        self::assertArrayHasKey('stats', $appealProps);

        $subscriptionProps = $this->inertiaProps($this->get(route('admin.subscriptions.index')));
        self::assertIsArray($subscriptionProps['subscriptions']);
        self::assertCount(1, $subscriptionProps['subscriptions']);
        self::assertArrayHasKey('payments', $subscriptionProps['subscriptions'][0]);
        self::assertArrayHasKey('refund_attempts', $subscriptionProps['subscriptions'][0]);

    }

    public function test_phase_seven_bounded_paths_keep_their_existing_contracts(): void
    {
        $viewer = $this->phaseTwoSuperAdmin();
        User::factory()->count(16)->create(['status' => 'active']);
        $this->actingAsCompletedPrivileged($viewer);

        $usersProps = $this->inertiaProps($this->get(route('admin.users.index')));
        self::assertSame(15, $usersProps['users']['per_page']);
        self::assertCount(15, $usersProps['users']['data']);
        self::assertSame(16, $usersProps['users']['total']);

        $renewalProps = $this->inertiaProps($this->get(route('admin.document-renewals.index')));
        self::assertSame(20, $renewalProps['pagination']['per_page']);
        self::assertSame([], $renewalProps['renewals']);

        $auditProps = $this->inertiaProps($this->get(route('admin.audit')));
        self::assertLessThanOrEqual(25, count($auditProps['entries']));
        self::assertSame(25, $auditProps['pagination']['per_page']);

        $monitoringProps = $this->inertiaProps($this->get(route('admin.system-monitoring')));
        self::assertLessThanOrEqual(5, count($monitoringProps['dashboard']['recent_activity']));
    }

    public function test_administrator_list_uses_server_filters_caps_and_global_metrics(): void
    {
        $viewer = $this->phaseTwoSuperAdmin();
        $timestamp = now()->subDay();

        $olderActive = SuperAdmin::factory()->admin()->mfaEnrolled()->create([
            'first_name' => 'Older',
            'last_name' => 'Active',
            'status' => SuperAdmin::STATUS_ACTIVE,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $newerActive = SuperAdmin::factory()->admin()->mfaEnrolled()->create([
            'first_name' => 'Newer',
            'last_name' => 'Active',
            'status' => SuperAdmin::STATUS_ACTIVE,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        SuperAdmin::factory()->admin()->suspended()->create([
            'first_name' => 'Suspended',
            'last_name' => 'Admin',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->actingAsCompletedPrivileged($viewer);

        $response = $this->get(route('admin.administrators.index', [
            'status' => SuperAdmin::STATUS_ACTIVE,
            'per_page' => 1,
        ]));
        $props = $this->inertiaProps($response);

        self::assertSame(1, $props['admins']['per_page']);
        self::assertSame(2, $props['admins']['total']);
        self::assertSame($newerActive->id, $props['admins']['data'][0]['id']);
        self::assertSame(3, $props['stats']['total']);
        self::assertSame(2, $props['stats']['active']);
        self::assertSame(1, $props['stats']['suspended']);
        self::assertNotContains($viewer->id, array_column($props['admins']['data'], 'id'));

        $secondPage = $this->inertiaProps($this->get(route('admin.administrators.index', [
            'status' => SuperAdmin::STATUS_ACTIVE,
            'per_page' => 1,
            'page' => 2,
        ])));
        self::assertSame($olderActive->id, $secondPage['admins']['data'][0]['id']);
    }

    public function test_administrator_filters_and_pagination_reject_invalid_values(): void
    {
        $viewer = $this->phaseTwoSuperAdmin();
        $this->actingAsCompletedPrivileged($viewer);

        $this->getJson(route('admin.administrators.index', [
            'role' => 'platform_owner',
            'status' => 'unknown',
            'page' => 'not-an-integer',
            'per_page' => 101,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role', 'status', 'page', 'per_page']);
    }

    public function test_registered_shop_list_uses_server_filters_caps_and_global_metrics(): void
    {
        $viewer = $this->phaseTwoSuperAdmin();
        $timestamp = now()->subDay();

        $olderActive = ShopOwner::factory()->approved()->create([
            'business_name' => 'Older Active Shop',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $newerActive = ShopOwner::factory()->approved()->create([
            'business_name' => 'Newer Active Shop',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $suspended = $this->suspendedPhaseTwoShop([
            'business_name' => 'Suspended Shop',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $archived = ShopOwner::factory()->approved()->create([
            'business_name' => 'Archived Shop',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $archived->delete();

        $this->actingAsCompletedPrivileged($viewer);

        $props = $this->inertiaProps($this->get(route('admin.shops.index', [
            'status' => 'approved',
            'lifecycle' => 'active',
            'per_page' => 1,
        ])));

        self::assertSame(1, $props['shops']['per_page']);
        self::assertSame(2, $props['shops']['total']);
        self::assertSame($newerActive->id, $props['shops']['data'][0]['id']);
        self::assertSame(4, $props['stats']['total']);
        self::assertSame(2, $props['stats']['active']);
        self::assertSame(1, $props['stats']['suspended']);
        self::assertSame(1, $props['stats']['archived']);
        self::assertNotContains($suspended->id, array_column($props['shops']['data'], 'id'));

        $secondPage = $this->inertiaProps($this->get(route('admin.shops.index', [
            'status' => 'approved',
            'lifecycle' => 'active',
            'per_page' => 1,
            'page' => 2,
        ])));
        self::assertSame($olderActive->id, $secondPage['shops']['data'][0]['id']);
    }

    public function test_registered_shop_filters_and_pagination_reject_invalid_values(): void
    {
        $viewer = $this->phaseTwoSuperAdmin();
        $this->actingAsCompletedPrivileged($viewer);

        $this->getJson(route('admin.shops.index', [
            'status' => 'pending',
            'lifecycle' => 'retired',
            'page' => 0,
            'per_page' => 101,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'lifecycle', 'page', 'per_page']);
    }

    public function test_user_list_validates_server_filters_preserves_scope_and_uses_global_metrics(): void
    {
        $viewer = $this->phaseTwoSuperAdmin();
        $older = $this->activePhaseTwoUser([
            'name' => 'Older Customer',
            'first_name' => 'Older',
            'last_name' => 'Customer',
            'role' => 'STAFF',
        ]);
        $newer = $this->activePhaseTwoUser([
            'name' => 'Newer Customer',
            'first_name' => 'Newer',
            'last_name' => 'Customer',
            'role' => 'STAFF',
        ]);
        $this->activePhaseTwoUser(['status' => 'suspended', 'name' => 'Suspended Customer']);
        $archived = $this->activePhaseTwoUser(['name' => 'Archived Customer']);
        $archived->delete();

        $this->actingAsCompletedPrivileged($viewer);

        $props = $this->inertiaProps($this->get(route('admin.users.index', [
            'q' => 'Customer',
            'role' => 'STAFF',
            'status' => 'active',
            'lifecycle' => 'active',
            'per_page' => 1,
        ])));

        self::assertSame(1, $props['users']['per_page']);
        self::assertSame(2, $props['users']['total']);
        self::assertSame($newer->id, $props['users']['data'][0]['id']);
        self::assertSame(4, $props['stats']['total']);
        self::assertSame(2, $props['stats']['active']);
        self::assertSame(1, $props['stats']['suspended']);
        self::assertSame(1, $props['stats']['archived']);
        self::assertNotContains($older->id, array_column($props['users']['data'], 'id'));
    }

    public function test_user_filters_and_pagination_reject_invalid_values(): void
    {
        $viewer = $this->phaseTwoSuperAdmin();
        $this->actingAsCompletedPrivileged($viewer);

        $this->getJson(route('admin.users.index', [
            'role' => 'platform_owner',
            'status' => 'unknown',
            'lifecycle' => 'retired',
            'page' => 'not-an-integer',
            'per_page' => 101,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role', 'status', 'lifecycle', 'page', 'per_page']);
    }

    public function test_account_search_treats_like_wildcards_as_literal_input(): void
    {
        $viewer = $this->phaseTwoSuperAdmin();
        SuperAdmin::factory()->admin()->mfaEnrolled()->create(['first_name' => 'Plain']);
        ShopOwner::factory()->approved()->create(['business_name' => 'Plain Shop']);
        $this->activePhaseTwoUser(['name' => 'Plain Customer']);
        $this->actingAsCompletedPrivileged($viewer);

        $adminProps = $this->inertiaProps($this->get(route('admin.administrators.index', [
            'search' => '%',
            'per_page' => 100,
        ])));
        $shopProps = $this->inertiaProps($this->get(route('admin.shops.index', [
            'search' => '_',
            'per_page' => 100,
        ])));
        $userProps = $this->inertiaProps($this->get(route('admin.users.index', [
            'q' => '%',
            'per_page' => 100,
        ])));

        self::assertSame(0, $adminProps['admins']['total']);
        self::assertSame(0, $shopProps['shops']['total']);
        self::assertSame(0, $userProps['users']['total']);
    }

    /** @return array<string, mixed> */
    private function inertiaProps(TestResponse $response): array
    {
        $props = null;

        $response
            ->assertOk()
            ->assertInertia(function ($page) use (&$props): void {
                $props = $page->toArray()['props'];
            });

        self::assertIsArray($props);

        return $props;
    }

    private function createPlan(): PremiumPlan
    {
        return PremiumPlan::create([
            'plan_code' => 'phase-eight-'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Phase 8 Baseline Plan',
            'description' => 'Baseline characterization plan',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => 48,
            'benefits' => [],
            'status' => 'active',
        ]);
    }
}
