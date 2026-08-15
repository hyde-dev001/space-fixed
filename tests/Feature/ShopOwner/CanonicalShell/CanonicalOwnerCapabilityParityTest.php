<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\CanonicalShell;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CanonicalOwnerCapabilityParityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('capabilityProvider')]
    public function test_inventory_records_one_canonical_route_and_authoritative_compatibility_source(
        string $capability,
        string $canonicalRoute,
        string $canonicalPath,
        ?string $compatibilityPath,
        string $expectedComponent,
        ?string $moduleKey,
        bool $fallbackRequired,
        bool $migrationComplete,
    ): void {
        $this->assertTrue(Route::has($canonicalRoute), "Missing canonical route for {$capability}.");
        $this->assertSame($canonicalPath, '/'.Route::getRoutes()->getByName($canonicalRoute)->uri());

        if ($moduleKey === null && $capability !== 'payments') {
            $this->assertNotNull($compatibilityPath, "{$capability} must record a compatibility source.");
        }

        if ($capability === 'payments') {
            $this->assertTrue($fallbackRequired);
            $this->assertFalse($migrationComplete);
        } else {
            $this->assertFalse($fallbackRequired);
            $this->assertTrue($migrationComplete);
        }
    }

    #[DataProvider('capabilityProvider')]
    public function test_canonical_and_compatibility_pages_have_component_and_tenant_parity(
        string $capability,
        string $canonicalRoute,
        string $canonicalPath,
        ?string $compatibilityPath,
        string $expectedComponent,
        ?string $moduleKey,
        bool $fallbackRequired,
        bool $migrationComplete,
    ): void {
        config([
            'shop_modules.enforcement_enabled' => false,
            'shop_modules.owner_erp_workspace_enabled' => true,
        ]);
        $owner = $this->owner('company', 'both');

        $canonical = $this->actingAs($owner, 'shop_owner')
            ->get($canonicalPath)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($expectedComponent, false));

        if ($moduleKey !== null) {
            $canonical->assertInertia(fn (Assert $page) => $page->where('tenantOwnerId', $owner->getKey()));
        }

        if ($compatibilityPath === null) {
            return;
        }

        $compatibility = $this->actingAs($owner, 'shop_owner')
            ->get($compatibilityPath)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($expectedComponent, false));

        if ($moduleKey !== null) {
            $compatibility->assertInertia(fn (Assert $page) => $page->where('tenantOwnerId', $owner->getKey()));
        }
    }

    #[DataProvider('capabilityProvider')]
    public function test_unauthenticated_http_outcomes_match_when_a_compatibility_source_exists(
        string $capability,
        string $canonicalRoute,
        string $canonicalPath,
        ?string $compatibilityPath,
        string $expectedComponent,
        ?string $moduleKey,
        bool $fallbackRequired,
        bool $migrationComplete,
    ): void {
        if ($compatibilityPath === null) {
            $this->assertTrue($fallbackRequired);

            return;
        }

        config(['shop_modules.owner_erp_workspace_enabled' => true]);

        $canonical = $this->getJson($canonicalPath);
        $compatibility = $this->getJson($compatibilityPath);

        $this->assertSame(
            $compatibility->status(),
            $canonical->status(),
            "Unauthenticated status drift for {$capability}.",
        );
    }

    #[DataProvider('moduleCapabilityProvider')]
    public function test_module_denial_code_and_status_match_the_authoritative_compatibility_route(
        string $capability,
        string $canonicalPath,
        string $compatibilityPath,
        string $moduleKey,
    ): void {
        config([
            'shop_modules.enforcement_enabled' => true,
            'shop_modules.owner_erp_workspace_enabled' => true,
        ]);
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => $moduleKey,
            'enabled' => false,
        ]);

        $canonical = $this->actingAs($owner, 'shop_owner')->getJson($canonicalPath);
        $compatibility = $this->actingAs($owner, 'shop_owner')->getJson($compatibilityPath);

        $this->assertSame($compatibility->status(), $canonical->status(), "Status drift for {$capability}.");
        $this->assertSame($compatibility->json('code'), $canonical->json('code'), "Denial code drift for {$capability}.");
    }

    /**
     * @return array<string, array{string, string, string, string|null, string, string|null, bool, bool}>
     */
    public static function capabilityProvider(): array
    {
        return [
            'home' => ['home', 'shop-owner.shell.home', '/shop-owner/home', '/shop-owner/dashboard', 'ShopOwner/Dashboard', null, false, true],
            'retail' => ['retail', 'shop-owner.shell.operate.retail', '/shop-owner/operate/retail', '/shop-owner/erp/retail', 'ERP/ModuleLanding', 'retail_operations', false, true],
            'repair' => ['repair', 'shop-owner.shell.operate.repair', '/shop-owner/operate/repair', '/shop-owner/erp/repair', 'ERP/ModuleLanding', 'repair_operations', false, true],
            'customers' => ['customers', 'shop-owner.shell.operate.customers', '/shop-owner/operate/customers', '/shop-owner/erp/crm', 'ERP/ModuleLanding', 'crm', false, true],
            'payments' => ['payments', 'shop-owner.shell.operate.payments', '/shop-owner/operate/payments', null, 'ShopOwner/Payments/CanonicalPaymentsLanding', null, true, false],
            'finance' => ['finance', 'shop-owner.shell.oversee.finance', '/shop-owner/oversee/finance', '/shop-owner/erp/finance', 'ERP/ModuleLanding', 'finance', false, true],
            'workforce' => ['workforce', 'shop-owner.shell.oversee.workforce', '/shop-owner/oversee/workforce', '/shop-owner/erp/hr', 'ERP/ModuleLanding', 'hr_employees', false, true],
            'inventory' => ['inventory', 'shop-owner.shell.oversee.inventory', '/shop-owner/oversee/inventory', '/shop-owner/erp/inventory', 'ERP/ModuleLanding', 'inventory', false, true],
            'procurement' => ['procurement', 'shop-owner.shell.oversee.procurement', '/shop-owner/oversee/procurement', '/shop-owner/erp/procurement', 'ERP/ModuleLanding', 'procurement', false, true],
            'logistics' => ['logistics', 'shop-owner.shell.oversee.logistics', '/shop-owner/oversee/logistics', '/shop-owner/erp/logistics', 'ERP/ModuleLanding', 'logistics', false, true],
            'reports' => ['reports', 'shop-owner.shell.reports', '/shop-owner/reports', '/shop-owner/erp/manager/reports', 'ERP/Manager/Reports', null, false, true],
            'audit' => ['audit', 'shop-owner.shell.audit', '/shop-owner/audit', '/shop-owner/erp/manager/audit-logs', 'ERP/Manager/AuditLogs', null, false, true],
            'settings.profile' => ['settings.profile', 'shop-owner.shell.settings.profile', '/shop-owner/settings/profile', '/shop-owner/settings', 'ShopOwner/Settings/shopSetting', null, false, true],
            'settings.modules-team' => ['settings.modules-team', 'shop-owner.shell.settings.modules-team', '/shop-owner/settings/modules-team', '/shop-owner/settings', 'ShopOwner/Settings/shopSetting', null, false, true],
            'settings.payments-approvals' => ['settings.payments-approvals', 'shop-owner.shell.settings.payments-approvals', '/shop-owner/settings/payments-approvals', '/shop-owner/settings', 'ShopOwner/Settings/shopSetting', null, false, true],
            'settings.operations' => ['settings.operations', 'shop-owner.shell.settings.operations', '/shop-owner/settings/operations', '/shop-owner/settings', 'ShopOwner/Settings/shopSetting', null, false, true],
            'settings.policies-compliance' => ['settings.policies-compliance', 'shop-owner.shell.settings.policies-compliance', '/shop-owner/settings/policies-compliance', '/shop-owner/settings', 'ShopOwner/Settings/shopSetting', null, false, true],
            'settings.subscription' => ['settings.subscription', 'shop-owner.shell.settings.subscription', '/shop-owner/settings/subscription', '/shop-owner/settings', 'ShopOwner/Settings/shopSetting', null, false, true],
        ];
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function moduleCapabilityProvider(): array
    {
        return [
            'retail' => ['retail', '/shop-owner/operate/retail', '/shop-owner/erp/retail', 'retail_operations'],
            'repair' => ['repair', '/shop-owner/operate/repair', '/shop-owner/erp/repair', 'repair_operations'],
            'customers' => ['customers', '/shop-owner/operate/customers', '/shop-owner/erp/crm', 'crm'],
            'finance' => ['finance', '/shop-owner/oversee/finance', '/shop-owner/erp/finance', 'finance'],
            'workforce' => ['workforce', '/shop-owner/oversee/workforce', '/shop-owner/erp/hr', 'hr_employees'],
            'inventory' => ['inventory', '/shop-owner/oversee/inventory', '/shop-owner/erp/inventory', 'inventory'],
            'procurement' => ['procurement', '/shop-owner/oversee/procurement', '/shop-owner/erp/procurement', 'procurement'],
            'logistics' => ['logistics', '/shop-owner/oversee/logistics', '/shop-owner/erp/logistics', 'logistics'],
        ];
    }

    private function owner(string $registrationType, string $businessType): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'registration_type' => $registrationType,
            'business_type' => $businessType,
        ]);
    }
}
