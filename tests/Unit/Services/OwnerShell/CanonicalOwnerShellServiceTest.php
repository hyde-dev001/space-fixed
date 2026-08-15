<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OwnerShell;

use App\Enums\OwnerShellPresentation;
use App\Enums\OwnerShellSelectionReason;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Services\OwnerShell\CanonicalOwnerShellService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

final class CanonicalOwnerShellServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'owner_shell.enabled' => true,
            'shop_modules.enforcement_enabled' => true,
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);

        foreach ($this->canonicalRoutes() as $name => $uri) {
            if (! Route::has($name)) {
                Route::get($uri, static fn (): null => null)->name($name);
            }
        }
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_individual_owner_emphasizes_operate_and_omits_ineligible_destinations(): void
    {
        $owner = $this->owner('individual', 'retail');
        $this->enableModules($owner, ['retail_operations', 'logistics']);
        config(['owner_shell.allowlisted_shop_ids' => [$owner->getKey()]]);

        $metadata = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();
        $groups = collect($metadata['groups'])->keyBy('key');

        $this->assertSame('canonical', $metadata['presentation']);
        $this->assertSame('individual', $metadata['context']);
        $this->assertSame(['home', 'operate', 'oversee', 'reports', 'settings'], array_column($metadata['groups'], 'key'));
        $this->assertTrue($groups['operate']['default_expanded']);
        $this->assertFalse($groups['oversee']['default_expanded']);
        $this->assertSame(['retail', 'payments'], array_column($groups['operate']['items'], 'key'));
        $this->assertSame(['logistics'], array_column($groups['oversee']['items'], 'key'));
        $this->assertNull(collect($groups['operate']['items'])->firstWhere('key', 'repair'));
        $this->assertNull(collect($groups['oversee']['items'])->firstWhere('key', 'finance'));
    }

    public function test_company_owner_emphasizes_oversee_and_retains_direct_operations(): void
    {
        $owner = $this->owner('company', 'both');
        $this->enableModules($owner, [
            'retail_operations',
            'repair_operations',
            'hr_employees',
            'finance',
            'crm',
            'inventory',
            'procurement',
            'logistics',
        ]);
        config(['owner_shell.allowlisted_shop_ids' => [$owner->getKey()]]);

        $metadata = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();
        $groups = collect($metadata['groups'])->keyBy('key');

        $this->assertSame(['home', 'oversee', 'operate', 'reports', 'settings'], array_column($metadata['groups'], 'key'));
        $this->assertTrue($groups['oversee']['default_expanded']);
        $this->assertFalse($groups['operate']['default_expanded']);
        $this->assertSame(
            ['finance', 'workforce', 'inventory', 'procurement', 'logistics'],
            array_column($groups['oversee']['items'], 'key'),
        );
        $this->assertSame(['retail', 'repair', 'customers', 'payments'], array_column($groups['operate']['items'], 'key'));
    }

    public function test_eligible_disabled_modules_are_unavailable_with_settings_management(): void
    {
        $owner = $this->owner('company', 'retail');
        $this->enableModules($owner, ['retail_operations']);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => 'finance',
            'enabled' => false,
        ]);
        config(['owner_shell.allowlisted_shop_ids' => [$owner->getKey()]]);

        $metadata = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();
        $finance = collect($metadata['groups'])
            ->firstWhere('key', 'oversee')['items'];
        $financeItem = collect($finance)->firstWhere('key', 'finance');

        $this->assertNotNull($financeItem);
        $this->assertFalse($financeItem['available']);
        $this->assertSame('module_disabled', $financeItem['unavailable_reason']);
        $this->assertSame('/shop-owner/settings/modules-team', $financeItem['management_url']);
    }

    public function test_empty_operational_groups_are_omitted(): void
    {
        $owner = $this->owner('individual', 'retail');
        config(['owner_shell.allowlisted_shop_ids' => [$owner->getKey()]]);

        $metadata = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();

        $this->assertSame(['home', 'reports', 'settings'], array_column($metadata['groups'], 'key'));
    }

    public function test_reports_and_audit_are_distinct_and_settings_has_six_sections(): void
    {
        $owner = $this->owner('company', 'both');
        config(['owner_shell.allowlisted_shop_ids' => [$owner->getKey()]]);

        $groups = collect(app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray()['groups'])->keyBy('key');

        $this->assertSame(['reports', 'audit'], array_column($groups['reports']['items'], 'key'));
        $this->assertSame(
            ['profile', 'modules-team', 'payments-approvals', 'operations', 'policies-compliance', 'subscription'],
            array_column($groups['settings']['items'], 'key'),
        );
    }

    public function test_payments_appears_only_when_a_retail_or_repair_path_is_accessible(): void
    {
        $owner = $this->owner('individual', 'repair');
        $this->enableModules($owner, ['repair_operations']);
        config(['owner_shell.allowlisted_shop_ids' => [$owner->getKey()]]);

        $groups = collect(app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray()['groups'])->keyBy('key');

        $this->assertSame(['repair', 'payments'], array_column($groups['operate']['items'], 'key'));

        $retailOwner = $this->owner('individual', 'retail');
        config(['owner_shell.allowlisted_shop_ids' => [$retailOwner->getKey()]]);
        $retailGroups = collect(app(CanonicalOwnerShellService::class)->forOwner($retailOwner)->toArray()['groups'])->keyBy('key');

        $this->assertArrayNotHasKey('operate', $retailGroups->all());
    }

    public function test_selected_phase_three_owner_gets_one_action_center_item_in_the_home_group(): void
    {
        $owner = $this->owner('company', 'both');
        config([
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'owner_action_center.enabled' => true,
            'owner_action_center.allowlisted_shop_ids' => [$owner->getKey()],
        ]);

        $home = collect(app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray()['groups'])
            ->firstWhere('key', 'home');

        $this->assertSame(['home', 'action-center'], array_column($home['items'], 'key'));
        $this->assertSame('/shop-owner/action-center', $home['items'][1]['canonical_url']);
        $this->assertSame(['/shop-owner/action-center'], $home['items'][1]['active_matching']);
    }

    public function test_canonical_destinations_do_not_change_when_erp_workspace_flag_changes(): void
    {
        $owner = $this->owner('company', 'both');
        $this->enableModules($owner, ['retail_operations', 'finance']);
        config([
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);

        $withoutWorkspace = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();
        config(['shop_modules.owner_erp_workspace_enabled' => true]);
        $withWorkspace = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();

        $this->assertSame($withoutWorkspace['groups'], $withWorkspace['groups']);
    }

    public function test_fallback_requires_canonical_selection_and_existing_workspace_eligibility(): void
    {
        $owner = $this->owner('company', 'retail');
        config([
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'shop_modules.owner_erp_workspace_enabled' => true,
        ]);

        $eligible = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();
        $this->assertTrue($eligible['compatibility']['show_erp_fallback']);
        $this->assertSame('/shop-owner/erp/workspace', $eligible['compatibility']['erp_workspace_url']);

        config(['owner_shell.enabled' => false]);
        $existing = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();
        $this->assertFalse($existing['compatibility']['show_erp_fallback']);

        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);
        $disabled = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();
        $this->assertFalse($disabled['compatibility']['show_erp_fallback']);
    }

    public function test_composition_failure_returns_complete_existing_presentation_and_logs_stable_shop_id(): void
    {
        $owner = $this->owner('company', 'retail');
        $this->enableModules($owner, ['retail_operations']);
        config(['owner_shell.allowlisted_shop_ids' => [$owner->getKey()]]);
        Log::spy();
        config(['shop_modules.modules' => new \stdClass()]);

        $metadata = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();

        $this->assertSame('existing', $metadata['presentation']);
        $this->assertSame('shell_composition_failed', $metadata['selection_reason']);
        $this->assertNull($metadata['context']);
        $this->assertSame([], $metadata['groups']);
        $this->assertFalse($metadata['compatibility']['show_erp_fallback']);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('shop_owner_shell_composition_failed', Mockery::on(
                static fn (array $context): bool => $context['shop_id'] === $owner->getKey()
                    && $context['reason'] === 'shell_composition_failed',
            ));
    }

    public function test_full_composition_loads_module_states_once_and_does_not_query_domain_work_queues(): void
    {
        $owner = $this->owner('company', 'both');
        $this->enableModules($owner, ['retail_operations', 'finance', 'logistics']);
        config(['owner_shell.allowlisted_shop_ids' => [$owner->getKey()]]);
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'shop_owner_modules')
                || str_contains($sql, 'approval')
                || str_contains($sql, 'refund')
                || str_contains($sql, 'notification')
                || str_contains($sql, 'repair')
                || str_contains($sql, 'order')
                || str_contains($sql, 'payroll')
                || str_contains($sql, 'exception')) {
                $queries[] = $sql;
            }
        });

        app(CanonicalOwnerShellService::class)->forOwner($owner);

        $moduleQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'shop_owner_modules'),
        ));
        $domainQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => ! str_contains($sql, 'shop_owner_modules'),
        ));

        $this->assertCount(1, $moduleQueries);
        $this->assertSame([], $domainQueries);
    }

    /**
     * @return array<string, string>
     */
    private function canonicalRoutes(): array
    {
        return [
            'shop-owner.shell.home' => '/shop-owner/home',
            'shop-owner.shell.action-center' => '/shop-owner/action-center',
            'shop-owner.shell.operate.retail' => '/shop-owner/operate/retail',
            'shop-owner.shell.operate.repair' => '/shop-owner/operate/repair',
            'shop-owner.shell.operate.customers' => '/shop-owner/operate/customers',
            'shop-owner.shell.operate.payments' => '/shop-owner/operate/payments',
            'shop-owner.shell.oversee.finance' => '/shop-owner/oversee/finance',
            'shop-owner.shell.oversee.workforce' => '/shop-owner/oversee/workforce',
            'shop-owner.shell.oversee.inventory' => '/shop-owner/oversee/inventory',
            'shop-owner.shell.oversee.procurement' => '/shop-owner/oversee/procurement',
            'shop-owner.shell.oversee.logistics' => '/shop-owner/oversee/logistics',
            'shop-owner.shell.reports' => '/shop-owner/reports',
            'shop-owner.shell.audit' => '/shop-owner/audit',
            'shop-owner.shell.settings.profile' => '/shop-owner/settings/profile',
            'shop-owner.shell.settings.modules-team' => '/shop-owner/settings/modules-team',
            'shop-owner.shell.settings.payments-approvals' => '/shop-owner/settings/payments-approvals',
            'shop-owner.shell.settings.operations' => '/shop-owner/settings/operations',
            'shop-owner.shell.settings.policies-compliance' => '/shop-owner/settings/policies-compliance',
            'shop-owner.shell.settings.subscription' => '/shop-owner/settings/subscription',
        ];
    }

    private function owner(string $registrationType, string $businessType): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'registration_type' => $registrationType,
            'business_type' => $businessType,
        ]);
    }

    /**
     * @param array<int, string> $moduleKeys
     */
    private function enableModules(ShopOwner $owner, array $moduleKeys): void
    {
        foreach ($moduleKeys as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->getKey(),
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }
    }
}
