<?php

namespace Tests\Feature\LocationPolicy;

use App\Services\CaviteLocationPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
            'first_name' => 'Maria',
            'last_name' => 'Reyes',
            'email' => 'full-register@solespaceph.com',
            'phone' => '09179876543',
            'business_name' => 'Maria Footwear Works',
            'business_address' => 'Imus, Cavite',
            'business_type' => 'repair',
            'registration_type' => 'individual',
            'attendance_geofence_enabled' => true,
            'shop_latitude' => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
            'shop_address' => 'Imus, Cavite',
            'shop_geofence_radius' => 150,
            'business_registration_type' => 'dti_registration',
            'business_registration' => UploadedFile::fake()->create('dti.png', 120, 'image/png'),
            'mayors_permit' => UploadedFile::fake()->create('permit.png', 120, 'image/png'),
            'bir_certificate' => UploadedFile::fake()->create('bir.png', 120, 'image/png'),
            'valid_id' => UploadedFile::fake()->create('id.png', 120, 'image/png'),
            'document_metadata' => [
                'business_registration' => [
                    'issued_on' => '2026-01-01',
                    'expiration_mode' => 'none',
                    'expires_on' => null,
                ],
                'mayors_permit' => [
                    'issued_on' => '2026-01-01',
                    'expiration_mode' => 'dated',
                    'expires_on' => '2027-01-01',
                ],
                'bir_certificate' => [
                    'issued_on' => '2026-01-01',
                    'expiration_mode' => 'none',
                    'expires_on' => null,
                ],
                'valid_id' => [
                    'issued_on' => '2026-01-01',
                    'expiration_mode' => 'none',
                    'expires_on' => null,
                ],
            ],
        ], $overrides);
    }

    private function register(array $overrides = [])
    {
        $payload = $this->payload($overrides);
        $email = strtolower(trim((string) $payload['email']));

        Cache::put(
            'shop_owner_registration_email_otp:' . sha1($email),
            [
                'verified' => true,
                'verified_at' => now()->timestamp,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(60)->timestamp,
                'otp_hash' => null,
            ],
            now()->addMinutes(60),
        );

        Storage::fake('public');

        return $this->withHeader('Accept', 'application/json')
            ->post('/shop-owner/register', $payload);
    }

    /** @test */
    public function canonical_registration_with_cavite_coordinates_succeeds_with_versioned_metadata(): void
    {
        $response = $this->register();

        $response->assertStatus(201);
    }

    /** @test */
    public function full_registration_with_ncr_coordinates_fails(): void
    {
        $response = $this->register([
            'email' => 'full-ncr@solespaceph.com',
            'shop_latitude' => self::LAT_MAKATI,
            'shop_longitude' => self::LNG_MAKATI,
            'business_address' => 'Makati, Metro Manila',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', CaviteLocationPolicyService::DENIAL_MESSAGE);
    }

    /** @test */
    public function full_registration_without_coordinates_fails_even_with_cavite_address(): void
    {
        $response = $this->register([
            'email' => 'full-no-coords@solespaceph.com',
            'shop_latitude' => null,
            'shop_longitude' => null,
            'business_address' => 'Bacoor, Cavite',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', CaviteLocationPolicyService::DENIAL_MESSAGE);
    }

    /** @test */
    public function full_registration_tampered_coordinate_payload_is_blocked(): void
    {
        $response = $this->register([
            'email' => 'full-tampered@solespaceph.com',
            'shop_latitude' => '14.30; DROP TABLE shop_owners; --',
            'shop_longitude' => '120.93',
        ]);

        $response->assertStatus(422);
    }
}
