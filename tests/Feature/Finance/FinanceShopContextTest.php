<?php

namespace Tests\Feature\Finance;

use App\Models\ShopOwner;
use App\Models\User;
use App\Support\Finance\FinanceShopContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Tests\TestCase;

class FinanceShopContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_proxy_uses_tenant_link_instead_of_colliding_user_id(): void
    {
        $own = ShopOwner::factory()->approved()->create();
        $other = ShopOwner::factory()->approved()->create();
        $proxy = User::factory()->create(['id' => $other->id, 'shop_owner_id' => $own->id, 'role' => null]);
        $proxy->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Shop Owner', 'user'));
        $request = Request::create('/api/finance/platform-balance?shop_id='.$other->id);
        $request->setUserResolver(fn ($guard) => $guard === 'user' ? $proxy : null);
        $this->assertSame($own->id, app(FinanceShopContext::class)->id($request));
    }

    public function test_owner_proxy_without_link_cannot_use_a_matching_owner_id(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $proxy = User::factory()->create(['id' => $owner->id, 'shop_owner_id' => null, 'role' => null]);
        $proxy->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Shop Owner', 'user'));
        $request = Request::create('/api/finance/invoices?shop_id='.$owner->id);
        $request->setUserResolver(fn ($guard) => $guard === 'user' ? $proxy : null);
        try {
            app(FinanceShopContext::class)->id($request);
            $this->fail('A role and matching numeric ID must not establish a tenant.');
        } catch (HttpResponseException $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
        }
    }

    public function test_dedicated_owner_route_cannot_fall_back_to_a_user_session(): void
    {
        $user = User::factory()->create(['shop_owner_id' => ShopOwner::factory()->approved()->create()->id]);
        $request = Request::create('/api/shop-owner/finance/platform-balance');
        $request->setUserResolver(fn ($guard) => $guard === 'user' ? $user : null);
        try {
            app(FinanceShopContext::class)->id($request);
            $this->fail('A dedicated owner guard is required.');
        } catch (HttpResponseException $e) {
            $this->assertSame(401, $e->getResponse()->getStatusCode());
        }
    }
}
