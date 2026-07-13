<?php

namespace Tests\Feature\UserSide;

use App\Models\ShopOwner;
use App\Services\AddressCoordinateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class ShippingEstimateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_reuses_shared_geocoder_and_preserves_response(): void
    {
        $shop = ShopOwner::factory()->create([
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        $this->mock(AddressCoordinateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('geocode')->once()->andReturn([
                'latitude' => 14.6091,
                'longitude' => 121.0223,
            ]);
        });
        Http::fake([
            'router.project-osrm.org/*' => Http::response([
                'routes' => [['distance' => 5000]],
            ]),
        ]);

        $this->postJson('/api/shipping/estimate', [
            'shop_owner_id' => $shop->id,
            'shipping_address_line' => '1 Test Street',
            'shipping_barangay' => 'Ermita',
            'shipping_city' => 'Manila',
            'shipping_region' => 'NCR',
            'shipping_postal_code' => '1000',
        ])->assertOk()->assertJson([
            'success' => true,
            'has_estimate' => true,
            'distance_km' => 5,
            'source' => 'osm-osrm',
            'shipping_summary' => 'To be calculated after order',
        ]);
    }
}
