<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Http\Controllers\Erp\HR\AuditLogController;
use App\Models\Employee;
use App\Models\HR\Payroll;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OwnerErpApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_read_api_wave_exposes_owner_crm_and_logistics_get_contracts(): void
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
    }

    public function test_second_read_api_wave_exposes_owner_hr_finance_and_manager_get_contracts(): void
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
            ->getJson('/api/shop-owner/erp/manager/audit-logs')
            ->assertOk()
            ->assertJsonStructure(['logs', 'stats']);
    }

    public function test_owner_hr_payroll_operations_use_owner_scoped_api_routes(): void
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

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/hr/payroll/periods')
            ->assertOk();

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/hr/payroll')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_owner_hr_generation_flow_can_load_attendance_and_batch_preview(): void
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
            ->assertOk()
            ->assertJsonStructure(['previews', 'errors', 'warnings', 'summary']);
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

    public function test_shop_owner_can_generate_a_payroll_without_a_user_guard_foreign_key(): void
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

        $response->assertCreated();
        $this->assertDatabaseHas('payrolls', [
            'id' => $response->json('payroll.id'),
            'employee_id' => $employee->id,
            'shop_owner_id' => $owner->id,
            'generated_by' => null,
        ]);
        $this->assertInstanceOf(Payroll::class, Payroll::find($response->json('payroll.id')));
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
            'shop_modules.owner_erp_workspace_enabled' => true,
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

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/inventory/dashboard')
            ->assertOk()
            ->assertJsonStructure(['metrics', 'chartData', 'products']);

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

    public function test_fourth_read_api_wave_exposes_owner_retail_and_repair_get_contracts(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
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
}
