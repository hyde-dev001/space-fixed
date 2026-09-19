<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Models\PurchaseRequest;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class ProcurementDashboardRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_open_the_procurement_dashboard_with_actor_safe_links(): void
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->for($shop)->create();
        Permission::findOrCreate('access-procurement-dashboard', 'user');
        $user->givePermissionTo('access-procurement-dashboard');

        $this->actingAs($user, 'user')
            ->get('/erp/procurement/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('ERP/Procurement/Dashboard', false)
                ->has('dashboard.summary.purchase_requests')
                ->has('dashboard.trend.months', 6)
                ->where('dashboard.links.purchase_requests', url('/erp/procurement/purchase-request'))
                ->where('dashboard.links.purchase_orders', url('/erp/procurement/purchase-orders'))
            );
    }

    public function test_employee_without_procurement_dashboard_access_is_forbidden(): void
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->for($shop)->create();

        $this->actingAs($user, 'user')
            ->get('/erp/procurement/dashboard')
            ->assertForbidden();
    }

    public function test_shop_owner_procurement_dashboard_uses_the_shared_read_model_and_owner_links(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'procurement',
            'enabled' => true,
        ]);
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/oversee/procurement')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('ERP/Procurement/Dashboard', false)
                ->where('activeModule.key', 'procurement')
                ->where('navigationMode', 'module')
                ->where('dashboard.summary.purchase_requests', 1)
                ->where('dashboard.links.purchase_requests', url('/shop-owner/erp/procurement/purchase-request'))
                ->where('dashboard.links.purchase_orders', url('/shop-owner/erp/procurement/purchase-orders'))
            );
    }
}
