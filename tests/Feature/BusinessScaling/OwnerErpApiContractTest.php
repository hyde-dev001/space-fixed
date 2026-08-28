<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Http\Controllers\Erp\HR\AuditLogController;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class OwnerErpApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_read_api_wave_exposes_owner_crm_and_logistics_get_contracts(): void
    {
        config([
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

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/dashboard-stats')
            ->assertOk()
            ->assertJsonStructure([
                'active_customers',
                'open_conversations',
                'pending_reviews',
                'average_rating',
            ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/customers')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/reviews')
            ->assertOk()
            ->assertJsonStructure(['reviews', 'stats']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/logistics/dashboard-stats')
            ->assertOk()
            ->assertJsonStructure(['stats']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/logistics/shipments')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/logistics/riders')
            ->assertOk()
            ->assertJsonStructure(['riders']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/logistics/batches')
            ->assertOk()
            ->assertJsonStructure(['batches']);
    }

    public function test_second_read_api_wave_exposes_owner_hr_finance_and_canonical_audit_get_contract(): void
    {
        config([
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

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/hr/audit-logs')
            ->assertOk()
            ->assertJsonStructure(['success', 'data']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/finance/audit-logs')
            ->assertOk()
            ->assertJsonStructure(['success', 'data']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/manager/reports')
            ->assertOk()
            ->assertJsonStructure(['metrics', 'report_types', 'recent_reports']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/audit-logs')
            ->assertOk()
            ->assertJsonStructure(['logs', 'stats']);
    }

    public function test_owner_hr_payroll_operations_use_owner_scoped_api_routes(): void
    {
        config([
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

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/hr/payroll/periods')
            ->assertOk();

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/hr/payroll')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_owner_hr_reads_remain_available_while_generation_mutations_are_denied(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'hr_employees',
            'enabled' => true,
        ]);
        $employee = Employee::factory()->create([
            'shop_owner_id' => $owner->id,
            'status' => 'active',
        ]);
        $startDate = now()->startOfMonth()->toDateString();
        $endDate = now()->endOfMonth()->toDateString();

        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/hr/attendance/employee/{$employee->id}?start_date={$startDate}&end_date={$endDate}")
            ->assertOk()
            ->assertJsonStructure(['employee', 'summary', 'records']);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/hr/payroll/batch/preview', [
                'payrollPeriod' => now()->format('Y-m'),
                'employeeIds' => [$employee->id],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'ERP_ROUTE_NOT_ALLOWED');

        foreach ([
            '/api/shop-owner/hr/payroll/calculate-preview',
            '/api/shop-owner/hr/payroll/batch/generate',
            '/api/shop-owner/hr/payroll/batch/retry',
            '/api/shop-owner/hr/payroll/batch/export',
        ] as $uri) {
            $this->actingAs($owner, 'shop_owner')
                ->postJson($uri, [])
                ->assertForbidden()
                ->assertJsonPath('code', 'ERP_ROUTE_NOT_ALLOWED');
        }
    }

    public function test_owner_can_update_an_employee_through_the_owner_scoped_json_route(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $employee = Employee::factory()->create([
            'shop_owner_id' => $owner->id,
            'email' => 'employee@example.test',
            'status' => 'active',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->putJson("/shop-owner/employees/{$employee->id}", [
                'name' => 'Updated Employee',
                'email' => 'updated.employee@example.test',
                'phone' => '09171234567',
                'address' => 'Updated address',
                'position' => 'HR Specialist',
                'department' => 'HR',
                'salary' => 25000,
                'hire_date' => now()->toDateString(),
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.name', 'Updated Employee');
    }

    public function test_owner_cannot_reactivate_a_terminated_employee(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $employee = Employee::factory()->create([
            'shop_owner_id' => $owner->id,
            'status' => 'terminated',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->putJson("/shop-owner/employees/{$employee->id}", [
                'name' => 'Terminated Employee',
                'email' => $employee->email,
                'status' => 'active',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMPLOYEE_TERMINATED');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status' => 'terminated',
        ]);
    }

    public function test_shop_owner_cannot_generate_a_payroll_through_the_owner_scoped_route(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'hr_employees',
            'enabled' => true,
        ]);
        $employee = Employee::factory()->create([
            'shop_owner_id' => $owner->id,
            'status' => 'active',
            'salary' => 1000,
        ]);

        $response = $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/hr/payroll', [
                'employee_id' => $employee->id,
                'payrollPeriod' => now()->format('Y-m'),
                'paymentMethod' => 'bank_transfer',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('code', 'ERP_ROUTE_NOT_ALLOWED');
    }

    public function test_hr_and_finance_audit_routes_use_the_case_correct_owner_controller(): void
    {
        foreach (['hr', 'finance'] as $module) {
            $route = Route::getRoutes()->getByName("shop-owner.erp.api.{$module}.audit-logs");

            $this->assertNotNull($route, $module);
            $this->assertSame(AuditLogController::class.'@index', $route->getAction('uses'), $module);
        }
    }

    public function test_third_read_api_wave_exposes_owner_inventory_and_procurement_get_contracts(): void
    {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach (['inventory', 'procurement'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'name' => 'Company Shoe Inventory',
            'category' => 'shoes',
            'available_quantity' => 12,
        ]);
        InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'name' => 'Repair Leather Patch',
            'category' => 'repair_materials',
            'available_quantity' => 8,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/inventory/dashboard')
            ->assertOk()
            ->assertJsonStructure(['metrics', 'chartData', 'products'])
            ->assertJsonPath('metrics.total_items', 2)
            ->assertJsonFragment(['name' => 'Company Shoe Inventory'])
            ->assertJsonFragment(['name' => 'Repair Leather Patch']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/inventory/products')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/inventory/movements')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/procurement/suppliers')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);
    }

    public function test_owner_inventory_reads_include_unlinked_shoe_products_with_repair_materials(): void
    {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'inventory',
            'enabled' => true,
        ]);

        InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'name' => 'Repair Leather Patch',
            'category' => 'repair_materials',
            'available_quantity' => 8,
        ]);
        Product::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Company Uploaded Runner',
            'slug' => 'company-uploaded-runner',
            'price' => 3200,
            'category' => 'shoes',
            'stock_quantity' => 14,
            'sku' => 'RUNNER-OWNER-001',
            'is_active' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/inventory/products?per_page=200')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['name' => 'Company Uploaded Runner'])
            ->assertJsonFragment(['name' => 'Repair Leather Patch']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/inventory/dashboard')
            ->assertOk()
            ->assertJsonPath('metrics.total_items', 2)
            ->assertJsonFragment(['name' => 'Company Uploaded Runner'])
            ->assertJsonFragment(['name' => 'Repair Leather Patch']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/inventory/dashboard?category=shoes&per_page=200')
            ->assertOk()
            ->assertJsonPath('products.total', 1)
            ->assertJsonFragment(['name' => 'Company Uploaded Runner'])
            ->assertJsonMissing(['name' => 'Repair Leather Patch']);
    }

    public function test_owner_inventory_projection_deduplicates_linked_products_and_is_tenant_scoped(): void
    {
        config([
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
            'module_key' => 'inventory',
            'enabled' => true,
        ]);

        $linkedProduct = Product::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Linked Catalog Runner',
            'slug' => 'linked-catalog-runner',
            'price' => 3200,
            'category' => 'shoes',
            'stock_quantity' => 20,
            'sku' => 'LINKED-RUNNER-001',
            'is_active' => true,
        ]);
        InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'product_id' => $linkedProduct->id,
            'name' => 'Linked Runner Inventory',
            'category' => 'shoes',
            'available_quantity' => 18,
        ]);
        Product::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Owner Catalog Loafer',
            'slug' => 'owner-catalog-loafer',
            'price' => 2800,
            'category' => 'shoes',
            'stock_quantity' => 9,
            'sku' => 'OWNER-LOAFER-001',
            'is_active' => true,
        ]);
        Product::create([
            'shop_owner_id' => $otherOwner->id,
            'name' => 'Other Tenant Runner',
            'slug' => 'other-tenant-runner',
            'price' => 2800,
            'category' => 'shoes',
            'stock_quantity' => 9,
            'sku' => 'OTHER-RUNNER-001',
            'is_active' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/inventory/products?per_page=200')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['name' => 'Linked Runner Inventory'])
            ->assertJsonFragment(['name' => 'Owner Catalog Loafer'])
            ->assertJsonMissing(['name' => 'Linked Catalog Runner'])
            ->assertJsonMissing(['name' => 'Other Tenant Runner']);
    }

    public function test_company_owner_product_catalog_includes_uploaded_products_without_inventory_links(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        foreach (['crm', 'repair_operations', 'retail_operations'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }
        $owner->givePermissionTo(Permission::findOrCreate('access-product-management', 'shop_owner'));

        $uploadedProduct = Product::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Uploaded Company Runner',
            'slug' => 'uploaded-company-runner',
            'price' => 2499,
            'category' => 'shoes',
            'stock_quantity' => 12,
            'sku' => 'UPLOADED-RUNNER-001',
            'is_active' => true,
        ]);
        $linkedProduct = Product::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Linked Company Runner',
            'slug' => 'linked-company-runner',
            'price' => 2699,
            'category' => 'shoes',
            'stock_quantity' => 8,
            'sku' => 'LINKED-RUNNER-001',
            'is_active' => true,
        ]);
        InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'product_id' => $linkedProduct->id,
            'name' => 'Linked Company Runner Inventory',
            'category' => 'shoes',
        ]);
        Product::create([
            'shop_owner_id' => $otherOwner->id,
            'name' => 'Other Shop Runner',
            'slug' => 'other-shop-runner',
            'price' => 2799,
            'category' => 'shoes',
            'stock_quantity' => 6,
            'sku' => 'OTHER-RUNNER-001',
            'is_active' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/products/my/products')
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonFragment(['id' => $uploadedProduct->id, 'name' => 'Uploaded Company Runner'])
            ->assertJsonFragment(['id' => $linkedProduct->id, 'name' => 'Linked Company Runner'])
            ->assertJsonMissing(['name' => 'Other Shop Runner']);
    }

    public function test_fourth_read_api_wave_exposes_owner_retail_and_repair_get_contracts(): void
    {
        config([
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach (['crm', 'repair_operations', 'retail_operations'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/staff/customers')
            ->assertOk()
            ->assertJsonStructure(['customers']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/staff/repair-dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'metricCards',
                'requestedServices',
                'revenueRows',
                'recentRepairs',
            ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/products')
            ->assertOk()
            ->assertJsonStructure(['success', 'products']);
    }

    public function test_owner_can_read_finance_and_repair_surfaces_without_creation_bypass(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach (['finance', 'repair_operations'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/finance/invoices')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/finance/expenses')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/repair-services')
            ->assertOk();

        InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'name' => 'Repair Leather Patch',
            'category' => 'repair_materials',
            'available_quantity' => 8,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/repair-materials')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Repair Leather Patch']);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/finance/expenses', [
                'date' => now()->toDateString(),
                'category' => 'supplies',
                'description' => 'Owner creation must remain disabled',
                'amount' => 100,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'ERP_ROUTE_NOT_ALLOWED');
    }

    public function test_owner_can_create_an_employee_from_the_employee_directory_contract(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        Role::findOrCreate('Staff', 'user');

        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'hr_employees',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/employees', [
                'name' => 'Directory Employee',
                'email' => 'directory.employee@example.test',
                'phone' => '09170000001',
                'position' => 'General Staff',
                'department' => 'Staff',
                'role' => 'Staff',
                'hire_date' => now()->toDateString(),
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('employee.name', 'Directory Employee');

        $this->assertDatabaseHas('employees', [
            'shop_owner_id' => $owner->id,
            'email' => 'directory.employee@example.test',
            'status' => 'active',
        ]);
    }

    public function test_owner_can_create_a_repair_service_from_the_repair_services_contract(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'repair_operations',
            'enabled' => true,
        ]);
        $material = InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'category' => 'repair_materials',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/repair-services', [
                'name' => 'Owner Shoe Restoration',
                'category' => 'Restoration',
                'price' => 1250,
                'duration' => '3 days',
                'description' => 'Owner-created repair service',
                'status' => 'Active',
                'material_templates' => [[
                    'inventory_item_id' => $material->id,
                    'default_quantity' => 1,
                    'is_critical' => false,
                    'tolerance_percent' => 20,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.shop_owner_id', $owner->id)
            ->assertJsonPath('data.name', 'Owner Shoe Restoration');

        $this->assertDatabaseHas('repair_services', [
            'shop_owner_id' => $owner->id,
            'name' => 'Owner Shoe Restoration',
            'price' => 1250,
        ]);
    }

    public function test_owner_repair_service_reads_and_updates_are_tenant_scoped(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'repair_operations',
            'enabled' => true,
        ]);
        $otherService = RepairService::create([
            'shop_owner_id' => $otherOwner->id,
            'name' => 'Other Shop Service',
            'category' => 'Cleaning',
            'price' => 500,
            'duration' => '1 day',
            'status' => 'Active',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/repair-services?shop_id='.$otherOwner->id)
            ->assertForbidden();

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/repair-services')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/repair-services/'.$otherService->id)
            ->assertNotFound();

        $this->actingAs($owner, 'shop_owner')
            ->putJson('/api/shop-owner/repair-services/'.$otherService->id, [
                'name' => 'Cross-shop mutation attempt',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('repair_services', [
            'id' => $otherService->id,
            'shop_owner_id' => $otherOwner->id,
            'name' => 'Other Shop Service',
        ]);
    }
}
