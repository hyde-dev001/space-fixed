<?php

namespace Tests\Unit\Services;

use App\Services\CaviteLocationPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * QA Test Matrix – Phase 6: CaviteLocationPolicyService unit tests.
 *
 * Covers all five scenarios at the service layer (no HTTP stack):
 *
 *  T1  Register with Cavite coordinates          → evaluate/assertRegistration pass
 *  T2  Register with NCR (Makati) coordinates    → evaluate/assertRegistration denied
 *  T3  Register with Cavite address text only    → assertRegistration blocked
 *       (coordinates are required; no manual-review fallback in default config)
 *  T3b evaluate() address-only fallback          → allowed for Cavite text, denied for NCR
 *  T4  Update existing shop to non-Cavite coords → validateUpdateLocation denied
 *  T5  GPS spoof / tampered payload values       → service still blocks
 *
 * RefreshDatabase is required because denied paths write to audit_logs
 * and activity_log (Spatie) via recordDeniedAttempt().
 */
class CaviteLocationPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────────
    // Coordinate constants
    // ──────────────────────────────────────────────────────────────────────────

    // Firmly inside Cavite polygon & bbox (14.05–14.52 lat, 120.55–121.05 lng)
    private const LAT_DASMARINAS    = 14.3294;
    private const LNG_DASMARINAS    = 120.9367;

    private const LAT_GEN_TRIAS     = 14.3863;
    private const LNG_GEN_TRIAS     = 120.8838;

    private const LAT_TAGAYTAY      = 14.1153;
    private const LNG_TAGAYTAY      = 120.9590;

    // Firmly outside Cavite – all NCR cities sit above bbox max_lat (14.52)
    private const LAT_MAKATI        = 14.5547;
    private const LNG_MAKATI        = 121.0244;

    private const LAT_MANILA        = 14.5995;
    private const LNG_MANILA        = 120.9842;

    private const LAT_QC            = 14.6760;
    private const LNG_QC            = 121.0437;

    // Edge / spoof values
    private const LAT_ZERO          = 0.0;   // Gulf of Guinea
    private const LNG_ZERO          = 0.0;

    private const LAT_EXTREME       = 999.0; // Physically impossible
    private const LNG_EXTREME       = 999.0;

    private const LAT_JUST_OUTSIDE  = 14.53; // 0.01° above Cavite bbox max_lat
    private const LNG_JUST_OUTSIDE  = 120.80;

    protected CaviteLocationPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CaviteLocationPolicyService::class);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // T1 – Register with Cavite coordinates → passes
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function evaluate_allows_dasmarinas_coordinates(): void
    {
        $result = $this->service->evaluate(self::LAT_DASMARINAS, self::LNG_DASMARINAS, null);

        $this->assertTrue($result['allowed']);
        $this->assertSame('coordinates', $result['source']);
        $this->assertNull($result['reason']);
    }

    /** @test */
    public function evaluate_allows_general_trias_coordinates(): void
    {
        $result = $this->service->evaluate(self::LAT_GEN_TRIAS, self::LNG_GEN_TRIAS, null);

        $this->assertTrue($result['allowed']);
        $this->assertSame('coordinates', $result['source']);
    }

    /** @test */
    public function evaluate_allows_tagaytay_coordinates(): void
    {
        $result = $this->service->evaluate(self::LAT_TAGAYTAY, self::LNG_TAGAYTAY, null);

        $this->assertTrue($result['allowed']);
        $this->assertSame('coordinates', $result['source']);
    }

    /** @test */
    public function assert_registration_passes_for_cavite_coordinates_without_throwing(): void
    {
        // Must not throw; returns an allowed result array
        $result = $this->service->assertRegistrationLocation(
            self::LAT_DASMARINAS,
            self::LNG_DASMARINAS,
            'Dasmariñas, Cavite'
        );

        $this->assertTrue($result['allowed']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // T2 – Register with NCR (Makati) coordinates → fails
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function evaluate_denies_makati_coordinates(): void
    {
        $result = $this->service->evaluate(self::LAT_MAKATI, self::LNG_MAKATI, null);

        $this->assertFalse($result['allowed']);
        $this->assertSame('coordinates', $result['source']);
        $this->assertNotNull($result['reason']);
    }

    /** @test */
    public function evaluate_denies_manila_coordinates(): void
    {
        $result = $this->service->evaluate(self::LAT_MANILA, self::LNG_MANILA, null);

        $this->assertFalse($result['allowed']);
        $this->assertSame('coordinates', $result['source']);
    }

    /** @test */
    public function evaluate_denies_quezon_city_coordinates(): void
    {
        $result = $this->service->evaluate(self::LAT_QC, self::LNG_QC, null);

        $this->assertFalse($result['allowed']);
    }

    /** @test */
    public function assert_registration_throws_validation_exception_for_makati_coordinates(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->assertRegistrationLocation(
            self::LAT_MAKATI,
            self::LNG_MAKATI,
            'Ayala Ave, Makati, Metro Manila'
        );
    }

    /** @test */
    public function assert_registration_ncr_exception_carries_standard_denial_message(): void
    {
        try {
            $this->service->assertRegistrationLocation(
                self::LAT_MAKATI,
                self::LNG_MAKATI,
                'Ayala Ave, Makati, Metro Manila'
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $allMessages = array_merge(...array_values($e->errors()));
            $this->assertContains(CaviteLocationPolicyService::DENIAL_MESSAGE, $allMessages);
        }
    }

    /** @test */
    public function assert_registration_ncr_exception_uses_shop_latitude_key(): void
    {
        try {
            $this->service->assertRegistrationLocation(
                self::LAT_MAKATI,
                self::LNG_MAKATI,
                null
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shop_latitude', $e->errors());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // T3 – Register with Cavite address text only, no coordinates → blocked
    //       (allow_manual_review_fallback is false in config/location_restrictions.php)
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function assert_registration_throws_when_coordinates_missing_even_with_cavite_address(): void
    {
        // Coordinates are mandatory; address-only is not accepted as a registration source
        $this->expectException(ValidationException::class);

        $this->service->assertRegistrationLocation(
            null,
            null,
            'Dasmariñas, Cavite, Philippines'
        );
    }

    /** @test */
    public function assert_registration_missing_coordinates_exception_keys_are_shop_latitude_and_longitude(): void
    {
        try {
            $this->service->assertRegistrationLocation(null, null, null);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('shop_latitude', $errors);
            $this->assertArrayHasKey('shop_longitude', $errors);
        }
    }

    /** @test */
    public function assert_registration_missing_coordinates_exception_carries_denial_message(): void
    {
        try {
            $this->service->assertRegistrationLocation(null, null, null);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $allMessages = array_merge(...array_values($e->errors()));
            $this->assertContains(CaviteLocationPolicyService::DENIAL_MESSAGE, $allMessages);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // T3b – evaluate() address-only fallback behaviour (direct method call)
    //        These test the pure evaluate() logic for when coordinates are
    //        absent; the assertRegistration* layer gates on coords first.
    // ════════════════════════════════════════════════════════════════════════

    /** @test */
    public function evaluate_address_fallback_allows_cavite_keyword_in_address(): void
    {
        $result = $this->service->evaluate(null, null, 'Bacoor, Cavite, Philippines');

        $this->assertTrue($result['allowed']);
        $this->assertSame('address', $result['source']);
    }

    /** @test */
    public function evaluate_address_fallback_allows_dasmarinas_keyword_in_address(): void
    {
        $result = $this->service->evaluate(null, null, '123 ML Quezon Ave, Dasmarinas City, Cavite');

        $this->assertTrue($result['allowed']);
        $this->assertSame('address', $result['source']);
    }

    /** @test */
    public function evaluate_address_fallback_allows_tagaytay_keyword_in_address(): void
    {
        $result = $this->service->evaluate(null, null, 'Rotonda, Tagaytay City');

        $this->assertTrue($result['allowed']);
        $this->assertSame('address', $result['source']);
    }

    /** @test */
    public function evaluate_address_fallback_denies_ncr_address(): void
    {
        $result = $this->service->evaluate(null, null, 'Ayala Avenue, Makati, Metro Manila');

        $this->assertFalse($result['allowed']);
        $this->assertSame('address', $result['source']);
    }

    /** @test */
    public function evaluate_address_fallback_denies_empty_string_address(): void
    {
        $result = $this->service->evaluate(null, null, '');

        $this->assertFalse($result['allowed']);
        $this->assertNotNull($result['reason']);
    }

    /** @test */
    public function evaluate_address_fallback_denies_null_address(): void
    {
        $result = $this->service->evaluate(null, null, null);

        $this->assertFalse($result['allowed']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // T4 – Update existing shop from Cavite to non-Cavite → fails
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function validate_update_allows_cavite_coordinates(): void
    {
        $result = $this->service->validateUpdateLocation(
            self::LAT_DASMARINAS,
            self::LNG_DASMARINAS,
            'Dasmariñas, Cavite'
        );

        $this->assertTrue($result['allowed']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function validate_update_allows_alternative_cavite_city(): void
    {
        $result = $this->service->validateUpdateLocation(
            self::LAT_GEN_TRIAS,
            self::LNG_GEN_TRIAS,
            'General Trias, Cavite'
        );

        $this->assertTrue($result['allowed']);
    }

    /** @test */
    public function validate_update_denies_ncr_coordinates(): void
    {
        $result = $this->service->validateUpdateLocation(
            self::LAT_MAKATI,
            self::LNG_MAKATI,
            'Ayala Ave, Makati'
        );

        $this->assertFalse($result['allowed']);
        $this->assertNotEmpty($result['errors']);
        $this->assertArrayHasKey('shop_latitude', $result['errors']);
    }

    /** @test */
    public function validate_update_denial_carries_standard_message_in_reason_field(): void
    {
        $result = $this->service->validateUpdateLocation(
            self::LAT_MAKATI,
            self::LNG_MAKATI,
            null
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame(CaviteLocationPolicyService::DENIAL_MESSAGE, $result['reason']);
    }

    /** @test */
    public function validate_update_denial_carries_standard_message_in_errors_array(): void
    {
        $result = $this->service->validateUpdateLocation(
            self::LAT_MAKATI,
            self::LNG_MAKATI,
            null
        );

        $firstError = $result['errors']['shop_latitude'][0] ?? null;
        $this->assertSame(CaviteLocationPolicyService::DENIAL_MESSAGE, $firstError);
    }

    /** @test */
    public function validate_update_cavite_then_ncr_transition_is_blocked(): void
    {
        // Simulates a shop that was in Cavite now trying to move to Manila
        $beforeUpdate = $this->service->validateUpdateLocation(
            self::LAT_DASMARINAS, self::LNG_DASMARINAS, null
        );
        $afterUpdate = $this->service->validateUpdateLocation(
            self::LAT_MANILA, self::LNG_MANILA, null
        );

        $this->assertTrue($beforeUpdate['allowed'],  'Cavite location should be allowed');
        $this->assertFalse($afterUpdate['allowed'],  'Manila location should be denied');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // T5 – GPS spoof / tampered payload → service still blocks
    //
    //  5a  (0,0) coordinates – Gulf of Guinea
    //  5b  Extreme (999,999) coordinates
    //  5c  Non-numeric strings treated as null → triggers missing-coords path
    //  5d  SQL injection string as latitude
    //  5e  Float-looking string with trailing garbage ("14.30abc")
    //  5f  Only one coordinate provided (partial pair)
    //  5g  Coordinates just 0.01° outside bbox
    //  5h  NCR coordinates paired with Cavite address text – coords still win
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function spoof_5a_zero_zero_coordinates_are_denied(): void
    {
        // (0,0) is in the Gulf of Guinea – well outside any allowed province
        $result = $this->service->evaluate(self::LAT_ZERO, self::LNG_ZERO, null);

        $this->assertFalse($result['allowed']);
        $this->assertSame('coordinates', $result['source']);
    }

    /** @test */
    public function spoof_5b_extreme_coordinates_are_denied(): void
    {
        // 999° is physically impossible and is outside every known polygon
        $result = $this->service->evaluate(self::LAT_EXTREME, self::LNG_EXTREME, null);

        $this->assertFalse($result['allowed']);
        $this->assertSame('coordinates', $result['source']);
    }

    /** @test */
    public function spoof_5c_non_numeric_string_lat_lng_treated_as_missing_and_blocked(): void
    {
        // "abc" / "xyz" → toFloatOrNull() returns null → missing-coords path → denied
        $this->expectException(ValidationException::class);

        $this->service->assertRegistrationLocation('abc', 'xyz', null);
    }

    /** @test */
    public function spoof_5d_sql_injection_string_as_latitude_is_blocked(): void
    {
        // Even if a bad actor injects a SQL fragment, the service casts to null
        $this->expectException(ValidationException::class);

        $this->service->assertRegistrationLocation(
            '14.30; DROP TABLE shop_owners; --',
            '120.93',
            null
        );
    }

    /** @test */
    public function spoof_5e_float_string_with_trailing_garbage_is_blocked(): void
    {
        // "14.30abc" is not is_numeric() → toFloatOrNull() returns null → blocked
        $this->expectException(ValidationException::class);

        $this->service->assertRegistrationLocation('14.30abc', '120.93', null);
    }

    /** @test */
    public function spoof_5f_partial_pair_missing_longitude_is_blocked(): void
    {
        // One-sided pair: both lat AND lng must be non-null for a coordinate check
        $this->expectException(ValidationException::class);

        $this->service->assertRegistrationLocation(
            self::LAT_DASMARINAS,
            null,
            null
        );
    }

    /** @test */
    public function spoof_5f_partial_pair_missing_latitude_is_blocked(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->assertRegistrationLocation(
            null,
            self::LNG_DASMARINAS,
            null
        );
    }

    /** @test */
    public function spoof_5g_coordinates_one_tick_outside_bbox_are_denied(): void
    {
        // lat = 14.53 is 0.01° above the Cavite bbox max_lat (14.52)
        $result = $this->service->evaluate(self::LAT_JUST_OUTSIDE, self::LNG_JUST_OUTSIDE, null);

        $this->assertFalse($result['allowed']);
    }

    /** @test */
    public function spoof_5h_ncr_coordinates_override_cavite_address_text_and_are_denied(): void
    {
        // Coordinates are the authoritative source; a misleading Cavite address must
        // not rescue an NCR coordinate pair from denial
        $result = $this->service->evaluate(
            self::LAT_MAKATI,
            self::LNG_MAKATI,
            'Dasmariñas, Cavite'   // fraudulent / misleading address
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('coordinates', $result['source'],
            'Coordinates must take priority over address text when both are present');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Denial message & exception helper contracts
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function denial_message_returns_exact_standard_string(): void
    {
        $this->assertSame(
            'Registration is only available for Cavite-based shops.',
            $this->service->denialMessage()
        );
    }

    /** @test */
    public function denial_message_method_matches_class_constant(): void
    {
        $this->assertSame(
            CaviteLocationPolicyService::DENIAL_MESSAGE,
            $this->service->denialMessage()
        );
    }

    /** @test */
    public function is_location_policy_exception_returns_true_for_denial_exception(): void
    {
        $exception = ValidationException::withMessages([
            'shop_latitude' => [CaviteLocationPolicyService::DENIAL_MESSAGE],
        ]);

        $this->assertTrue($this->service->isLocationPolicyValidationException($exception));
    }

    /** @test */
    public function is_location_policy_exception_returns_true_when_denial_message_is_in_address_key(): void
    {
        $exception = ValidationException::withMessages([
            'business_address' => [CaviteLocationPolicyService::DENIAL_MESSAGE],
        ]);

        $this->assertTrue($this->service->isLocationPolicyValidationException($exception));
    }

    /** @test */
    public function is_location_policy_exception_returns_false_for_unrelated_validation_error(): void
    {
        $exception = ValidationException::withMessages([
            'email' => ['The email field is required.'],
            'phone' => ['The phone field must be a valid phone number.'],
        ]);

        $this->assertFalse($this->service->isLocationPolicyValidationException($exception));
    }

    /** @test */
    public function is_location_policy_exception_returns_false_for_empty_errors_bag(): void
    {
        $exception = ValidationException::withMessages([]);

        $this->assertFalse($this->service->isLocationPolicyValidationException($exception));
    }
}
