<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OwnerShell;

use App\Enums\OwnerShellPresentation;
use App\Enums\OwnerShellSelectionReason;
use App\Models\ShopOwner;
use App\Services\OwnerShell\OwnerShellRolloutPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OwnerShellRolloutPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_flag_off_selects_existing_presentation(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
        ]);
        config([
            'owner_shell.enabled' => false,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
        ]);

        $selection = app(OwnerShellRolloutPolicy::class)->select($owner);

        $this->assertSame(OwnerShellPresentation::Existing, $selection->presentation);
        $this->assertSame(OwnerShellSelectionReason::GlobalDisabled, $selection->reason);
        $this->assertSame('individual', $selection->context);
    }

    public function test_enabled_flag_without_owner_id_in_allowlist_selects_existing_presentation(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [],
        ]);

        $selection = app(OwnerShellRolloutPolicy::class)->select($owner);

        $this->assertSame(OwnerShellPresentation::Existing, $selection->presentation);
        $this->assertSame(OwnerShellSelectionReason::ShopNotAllowlisted, $selection->reason);
        $this->assertSame('company', $selection->context);
    }

    public function test_enabled_flag_and_allowlisted_owner_select_canonical_candidate(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
        ]);

        $selection = app(OwnerShellRolloutPolicy::class)->select($owner);

        $this->assertSame(OwnerShellPresentation::Canonical, $selection->presentation);
        $this->assertSame(OwnerShellSelectionReason::ShopAllowlisted, $selection->reason);
        $this->assertSame('company', $selection->context);
    }

    public function test_invalid_registration_context_fails_closed_before_cohort_evaluation(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'partnership',
        ]);
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
        ]);

        $selection = app(OwnerShellRolloutPolicy::class)->select($owner);

        $this->assertSame(OwnerShellPresentation::Existing, $selection->presentation);
        $this->assertSame(OwnerShellSelectionReason::InvalidRegistrationContext, $selection->reason);
        $this->assertNull($selection->context);
    }

    public function test_allowlist_evaluation_failure_fails_closed_with_stable_reason(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
        ]);
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => new \stdClass(),
        ]);

        $selection = app(OwnerShellRolloutPolicy::class)->select($owner);

        $this->assertSame(OwnerShellPresentation::Existing, $selection->presentation);
        $this->assertSame(OwnerShellSelectionReason::CohortEvaluationFailed, $selection->reason);
        $this->assertSame('individual', $selection->context);
    }

    public function test_erp_workspace_flag_does_not_change_rollout_selection(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
        ]);
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);

        $disabledWorkspaceSelection = app(OwnerShellRolloutPolicy::class)->select($owner);

        config(['shop_modules.owner_erp_workspace_enabled' => true]);
        $enabledWorkspaceSelection = app(OwnerShellRolloutPolicy::class)->select($owner);

        $this->assertSame($disabledWorkspaceSelection->presentation, $enabledWorkspaceSelection->presentation);
        $this->assertSame($disabledWorkspaceSelection->reason, $enabledWorkspaceSelection->reason);
        $this->assertSame($disabledWorkspaceSelection->context, $enabledWorkspaceSelection->context);
    }

    public function test_owner_without_a_persisted_primary_key_is_not_allowlisted(): void
    {
        $owner = new ShopOwner([
            'registration_type' => 'company',
        ]);
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [1, 2, 3],
        ]);

        $selection = app(OwnerShellRolloutPolicy::class)->select($owner);

        $this->assertSame(OwnerShellPresentation::Existing, $selection->presentation);
        $this->assertSame(OwnerShellSelectionReason::ShopNotAllowlisted, $selection->reason);
        $this->assertSame('company', $selection->context);
    }
}
