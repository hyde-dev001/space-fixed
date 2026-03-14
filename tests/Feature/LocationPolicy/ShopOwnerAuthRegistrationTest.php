<?php

namespace Tests\Feature\LocationPolicy;

use App\Services\CaviteLocationPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShopOwnerAuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const LAT_DASMARINAS = 14.3294;
    private const LNG_DASMARINAS = 120.9367;
    private const LAT_MAKATI = 14.5547;
    private const LNG_MAKATI = 121.0244;

    private function docs(): array
    {
        return [
            'dti_registration' => UploadedFile::fake()->create('dti_registration.pdf', 120, 'application/pdf'),
            'mayors_permit' => UploadedFile::fake()->create('mayors_permit.pdf', 120, 'application/pdf'),
            'bir_certificate' => UploadedFile::fake()->create('bir_certificate.pdf', 120, 'application/pdf'),
            'valid_id' => UploadedFile::fake()->create('valid_id.pdf', 120, 'application/pdf'),
        ];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'auth-register@example.com',
            'phone' => '09171234567',
            'business_name' => 'Juan Shoes & Repairs',
            'business_address' => 'Dasmariñas, Cavite',
            'business_type' => 'repair',
            'registration_type' => 'individual',
            'attendance_geofence_enabled' => true,
            'shop_latitude' => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
            'shop_address' => 'Dasmariñas, Cavite',
            'shop_geofence_radius' => 150,
        ], $overrides);
    }

    /** @test */
    public function it_registers_successfully_with_cavite_coordinates(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload(),
            $this->docs()
        ));

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('shop_owners', [
            'email' => 'auth-register@example.com',
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function it_blocks_registration_with_ncr_coordinates(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload([
                'email' => 'auth-ncr@example.com',
                'shop_latitude' => self::LAT_MAKATI,
                'shop_longitude' => self::LNG_MAKATI,
                'business_address' => 'Makati, Metro Manila',
                'shop_address' => 'Makati, Metro Manila',
            ]),
            $this->docs()
        ));

        $response->assertStatus(422)
            ->assertJsonPath('message', CaviteLocationPolicyService::DENIAL_MESSAGE);

        $this->assertDatabaseMissing('shop_owners', [
            'email' => 'auth-ncr@example.com',
        ]);
    }

    /** @test */
    public function it_blocks_registration_without_coordinates_even_if_address_mentions_cavite(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload([
                'email' => 'auth-no-coords@example.com',
                'shop_latitude' => null,
                'shop_longitude' => null,
                'business_address' => 'Imus, Cavite',
                'shop_address' => 'Imus, Cavite',
            ]),
            $this->docs()
        ));

        $response->assertStatus(422)
            ->assertJsonPath('message', CaviteLocationPolicyService::DENIAL_MESSAGE);

        $this->assertDatabaseMissing('shop_owners', [
            'email' => 'auth-no-coords@example.com',
        ]);
    }

    /** @test */
    public function it_blocks_tampered_non_numeric_coordinate_payload(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload([
                'email' => 'auth-tampered@example.com',
                'shop_latitude' => '14.30; DROP TABLE shop_owners; --',
                'shop_longitude' => '120.93',
            ]),
            $this->docs()
        ));

        $response->assertStatus(422);

        $this->assertDatabaseMissing('shop_owners', [
            'email' => 'auth-tampered@example.com',
        ]);
    }

    /** @test */
    public function ncr_coordinates_still_fail_even_with_cavite_address_text(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload([
                'email' => 'auth-spoof-address@example.com',
                'shop_latitude' => self::LAT_MAKATI,
                'shop_longitude' => self::LNG_MAKATI,
                'business_address' => 'Dasmariñas, Cavite',
                'shop_address' => 'Dasmariñas, Cavite',
            ]),
            $this->docs()
        ));

        $response->assertStatus(422)
            ->assertJsonPath('message', CaviteLocationPolicyService::DENIAL_MESSAGE);
    }
}
