<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OwnerActionCenter;

use App\Enums\OwnerActionCenterDegradationStatus;
use App\Enums\OwnerActionCenterRolloutReason;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\OwnerActionCenterRolloutPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OwnerActionCenterRolloutPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_two_existing_selection_blocks_action_center(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
        ]);

        $this->enablePhaseThreeFor($owner);
        config(['owner_shell.enabled' => false]);

        $selection = app(OwnerActionCenterRolloutPolicy::class)->select($owner);

        $this->assertFalse($selection->selected);
        $this->assertSame(
            OwnerActionCenterRolloutReason::CanonicalShellNotSelected,
            $selection->reason,
        );
        $this->assertSame(
            OwnerActionCenterDegradationStatus::NotSelected,
            $selection->degradationStatus,
        );
    }

    public function test_phase_three_global_flag_off_fails_closed(): void
    {
        $owner = $this->allowlistedCanonicalOwner();
        config(['owner_action_center.enabled' => false]);

        $selection = app(OwnerActionCenterRolloutPolicy::class)->select($owner);

        $this->assertFalse($selection->selected);
        $this->assertSame(
            OwnerActionCenterRolloutReason::GlobalDisabled,
            $selection->reason,
        );
        $this->assertSame(
            OwnerActionCenterDegradationStatus::NotSelected,
            $selection->degradationStatus,
        );
    }

    public function test_empty_phase_three_allowlist_selects_any_valid_owner_for_the_thesis_build(): void
    {
        $owner = $this->allowlistedCanonicalOwner();
        config(['owner_action_center.allowlisted_shop_ids' => []]);

        $selection = app(OwnerActionCenterRolloutPolicy::class)->select($owner);

        $this->assertTrue($selection->selected);
        $this->assertSame('always_on', $selection->reason->value);
    }

    public function test_same_stable_shop_id_selects_the_owner_regardless_of_profile_or_module_state(): void
    {
        $owner = $this->allowlistedCanonicalOwner();

        $before = app(OwnerActionCenterRolloutPolicy::class)->select($owner);

        $owner->forceFill([
            'email' => 'changed@example.test',
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);
        config(['shop_modules.enforcement_enabled' => false]);

        $after = app(OwnerActionCenterRolloutPolicy::class)->select($owner);

        $this->assertTrue($before->selected);
        $this->assertTrue($after->selected);
        $this->assertSame($before->reason, $after->reason);
        $this->assertSame($before->degradationStatus, $after->degradationStatus);
    }

    public function test_malformed_phase_three_allowlist_fails_closed_without_leaking_an_exception(): void
    {
        $owner = $this->allowlistedCanonicalOwner();
        config(['owner_action_center.allowlisted_shop_ids' => new \stdClass()]);

        $selection = app(OwnerActionCenterRolloutPolicy::class)->select($owner);

        $this->assertFalse($selection->selected);
        $this->assertSame(
            OwnerActionCenterRolloutReason::CohortEvaluationFailed,
            $selection->reason,
        );
        $this->assertSame(
            OwnerActionCenterDegradationStatus::NotSelected,
            $selection->degradationStatus,
        );
    }

    public function test_all_adapter_families_disabled_keep_rollout_reason_separate_from_degradation(): void
    {
        $owner = $this->allowlistedCanonicalOwner();
        config([
            'owner_action_center.coverage.refunds' => false,
            'owner_action_center.coverage.prices' => false,
            'owner_action_center.coverage.payslips' => false,
            'owner_action_center.coverage.salary_changes' => false,
            'owner_action_center.coverage.expenses' => false,
            'owner_action_center.coverage.purchase_requests' => false,
            'owner_action_center.coverage.repair_rejections' => false,
        ]);

        $selection = app(OwnerActionCenterRolloutPolicy::class)->select($owner);

        $this->assertTrue($selection->selected);
        $this->assertSame(
            OwnerActionCenterRolloutReason::ShopAllowlisted,
            $selection->reason,
        );
        $this->assertSame(
            OwnerActionCenterDegradationStatus::NoEnabledAdapters,
            $selection->degradationStatus,
        );
        $this->assertNotSame($selection->reason->value, $selection->degradationStatus->value);
    }

    private function allowlistedCanonicalOwner(): ShopOwner
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);

        $this->enablePhaseThreeFor($owner);

        return $owner;
    }

    private function enablePhaseThreeFor(ShopOwner $owner): void
    {
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'owner_action_center.enabled' => true,
            'owner_action_center.allowlisted_shop_ids' => [$owner->getKey()],
            'owner_action_center.coverage.refunds' => true,
            'owner_action_center.coverage.prices' => true,
            'owner_action_center.coverage.payslips' => true,
            'owner_action_center.coverage.salary_changes' => true,
            'owner_action_center.coverage.expenses' => true,
            'owner_action_center.coverage.purchase_requests' => true,
            'owner_action_center.coverage.repair_rejections' => true,
        ]);
    }
}
