<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Services\ErpRouteCatalog;
use App\Services\ErpWorkspaceNavigationService;
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

    public function test_owner_employee_directory_seeds_linked_user_ids_for_owner_actions(): void
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
            'module_key' => 'hr_employees',
            'enabled' => true,
        ]);
        $employee = \App\Models\Employee::factory()->create([
            'shop_owner_id' => $owner->id,
            'email' => 'employee@example.test',
            'status' => 'active',
        ]);
        $linkedUser = User::factory()->create([
            'shop_owner_id' => $owner->id,
            'email' => $employee->email,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/hr/employee-directory')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/HR/HR', false)
                ->where('initialSection', 'employees')
                ->where('initialEmployees.0.id', $employee->id)
                ->where('initialEmployees.0.linkedUser', $linkedUser->id)
            );
    }

    public function test_owner_module_navigation_uses_loaded_catalog_pages_instead_of_a_hard_coded_list(): void
    {
        $pages = app(ErpWorkspaceNavigationService::class)->forKey('crm')['pages'];
        $routeNames = array_column($pages, 'routeName');

        $this->assertContains('shop-owner.erp.crm.customers', $routeNames);
        $this->assertNotContains('shop-owner.erp.staff.customers', $routeNames);
        $this->assertNotContains('shop-owner.erp.api.crm.dashboard-stats', $routeNames);
    }

    public function test_owner_module_navigation_excludes_pages_without_a_complete_owner_readability_contract(): void
    {
        $routes = config('shop_modules.routes');
        $routes['shop-owner.erp.crm.dashboard']['supporting_routes'] = ['testing.missing-owner-data-surface'];
        config(['shop_modules.routes' => $routes]);

        $navigation = app(ErpWorkspaceNavigationService::class);
        $financeRoutes = array_column($navigation->forKey('finance')['pages'], 'routeName');
        $crmRoutes = array_column($navigation->forKey('crm')['pages'], 'routeName');

        $this->assertNotContains('shop-owner.erp.crm.dashboard', $crmRoutes);
        $this->assertNotContains('shop-owner.erp.finance.invoices', $financeRoutes);
        $this->assertNotContains('shop-owner.erp.finance.create-invoice', $financeRoutes);
        $this->assertNotContains('shop-owner.erp.finance.audit-logs', $financeRoutes);
        $this->assertNotContains('shop-owner.erp.finance.expense-approvals', $financeRoutes);

    }

    public function test_owner_catalog_keeps_only_task_one_proven_readable_pages_navigable(): void
    {
        $catalog = app(ErpRouteCatalog::class);

        foreach (app(ErpWorkspaceNavigationService::class)->definitions() as $module) {
            foreach ($module['pages'] as $page) {
                $this->assertTrue($catalog->hasOwnerReadablePageContract($page['routeName']));
            }
        }

        $expectedReadablePages = [
            'retail_operations' => [
                'shop-owner.erp.retail.products',
                'shop-owner.erp.retail.orders',
            ],
            'crm' => [
                'shop-owner.erp.crm.dashboard',
                'shop-owner.erp.crm.customers',
                'shop-owner.erp.crm.customer-reviews',
            ],
            'logistics' => [
                'shop-owner.erp.logistics.dashboard',
                'shop-owner.erp.logistics.shipments',
            ],
        ];

        foreach ($expectedReadablePages as $moduleKey => $routeNames) {
            $this->assertSame(
                $routeNames,
                array_column(app(ErpWorkspaceNavigationService::class)->forKey($moduleKey)['pages'], 'routeName'),
                $moduleKey,
            );
        }

        $allNavigableRoutes = collect(app(ErpWorkspaceNavigationService::class)->definitions())
            ->flatMap(fn (array $module): array => array_column($module['pages'], 'routeName'))
            ->all();

        foreach ([
            'shop-owner.erp.retail.dashboard',
            'shop-owner.erp.retail.point-of-sale',
            'shop-owner.erp.retail.discounts',
            'shop-owner.erp.repair.job-orders',
            'shop-owner.erp.crm.customer-support',
            'shop-owner.erp.logistics.riders',
            'shop-owner.erp.logistics.batches',
            'shop-owner.erp.logistics.settings',
            'shop-owner.erp.finance.invoices',
            'shop-owner.erp.finance.create-invoice',
            'shop-owner.erp.finance.expense-approvals',
            'shop-owner.erp.finance.audit-logs',
        ] as $routeName) {
            $this->assertNotContains($routeName, $allNavigableRoutes);
        }
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
                'pages' => [
                    'shop-owner.erp.retail.products',
                    'shop-owner.erp.retail.orders',
                ],
            ],
            ['key' => 'repair_operations', 'slug' => 'repair', 'pages' => []],
            ['key' => 'hr_employees', 'slug' => 'hr', 'pages' => []],
            ['key' => 'finance', 'slug' => 'finance', 'pages' => []],
            [
                'key' => 'crm',
                'slug' => 'crm',
                'pages' => [
                    'shop-owner.erp.crm.dashboard',
                    'shop-owner.erp.crm.customers',
                    'shop-owner.erp.crm.customer-reviews',
                ],
            ],
            ['key' => 'inventory', 'slug' => 'inventory', 'pages' => []],
            ['key' => 'procurement', 'slug' => 'procurement', 'pages' => []],
            [
                'key' => 'logistics',
                'slug' => 'logistics',
                'pages' => [
                    'shop-owner.erp.logistics.dashboard',
                    'shop-owner.erp.logistics.shipments',
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

    public function test_owner_module_catalog_includes_the_existing_operational_erp_pages(): void
    {
        $expectedPages = [
            'retail_operations' => [
                'shop-owner.erp.retail.products',
                'shop-owner.erp.retail.orders',
            ],
            'crm' => [
                'shop-owner.erp.crm.dashboard',
                'shop-owner.erp.crm.customers',
                'shop-owner.erp.crm.customer-reviews',
            ],
            'logistics' => [
                'shop-owner.erp.logistics.dashboard',
                'shop-owner.erp.logistics.shipments',
            ],
        ];

        foreach ($expectedPages as $moduleKey => $routeNames) {
            $actualRouteNames = array_column(
                app(ErpWorkspaceNavigationService::class)->forKey($moduleKey)['pages'],
                'routeName',
            );

            $this->assertSame($routeNames, $actualRouteNames, $moduleKey);
            foreach ($routeNames as $routeName) {
                $this->assertTrue(\Illuminate\Support\Facades\Route::has($routeName), $routeName);
                $this->assertStringStartsWith('/shop-owner/erp/', route($routeName, [], false));
            }
        }

        $this->assertFileExists(base_path('resources/js/Pages/ERP/HR/AuditLogs.tsx'));
        $this->assertFileExists(base_path('resources/js/Pages/ERP/Finance/AuditLogs.tsx'));
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
            ['/shop-owner/erp/retail/dashboard', 'retail_operations', 'ShopOwner/Dashboard'],
            ['/shop-owner/erp/retail/products', 'retail_operations', 'ShopOwner/Products/product management/ProductManagementWithVariants'],
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
                ->assertInertia(function (Assert $page) use ($component, $moduleKey, $uri): Assert {
                    $page
                        ->component($component, false)
                        ->where('activeModule.key', $moduleKey)
                        ->where('navigationMode', 'module');

                    if (in_array($uri, ['/shop-owner/erp/retail/dashboard', '/shop-owner/erp/retail/products'], true)) {
                        $page->where('erpMode', true);
                    }

                    if ($uri === '/shop-owner/erp/finance/invoices') {
                        $page->where('ownerMode', true)->where('initialSection', 'invoice-generation');
                    }

                    if ($uri === '/shop-owner/erp/finance/expenses') {
                        $page->where('ownerMode', true)->where('initialSection', 'expense-tracking');
                    }

                    if ($uri === '/shop-owner/erp/finance/create-invoice') {
                        $page->where('ownerMode', true)->where('initialSection', 'create-invoice');
                    }

                    if ($uri === '/shop-owner/erp/finance/repair-pricing') {
                        $page->where('erpMode', true)->where('approvalType', 'repair');
                    }

                    if ($uri === '/shop-owner/erp/finance/shoe-pricing') {
                        $page->where('erpMode', true)->where('approvalType', 'shoe');
                    }

                    return $page;
                });
        }
    }

    public function test_new_owner_operational_pages_render_their_scoped_components(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->id],
            'owner_action_center.enabled' => true,
            'owner_action_center.allowlisted_shop_ids' => [$owner->id],
        ]);

        foreach (['hr_employees', 'finance', 'inventory', 'procurement', 'logistics'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        $pages = [
            ['/shop-owner/erp/hr/dashboard', 'ERP/HR/HR'],
            ['/shop-owner/erp/hr/attendance', 'ERP/HR/HR'],
            ['/shop-owner/erp/hr/leave-approvals', 'ERP/HR/HR'],
            ['/shop-owner/erp/hr/overtime-approvals', 'ERP/HR/HR'],
            ['/shop-owner/erp/hr/payroll-view', 'ERP/HR/HR'],
            ['/shop-owner/erp/hr/payroll-generate', 'ERP/HR/HR'],
            ['/shop-owner/erp/hr/salary-changes', 'ERP/HR/HR'],
            ['/shop-owner/erp/finance/dashboard', 'ERP/Finance/Dashboard'],
            ['/shop-owner/erp/finance/invoices', 'ERP/Finance/Finance'],
            ['/shop-owner/erp/finance/create-invoice', 'ERP/Finance/Finance'],
            ['/shop-owner/erp/finance/expenses', 'ERP/Finance/Finance'],
            ['/shop-owner/erp/inventory/upload-stocks', 'ERP/inventory/UploadInventory'],
            ['/shop-owner/erp/inventory/stock-request', 'ERP/inventory/StockRequest'],
            ['/shop-owner/erp/inventory/request-material-approval', 'ERP/inventory/RequestApproval'],
            ['/shop-owner/erp/inventory/supplier-order-monitoring', 'ERP/inventory/SupplierOrderMonitoring'],
            ['/shop-owner/erp/procurement/purchase-request', 'ERP/Procurement/PurchaseRequest'],
            ['/shop-owner/erp/procurement/purchase-orders', 'ERP/Procurement/PurchaseOrders'],
            ['/shop-owner/erp/procurement/stock-request-approval', 'ERP/Procurement/StockRequestApproval'],
            ['/shop-owner/erp/logistics/batches', 'ERP/Logistics/Batches'],
            ['/shop-owner/erp/logistics/settings', 'ERP/Logistics/Settings'],
        ];

        foreach ($pages as [$uri, $component]) {
            $this->actingAs($owner, 'shop_owner')
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component, false)
                    ->where('navigationMode', 'module'));
        }

        foreach ([
            '/shop-owner/erp/finance/repair-pricing',
            '/shop-owner/erp/finance/shoe-pricing',
            '/shop-owner/erp/finance/purchase-request-review',
        ] as $uri) {
            $this->actingAs($owner, 'shop_owner')
                ->get($uri)
                ->assertRedirect(route('shop-owner.shell.action-center'));
        }
    }

    public function test_owner_can_load_hr_and_finance_audit_logs_through_scoped_api_routes(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach (['hr_employees', 'finance'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        foreach (['hr', 'finance'] as $module) {
            $this->actingAs($owner, 'shop_owner')
                ->getJson("/api/shop-owner/erp/{$module}/audit-logs")
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    public function test_owner_finance_operations_use_owner_scoped_api_routes(): void
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
            'module_key' => 'finance',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/finance/invoices')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/finance/expenses')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/finance/tax-rates')
            ->assertOk();

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/finance/invoices', [
                'reference' => 'OWNER-INV-'.uniqid(),
                'customer_name' => 'ERP Owner Customer',
                'customer_email' => 'customer@example.com',
                'date' => now()->toDateString(),
                'items' => [[
                    'description' => 'Owner ERP invoice item',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 12,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('shop_id', $owner->id);
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
                ->component('ShopOwner/Products/product management/ProductManagementWithVariants', false)
                ->where('erpMode', true)
                ->where('activeModule.key', 'retail_operations')
            );
    }

    public function test_retail_operational_pages_use_owner_safe_components_in_the_erp_shell(): void
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

        foreach ([
            ['/shop-owner/erp/retail/orders', 'ShopOwner/Orders/order management/JobOrders'],
            ['/shop-owner/erp/retail/point-of-sale', 'ShopOwner/Repairs/service management/POS'],
            ['/shop-owner/erp/retail/discounts', 'ShopOwner/Orders/order management/discount'],
        ] as [$uri, $component]) {
            $this->actingAs($owner, 'shop_owner')
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component, false)
                    ->where('erpMode', true)
                    ->where('activeModule.key', 'retail_operations')
                    ->where('navigationMode', 'module'));
        }
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
            ['/shop-owner/erp/crm/customer-support', 'ShopOwner/Customers/customer management/customerSupport'],
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
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->id],
            'owner_action_center.enabled' => true,
            'owner_action_center.allowlisted_shop_ids' => [$owner->id],
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
            ['/shop-owner/erp/hr/employee-directory', 'ERP/HR/HR'],
            ['/shop-owner/erp/hr/suspend-accounts', 'ShopOwner/TeamManagement/suspendAccount'],
            ['/shop-owner/erp/finance/audit-logs', 'ERP/Finance/AuditLogs'],
            ['/shop-owner/erp/manager/reports', 'ERP/Manager/Reports'],
            ['/shop-owner/erp/manager/audit-logs', 'ERP/Manager/AuditLogs'],
            ['/shop-owner/erp/inventory/inventory-dashboard', 'ERP/inventory/InventoryDashboard'],
            ['/shop-owner/erp/inventory/product-inventory', 'ERP/inventory/ProductInventory'],
            ['/shop-owner/erp/inventory/stock-movement', 'ERP/inventory/StockMovement'],
            ['/shop-owner/erp/inventory/overview', 'ShopOwner/Products/product management/InventoryOverview'],
            ['/shop-owner/erp/procurement/suppliers-management', 'ERP/Procurement/SuppliersManagement'],
        ];

        foreach ($pages as [$uri, $component]) {
            $this->actingAs($owner, 'shop_owner')
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component, false));
        }

        foreach ([
            '/shop-owner/erp/finance/expense-approvals',
            '/shop-owner/erp/finance/refund-approvals',
            '/shop-owner/erp/finance/repair-pricing',
            '/shop-owner/erp/finance/shoe-pricing',
            '/shop-owner/erp/finance/purchase-request-review',
            '/shop-owner/erp/finance/payslip-approvals',
            '/shop-owner/erp/finance/salary-adjustment-approvals',
            '/shop-owner/erp/procurement/purchase-request-approval',
        ] as $uri) {
            $this->actingAs($owner, 'shop_owner')
                ->get($uri)
                ->assertRedirect(route('shop-owner.shell.action-center'));
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

    public function test_repair_module_exposes_owner_operational_pages_in_the_erp_shell(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'repair_operations',
            'enabled' => true,
        ]);

        $expectedPages = [];

        $this->assertSame(
            $expectedPages,
            array_column(app(ErpWorkspaceNavigationService::class)->forKey('repair_operations')['pages'], 'routeName'),
        );

        foreach ([
            ['/shop-owner/erp/repair/job-orders', 'ShopOwner/Repairs/service management/JobOrdersRepair'],
            ['/shop-owner/erp/repair/warranty-queue', 'ShopOwner/Repairs/service management/WarrantyQueue'],
            ['/shop-owner/erp/repair/services', 'ShopOwner/Repairs/service management/uploadService'],
            ['/shop-owner/erp/repair/stock-materials', 'ShopOwner/Repairs/individual/uploadStockMaterial'],
            ['/shop-owner/erp/repair/point-of-sale', 'ShopOwner/Repairs/service management/POS'],
            ['/shop-owner/erp/repair/support', 'ShopOwner/Customers/customer management/repairSupport'],
        ] as [$uri, $component]) {
            $this->actingAs($owner, 'shop_owner')
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component, false)
                    ->where('erpMode', true)
                    ->where('activeModule.key', 'repair_operations')
                    ->where('navigationMode', 'module'));
        }
    }
}
