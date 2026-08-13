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
        self::assertCount(2, $adminProps['admins']);
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
        self::assertCount(1, $shopProps['shops']);
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
