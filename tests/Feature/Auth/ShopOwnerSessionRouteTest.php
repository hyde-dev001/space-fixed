<?php

namespace Tests\Feature\Auth;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopOwnerSessionRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_shop_owner_can_read_promos_with_the_session_guard(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/promos')
            ->assertOk();
    }
}
