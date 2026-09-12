<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ShopOwner $shopOwner;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-inventory-dashboard', 'user');

        $this->shopOwner = ShopOwner::factory()->approved()->create();
        $this->user = User::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        $this->user->givePermissionTo('access-inventory-dashboard');
        $this->actingAs($this->user, 'user');
    }

    /** @test */
    public function it_returns_dashboard_metrics()
    {
        InventoryItem::factory()->count(5)->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 50,
        ]);

        $response = $this->getJson('/api/erp/inventory/dashboard/metrics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_items',
                'total_value',
                'low_stock_count',
                'out_of_stock_count',
            ]);
    }

    /** @test */
    public function it_returns_chart_data()
    {
        InventoryItem::factory()->count(3)->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->getJson('/api/erp/inventory/dashboard/chart-data');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'categories',
                'series',
            ]);
    }
}
