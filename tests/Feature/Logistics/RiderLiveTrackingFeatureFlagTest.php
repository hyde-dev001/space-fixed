<?php

namespace Tests\Feature\Logistics;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RiderLiveTrackingFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_endpoint_is_unavailable_when_tracking_is_disabled(): void
    {
        config(['logistics_tracking.enabled' => false]);
        $shop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(Permission::findOrCreate('view-logistics-shipments', 'user'));

        $this->actingAs($dispatcher, 'user')
            ->getJson('/api/logistics/live-locations')
            ->assertNotFound();
    }
}
