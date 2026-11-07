<?php

namespace Tests\Unit\Services;

use App\Services\AddressCoordinateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddressCoordinateServiceTest extends TestCase
{
    public function test_geocode_falls_back_to_locality_through_the_shared_nominatim_service(): void
    {
        config([
            'cache.default' => 'array',
            'services.nominatim.url' => 'https://nominatim.test',
        ]);
        Cache::clear();
        Http::fakeSequence()
            ->push([])
            ->push([['lat' => '14.2990', 'lon' => '120.9580']]);

        $coordinates = app(AddressCoordinateService::class)->geocode([
            'shipping_address_line' => 'Block 10 Lot 8 Daylight Street Sunrise Hills',
            'shipping_barangay' => 'Sabang',
            'shipping_city' => 'Dasmariñas',
            'shipping_region' => 'Cavite',
            'shipping_postal_code' => '4114',
        ]);

        $this->assertSame(['latitude' => 14.299, 'longitude' => 120.958], $coordinates);
        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertSame(
            'Block 10 Lot 8 Daylight Street Sunrise Hills, Sabang, Dasmariñas, Cavite, 4114, Philippines',
            $requests[0][0]['q'],
        );
        $this->assertSame('Dasmariñas, Cavite, 4114, Philippines', $requests[1][0]['q']);
        $this->assertTrue(str_starts_with($requests[0][0]->url(), 'https://nominatim.test/search?'));
        $this->assertTrue(str_starts_with($requests[1][0]->url(), 'https://nominatim.test/search?'));
        $this->assertSame(0, $requests[0][0]['addressdetails']);
        $this->assertSame(0, $requests[1][0]['addressdetails']);
    }
}
