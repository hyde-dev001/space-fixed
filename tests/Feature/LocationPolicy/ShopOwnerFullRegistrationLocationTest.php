<?php

namespace Tests\Feature\LocationPolicy;

use App\Services\CaviteLocationPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShopOwnerFullRegistrationLocationTest extends TestCase
{
    use RefreshDatabase;

    private const LAT_DASMARINAS = 14.3294;
    private const LNG_DASMARINAS = 120.9367;
    private const LAT_MAKATI = 14.5547;
    private const LNG_MAKATI = 121.0244;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Maria',
            'lastName' => 'Reyes',
            'email' => 'full-register@example.com',
            'phone' => '09179876543',
            'businessName' => 'Maria Footwear Works',
            'businessAddress' => 'Imus, Cavite',
            'businessType' => 'repair',
            'registrationType' => 'individual',
            'operatingHours' => [
                ['day' => 'Monday', 'open' => '09:00', 'close' => '18:00'],
                ['day' => 'Tuesday', 'open' => '09:00', 'close' => '18:00'],
            ],
            'agreesToRequirements' => true,
            'shop_latitude' => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
            'dtiRegistration' => UploadedFile::fake()->create('dti.png', 120, 'image/png'),
            'mayorsPermit' => UploadedFile::fake()->create('permit.png', 120, 'image/png'),
            'birCertificate' => UploadedFile::fake()->create('bir.png', 120, 'image/png'),
            'validId' => UploadedFile::fake()->create('id.png', 120, 'image/png'),
        ], $overrides);
    }

    /** @test */
    public function full_registration_with_cavite_coordinates_passes(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/shop/register-full', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    /** @test */
    public function full_registration_with_ncr_coordinates_fails(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/shop/register-full', $this->payload([
            'email' => 'full-ncr@example.com',
            'shop_latitude' => self::LAT_MAKATI,
            'shop_longitude' => self::LNG_MAKATI,
            'businessAddress' => 'Makati, Metro Manila',
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('message', CaviteLocationPolicyService::DENIAL_MESSAGE);
    }

    /** @test */
    public function full_registration_without_coordinates_fails_even_with_cavite_address(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/shop/register-full', $this->payload([
            'email' => 'full-no-coords@example.com',
            'shop_latitude' => null,
            'shop_longitude' => null,
            'businessAddress' => 'Bacoor, Cavite',
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('message', CaviteLocationPolicyService::DENIAL_MESSAGE);
    }

    /** @test */
    public function full_registration_tampered_coordinate_payload_is_blocked(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/shop/register-full', $this->payload([
            'email' => 'full-tampered@example.com',
            'shop_latitude' => '14.30; DROP TABLE shop_owners; --',
            'shop_longitude' => '120.93',
        ]));

        $response->assertStatus(422);
    }
}
