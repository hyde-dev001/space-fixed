<?php

namespace Tests\Feature\LocationPolicy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShopOwnerRegistrationEmailVerificationSuccessTest extends TestCase
{
    use RefreshDatabase;

    private const LAT_DASMARINAS = 14.3294;
    private const LNG_DASMARINAS = 120.9367;

    private function docs(): array
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        return [
            'business_registration' => UploadedFile::fake()->createWithContent('dti_registration.png', $png),
            'business_registration_type' => 'dti_registration',
            'mayors_permit' => UploadedFile::fake()->createWithContent('mayors_permit.png', $png),
            'bir_certificate' => UploadedFile::fake()->createWithContent('bir_certificate.png', $png),
            'valid_id' => UploadedFile::fake()->createWithContent('valid_id.png', $png),
            'document_metadata' => [
                'business_registration' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
                'mayors_permit' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'dated', 'expires_on' => '2027-01-01'],
                'bir_certificate' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
                'valid_id' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
            ],
        ];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Santos',
            'email' => 'verified-register@solespaceph.com',
            'phone' => '09171234567',
            'business_name' => 'Ana Repair Hub',
            'business_address' => 'Dasmarinas, Cavite',
            'business_type' => 'repair',
            'registration_type' => 'individual',
            'attendance_geofence_enabled' => true,
            'shop_latitude' => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
            'shop_address' => 'Dasmarinas, Cavite',
            'shop_geofence_radius' => 150,
        ], $overrides);
    }

    private function markRegistrationEmailVerified(string $email): void
    {
        $normalizedEmail = strtolower(trim($email));
        Cache::put(
            'shop_owner_registration_email_otp:' . sha1($normalizedEmail),
            [
                'verified' => true,
                'verified_at' => now()->timestamp,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(60)->timestamp,
                'otp_hash' => null,
            ],
            now()->addMinutes(60)
        );
    }

    /** @test */
    public function shop_owner_registration_succeeds_after_email_verification(): void
    {
        Storage::fake('public');
        $this->markRegistrationEmailVerified('verified-register@solespaceph.com');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload(),
            $this->docs()
        ));

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }
}
