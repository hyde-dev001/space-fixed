<?php

namespace Tests\Feature\UserSide;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Auth\Notifications\VerifyEmail;
use Tests\TestCase;

class CustomerRegistrationAddressTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan.dela.cruz@gmail.com',
            'phone' => '09171234567',
            'age' => 25,
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'address' => '123 Rizal Street, Ermita, Manila',
            'address_region' => 'National Capital Region',
            'address_province' => 'Metro Manila',
            'address_city' => 'Manila',
            'address_barangay' => 'Ermita',
            'address_postal_code' => '1000',
            'address_latitude' => 14.5832,
            'address_longitude' => 120.9822,
            'document_type' => 'national_id',
            'screening_metadata' => json_encode([
                'document_type' => 'national_id',
                'outcome' => 'screening_passed',
                'duplicate_kind' => 'none',
                'name_match' => true,
                'sides' => [
                    'front' => [
                        'side' => 'front',
                        'outcome' => 'plausible',
                        'detected_document_family' => 'national_id',
                        'detected_anchor_keys' => ['philsys_document', 'philippine_issuer', 'identity_fields'],
                        'confidence_band' => 'high',
                        'qr_detected' => false,
                        'fingerprint' => 'front-fingerprint',
                    ],
                    'back' => [
                        'side' => 'back',
                        'outcome' => 'plausible',
                        'detected_document_family' => 'national_id',
                        'detected_anchor_keys' => ['philsys_back_structure'],
                        'confidence_band' => 'high',
                        'qr_detected' => true,
                        'fingerprint' => 'back-fingerprint',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'valid_id' => $this->validPng('valid-id.png'),
            'valid_id_back' => $this->validPng('valid-id-back.png'),
        ], $overrides);
    }

    private function validPng(string $name): UploadedFile
    {
        $content = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAwUBAO+X2ioAAAAASUVORK5CYII=',
            true,
        );

        if ($content === false) {
            throw new \RuntimeException('Unable to create image fixture.');
        }

        return UploadedFile::fake()->createWithContent($name, $content.$name);
    }

    public function test_phone_availability_returns_a_minimal_duplicate_response(): void
    {
        User::factory()->create(['phone' => '09171234567']);

        $this->getJson('/auth/check-phone-availability?phone=09171234567')
            ->assertOk()
            ->assertExactJson([
                'available' => false,
                'message' => 'This phone number is already registered. Try another number or sign in instead.',
            ]);
    }

    public function test_customer_phone_has_a_database_unique_constraint(): void
    {
        User::factory()->create(['phone' => '09171234567']);

        $this->expectException(QueryException::class);

        User::factory()->create(['phone' => '09171234567']);
    }

    public function test_customer_phone_unique_constraint_allows_multiple_nulls(): void
    {
        User::factory()->count(2)->create(['phone' => null]);

        $this->assertSame(2, User::query()->whereNull('phone')->count());
    }

    public function test_registration_availability_and_submit_routes_are_throttled(): void
    {
        foreach (['auth.check-email-availability', 'auth.check-phone-availability', 'user.register'] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route);
            $this->assertContains('throttle:10,1', $route->middleware());
        }
    }

    public function test_registration_rejects_a_phone_registered_to_any_account_type(): void
    {
        foreach ([User::class, Employee::class, ShopOwner::class] as $index => $modelClass) {
            $account = $modelClass::factory()->create(['phone' => '09171234567']);
            $email = "duplicate-phone-{$index}@example.test";

            $this->post('/user/register', $this->payload(['email' => $email]))
                ->assertSessionHasErrors([
                    'phone' => 'This phone number is already registered. Try another number or sign in instead.',
                ]);

            $this->assertDatabaseMissing('users', ['email' => $email]);
            $account->delete();
        }
    }

    public function test_registration_queues_unreadable_name_for_manual_review(): void
    {
        Notification::fake();
        Storage::fake('local');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '14.5832',
            'lon' => '120.9822',
            'address' => [
                'country_code' => 'ph',
                'region' => 'National Capital Region',
                'state' => 'Metro Manila',
                'city' => 'Manila',
                'suburb' => 'Ermita',
                'postcode' => '1000',
            ],
        ])]);

        $payload = $this->payload(['email' => 'missing-name-match@example.test']);
        $metadata = json_decode($payload['screening_metadata'], true, 32, JSON_THROW_ON_ERROR);
        $metadata['outcome'] = 'manual_review_required';
        $metadata['name_match'] = false;
        $payload['screening_metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);

        $this->post('/user/register', $payload)
            ->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'missing-name-match@example.test')->firstOrFail();

        $this->assertSame('active', $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(User::IDENTITY_PENDING_REVIEW, $user->identity_verification_status);
        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id,
            'screening_status' => 'manual_review_required',
            'review_status' => 'pending',
            'failure_reason' => 'name_unreadable_or_mismatch',
        ]);
    }

    public function test_registration_rejects_non_boolean_name_match_evidence(): void
    {
        $payload = $this->payload(['email' => 'invalid-name-match@example.test']);
        $metadata = json_decode($payload['screening_metadata'], true, 32, JSON_THROW_ON_ERROR);
        $metadata['name_match'] = 'true';
        $payload['screening_metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);

        $this->post('/user/register', $payload)
            ->assertSessionHasErrors([
                'screening_metadata' => 'The ID image check result is invalid. Please try again.',
            ]);

        $this->assertDatabaseMissing('users', ['email' => 'invalid-name-match@example.test']);
    }

    public function test_registration_leaves_customer_unverified_and_sends_verification_email(): void
    {
        Notification::fake();
        Storage::fake('local');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '14.5832',
            'lon' => '120.9822',
            'address' => [
                'country_code' => 'ph',
                'region' => 'National Capital Region',
                'state' => 'Metro Manila',
                'city' => 'Manila',
                'suburb' => 'Ermita',
                'postcode' => '1000',
            ]])]);

        $this->post('/user/register', $this->payload())
            ->assertRedirect(route('verification.notice'));

        $user = User::query()
            ->where('email', 'juan.dela.cruz@gmail.com')
            ->firstOrFail();

        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertGuest('user');
    }

    public function test_registration_recovers_when_verification_email_delivery_fails(): void
    {
        Notification::fake();
        Event::listen(Registered::class, static function (): void {
            throw new \RuntimeException('mail transport unavailable');
        });
        Storage::fake('local');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '14.5832',
            'lon' => '120.9822',
            'address' => [
                'country_code' => 'ph',
                'region' => 'National Capital Region',
                'state' => 'Metro Manila',
                'city' => 'Manila',
                'suburb' => 'Ermita',
                'postcode' => '1000',
            ],
        ])]);

        $response = $this->post('/user/register', $this->payload([
            'email' => 'mail-failure@example.test',
        ]));

        $response->assertRedirect(route('verification.notice'))
            ->assertSessionHas('registration_email_failed', true)
            ->assertSessionHas('email', 'mail-failure@example.test');

        $user = User::query()->where('email', 'mail-failure@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertGuest('user');
    }

    public function test_registration_creates_a_default_shipping_address(): void
    {
        Storage::fake('public');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '14.5832',
            'lon' => '120.9822',
            'address' => [
                'country_code' => 'ph',
                'region' => 'National Capital Region',
                'state' => 'Metro Manila',
                'city' => 'Manila',
                'suburb' => 'Ermita',
                'postcode' => '1000',
            ]])]);

        $this->post('/user/register', $this->payload())
            ->assertRedirect(route('verification.notice'));

        $user = User::query()
            ->where('email', 'juan.dela.cruz@gmail.com')
            ->firstOrFail();

        $this->assertGuest('user');
        $this->assertGuest('web');

        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $user->id,
            'name' => 'Juan Dela Cruz',
            'phone' => '09171234567',
            'region' => 'National Capital Region',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'postal_code' => '1000',
            'address_line' => '123 Rizal Street, Ermita, Manila',
            'latitude' => 14.5832,
            'longitude' => 120.9822,
            'is_default' => true,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user->fresh(), 'user')
            ->getJson(route('user.addresses.index'))
            ->assertOk();
    }

    public function test_signed_verification_does_not_require_a_customer_session_or_create_one(): void
    {
        $user = User::factory()->unverified()->create([
            'status' => 'active',
        ]);
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['accountType' => 'user', 'id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->get($verificationUrl)
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertGuest('user');
        $this->assertGuest('web');
    }

    public function test_unverified_customer_login_uses_user_guard_for_verification_notice(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'unverified.customer@example.test',
            'status' => 'active',
        ]);

        $this->post('/user/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('verification.notice'));

        $this->assertGuest('user');
        $this->assertGuest('web');
        $this->get(route('verification.notice'))->assertOk();
    }

    public function test_unverified_customer_login_clears_an_existing_user_session(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'existing.customer@example.test',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $unverifiedUser = User::factory()->unverified()->create([
            'email' => 'unverified-session@example.test',
            'status' => 'active',
        ]);

        $this->actingAs($existingUser, 'user')
            ->post('/user/login', [
                'email' => $unverifiedUser->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('verification.notice'));

        $this->assertGuest('user');
    }

    public function test_registration_requires_a_complete_map_location(): void
    {
        Storage::fake('public');

        $this->post('/user/register', $this->payload([
            'address_region' => null,
            'address_province' => null,
            'address_city' => null,
            'address_barangay' => null,
            'address_latitude' => null,
            'address_longitude' => null,
        ]))->assertSessionHasErrors([
            'address_region',
            'address_province',
            'address_city',
            'address_barangay',
            'address_latitude',
            'address_longitude',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'juan.dela.cruz@gmail.com']);
    }

    public function test_registration_rejects_non_philippine_coordinates_inside_the_bounding_box(): void
    {
        Storage::fake('public');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '7.0',
            'lon' => '116.8',
            'address' => ['country_code' => 'my'],
        ])]);

        $this->post('/user/register', $this->payload([
            'address' => 'Kudat, Sabah, Malaysia',
            'address_region' => 'Sabah',
            'address_province' => 'Sabah',
            'address_city' => 'Kudat',
            'address_barangay' => 'Kudat',
            'address_latitude' => 7.0,
            'address_longitude' => 116.8,
        ]))->assertSessionHasErrors('address_latitude');

        $this->assertDatabaseMissing('users', ['email' => 'juan.dela.cruz@gmail.com']);
    }

    public function test_registration_uses_reverse_geocoded_shipping_fields_instead_of_client_values(): void
    {
        Storage::fake('public');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '14.5832',
            'lon' => '120.9822',
            'address' => [
                'country_code' => 'ph',
                'region' => 'National Capital Region',
                'state' => 'Metro Manila',
                'city' => 'Manila',
                'suburb' => 'Ermita',
                'postcode' => '1000',
            ]])]);

        $this->post('/user/register', $this->payload([
            'address_region' => 'Forged Region',
            'address_province' => 'Forged Province',
            'address_city' => 'Forged City',
            'address_barangay' => 'Forged Barangay',
        ]))->assertRedirect(route('verification.notice'));

        $this->assertDatabaseHas('user_addresses', [
            'region' => 'National Capital Region',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
        ]);
        $this->assertDatabaseMissing('user_addresses', ['city' => 'Forged City']);
    }

    public function test_rejected_screening_stops_before_account_creation_or_address_geocoding(): void
    {
        Storage::fake('local');
        Http::fake();

        $response = $this->post('/user/register', $this->payload([
            'email' => 'rejected-screening@example.test',
            'screening_metadata' => json_encode([
                'document_type' => 'national_id',
                'outcome' => 'reject_upload',
                'duplicate_kind' => 'none',
                'sides' => [
                    'front' => [
                        'side' => 'front',
                        'outcome' => 'reject_upload',
                        'detected_document_family' => null,
                        'detected_anchor_keys' => [],
                        'confidence_band' => 'low',
                        'qr_detected' => false,
                        'fingerprint' => 'meme',
                    ],
                    'back' => [
                        'side' => 'back',
                        'outcome' => 'reject_upload',
                        'detected_document_family' => null,
                        'detected_anchor_keys' => [],
                        'confidence_band' => 'low',
                        'qr_detected' => false,
                        'fingerprint' => 'meme-back',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]));

        $response->assertSessionHasErrors('valid_id');
        $this->assertDatabaseMissing('users', ['email' => 'rejected-screening@example.test']);
        $this->assertDatabaseCount('user_addresses', 0);
        $this->assertDatabaseCount('identity_verifications', 0);
        Http::assertNothingSent();
        Storage::disk('local')->assertDirectoryEmpty('valid_ids');
    }

    public function test_screening_error_stops_before_account_creation_and_can_be_retried(): void
    {
        Storage::fake('local');
        Http::fake();

        $response = $this->post('/user/register', $this->payload([
            'email' => 'screening-error@example.test',
            'screening_metadata' => json_encode([
                'document_type' => 'national_id',
                'outcome' => 'screening_error',
                'duplicate_kind' => 'none',
                'sides' => [
                    'front' => [
                        'side' => 'front',
                        'outcome' => 'screening_error',
                        'detected_document_family' => null,
                        'detected_anchor_keys' => [],
                        'confidence_band' => null,
                        'qr_detected' => false,
                        'fingerprint' => null,
                    ],
                    'back' => [
                        'side' => 'back',
                        'outcome' => 'screening_error',
                        'detected_document_family' => null,
                        'detected_anchor_keys' => [],
                        'confidence_band' => null,
                        'qr_detected' => false,
                        'fingerprint' => null,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]));

        $response->assertSessionHasErrors([
            'screening_metadata' => 'We couldn\'t check this image right now. Please try again or select another image.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'screening-error@example.test']);
        $this->assertDatabaseCount('identity_verifications', 0);
        Http::assertNothingSent();
        Storage::disk('local')->assertDirectoryEmpty('valid_ids');
    }

    public function test_duplicate_front_and_back_stops_before_account_creation(): void
    {
        Storage::fake('local');
        Http::fake();

        $response = $this->post('/user/register', $this->payload([
            'email' => 'duplicate-screening@example.test',
            'screening_metadata' => json_encode([
                'document_type' => 'national_id',
                'outcome' => 'reject_upload',
                'duplicate_kind' => 'exact',
                'sides' => [
                    'front' => [
                        'side' => 'front',
                        'outcome' => 'plausible',
                        'detected_document_family' => 'national_id',
                        'detected_anchor_keys' => ['philsys_document'],
                        'confidence_band' => 'high',
                        'qr_detected' => false,
                        'fingerprint' => 'same',
                    ],
                    'back' => [
                        'side' => 'back',
                        'outcome' => 'plausible',
                        'detected_document_family' => 'national_id',
                        'detected_anchor_keys' => ['philsys_back_structure'],
                        'confidence_band' => 'high',
                        'qr_detected' => false,
                        'fingerprint' => 'same',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]));

        $response->assertSessionHasErrors([
            'valid_id_back' => 'The front and back images appear to be the same. Please upload the back side of your ID.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'duplicate-screening@example.test']);
        $this->assertDatabaseCount('identity_verifications', 0);
        Http::assertNothingSent();
        Storage::disk('local')->assertDirectoryEmpty('valid_ids');
    }

    public function test_server_exact_duplicate_is_reported_as_an_upload_error(): void
    {
        Storage::fake('local');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '14.5832',
            'lon' => '120.9822',
            'address' => [
                'country_code' => 'ph',
                'region' => 'National Capital Region',
                'state' => 'Metro Manila',
                'city' => 'Manila',
                'suburb' => 'Ermita',
                'postcode' => '1000',
            ],
        ])]);

        $front = $this->validPng('front.png');
        $frontBytes = file_get_contents($front->getRealPath());
        $this->assertIsString($frontBytes);

        $response = $this->post('/user/register', $this->payload([
            'email' => 'server-duplicate@example.test',
            'valid_id' => $front,
            'valid_id_back' => UploadedFile::fake()->createWithContent('renamed-back.png', $frontBytes),
        ]));

        $response->assertSessionHasErrors([
            'valid_id_back' => 'The front and back images appear to be the same. Please upload the back side of your ID.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'server-duplicate@example.test']);
        $this->assertDatabaseCount('identity_verifications', 0);
        Storage::disk('local')->assertDirectoryEmpty('valid_ids');
    }

    public function test_inconsistent_screening_outcome_stops_before_account_creation(): void
    {
        Storage::fake('local');
        Http::fake();

        $response = $this->post('/user/register', $this->payload([
            'email' => 'inconsistent-screening@example.test',
            'screening_metadata' => json_encode([
                'document_type' => 'national_id',
                'outcome' => 'screening_passed',
                'duplicate_kind' => 'none',
                'sides' => [
                    'front' => [
                        'side' => 'front',
                        'outcome' => 'reject_upload',
                        'detected_document_family' => null,
                        'detected_anchor_keys' => [],
                        'confidence_band' => 'low',
                        'qr_detected' => false,
                        'fingerprint' => 'unrelated',
                    ],
                    'back' => [
                        'side' => 'back',
                        'outcome' => 'reject_upload',
                        'detected_document_family' => null,
                        'detected_anchor_keys' => [],
                        'confidence_band' => 'low',
                        'qr_detected' => false,
                        'fingerprint' => 'unrelated-back',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]));

        $response->assertSessionHasErrors([
            'screening_metadata' => 'The ID image check result is inconsistent. Please try again.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'inconsistent-screening@example.test']);
        $this->assertDatabaseCount('identity_verifications', 0);
        Http::assertNothingSent();
        Storage::disk('local')->assertDirectoryEmpty('valid_ids');
    }

    public function test_passport_registration_requires_only_the_biodata_image(): void
    {
        Notification::fake();
        Storage::fake('local');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '14.5832',
            'lon' => '120.9822',
            'address' => [
                'country_code' => 'ph',
                'region' => 'National Capital Region',
                'state' => 'Metro Manila',
                'city' => 'Manila',
                'suburb' => 'Ermita',
                'postcode' => '1000',
            ],
        ])]);

        $payload = $this->payload([
            'email' => 'passport-registration@example.test',
            'document_type' => 'passport',
            'screening_metadata' => json_encode([
                'document_type' => 'passport',
                'outcome' => 'screening_passed',
                'duplicate_kind' => 'none',
                'name_match' => true,
                'sides' => [
                    'biodata' => [
                        'side' => 'biodata',
                        'outcome' => 'plausible',
                        'detected_document_family' => 'passport',
                        'detected_anchor_keys' => ['passport_document', 'philippine_issuer', 'mrz_structure'],
                        'confidence_band' => 'high',
                        'qr_detected' => false,
                        'fingerprint' => 'passport',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        unset($payload['valid_id_back']);

        $this->post('/user/register', $payload)
            ->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'passport-registration@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id,
            'document_type' => 'passport',
            'back_file_path' => null,
        ]);
        $this->assertNotNull($user->valid_id_path);
        Storage::disk('local')->assertExists($user->valid_id_path);
    }

    public function test_digital_national_id_registration_requires_front_and_back_images(): void
    {
        Notification::fake();
        Storage::fake('local');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '14.5832',
            'lon' => '120.9822',
            'address' => [
                'country_code' => 'ph',
                'region' => 'National Capital Region',
                'state' => 'Metro Manila',
                'city' => 'Manila',
                'suburb' => 'Ermita',
                'postcode' => '1000',
            ],
        ])]);

        $payload = $this->payload([
            'email' => 'digital-national-id@example.test',
            'national_id_format' => 'digital_image',
            'screening_metadata' => json_encode([
                'document_type' => 'national_id',
                'national_id_format' => 'digital_image',
                'outcome' => 'screening_passed',
                'duplicate_kind' => 'none',
                'name_match' => true,
                'sides' => [
                    'front' => [
                        'side' => 'front',
                        'outcome' => 'plausible',
                        'detected_document_family' => 'national_id',
                        'detected_anchor_keys' => ['philsys_document', 'philippine_issuer', 'identity_fields'],
                        'confidence_band' => 'high',
                        'qr_detected' => false,
                        'fingerprint' => 'digital-front',
                    ],
                    'back' => [
                        'side' => 'back',
                        'outcome' => 'plausible',
                        'detected_document_family' => 'national_id',
                        'detected_anchor_keys' => ['philsys_back_structure'],
                        'confidence_band' => 'high',
                        'qr_detected' => true,
                        'fingerprint' => 'digital-back',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->post('/user/register', $payload)
            ->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'digital-national-id@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id,
            'document_type' => 'national_id',
        ]);
        Storage::disk('local')->assertExists($user->valid_id_path);

        $verification = $user->identityVerifications()->firstOrFail();
        $this->assertNotNull($verification->back_file_path);
        Storage::disk('local')->assertExists($verification->back_file_path);
    }

    public function test_paper_national_id_format_is_rejected_by_registration_validation(): void
    {
        $response = $this->post('/user/register', $this->payload([
            'email' => 'paper-national-id@example.test',
            'national_id_format' => 'paper_document',
        ]));

        $response->assertSessionHasErrors('national_id_format');
        $this->assertDatabaseMissing('users', [
            'email' => 'paper-national-id@example.test',
        ]);
    }

    public function test_umid_registration_accepts_the_front_image_only(): void
    {
        Notification::fake();
        Storage::fake('local');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '14.5832',
            'lon' => '120.9822',
            'address' => [
                'country_code' => 'ph',
                'region' => 'National Capital Region',
                'state' => 'Metro Manila',
                'city' => 'Manila',
                'suburb' => 'Ermita',
                'postcode' => '1000',
            ],
        ])]);

        $payload = $this->payload([
            'email' => 'umid-front-only@example.test',
            'document_type' => 'umid',
            'screening_metadata' => json_encode([
                'document_type' => 'umid',
                'outcome' => 'screening_passed',
                'duplicate_kind' => 'none',
                'name_match' => true,
                'sides' => [
                    'front' => [
                        'side' => 'front',
                        'outcome' => 'plausible',
                        'detected_document_family' => 'umid',
                        'detected_anchor_keys' => ['umid_document', 'philippine_issuer', 'identity_fields'],
                        'confidence_band' => 'high',
                        'qr_detected' => false,
                        'fingerprint' => 'umid-front',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        unset($payload['valid_id_back']);

        $this->post('/user/register', $payload)
            ->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'umid-front-only@example.test')->firstOrFail();
        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id,
            'document_type' => 'umid',
            'back_file_path' => null,
        ]);
    }
}
