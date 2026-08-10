<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OwnerErpPageContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_contract_contains_owner_safe_modules_navigation_and_urls(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/workspace')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/Workspace', false)
                ->where('ownerMode', true)
                ->where('activeModule', null)
                ->where('shopModuleEnforcementEnabled', true)
                ->has('moduleStates')
                ->where('erpCapabilities', fn (Collection $capabilities): bool => $capabilities->has(
                    'GET:shop-owner.erp.workspace',
                ))
                ->has('enabledModules')
                ->has('unavailableModules', 8)
                ->has('navigationGroups', 1)
                ->where('navigationGroups.0.pages.0.routeName', 'shop-owner.erp.workspace')
                ->where('urls.portal', route('shop-owner.dashboard'))
                ->where('urls.settings', route('shop-owner.settings'))
                ->where('erpUrls.workspace', route('shop-owner.erp.workspace'))
            );
    }

    public function test_workspace_modules_expose_server_selected_related_page_urls(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        foreach (['finance', 'crm', 'inventory', 'logistics'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        $expectedUrls = [
            'finance' => url('/shop-owner/erp/finance'),
            'crm' => url('/shop-owner/erp/crm'),
            'inventory' => url('/shop-owner/erp/inventory'),
            'logistics' => url('/shop-owner/erp/logistics'),
        ];

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/workspace')
            ->assertInertia(fn (Assert $page) => $page
                ->where('enabledModules', fn (Collection $modules): bool => collect($expectedUrls)
                    ->every(fn (string $expectedUrl, string $moduleKey): bool => ($modules
                        ->firstWhere('key', $moduleKey)['url'] ?? null) === $expectedUrl))
            );
    }

    public function test_module_landing_contains_the_active_module_and_related_owner_pages(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/logistics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/ModuleLanding', false)
                ->where('activeModule.key', 'logistics')
                ->where('activeModule.slug', 'logistics')
                ->where('activeModule.label', 'Logistics')
                ->has('activeModule.pages', fn (Assert $pages): Assert => $pages
                    ->where('0.routeName', 'shop-owner.erp.logistics.dashboard')
                    ->where('0.url', url('/shop-owner/erp/logistics/dashboard'))
                    ->etc())
                ->where('navigationMode', 'module')
                ->where('urls.workspace', route('shop-owner.erp.workspace'))
            );
    }

    public function test_every_enabled_module_landing_exposes_only_its_related_pages(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $modules = [
            [
                'key' => 'retail_operations',
                'slug' => 'retail',
                'pages' => ['shop-owner.erp.retail.products'],
            ],
            [
                'key' => 'repair_operations',
                'slug' => 'repair',
                'pages' => ['shop-owner.erp.staff.repair-dashboard'],
            ],
            [
                'key' => 'hr_employees',
                'slug' => 'hr',
                'pages' => ['shop-owner.erp.hr.audit-logs'],
            ],
            [
                'key' => 'finance',
                'slug' => 'finance',
                'pages' => ['shop-owner.erp.finance.audit-logs'],
            ],
            [
                'key' => 'crm',
                'slug' => 'crm',
                'pages' => [
                    'shop-owner.erp.crm.dashboard',
                    'shop-owner.erp.crm.customers',
                    'shop-owner.erp.crm.customer-reviews',
                ],
            ],
            [
                'key' => 'inventory',
                'slug' => 'inventory',
                'pages' => [
                    'shop-owner.erp.inventory.inventory-dashboard',
                    'shop-owner.erp.inventory.product-inventory',
                    'shop-owner.erp.inventory.stock-movement',
                ],
            ],
            [
                'key' => 'procurement',
                'slug' => 'procurement',
                'pages' => ['shop-owner.erp.procurement.suppliers-management'],
            ],
            [
                'key' => 'logistics',
                'slug' => 'logistics',
                'pages' => [
                    'shop-owner.erp.logistics.dashboard',
                    'shop-owner.erp.logistics.shipments',
                    'shop-owner.erp.logistics.riders',
                ],
            ],
        ];

        foreach ($modules as $module) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $module['key'],
                'enabled' => true,
            ]);
        }

        foreach ($modules as $module) {
            $this->actingAs($owner, 'shop_owner')
                ->get('/shop-owner/erp/'.$module['slug'])
                ->assertOk()
                ->assertInertia(function (Assert $page) use ($module): Assert {
                    $page
                        ->component('ERP/ModuleLanding', false)
                        ->where('activeModule.key', $module['key'])
                        ->where('activeModule.slug', $module['slug'])
                        ->where('navigationMode', 'module')
                        ->has('activeModule.pages', count($module['pages']));

                    foreach ($module['pages'] as $index => $routeName) {
                        $page->where("activeModule.pages.{$index}.routeName", $routeName);
                    }

                    return $page;
                });
        }
    }

    public function test_owner_module_pages_resolve_their_server_module_scope_on_refresh(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $pages = [
            ['/shop-owner/erp/retail/products', 'retail_operations', 'ERP/STAFF/ProductManagementWithVariants'],
            ['/shop-owner/erp/staff/repair-dashboard', 'repair_operations', 'ERP/repairer/dashboardRepair'],
            ['/shop-owner/erp/hr/audit-logs', 'hr_employees', 'ERP/HR/AuditLogs'],
            ['/shop-owner/erp/finance/audit-logs', 'finance', 'ERP/Finance/AuditLogs'],
            ['/shop-owner/erp/crm/dashboard', 'crm', 'ERP/CRM/CRMDashboard'],
            ['/shop-owner/erp/inventory/inventory-dashboard', 'inventory', 'ERP/inventory/InventoryDashboard'],
            ['/shop-owner/erp/procurement/suppliers-management', 'procurement', 'ERP/Procurement/SuppliersManagement'],
            ['/shop-owner/erp/logistics/dashboard', 'logistics', 'ERP/Logistics/Dashboard'],
        ];

        foreach (array_unique(array_column($pages, 1)) as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        foreach ($pages as [$uri, $moduleKey, $component]) {
            $this->actingAs($owner, 'shop_owner')
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component, false)
                    ->where('activeModule.key', $moduleKey)
                    ->where('navigationMode', 'module')
                );
        }
    }

    public function test_disabled_module_landing_is_denied(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'logistics',
            'enabled' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/logistics')
            ->assertRedirect(route('shop-owner.erp.workspace'));
    }

    public function test_module_landing_ignores_a_client_supplied_shop_identifier(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/logistics?shop_owner_id='.$otherOwner->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenantOwnerId', $owner->id)
                ->where('activeModule.key', 'logistics')
            );
    }

    public function test_direct_module_page_refresh_keeps_the_active_module_scope(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/logistics/shipments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeModule.key', 'logistics')
                ->where('navigationMode', 'module')
            );
    }

    public function test_retail_module_uses_an_owner_erp_page_route(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/retail/products')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/STAFF/ProductManagementWithVariants', false)
                ->where('activeModule.key', 'retail_operations')
            );
    }

    public function test_workspace_ignores_a_client_supplied_shop_identifier(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/workspace?shop_owner_id='.$otherOwner->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenantOwnerId', fn (int $tenantOwnerId): bool => $tenantOwnerId === $owner->id
                    && $tenantOwnerId !== $otherOwner->id)
            );
    }

    public function test_first_read_wave_exposes_owner_crm_and_logistics_pages_with_shared_components(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach (['crm', 'logistics'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        $pages = [
            ['/shop-owner/erp/crm/dashboard', 'ERP/CRM/CRMDashboard'],
            ['/shop-owner/erp/crm/customers', 'ERP/CRM/Customers'],
            ['/shop-owner/erp/crm/customer-reviews', 'ERP/CRM/CustomerReviews'],
            ['/shop-owner/erp/logistics/dashboard', 'ERP/Logistics/Dashboard'],
            ['/shop-owner/erp/logistics/shipments', 'ERP/Logistics/Shipments'],
            ['/shop-owner/erp/logistics/riders', 'ERP/Logistics/Riders'],
        ];

        foreach ($pages as [$uri, $component]) {
            $this->actingAs($owner, 'shop_owner')
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component, false));
        }
    }

    public function test_second_and_third_read_waves_expose_owner_audit_inventory_and_supplier_pages(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach (['hr_employees', 'finance', 'inventory', 'procurement'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        $pages = [
            ['/shop-owner/erp/hr/audit-logs', 'ERP/HR/AuditLogs'],
            ['/shop-owner/erp/finance/audit-logs', 'ERP/Finance/AuditLogs'],
            ['/shop-owner/erp/manager/reports', 'ERP/Manager/Reports'],
            ['/shop-owner/erp/manager/audit-logs', 'ERP/Manager/AuditLogs'],
            ['/shop-owner/erp/inventory/inventory-dashboard', 'ERP/inventory/InventoryDashboard'],
            ['/shop-owner/erp/inventory/product-inventory', 'ERP/inventory/ProductInventory'],
            ['/shop-owner/erp/inventory/stock-movement', 'ERP/inventory/StockMovement'],
            ['/shop-owner/erp/procurement/suppliers-management', 'ERP/Procurement/SuppliersManagement'],
        ];

        foreach ($pages as [$uri, $component]) {
            $this->actingAs($owner, 'shop_owner')
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component, false));
        }
    }

    public function test_fourth_read_wave_exposes_owner_retail_customer_and_repair_dashboard_pages(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach (['crm', 'repair_operations'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        $pages = [
            ['/shop-owner/erp/staff/customers', 'ERP/STAFF/Customers'],
            ['/shop-owner/erp/staff/repair-dashboard', 'ERP/repairer/dashboardRepair'],
        ];

        foreach ($pages as [$uri, $component]) {
            $this->actingAs($owner, 'shop_owner')
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component, false));
        }
    }
}
