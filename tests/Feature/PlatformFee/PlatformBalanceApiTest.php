<?php

namespace Tests\Feature\PlatformFee;

use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PlatformBalanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-dashboard', 'user');
    }

    #[Test]
    public function finance_can_read_only_its_shop_platform_balance_and_ledger(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);
        $otherShop = ShopOwner::factory()->approved()->create(['registration_type' => 'business']);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-finance-dashboard');

        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 1000,
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $order->update(['status' => 'delivered']);

        Order::factory()->create([
            'shop_owner_id' => $otherShop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 3000,
            'payment_status' => 'paid',
            'status' => 'delivered',
        ]);

        $response = $this->actingAs($finance, 'user')->getJson('/api/finance/platform-balance');

        $response->assertOk()
            ->assertJsonPath('balance.outstanding_balance', '56.00')
            ->assertJsonPath('balance.net_payable', '56.00')
            ->assertJsonPath('balance.shop_type', 'individual')
            ->assertJsonCount(1, 'charges');
        $this->assertSame($shop->id, $response->json('charges.0.shop_id'));
        $this->assertNotSame($otherShop->id, $response->json('charges.0.shop_id'));
    }

    #[Test]
    public function an_individual_shop_owner_can_open_the_platform_balance_page(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.platform-balance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/Finance/PlatformBalance', false));
    }
}
