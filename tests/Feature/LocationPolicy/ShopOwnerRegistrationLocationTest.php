<?php

namespace Tests\Feature\LocationPolicy;

use App\Models\ShopOwner;
use App\Services\CaviteLocationPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * QA Test Matrix – Phase 6 (HTTP / Feature layer).
 *
 * Tests the same five scenarios through the real HTTP stack:
 *
     *  T1  Legacy register with Cavite coordinates     → HTTP 422 (safe refusal)
 *  T2  Register with NCR (Makati) coordinates     → HTTP 422 + denial message
 *  T3  Register with Cavite address text only
 *       (no coordinates)                          → HTTP 422 (coords required)
 *  T4  Update existing shop to non-Cavite coords  → HTTP 422 + denial message
 *  T5  GPS spoof / tampered payload               → HTTP 422 (backend still blocks)
 *
 * Registration endpoint:  POST /shop-owner/register  (ShopOwnerAuthController::register)
 * Geofence update:        POST /shop-owner/settings/geofence  (ShopSettingsController::updateGeofence)
 */
class ShopOwnerRegistrationLocationTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────────
    // Coordinate constants
    // ──────────────────────────────────────────────────────────────────────────

    // Inside Cavite polygon & bbox (14.05–14.52 lat, 120.55–121.05 lng)
    private const LAT_DASMARINAS = 14.3294;
    private const LNG_DASMARINAS = 120.9367;

    private const LAT_GEN_TRIAS  = 14.3863;
    private const LNG_GEN_TRIAS  = 120.8838;

    // Outside Cavite – NCR cities are above bbox max_lat (14.52)
    private const LAT_MAKATI     = 14.5547;
    private const LNG_MAKATI     = 121.0244;

    private const LAT_MANILA     = 14.5995;
    private const LNG_MANILA     = 120.9842;

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Build a minimal valid registration payload, optionally overriding any field.
     */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name'       => 'Juan',
            'last_name'        => 'dela Cruz',
            'email'            => 'juan@solespaceph.com',
            'phone'            => '09171234567',
            'business_name'    => 'Juan Sole Works',
            'business_address' => 'Dasmariñas, Cavite',
            'business_type'    => 'repair',
            'registration_type' => 'individual',
            'business_registration_type' => 'dti_registration',
            'attendance_geofence_enabled' => true,
            'shop_address'     => 'Dasmariñas, Cavite',
            'shop_geofence_radius' => 150,
        ], $overrides);
    }

    private function documents(): array
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        return [
            'business_registration' => UploadedFile::fake()->createWithContent('dti_registration.png', $png),
            'mayors_permit' => UploadedFile::fake()->createWithContent('mayors_permit.png', $png),
            'bir_certificate' => UploadedFile::fake()->createWithContent('bir_certificate.png', $png),
            'valid_id' => UploadedFile::fake()->createWithContent('valid_id.png', $png),
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
        ];
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
            now()->addMinutes(60),
        );
    }

    private function register(array $overrides = [])
    {
        $payload = array_merge($this->basePayload(), $this->documents(), $overrides);
        $this->markRegistrationEmailVerified((string) $payload['email']);
        Storage::fake('public');

        return $this->withHeader('Accept', 'application/json')
            ->post('/shop-owner/register', $payload);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // T1 – Register with Cavite coordinates → HTTP 201
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function registration_with_dasmarinas_coordinates_is_accepted_by_the_canonical_flow(): void
    {
        $response = $this->register([
            'shop_latitude'  => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('shop_owners', ['email' => 'juan@solespaceph.com']);
    }

    /** @test */
    public function registration_with_general_trias_coordinates_is_accepted_by_the_canonical_flow(): void
    {
        $response = $this->register([
            'email'          => 'gentrias@solespaceph.com',
            'shop_latitude'  => self::LAT_GEN_TRIAS,
            'shop_longitude' => self::LNG_GEN_TRIAS,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('shop_owners', ['email' => 'gentrias@solespaceph.com']);
    }

    /** @test */
    public function registration_with_cavite_coordinates_persists_the_shop_owner_record(): void
    {
        $this->register([
            'email'          => 'persist@solespaceph.com',
            'shop_latitude'  => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
        ]);

        $this->assertDatabaseHas('shop_owners', ['email' => 'persist@solespaceph.com']);
    }

    /** @test */
    public function registration_with_cavite_coordinates_persists_the_authoritative_coordinates(): void
    {
        $this->register([
            'email'          => 'coords@solespaceph.com',
            'shop_latitude'  => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
        ]);

        $shopOwner = ShopOwner::where('email', 'coords@solespaceph.com')->first();
        $this->assertNotNull($shopOwner);
        $this->assertSame(self::LAT_DASMARINAS, (float) $shopOwner->shop_latitude);
        $this->assertSame(self::LNG_DASMARINAS, (float) $shopOwner->shop_longitude);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // T2 – Register with NCR (Makati) coordinates → HTTP 422
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function registration_with_makati_coordinates_returns_422(): void
    {
        $response = $this->register([
            'shop_latitude'  => self::LAT_MAKATI,
            'shop_longitude' => self::LNG_MAKATI,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function registration_with_ncr_coordinates_response_contains_standard_denial_message(): void
    {
        $response = $this->register([
            'shop_latitude'  => self::LAT_MAKATI,
            'shop_longitude' => self::LNG_MAKATI,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', CaviteLocationPolicyService::DENIAL_MESSAGE);
    }

    /** @test */
    public function registration_with_ncr_coordinates_does_not_create_shop_owner_record(): void
    {
        $email = 'ncr-blocked@solespaceph.com';

        $this->register([
            'email'          => $email,
            'shop_latitude'  => self::LAT_MAKATI,
            'shop_longitude' => self::LNG_MAKATI,
        ]);

        $this->assertDatabaseMissing('shop_owners', ['email' => $email]);
    }

    /** @test */
    public function registration_with_manila_coordinates_returns_422(): void
    {
        $response = $this->register([
            'shop_latitude'  => self::LAT_MANILA,
            'shop_longitude' => self::LNG_MANILA,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // T3 – Register with Cavite address text only (no coordinates) → HTTP 422
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function registration_without_any_coordinates_is_rejected(): void
    {
        // Payload has a valid Cavite address but absolutely no lat/lng
        $response = $this->register([
            'business_address' => 'Dasmariñas, Cavite, Philippines',
            // shop_latitude and shop_longitude deliberately absent
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function registration_without_coordinates_returns_denial_message_even_with_cavite_address(): void
    {
        $response = $this->register([
            'business_address' => 'Bacoor, Cavite',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', CaviteLocationPolicyService::DENIAL_MESSAGE);
    }

    /** @test */
    public function registration_without_coordinates_does_not_persist_record(): void
    {
        $email = 'no-coords@solespaceph.com';

        $this->register([
            'email'           => $email,
            'business_address' => 'Imus, Cavite',
        ]);

        $this->assertDatabaseMissing('shop_owners', ['email' => $email]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // T4 – Update existing shop from Cavite to non-Cavite → HTTP 422
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function geofence_update_to_ncr_returns_422_for_authenticated_shop_owner(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'shop_latitude'  => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
            'shop_address'   => 'Dasmariñas, Cavite',
            'status'         => 'approved',
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->postJson('/shop-owner/settings/geofence', [
                'attendance_geofence_enabled' => true,
                'shop_latitude'               => self::LAT_MAKATI,
                'shop_longitude'              => self::LNG_MAKATI,
                'shop_address'                => 'Ayala Ave, Makati',
                'shop_geofence_radius'        => 500,
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function geofence_update_to_ncr_returns_standard_denial_message(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'shop_latitude'  => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
            'status'         => 'approved',
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->postJson('/shop-owner/settings/geofence', [
                'attendance_geofence_enabled' => true,
                'shop_latitude'               => self::LAT_MAKATI,
                'shop_longitude'              => self::LNG_MAKATI,
                'shop_address'                => 'Ayala Ave, Makati',
                'shop_geofence_radius'        => 500,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', CaviteLocationPolicyService::DENIAL_MESSAGE);
    }

    /** @test */
    public function geofence_update_within_cavite_is_allowed(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'shop_latitude'  => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
            'status'         => 'approved',
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->postJson('/shop-owner/settings/geofence', [
                'attendance_geofence_enabled' => true,
                'shop_latitude'               => self::LAT_GEN_TRIAS,
                'shop_longitude'              => self::LNG_GEN_TRIAS,
                'shop_address'                => 'General Trias, Cavite',
                'shop_geofence_radius'        => 500,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /** @test */
    public function geofence_update_to_ncr_does_not_persist_new_coordinates(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'shop_latitude'  => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
            'status'         => 'approved',
        ]);

        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson('/shop-owner/settings/geofence', [
                'attendance_geofence_enabled' => true,
                'shop_latitude'               => self::LAT_MAKATI,
                'shop_longitude'              => self::LNG_MAKATI,
                'shop_address'                => 'Ayala Ave, Makati',
                'shop_geofence_radius'        => 500,
            ]);

        // The original Cavite coordinates must not be overwritten
        $shopOwner->refresh();
        $this->assertNotEquals(self::LAT_MAKATI, (float) $shopOwner->shop_latitude);
        $this->assertNotEquals(self::LNG_MAKATI, (float) $shopOwner->shop_longitude);
    }

    /** @test */
    public function geofence_update_requires_authentication(): void
    {
        // Unauthenticated request must not reach the policy check
        $response = $this->postJson('/shop-owner/settings/geofence', [
            'attendance_geofence_enabled' => true,
            'shop_latitude'               => self::LAT_DASMARINAS,
            'shop_longitude'              => self::LNG_DASMARINAS,
            'shop_geofence_radius'        => 500,
        ]);

        // 401 Unauthorized (or 302 redirect) – either way, not 200
        $this->assertNotEquals(200, $response->status());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // T5 – GPS spoof / tampered payload → HTTP 422 (backend still blocks)
    //
    //  5a  (0, 0) coordinates – Gulf of Guinea
    //  5b  Out-of-range lat/lng (999) – caught by Laravel 'between' validation rule
    //  5c  Non-numeric string coordinates – caught by Laravel 'numeric' rule
    //  5d  SQL injection string as latitude
    //  5e  Wrong field names ('latitude'/'longitude') – silently ignored; no coords → blocked
    //  5f  NCR coordinates paired with Cavite address text – coordinates still win
    //  5g  Completely missing coordinate fields from payload
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function spoof_5a_zero_zero_coordinates_are_rejected(): void
    {
        // (0,0) is in the Gulf of Guinea, not Cavite
        $response = $this->register([
            'shop_latitude'  => 0.0,
            'shop_longitude' => 0.0,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function spoof_5b_out_of_range_coordinates_are_rejected_by_laravel_validation(): void
    {
        // between:-90,90 / between:-180,180 rules reject impossible values
        // before the Cavite policy is even reached
        $response = $this->register([
            'shop_latitude'  => 999,
            'shop_longitude' => 999,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('shop_owners', ['email' => 'juan@example.com']);
    }

    /** @test */
    public function spoof_5c_non_numeric_string_coordinates_are_rejected_by_laravel_validation(): void
    {
        // 'numeric' rule catches strings that are not numbers
        $response = $this->register([
            'shop_latitude'  => 'abc',
            'shop_longitude' => 'xyz',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function spoof_5d_sql_injection_as_coordinates_is_rejected_and_no_record_persisted(): void
    {
        $response = $this->register([
            'shop_latitude'  => '14.30; DROP TABLE shop_owners; --',
            'shop_longitude' => '120.93',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('shop_owners', ['email' => 'juan@example.com']);
    }

    /** @test */
    public function spoof_5e_alternate_field_names_are_ignored_and_request_is_blocked(): void
    {
        // 'latitude'/'longitude' are not declared in the validation rules;
        // they are silently ignored, leaving shop_latitude/shop_longitude absent → blocked
        $response = $this->register([
            'latitude'  => self::LAT_DASMARINAS,
            'longitude' => self::LNG_DASMARINAS,
            // shop_latitude / shop_longitude deliberately absent
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function spoof_5f_ncr_coordinates_with_cavite_address_are_still_rejected(): void
    {
        // Coordinates are authoritative; a matching Cavite address cannot rescue NCR coords
        $response = $this->register([
            'business_address' => 'Dasmariñas, Cavite',  // truthful-looking but irrelevant
            'shop_latitude'   => self::LAT_MAKATI,
            'shop_longitude'  => self::LNG_MAKATI,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', CaviteLocationPolicyService::DENIAL_MESSAGE);
    }

    /** @test */
    public function spoof_5g_completely_omitting_coordinates_is_rejected(): void
    {
        // Payload contains every field except coordinates
        $response = $this->register();

        $response->assertStatus(422);
    }
}
