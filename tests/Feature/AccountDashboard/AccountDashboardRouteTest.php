<?php

namespace Tests\Feature\AccountDashboard;

use App\Models\Order;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AccountDashboardRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_dashboard_returns_only_the_authenticated_staff_workload(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $staff = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'STAFF',
        ]);
        $otherStaff = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'STAFF',
        ]);
        Permission::findOrCreate('access-staff-dashboard', 'user');
        $staff->givePermissionTo('access-staff-dashboard');

        Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'assigned_staff_id' => $staff->id,
            'status' => 'processing',
        ]);
        Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'assigned_staff_id' => $otherStaff->id,
            'status' => 'processing',
        ]);

        $response = $this->actingAs($staff, 'user')->get('/erp/staff/dashboard')->assertOk();
        $dashboard = $response->viewData('page')['props']['dashboard'];

        $this->assertSame(1, $dashboard['summary']['assigned_open_work']);
        $this->assertSame(1, $dashboard['summary']['active_orders']);
    }

    public function test_staff_dashboard_requires_dashboard_access(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $staff = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'STAFF',
        ]);

        $this->actingAs($staff, 'user')
            ->get('/erp/staff/dashboard')
            ->assertForbidden();
    }

    public function test_staff_customers_route_still_renders_customer_management(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $staff = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'STAFF',
        ]);
        Permission::findOrCreate('access-staff-customers', 'user');
        $staff->givePermissionTo('access-staff-customers');

        $this->actingAs($staff, 'user')
            ->get('/erp/staff/customers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('ERP/STAFF/Customers'));
    }

    public function test_cashier_dashboard_is_shop_scoped_and_uses_pos_records(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'both']);
        $otherShop = ShopOwner::factory()->create(['business_type' => 'both']);
        $cashier = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'CASHIER',
        ]);
        Permission::findOrCreate('access-unified-pos', 'user');
        $cashier->givePermissionTo('access-unified-pos');

        $todayTransaction = PosTransaction::query()->create([
            'transaction_no' => 'POS-ACCOUNT-001',
            'shop_owner_id' => $shop->id,
            'module_type' => 'retail',
            'module_reference_id' => 1001,
            'customer_type' => 'walk_in',
            'due_type' => 'full',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'status' => 'paid',
            'paid_at' => Carbon::now(),
        ]);
        $todayTransaction->forceFill(['created_at' => Carbon::yesterday()])->save();
        PosTransaction::query()->create([
            'transaction_no' => 'POS-ACCOUNT-002',
            'shop_owner_id' => $otherShop->id,
            'module_type' => 'retail',
            'module_reference_id' => 1002,
            'customer_type' => 'walk_in',
            'due_type' => 'full',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => Carbon::now(),
        ]);
        $source = PosTransaction::query()->where('transaction_no', 'POS-ACCOUNT-001')->firstOrFail();
        PosRefund::query()->create([
            'refund_no' => 'REF-ACCOUNT-001',
            'shop_owner_id' => $shop->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'retail',
            'module_reference_id' => 1001,
            'request_type' => 'full',
            'requested_amount' => 100,
            'status' => 'requested',
            'reason_code' => 'customer_request',
        ]);

        $response = $this->actingAs($cashier, 'user')->get('/erp/cashier/dashboard')->assertOk();
        $dashboard = $response->viewData('page')['props']['dashboard'];

        $this->assertSame(1, $dashboard['summary']['today_transactions']);
        $this->assertSame('100.00', $dashboard['summary']['today_sales']);
        $this->assertSame(1, $dashboard['summary']['refund_queue']);
        $this->assertCount(1, $dashboard['recent_transactions']);
        $this->assertSame('POS-ACCOUNT-001', $dashboard['recent_transactions'][0]['transaction_no']);
    }

    public function test_cashier_dashboard_requires_pos_access(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $cashier = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'CASHIER',
        ]);

        $this->actingAs($cashier, 'user')
            ->get('/erp/cashier/dashboard')
            ->assertForbidden();
    }
}
