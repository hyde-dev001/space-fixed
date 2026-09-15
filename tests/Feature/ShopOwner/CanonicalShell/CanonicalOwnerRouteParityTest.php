<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\CanonicalShell;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CanonicalOwnerRouteParityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('moduleRouteProvider')]
    public function test_eligible_enabled_owner_receives_the_same_module_component_from_both_routes(
        string $slug,
        string $canonicalRoute,
        string $canonicalPath,
        string $compatibilityPath,
        string $moduleKey,
    ): void {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => $moduleKey,
            'enabled' => true,
        ]);

        $canonical = $this->actingAs($owner, 'shop_owner')
            ->get($canonicalPath)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($this->dashboardComponent($moduleKey), false));

        $compatibility = $this->actingAs($owner, 'shop_owner')
            ->get($compatibilityPath)
            ->assertRedirect(route($canonicalRoute));

        $this->assertSame(200, $canonical->status());
        $this->assertSame(302, $compatibility->status());
    }

    #[DataProvider('moduleRouteProvider')]
    public function test_canonical_module_route_works_without_a_workspace_toggle(
        string $slug,
        string $canonicalRoute,
        string $canonicalPath,
        string $compatibilityPath,
        string $moduleKey,
    ): void {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => $moduleKey,
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get($canonicalPath)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($this->dashboardComponent($moduleKey), false));

        $this->actingAs($owner, 'shop_owner')
            ->get($compatibilityPath)
            ->assertRedirect(route($canonicalRoute));
    }

    public function test_individual_owner_can_open_repair_operations_without_company_module_state(): void
    {
        config(['shop_modules.enforcement_enabled' => false]);
        $owner = $this->owner('individual', 'repair');

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/operate/repair')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/repairer/dashboardRepair', false));
    }

    #[DataProvider('moduleRouteProvider')]
    public function test_ineligible_and_disabled_module_outcomes_match_the_compatibility_route(
        string $slug,
        string $canonicalRoute,
        string $canonicalPath,
        string $compatibilityPath,
        string $moduleKey,
    ): void {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $ineligible = $this->owner('partnership', 'retail');

        $canonicalIneligible = $this->actingAs($ineligible, 'shop_owner')->getJson($canonicalPath);
        $canonicalIneligible->assertForbidden();
        $this->actingAs($ineligible, 'shop_owner')
            ->get($compatibilityPath)
            ->assertRedirect(route('shop-owner.pending-approval'));

        $disabled = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $disabled->getKey(),
            'module_key' => $moduleKey,
            'enabled' => false,
        ]);

        $canonicalDisabled = $this->actingAs($disabled, 'shop_owner')->getJson($canonicalPath);
        $canonicalDisabled->assertForbidden();
        $this->actingAs($disabled, 'shop_owner')
            ->get($compatibilityPath)
            ->assertRedirect(route('shop-owner.shell.settings.modules-team', [
                'module' => $moduleKey,
            ]));
    }

    #[DataProvider('moduleRouteProvider')]
    public function test_unauthenticated_and_dual_session_requests_select_the_owner_actor_for_both_routes(
        string $slug,
        string $canonicalRoute,
        string $canonicalPath,
        string $compatibilityPath,
        string $moduleKey,
    ): void {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => $moduleKey,
            'enabled' => true,
        ]);

        $canonicalGuest = $this->getJson($canonicalPath);
        $compatibilityGuest = $this->getJson($compatibilityPath);
        $this->assertSame($compatibilityGuest->status(), $canonicalGuest->status());
        $this->assertSame($compatibilityGuest->json('code'), $canonicalGuest->json('code'));

        $employee = \App\Models\User::factory()->create(['shop_owner_id' => $owner->getKey()]);
        $this->actingAs($owner, 'shop_owner');
        $this->actingAs($employee, 'user');

        $this->get($canonicalPath)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($this->dashboardComponent($moduleKey), false));
        $this->get($compatibilityPath)
            ->assertRedirect(route($canonicalRoute));
    }

    public function test_shop_owner_pos_routes_are_retired_and_fail_closed(): void
    {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => 'repair_operations',
            'enabled' => true,
        ]);

        foreach ([
            '/shop-owner/operate/payments',
            '/shop-owner/erp/retail/point-of-sale',
            '/shop-owner/erp/repair/point-of-sale',
        ] as $path) {
            $this->actingAs($owner, 'shop_owner')
                ->get($path)
                ->assertRedirect(route('shop-owner.pending-approval'));
        }

        foreach (['/shop-owner/point-of-sale', '/point-of-sale'] as $path) {
            $this->actingAs($owner, 'shop_owner')
                ->get($path)
                ->assertRedirect(route('shop-owner.shell.home'));
        }
    }

    public function test_individual_owner_keeps_owner_pos_but_has_no_logistics_surface(): void
    {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = $this->owner('individual', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => 'repair_operations',
            'enabled' => true,
        ]);

        foreach ([
            '/shop-owner/erp/retail/point-of-sale',
            '/shop-owner/erp/repair/point-of-sale',
        ] as $path) {
            $this->actingAs($owner, 'shop_owner')
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('ShopOwner/Repairs/service management/POS', false));
        }

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/operate/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/cashier/POS', false)
                ->missing('links'));

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/shop-owner/oversee/logistics')
            ->assertForbidden()
            ->assertJsonPath('code', 'OWNER_ERP_ACCOUNT_INELIGIBLE');
    }

    public function test_individual_owner_can_open_each_approved_operational_page(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $owner = $this->owner('individual', 'both');

        foreach (['retail_operations', 'repair_operations'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->getKey(),
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        $pages = [
            '/shop-owner/erp/retail/orders' => 'ShopOwner/Orders/order management/JobOrders',
            '/shop-owner/erp/retail/products' => 'ShopOwner/Products/product management/ProductManagementWithVariants',
            '/shop-owner/erp/retail/discounts' => 'ShopOwner/Orders/order management/discount',
            '/shop-owner/erp/retail/point-of-sale' => 'ShopOwner/Repairs/service management/POS',
            '/shop-owner/erp/repair/job-orders' => 'ShopOwner/Repairs/service management/JobOrdersRepair',
            '/shop-owner/erp/repair/warranty-queue' => 'ShopOwner/Repairs/service management/WarrantyQueue',
            '/shop-owner/erp/repair/services' => 'ShopOwner/Repairs/service management/uploadService',
            '/shop-owner/erp/repair/stock-materials' => 'ShopOwner/Repairs/individual/uploadStockMaterial',
            '/shop-owner/erp/repair/point-of-sale' => 'ShopOwner/Repairs/service management/POS',
            '/shop-owner/erp/repair/support' => 'ShopOwner/Customers/customer management/repairSupport',
        ];

        foreach ($pages as $path => $component) {
            $this->actingAs($owner, 'shop_owner')
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component, false));
        }
    }

    public function test_company_owner_keeps_shared_product_and_repair_service_pages(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $owner = $this->owner('company', 'both');

        foreach (['retail_operations', 'repair_operations'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->getKey(),
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/retail/products')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component(
                'ShopOwner/Products/product management/ProductManagementWithVariants',
                false,
            ));

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/repair/services')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component(
                'ShopOwner/Repairs/service management/uploadService',
                false,
            ));

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/repair-services')
            ->assertOk();

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/repair-materials?category=repair_materials')
            ->assertOk();
    }

    public function test_legacy_workspace_picker_get_redirects_to_canonical_home_after_parity(): void
    {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = $this->owner('company', 'both');

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/workspace')
            ->assertRedirect(route('shop-owner.shell.home'));
    }

    public function test_legacy_enabled_module_get_redirects_to_its_canonical_overview(): void
    {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/retail')
            ->assertRedirect(route('shop-owner.shell.operate.retail'));
    }

    public function test_legacy_disabled_module_get_redirects_to_module_settings(): void
    {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => 'retail_operations',
            'enabled' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/retail')
            ->assertRedirect(route('shop-owner.shell.settings.modules-team', [
                'module' => 'retail_operations',
            ]));
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function moduleRouteProvider(): array
    {
        return [
            'retail' => [
                'retail',
                'shop-owner.shell.operate.retail',
                '/shop-owner/operate/retail',
                '/shop-owner/erp/retail',
                'retail_operations',
            ],
            'repair' => [
                'repair',
                'shop-owner.shell.operate.repair',
                '/shop-owner/operate/repair',
                '/shop-owner/erp/repair',
                'repair_operations',
            ],
            'customers' => [
                'crm',
                'shop-owner.shell.operate.customers',
                '/shop-owner/operate/customers',
                '/shop-owner/erp/crm',
                'crm',
            ],
            'finance' => [
                'finance',
                'shop-owner.shell.oversee.finance',
                '/shop-owner/oversee/finance',
                '/shop-owner/erp/finance',
                'finance',
            ],
            'workforce' => [
                'hr',
                'shop-owner.shell.oversee.workforce',
                '/shop-owner/oversee/workforce',
                '/shop-owner/erp/hr',
                'hr_employees',
            ],
            'inventory' => [
                'inventory',
                'shop-owner.shell.oversee.inventory',
                '/shop-owner/oversee/inventory',
                '/shop-owner/erp/inventory',
                'inventory',
            ],
            'procurement' => [
                'procurement',
                'shop-owner.shell.oversee.procurement',
                '/shop-owner/oversee/procurement',
                '/shop-owner/erp/procurement',
                'procurement',
            ],
            'logistics' => [
                'logistics',
                'shop-owner.shell.oversee.logistics',
                '/shop-owner/oversee/logistics',
                '/shop-owner/erp/logistics',
                'logistics',
            ],
        ];
    }

    private function owner(string $registrationType, string $businessType): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'registration_type' => $registrationType,
            'business_type' => $businessType,
        ]);
    }

    private function dashboardComponent(string $moduleKey): string
    {
        return [
            'retail_operations' => 'ShopOwner/Dashboard',
            'repair_operations' => 'ERP/repairer/dashboardRepair',
            'crm' => 'ERP/CRM/CRMDashboard',
            'finance' => 'ERP/Finance/Dashboard',
            'hr_employees' => 'ERP/HR/HR',
            'inventory' => 'ERP/inventory/InventoryDashboard',
            'procurement' => 'ERP/Procurement/Dashboard',
            'logistics' => 'ERP/Logistics/Dashboard',
        ][$moduleKey] ?? throw new \InvalidArgumentException("Unknown module {$moduleKey}.");
    }
}
