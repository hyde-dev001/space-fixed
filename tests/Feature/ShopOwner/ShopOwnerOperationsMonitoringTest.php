<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner;

use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ShopOwnerOperationsMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop_modules.enforcement_enabled' => true]);
    }

    public function test_company_retail_owner_uses_the_manager_style_read_only_order_surface(): void
    {
        $owner = $this->owner('company', 'retail');
        $otherOwner = $this->owner('company', 'retail');
        $order = Order::factory()->create([
            'shop_owner_id' => $owner->id,
            'customer_name' => 'Visible customer',
        ]);
        Order::factory()->create([
            'shop_owner_id' => $otherOwner->id,
            'customer_name' => 'Hidden customer',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/retail/orders')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Operations/JobOrders'));

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/operations/orders')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $order->id)
            ->assertJsonMissing(['customer_name' => 'Hidden customer']);
    }

    public function test_company_repair_owner_uses_the_manager_style_repair_monitoring_surface(): void
    {
        $owner = $this->owner('company', 'repair');
        $otherOwner = $this->owner('company', 'repair');
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'customer_name' => 'Visible repair customer',
        ]);
        RepairRequest::factory()->create([
            'shop_owner_id' => $otherOwner->id,
            'customer_name' => 'Hidden repair customer',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/repair/job-orders')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Operations/RepairJobs'));

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/operations/repairs')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $repair->id)
            ->assertJsonMissing(['customer_name' => 'Hidden repair customer']);
    }

    public function test_individual_owner_keeps_the_existing_operational_pages(): void
    {
        $retailOwner = $this->owner('individual', 'retail');
        $repairOwner = $this->owner('individual', 'repair');

        $this->actingAs($retailOwner, 'shop_owner')
            ->get('/shop-owner/erp/retail/orders')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Orders/order management/JobOrders'));

        $this->actingAs($retailOwner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/operations/orders')
            ->assertForbidden();

        $this->actingAs($repairOwner, 'shop_owner')
            ->get('/shop-owner/erp/repair/job-orders')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Repairs/service management/JobOrdersRepair'));

        $this->actingAs($repairOwner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/operations/repairs')
            ->assertForbidden();
    }

    public function test_owner_monitoring_surfaces_follow_business_type_boundaries(): void
    {
        $retailOwner = $this->owner('company', 'retail');
        $repairOwner = $this->owner('company', 'repair');

        $this->actingAs($retailOwner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/operations/repairs')
            ->assertForbidden();

        $this->actingAs($repairOwner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/operations/orders')
            ->assertForbidden();
    }

    public function test_company_owner_tabs_use_manager_names_and_canonical_page_targets(): void
    {
        $retailOwner = $this->owner('company', 'retail');
        $repairOwner = $this->owner('company', 'repair');
        $individualOwner = $this->owner('individual', 'retail');

        $this->actingAs($retailOwner, 'shop_owner')
            ->get('/shop-owner/operate/retail')
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeModule.pages.1.routeName', 'shop-owner.erp.retail.orders')
                ->where('activeModule.pages.1.label', 'Job Orders')
                ->where('activeModule.pages.1.url', route('shop-owner.erp.retail.orders')));

        $this->actingAs($repairOwner, 'shop_owner')
            ->get('/shop-owner/operate/repair')
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeModule.pages.0.routeName', 'shop-owner.erp.repair.job-orders')
                ->where('activeModule.pages.0.label', 'Repair Jobs')
                ->where('activeModule.pages.0.url', route('shop-owner.erp.repair.job-orders')));

        $this->actingAs($individualOwner, 'shop_owner')
            ->get('/shop-owner/operate/retail')
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeModule.pages.1.routeName', 'shop-owner.erp.retail.orders')
                ->where('activeModule.pages.1.label', 'Orders'));
    }

    private function owner(string $registrationType, string $businessType): ShopOwner
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => $registrationType,
            'business_type' => $businessType,
        ]);

        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => $businessType === 'repair' ? 'repair_operations' : 'retail_operations',
            'enabled' => true,
        ]);

        return $owner;
    }
}
