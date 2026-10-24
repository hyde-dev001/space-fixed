<?php

namespace Tests\Feature\BusinessScaling;

use App\Actions\ShopOwner\ToggleShopOwnerModule;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReleaseRouteCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_finance_read_keeps_its_permission_and_module_boundary(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'company', 'business_type' => 'both']);
        \App\Models\ShopOwnerModule::factory()->create(['shop_owner_id' => $shop->id, 'module_key' => 'finance', 'enabled' => true]);
        $user = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $this->clockInEmployee($user);
        $this->actingAs($user, 'user')->getJson('/api/finance/expense-categories')->assertForbidden();
        $user->givePermissionTo(Permission::findOrCreate('access-finance-expenses', 'user'));
        $this->getJson('/api/finance/expense-categories')->assertOk();
        app(ToggleShopOwnerModule::class)->handle($shop, 'finance', false);
        $this->actingAs($user->fresh(), 'user')->getJson('/api/finance/expense-categories')->assertForbidden();
    }

    public function test_owner_platform_balance_stays_owner_only_core_self_service(): void
    {
        $entry = config('shop_modules.routes')['shop-owner.shell.platform-balance'];
        $this->assertSame('shop_owner', $entry['audience']);
        $this->assertSame('shop_owner', $entry['actor_guard']);
        $this->assertTrue($entry['self_service']);
        $this->assertSame('allowed', $entry['owner_access']);
        $this->assertNull($entry['paired_route']);
        foreach (['shop_owner.finance.platform-balance.index', 'shop_owner.finance.platform-balance.payment-requests.approve'] as $name) {
            $api = config('shop_modules.routes')[$name] ?? null;
            $this->assertNotNull($api, $name);
            $this->assertSame('core', $api['classification']);
            $this->assertSame('shop_owner', $api['actor_guard']);
            $this->assertSame('allowed', $api['owner_access']);
            $this->assertSame('financial', $api['risk_tier']);
        }
    }
}
