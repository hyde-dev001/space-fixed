<?php

namespace Tests\Unit\Services;

use App\Services\AddressCoordinateService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddressCoordinateServiceTest extends TestCase
{
    public function test_geocode_uses_the_shared_nominatim_service(): void
    {
        Http::fakeSequence()
            ->push([['lat' => '14.2990', 'lon' => '120.9580']]);

        $coordinates = app(AddressCoordinateService::class)->geocode([
            'shipping_address_line' => 'Block 10 Lot 8 Daylight Street Sunrise Hills',
            'shipping_barangay' => 'Sabang',
            'shipping_city' => 'Dasmariñas',
            'shipping_region' => 'Cavite',
            'shipping_postal_code' => '4114',
        ]);

        $this->assertSame(['latitude' => 14.299, 'longitude' => 120.958], $coordinates);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request['q'], 'Block 10 Lot 8 Daylight Street Sunrise Hills, Sabang, ')
            && $request['addressdetails'] === 0);
    }
}
