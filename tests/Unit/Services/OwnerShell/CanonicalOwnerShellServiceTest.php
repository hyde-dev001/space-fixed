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
        $this->enableModules($owner, ['retail_operations', 'crm', 'logistics', 'finance']);
        config(['owner_shell.allowlisted_shop_ids' => [$owner->getKey()]]);

        $metadata = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();
        $groups = collect($metadata['groups'])->keyBy('key');

        $this->assertSame('canonical', $metadata['presentation']);
        $this->assertSame('individual', $metadata['context']);
        $this->assertSame(['home', 'action-center', 'operate'], array_column($metadata['groups'], 'key'));
        $this->assertTrue($groups['operate']['default_expanded']);
        $this->assertSame(['retail', 'customers', 'cashier'], array_column($groups['operate']['items'], 'key'));
        $this->assertArrayNotHasKey('oversee', $groups->all());
        $this->assertNull(collect($groups['operate']['items'])->firstWhere('key', 'repair'));
        $retailItem = collect($groups['operate']['items'])->firstWhere('key', 'retail');
        $this->assertNotNull($retailItem);
        $this->assertSame([], $retailItem['children']);
        $cashierItem = collect($groups['operate']['items'])->firstWhere('key', 'cashier');
        $this->assertNotNull($cashierItem);
        $this->assertSame('/shop-owner/operate/payments', $cashierItem['canonical_url']);
        $this->assertSame([], $cashierItem['children']);
        $customerItem = collect($groups['operate']['items'])->firstWhere('key', 'customers');
        $this->assertNotNull($customerItem);
        $this->assertSame('Customer Management', $customerItem['label']);
        $this->assertSame('/shop-owner/operate/customers', $customerItem['canonical_url']);
        $this->assertSame([], $customerItem['children']);
    }

    public function test_individual_owner_keeps_eligible_operational_modules_when_enforcement_is_disabled(): void
    {
        $owner = $this->owner('individual', 'both');
        config([
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'shop_modules.enforcement_enabled' => false,
        ]);

        $metadata = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();
        $groups = collect($metadata['groups'])->keyBy('key');

        $this->assertSame(['home', 'action-center', 'operate'], array_column($metadata['groups'], 'key'));
        $this->assertSame(
            ['retail', 'repair', 'customers', 'cashier'],
            array_column($groups['operate']['items'], 'key'),
        );
        $retailItem = collect($groups['operate']['items'])->firstWhere('key', 'retail');
        $repairItem = collect($groups['operate']['items'])->firstWhere('key', 'repair');
        $this->assertSame([], $retailItem['children']);
        $this->assertSame([], $repairItem['children']);
        $this->assertArrayNotHasKey('oversee', $groups->all());
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

        $this->assertSame(['home', 'action-center', 'oversee', 'operate', 'reports'], array_column($metadata['groups'], 'key'));
        $this->assertTrue($groups['oversee']['default_expanded']);
        $this->assertFalse($groups['operate']['default_expanded']);
        $this->assertSame(
            ['finance', 'workforce', 'inventory', 'procurement', 'logistics'],
            array_column($groups['oversee']['items'], 'key'),
        );
        $this->assertSame(['retail', 'repair', 'customers'], array_column($groups['operate']['items'], 'key'));
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

        $this->assertSame(['home', 'action-center'], array_column($metadata['groups'], 'key'));
    }

    public function test_reports_and_audit_are_distinct_and_business_settings_is_not_in_the_sidebar(): void
    {
        $owner = $this->owner('company', 'both');
        config(['owner_shell.allowlisted_shop_ids' => [$owner->getKey()]]);

        $groups = collect(app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray()['groups'])->keyBy('key');

        $this->assertSame(['reports', 'audit'], array_column($groups['reports']['items'], 'key'));
        $this->assertArrayNotHasKey('settings', $groups->all());
    }

    public function test_individual_owner_receives_payments_for_each_operational_path(): void
    {
        $owner = $this->owner('individual', 'repair');
        $this->enableModules($owner, ['repair_operations']);
        config(['owner_shell.allowlisted_shop_ids' => [$owner->getKey()]]);

        $groups = collect(app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray()['groups'])->keyBy('key');

        $this->assertSame(['repair', 'cashier'], array_column($groups['operate']['items'], 'key'));
        $repairItem = collect($groups['operate']['items'])->firstWhere('key', 'repair');
        $this->assertSame([], $repairItem['children']);
        $cashierItem = collect($groups['operate']['items'])->firstWhere('key', 'cashier');
        $this->assertNotNull($cashierItem);
        $this->assertSame('/shop-owner/operate/payments', $cashierItem['canonical_url']);
        $this->assertSame([], $cashierItem['children']);

        $retailOwner = $this->owner('individual', 'retail');
        $this->enableModules($retailOwner, ['retail_operations']);
        config(['owner_shell.allowlisted_shop_ids' => [$retailOwner->getKey()]]);
        $retailGroups = collect(app(CanonicalOwnerShellService::class)->forOwner($retailOwner)->toArray()['groups'])->keyBy('key');

        $this->assertSame(['retail', 'cashier'], array_column($retailGroups['operate']['items'], 'key'));
    }

    public function test_selected_phase_three_owner_gets_a_separate_action_center_group(): void
    {
        $owner = $this->owner('company', 'both');
        config([
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'owner_action_center.enabled' => true,
            'owner_action_center.allowlisted_shop_ids' => [$owner->getKey()],
        ]);

        $groups = collect(app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray()['groups'])->keyBy('key');

        $this->assertSame(['home', 'action-center', 'reports'], array_keys($groups->all()));
        $this->assertSame(['home'], array_column($groups['home']['items'], 'key'));
        $this->assertSame(['action-center'], array_column($groups['action-center']['items'], 'key'));
        $this->assertSame('/shop-owner/action-center', $groups['action-center']['items'][0]['canonical_url']);
        $this->assertSame(['/shop-owner/action-center'], $groups['action-center']['items'][0]['active_matching']);
    }

    public function test_canonical_destinations_are_independent_of_the_retired_workspace_boundary(): void
    {
        $owner = $this->owner('company', 'both');
        $this->enableModules($owner, ['retail_operations', 'finance']);
        config([
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
        ]);

        $withoutWorkspace = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();
        $withWorkspace = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();

        $this->assertSame($withoutWorkspace['groups'], $withWorkspace['groups']);
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
            'shop-owner.dss-insights' => '/shop-owner/dss-insights',
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
            'shop-owner.erp.retail.orders' => '/shop-owner/erp/retail/orders',
            'shop-owner.erp.retail.products' => '/shop-owner/erp/retail/products',
            'shop-owner.erp.retail.discounts' => '/shop-owner/erp/retail/discounts',
            'shop-owner.erp.repair.job-orders' => '/shop-owner/erp/repair/job-orders',
            'shop-owner.erp.repair.warranty-queue' => '/shop-owner/erp/repair/warranty-queue',
            'shop-owner.erp.repair.services' => '/shop-owner/erp/repair/services',
            'shop-owner.erp.repair.stock-materials' => '/shop-owner/erp/repair/stock-materials',
            'shop-owner.erp.repair.support' => '/shop-owner/erp/repair/support',
            'shop-owner.erp.retail.point-of-sale' => '/shop-owner/erp/retail/point-of-sale',
            'shop-owner.erp.repair.point-of-sale' => '/shop-owner/erp/repair/point-of-sale',
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
