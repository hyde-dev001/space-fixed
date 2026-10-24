<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CustomerSpendTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-customer-support', 'user');
    }

    public function test_owner_and_crm_customer_totals_include_shipping_and_vat(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'crm',
            'enabled' => true,
        ]);

        $crm = User::factory()->create([
            'shop_owner_id' => $owner->id,
            'role' => 'CRM',
            'status' => 'active',
        ]);
        $crm->givePermissionTo('access-customer-support');

        $customer = User::factory()->create(['status' => 'active']);
        Order::create([
            'shop_owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-CRM-TOTAL-1',
            'total_amount' => 100.00,
            'shipping_fee' => 10.00,
            'vat_amount' => 12.00,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/customers')
            ->assertOk()
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.total_spent', 122);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('stats.total_spent', 122);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/crm/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/CRM/Customers', false)
                ->where('initialCustomers.0.id', $customer->id)
                ->where('initialCustomers.0.totalSpent', 122));

        $this->actingAs($crm, 'user')
            ->getJson('/api/crm/customers')
            ->assertOk()
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.total_spent', 122);

        $this->actingAs($crm, 'user')
            ->getJson('/api/crm/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('stats.total_spent', 122);

        $this->actingAs($crm, 'user')
            ->get('/crm/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/CRM/Customers', false)
                ->where('initialCustomers.0.id', $customer->id)
                ->where('initialCustomers.0.totalSpent', 122));
    }
}
