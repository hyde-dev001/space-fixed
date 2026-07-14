<?php

namespace Tests\Unit\Services;

use App\Services\AddressCoordinateService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddressCoordinateServiceTest extends TestCase
{
    public function test_geocode_falls_back_to_locality_when_full_address_has_no_match(): void
    {
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
        Http::assertSent(fn ($request) => $request['q'] === 'Dasmariñas, Cavite, 4114, Philippines');
    }
}
