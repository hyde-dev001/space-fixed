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
use Illuminate\Support\Carbon;
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
        self::assertSame(25, $registrationProps['registrations']['per_page']);
        self::assertCount(2, $registrationProps['registrations']['data']);
        self::assertContains(
            $pendingRegistration->id,
            array_column($registrationProps['registrations']['data'], 'id'),
        );

        $shopProps = $this->inertiaProps($this->get(route('admin.shops.index')));
        self::assertIsArray($shopProps['shops']);
        self::assertSame(25, $shopProps['shops']['per_page']);
        self::assertCount(1, $shopProps['shops']['data']);
        self::assertArrayHasKey('stats', $shopProps);

        $reportProps = $this->inertiaProps($this->get(route('admin.shop-reports')));
        self::assertIsArray($reportProps['shopGroups']);
        self::assertSame(25, $reportProps['shopGroups']['per_page']);
        self::assertCount(1, $reportProps['shopGroups']['data']);
        self::assertArrayNotHasKey('reports', $reportProps['shopGroups']['data'][0]);

        $flaggedProps = $this->inertiaProps($this->get(route('admin.flagged-accounts.index')));
        self::assertIsArray($flaggedProps['flaggedAccounts']);
        self::assertSame(25, $flaggedProps['flaggedAccounts']['per_page']);
        self::assertCount(1, $flaggedProps['flaggedAccounts']['data']);

        $appealProps = $this->inertiaProps($this->get(route('admin.suspension-appeals')));
        self::assertIsArray($appealProps['appeals']);
        self::assertSame(25, $appealProps['appeals']['per_page']);
        self::assertCount(1, $appealProps['appeals']['data']);
        self::assertArrayHasKey('stats', $appealProps);

        $subscriptionProps = $this->inertiaProps($this->get(route('admin.subscriptions.index')));
        self::assertIsArray($subscriptionProps['subscriptions']);
        self::assertSame(25, $subscriptionProps['subscriptions']['per_page']);
        self::assertCount(1, $subscriptionProps['subscriptions']['data']);
        self::assertArrayNotHasKey('payments', $subscriptionProps['subscriptions']['data'][0]);
        self::assertArrayNotHasKey('refund_attempts', $subscriptionProps['subscriptions']['data'][0]);

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

    public function test_review_queues_use_capped_server_filters_global_stats_and_detail_boundaries(): void
    {
        $viewer = $this->phaseTwoSuperAdmin();
        $pendingRegistration = ShopOwner::factory()->pending()->create([
            'business_name' => 'Pending Registration',
        ]);
        ShopOwner::factory()->approved()->create([
            'business_name' => 'Approved Registration',
        ]);

        $highPriorityShop = $this->approvedPhaseTwoShop(['business_name' => 'High Priority Shop']);
        $normalPriorityShop = $this->approvedPhaseTwoShop(['business_name' => 'Normal Priority Shop']);
        $highReports = $this->openPhaseTwoShopReports($highPriorityShop, 5);
        $this->openPhaseTwoShopReports($normalPriorityShop, 1);

        $archivedCustomer = $this->activePhaseTwoUser(['name' => 'Archived Report Customer']);
        $archivedReport = $this->flaggedPhaseTwoReviewReport($archivedCustomer, [
            'shop_owner_id' => $normalPriorityShop->id,
        ]);
        $archivedCustomer->delete();

        SuspensionAppeal::create([
            'account_type' => 'customer',
            'account_id' => $archivedCustomer->id,
            'account_name' => $archivedCustomer->name,
            'recipient_email' => $archivedCustomer->email,
            'suspension_reason' => 'Archived appeal fixture',
            'status' => 'rejected',
            'appeal_token' => 'phase-eight-queue-appeal-1',
            'appeal_message' => 'The account owner submitted an appeal.',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        SuspensionAppeal::create([
            'account_type' => 'customer',
            'account_id' => $archivedCustomer->id,
            'account_name' => $archivedCustomer->name,
            'recipient_email' => $archivedCustomer->email,
            'suspension_reason' => 'Second appeal fixture',
            'status' => 'rejected',
            'appeal_token' => 'phase-eight-queue-appeal-2',
            'appeal_message' => 'A second historical appeal record.',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->actingAsCompletedPrivileged($viewer);

        $registrationProps = $this->inertiaProps($this->get(route('admin.registrations.index', [
            'status' => 'pending',
            'search' => 'Pending',
            'per_page' => 1,
        ])));
        self::assertSame(1, $registrationProps['registrations']['per_page']);
        self::assertSame(1, $registrationProps['registrations']['total']);
        self::assertSame($pendingRegistration->id, $registrationProps['registrations']['data'][0]['id']);
        self::assertSame(1, $registrationProps['stats']['pending']);

        $reportProps = $this->inertiaProps($this->get(route('admin.shop-reports', [
            'priority' => 'high',
            'per_page' => 1,
        ])));
        self::assertSame(1, $reportProps['shopGroups']['per_page']);
        self::assertSame(1, $reportProps['shopGroups']['total']);
        self::assertSame($highPriorityShop->id, $reportProps['shopGroups']['data'][0]['shop_owner_id']);
        self::assertSame(5, $reportProps['shopGroups']['data'][0]['open_reports']);
        self::assertArrayNotHasKey('open_report_ids', $reportProps['shopGroups']['data'][0]);
        self::assertArrayNotHasKey('reports', $reportProps['shopGroups']['data'][0]);
        self::assertSame(6, $reportProps['stats']['total_reports']);
        self::assertSame(1, $reportProps['stats']['high_priority']);

        $reportDetail = $this->getJson(route('admin.shop-reports.show', [
            'shopOwner' => $highPriorityShop->id,
            'per_page' => 2,
        ]));
        $reportDetail
            ->assertOk()
            ->assertJsonPath('reports.per_page', 2)
            ->assertJsonCount(2, 'reports.data');
        self::assertSame($highReports->sortByDesc('id')->values()->all()[0]->id, $reportDetail->json('reports.data.0.id'));

        $flaggedProps = $this->inertiaProps($this->get(route('admin.flagged-accounts.index', [
            'status' => 'pending_review',
            'search' => 'Archived Report Customer',
            'per_page' => 1,
        ])));
        self::assertSame(1, $flaggedProps['flaggedAccounts']['per_page']);
        self::assertSame(1, $flaggedProps['flaggedAccounts']['total']);
        self::assertSame((string) $archivedReport->id, $flaggedProps['flaggedAccounts']['data'][0]['id']);
        self::assertSame(1, $flaggedProps['stats']['total']);

        $appealProps = $this->inertiaProps($this->get(route('admin.suspension-appeals', [
            'status' => 'rejected',
            'per_page' => 1,
        ])));
        self::assertSame(1, $appealProps['appeals']['per_page']);
        self::assertSame(2, $appealProps['appeals']['total']);
        self::assertSame(2, $appealProps['stats']['total']);
    }

    public function test_review_queue_filters_and_caps_reject_invalid_values(): void
    {
        $viewer = $this->phaseTwoSuperAdmin();
        $this->actingAsCompletedPrivileged($viewer);

        $this->getJson(route('admin.registrations.index', [
            'status' => 'archived',
            'page' => 'not-an-integer',
            'per_page' => 101,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'page', 'per_page']);

        $this->getJson(route('admin.shop-reports', [
            'priority' => 'urgent',
            'status' => 'unknown',
            'page' => 0,
            'per_page' => 101,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['priority', 'status', 'page', 'per_page']);

        $this->getJson(route('admin.flagged-accounts.index', [
            'status' => 'unknown',
            'page' => 0,
            'per_page' => 101,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'page', 'per_page']);

        $this->getJson(route('admin.suspension-appeals', [
            'status' => 'unknown',
            'page' => 0,
            'per_page' => 101,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'page', 'per_page']);
    }

    public function test_shop_report_pattern_characterization_excludes_terminal_reports(): void
    {
        $now = Carbon::parse('2026-08-13 12:00:00');
        Carbon::setTestNow($now);

        try {
            $viewer = $this->phaseTwoSuperAdmin();
            $this->actingAsCompletedPrivileged($viewer);
            $shop = $this->approvedPhaseTwoShop();
            for ($index = 0; $index < 5; $index++) {
                $reporter = $this->activePhaseTwoUser([
                    'created_at' => $now->copy()->subDays(3),
                    'updated_at' => $now->copy()->subDays(3),
                ]);
                ShopReport::create([
                    'user_id' => $reporter->id,
                    'shop_owner_id' => $shop->id,
                    'reason' => 'misconduct',
                    'description' => "Pattern characterization report {$index}",
                    'status' => 'submitted',
                    'ip_address' => "203.0.113.{$index}",
                    'created_at' => $now->copy()->subMinutes($index * 10),
                    'updated_at' => $now->copy()->subMinutes($index * 10),
                ]);
            }

            self::assertSame([
                'batch_reports',
                'new_account_reporters',
                'ip_clustering',
            ], ShopReport::detectPatterns($shop->id));

            $props = $this->inertiaProps($this->get(route('admin.shop-reports', ['per_page' => 100])));
            $group = collect($props['shopGroups']['data'] ?? [])->firstWhere('shop_owner_id', $shop->id);
            self::assertSame([
                'batch_reports',
                'new_account_reporters',
                'ip_clustering',
            ], $group['pattern_flags'] ?? null);

            ShopReport::query()
                ->where('shop_owner_id', $shop->id)
                ->orderBy('id')
                ->limit(2)
                ->update(['status' => 'dismissed']);

            self::assertSame([
                'new_account_reporters',
                'ip_clustering',
            ], ShopReport::detectPatterns($shop->id));

            $props = $this->inertiaProps($this->get(route('admin.shop-reports', ['per_page' => 100])));
            $group = collect($props['shopGroups']['data'] ?? [])->firstWhere('shop_owner_id', $shop->id);
            self::assertSame([
                'new_account_reporters',
                'ip_clustering',
            ], $group['pattern_flags'] ?? null);
        } finally {
            Carbon::setTestNow();
        }
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
