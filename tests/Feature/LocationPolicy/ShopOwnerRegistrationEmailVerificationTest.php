<?php

namespace Tests\Feature\LocationPolicy;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShopOwnerRegistrationEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const LAT_DASMARINAS = 14.3294;
    private const LNG_DASMARINAS = 120.9367;

    private function docs(): array
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        return [
            'dti_registration' => UploadedFile::fake()->createWithContent('dti_registration.png', $png),
            'mayors_permit' => UploadedFile::fake()->createWithContent('mayors_permit.png', $png),
            'bir_certificate' => UploadedFile::fake()->createWithContent('bir_certificate.png', $png),
            'valid_id' => UploadedFile::fake()->createWithContent('valid_id.png', $png),
        ];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Santos',
            'email' => 'shop-verify@solespaceph.com',
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

    private function cacheKey(string $email): string
    {
        return 'shop_owner_registration_email_otp:' . sha1(strtolower(trim($email)));
    }

    private function markRegistrationEmailVerified(string $email): void
    {
        Cache::put(
            $this->cacheKey($email),
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
    public function shop_owner_registration_requires_verified_email_before_submit(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload(),
            $this->docs()
        ));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /** @test */
    public function verify_email_otp_endpoint_marks_email_as_verified(): void
    {
        // Force DB bootstrap on this test to keep sqlite in-memory schema available across requests.
        ShopOwner::query()->count();

        $email = 'otp-verify@solespaceph.com';
        Cache::put(
            $this->cacheKey($email),
            [
                'otp_hash' => Hash::make('123456'),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10)->timestamp,
                'verified' => false,
                'verified_at' => null,
            ],
            now()->addMinutes(10)
        );

        $response = $this->postJson('/shop-owner/email-verification/verify-code', [
            'email' => $email,
            'otp' => '123456',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $entry = Cache::get($this->cacheKey($email));
        $this->assertIsArray($entry);
        $this->assertTrue((bool) ($entry['verified'] ?? false));
    }

}
