<?php

namespace Tests\Feature\Logistics;

use App\Services\Logistics\RouteEstimationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RouteEstimationServiceTest extends TestCase
{
    public function test_it_returns_and_caches_the_fastest_road_route(): void
    {
        config([
            'logistics_tracking.routing.enabled' => true,
            'logistics_tracking.routing.provider' => 'osrm',
            'logistics_tracking.routing.base_url' => 'https://router.project-osrm.org',
        ]);
        Cache::flush();
        Http::fake([
            'https://router.project-osrm.org/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [[
                    'distance' => 1500,
                    'duration' => 420,
                    'geometry' => [
                        'coordinates' => [
                            [120.98421, 14.59951],
                            [120.98700, 14.60400],
                            [120.99000, 14.61000],
                        ],
                    ],
                ]],
            ]),
        ]);

        $service = app(RouteEstimationService::class);
        $first = $service->estimate(
            ['latitude' => 14.59951, 'longitude' => 120.98421],
            ['latitude' => 14.61, 'longitude' => 120.99],
        );
        $second = $service->estimate(
            ['latitude' => 14.59952, 'longitude' => 120.98422],
            ['latitude' => 14.61, 'longitude' => 120.99],
        );

        $this->assertSame([
            'distance_m' => 1500.0,
            'duration_s' => 420,
            'geometry' => [
                [14.59951, 120.98421],
                [14.604, 120.987],
                [14.61, 120.99],
            ],
            'source' => 'road',
        ], $first);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_it_falls_back_to_a_direct_estimate_when_the_road_provider_is_unavailable(): void
    {
        config([
            'logistics_tracking.routing.enabled' => true,
            'logistics_tracking.routing.provider' => 'osrm',
            'logistics_tracking.routing.base_url' => 'https://router.project-osrm.org',
        ]);
        Cache::flush();
        Http::fake([
            'https://router.project-osrm.org/route/v1/driving/*' => Http::response([], 503),
        ]);

        $route = app(RouteEstimationService::class)->estimate(
            ['latitude' => 14.5995, 'longitude' => 120.9842],
            ['latitude' => 14.61, 'longitude' => 120.99],
        );

        $this->assertNotNull($route);
        $this->assertSame('direct', $route['source']);
        $this->assertCount(2, $route['geometry']);
    }

    public function test_it_returns_null_when_routing_is_disabled_or_coordinates_are_invalid(): void
    {
        $service = app(RouteEstimationService::class);
        $point = ['latitude' => 14.5995, 'longitude' => 120.9842];

        config(['logistics_tracking.routing.enabled' => false]);
        $this->assertNull($service->estimate($point, $point));

        config(['logistics_tracking.routing.enabled' => true]);
        $this->assertNull($service->estimate(['latitude' => 100, 'longitude' => 120], $point));
        $this->assertNull($service->estimate(['latitude' => 14, 'longitude' => 'not-a-coordinate'], $point));
    }
}
