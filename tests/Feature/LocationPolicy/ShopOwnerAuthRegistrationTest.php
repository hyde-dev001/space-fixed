<?php

namespace Tests\Feature\LocationPolicy;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\CaviteLocationPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopOwnerAuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const LAT_DASMARINAS = 14.3294;
    private const LNG_DASMARINAS = 120.9367;
    private const LAT_MAKATI = 14.5547;
    private const LNG_MAKATI = 121.0244;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'lat' => '14.3294',
                'lon' => '120.9367',
                'address' => [
                    'country_code' => 'ph',
                    'region' => 'Cavite',
                    'province' => 'Cavite',
                    'city' => 'Dasmarinas',
                    'suburb' => 'Salitran I',
                    'postcode' => '4114',
                ],
            ]),
        ]);
    }

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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'auth-register@solespaceph.com',
            'phone' => '09171234567',
            'suffix' => 'Jr.',
            'age' => 32,
            'address' => 'Blk 1 Lot 2, Salitran I, Dasmarinas, Cavite',
            'address_region' => 'Cavite',
            'address_province' => 'Cavite',
            'address_city' => 'Dasmarinas',
            'address_barangay' => 'Salitran I',
            'address_postal_code' => '4114',
            'address_latitude' => self::LAT_DASMARINAS,
            'address_longitude' => self::LNG_DASMARINAS,
            'business_name' => 'Juan Shoes & Repairs',
            'business_address' => 'Dasmariñas, Cavite',
            'business_type' => 'repair',
            'registration_type' => 'individual',
            'terms_accepted' => '1',
            'attendance_geofence_enabled' => true,
            'shop_latitude' => self::LAT_DASMARINAS,
            'shop_longitude' => self::LNG_DASMARINAS,
            'shop_address' => 'Dasmariñas, Cavite',
            'shop_geofence_radius' => 150,
            'business_registration_type' => 'dti_registration',
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
    public function it_registers_successfully_with_cavite_coordinates(): void
    {
        Storage::fake('public');
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $this->markRegistrationEmailVerified('auth-register@solespaceph.com');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload(),
            $this->docs()
        ));

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('shop_owners', [
            'email' => 'auth-register@solespaceph.com',
            'status' => 'pending',
            'suffix' => 'Jr.',
            'age' => 32,
            'address' => 'Blk 1 Lot 2, Salitran I, Dasmarinas, Cavite',
            'address_region' => 'Cavite',
            'address_province' => 'Cavite',
            'address_city' => 'Dasmarinas',
            'address_barangay' => 'Salitran I',
            'address_postal_code' => '4114',
        ]);
        $this->assertSame(4, ShopDocument::query()
            ->where('shop_owner_id', ShopOwner::query()->where('email', 'auth-register@solespaceph.com')->value('id'))
            ->where('version_number', 1)
            ->whereNull('is_current')
            ->count());
        $this->assertDatabaseHas('shop_documents', [
            'document_type' => 'dti_registration',
            'logical_slot' => 'business_registration',
            'version_number' => 1,
            'is_current' => null,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('notifications', [
            'super_admin_id' => $admin->id,
            'type' => NotificationType::SHOP_REGISTRATION_PENDING->value,
            'action_url' => '/admin/registrations?status=pending',
            'is_read' => false,
            'requires_action' => true,
        ]);
    }

    public function test_registration_requires_and_persists_shop_owner_terms_acceptance(): void
    {
        Storage::fake('public');
        $email = 'auth-terms-register@solespaceph.com';
        $this->markRegistrationEmailVerified($email);

        $this->postJson('/shop-owner/register', array_merge(
            $this->payload([
                'email' => $email,
                'terms_accepted' => '0',
            ]),
            $this->docs(),
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['terms_accepted']);

        $this->assertDatabaseMissing('shop_owners', ['email' => $email]);

        $acceptedEmail = 'auth-terms-register-accepted@solespaceph.com';
        $this->markRegistrationEmailVerified($acceptedEmail);

        $this->postJson('/shop-owner/register', array_merge(
            $this->payload([
                'email' => $acceptedEmail,
                'terms_accepted' => '1',
            ]),
            $this->docs(),
        ))->assertStatus(201);

        $this->assertDatabaseHas('shop_owners', [
            'email' => $acceptedEmail,
            'registration_terms_version' => 'customer-account-v1',
        ]);
        $this->assertNotNull(ShopOwner::query()
            ->where('email', $acceptedEmail)
            ->value('registration_terms_accepted_at'));
    }

    public function test_resubmission_requires_shop_owner_terms_acceptance(): void
    {
        Storage::fake('local');
        $owner = $this->rejectedOwnerWithDocuments();

        $this->withHeaders(['Accept' => 'application/json'])
            ->post($this->resubmissionUrl($owner), $this->payload([
                'email' => $owner->email,
                'terms_accepted' => '0',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['terms_accepted']);

        $this->assertDatabaseHas('shop_owners', [
            'id' => $owner->id,
            'status' => 'rejected',
            'resubmission_count' => 0,
        ]);
    }

    public function test_registration_requires_personal_age_and_verified_address_location(): void
    {
        Storage::fake('public');
        $email = 'auth-missing-personal-address@solespaceph.com';
        $this->markRegistrationEmailVerified($email);

        $payload = array_merge($this->payload(['email' => $email]), $this->docs());
        unset(
            $payload['age'],
            $payload['address'],
            $payload['address_region'],
            $payload['address_province'],
            $payload['address_city'],
            $payload['address_barangay'],
            $payload['address_latitude'],
            $payload['address_longitude'],
        );

        $this->postJson('/shop-owner/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'age',
                'address',
                'address_region',
                'address_province',
                'address_city',
                'address_barangay',
                'address_latitude',
                'address_longitude',
            ]);
    }

    public function test_rejected_resubmission_notifies_super_admins_once_when_it_returns_to_pending(): void
    {
        Storage::fake('local');
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $owner = $this->rejectedOwnerWithDocuments();

        $this->postResubmission($owner)->assertOk()->assertJsonPath('applied', true);

        $this->assertSame(1, Notification::query()
            ->where('super_admin_id', $admin->id)
            ->where('type', NotificationType::SHOP_REGISTRATION_PENDING->value)
            ->count());

        $this->postResubmission($owner)->assertOk()->assertJsonPath('applied', false);

        $this->assertSame(1, Notification::query()
            ->where('super_admin_id', $admin->id)
            ->where('type', NotificationType::SHOP_REGISTRATION_PENDING->value)
            ->count());
    }

    public function test_registration_rejects_an_ambiguous_business_registration_contract(): void
    {
        Storage::fake('local');
        $this->markRegistrationEmailVerified('auth-missing-business-registration@solespaceph.com');

        $payload = array_merge($this->payload([
            'email' => 'auth-missing-business-registration@solespaceph.com',
        ]), $this->docs());
        unset($payload['business_registration_type']);

        $this->postJson('/shop-owner/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_registration_type']);

        $this->assertDatabaseMissing('shop_owners', [
            'email' => 'auth-missing-business-registration@solespaceph.com',
        ]);
    }

    public function test_registration_persists_supporting_documents_under_stable_uuid_slots(): void
    {
        Storage::fake('local');
        $email = 'auth-supporting-document@solespaceph.com';
        $slotId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $this->markRegistrationEmailVerified($email);

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload(['email' => $email]),
            $this->docs(),
            [
                'other_documents' => [
                    $slotId => UploadedFile::fake()->createWithContent('lease.png', $this->pngBytes()),
                ],
                'other_document_metadata' => [
                    $slotId => [
                        'issued_on' => '2026-01-01',
                        'expiration_mode' => 'dated',
                        'expires_on' => '2028-01-01',
                    ],
                ],
            ],
        ));

        $response->assertStatus(201);

        $this->assertDatabaseHas('shop_documents', [
            'shop_owner_id' => ShopOwner::query()->where('email', $email)->value('id'),
            'document_type' => 'supporting_document',
            'logical_slot' => 'supporting_document:' . $slotId,
            'version_number' => 1,
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function it_blocks_registration_with_ncr_coordinates(): void
    {
        Storage::fake('public');
        $this->markRegistrationEmailVerified('auth-ncr@solespaceph.com');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload([
                'email' => 'auth-ncr@solespaceph.com',
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
            'email' => 'auth-ncr@solespaceph.com',
        ]);
    }

    /** @test */
    public function it_blocks_registration_without_coordinates_even_if_address_mentions_cavite(): void
    {
        Storage::fake('public');
        $this->markRegistrationEmailVerified('auth-no-coords@solespaceph.com');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload([
                'email' => 'auth-no-coords@solespaceph.com',
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
            'email' => 'auth-no-coords@solespaceph.com',
        ]);
    }

    /** @test */
    public function it_blocks_tampered_non_numeric_coordinate_payload(): void
    {
        Storage::fake('public');
        $this->markRegistrationEmailVerified('auth-tampered@solespaceph.com');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload([
                'email' => 'auth-tampered@solespaceph.com',
                'shop_latitude' => '14.30; DROP TABLE shop_owners; --',
                'shop_longitude' => '120.93',
            ]),
            $this->docs()
        ));

        $response->assertStatus(422);

        $this->assertDatabaseMissing('shop_owners', [
            'email' => 'auth-tampered@solespaceph.com',
        ]);
    }

    /** @test */
    public function ncr_coordinates_still_fail_even_with_cavite_address_text(): void
    {
        Storage::fake('public');
        $this->markRegistrationEmailVerified('auth-spoof-address@solespaceph.com');

        $response = $this->postJson('/shop-owner/register', array_merge(
            $this->payload([
                'email' => 'auth-spoof-address@solespaceph.com',
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

    public function test_rejected_resubmission_moves_to_pending_once_and_retries_idempotently(): void
    {
        Storage::fake('local');
        $owner = $this->rejectedOwnerWithDocuments();
        $signedUrl = $this->resubmissionUrl($owner);
        $payload = $this->payload([
            'email' => $owner->email,
            'business_name' => 'Updated Juan Shoes',
            'age' => 41,
            'address' => 'Updated personal address, Dasmarinas, Cavite',
        ]);

        $this->withHeaders(['Accept' => 'application/json'])
            ->post($signedUrl, $payload)
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertDatabaseHas('shop_owners', [
            'id' => $owner->id,
            'status' => 'pending',
            'resubmission_count' => 1,
            'rejection_reason' => null,
            'business_name' => 'Updated Juan Shoes',
            'age' => 41,
            'address' => 'Updated personal address, Dasmarinas, Cavite',
        ]);
        $this->assertSame(8, ShopDocument::query()->where('shop_owner_id', $owner->id)->count());
        $this->assertSame(4, ShopDocument::query()->where('shop_owner_id', $owner->id)->where('status', 'rejected')->count());
        $this->assertSame(4, ShopDocument::query()->where('shop_owner_id', $owner->id)->where('status', 'pending')->count());

        $this->withHeaders(['Accept' => 'application/json'])
            ->post($signedUrl, $payload)
            ->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('idempotent', true);

        $this->assertDatabaseHas('shop_owners', [
            'id' => $owner->id,
            'resubmission_count' => 1,
        ]);
        $this->assertSame(8, ShopDocument::query()->where('shop_owner_id', $owner->id)->count());
    }

    public function test_resubmission_fails_closed_for_missing_and_missing_on_disk_documents(): void
    {
        Storage::fake('local');

        $missing = $this->rejectedOwnerWithDocuments(['valid_id']);
        $this->postResubmission($missing)->assertStatus(422)->assertJsonValidationErrors(['mayors_permit']);

        $missingOnDisk = $this->rejectedOwnerWithDocuments([], ['valid_id' => false]);
        $this->postResubmission($missingOnDisk)->assertStatus(422)->assertJsonValidationErrors(['valid_id']);

        foreach ([$missing, $missingOnDisk] as $owner) {
            $this->assertDatabaseHas('shop_owners', [
                'id' => $owner->id,
                'status' => 'rejected',
                'resubmission_count' => 0,
            ]);
        }
    }

    public function test_resubmission_form_preserves_sec_and_lifecycle_supporting_document_identity(): void
    {
        Storage::fake('local');
        $owner = ShopOwner::factory()->rejected()->create([
            'rejection_reason' => 'Please provide clearer documents.',
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);
        $businessPath = "shop_documents/{$owner->id}/sec-registration.png";
        $supportingPath = "shop_documents/{$owner->id}/lease.png";
        Storage::disk('local')->put($businessPath, 'business');
        Storage::disk('local')->put($supportingPath, 'supporting');

        $business = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'sec_registration',
            'file_path' => $businessPath,
            'status' => 'rejected',
        ]);
        $business->forceFill([
            'disk' => 'local',
            'logical_slot' => 'business_registration',
            'version_number' => 1,
            'is_current' => null,
            'expiration_mode' => 'none',
        ])->save();

        $supporting = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'supporting_document',
            'file_path' => $supportingPath,
            'status' => 'rejected',
        ]);
        $supporting->forceFill([
            'disk' => 'local',
            'logical_slot' => 'supporting_document:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'version_number' => 1,
            'is_current' => null,
            'expiration_mode' => 'none',
        ])->save();

        $owner->documents()->create([
            'document_type' => 'supporting_document',
            'logical_slot' => 'supporting_document:bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'file_path' => $supportingPath,
            'disk' => 'local',
            'status' => 'withdrawn',
        ]);

        $url = URL::temporarySignedRoute(
            'shop-owner.resubmission.form',
            now()->addDay(),
            ['shopOwner' => $owner->id],
        );

        $this->get($url)
            ->assertInertia(fn (Assert $page) => $page
                ->component('UserSide/Auth/ShopOwnerRegistration')
                ->where('resubmission.documents.dti_registration.type', 'sec_registration')
                ->where('resubmission.documents.other_documents.0.type', 'supporting_document')
                ->where('resubmission.documents.other_documents.0.logical_slot', 'supporting_document:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
                ->has('resubmission.documents.other_documents', 1)
            );
    }

    public function test_resubmission_creates_immutable_versions_instead_of_updating_current_rows(): void
    {
        Storage::fake('local');
        $owner = $this->rejectedOwnerWithDocuments();
        $originalIds = ShopDocument::query()
            ->where('shop_owner_id', $owner->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $replacement = UploadedFile::fake()->createWithContent('new-valid-id.png', $this->pngBytes());
        $payload = array_merge($this->payload(['email' => $owner->email]), ['valid_id' => $replacement]);

        $this->withHeaders(['Accept' => 'application/json'])
            ->post($this->resubmissionUrl($owner), $payload)
            ->assertOk();

        $this->assertDatabaseHas('shop_owners', [
            'id' => $owner->id,
            'registration_terms_version' => 'customer-account-v1',
        ]);
        $this->assertNotNull($owner->fresh()->registration_terms_accepted_at);

        $currentIds = ShopDocument::query()
            ->where('shop_owner_id', $owner->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertCount(8, $currentIds);
        $this->assertSame($originalIds, array_slice($currentIds, 0, 4));
        $this->assertSame(
            ['rejected', 'rejected', 'rejected', 'rejected'],
            ShopDocument::query()->whereKey($originalIds)->orderBy('id')->pluck('status')->all(),
        );
    }

    public function test_resubmission_withdraws_selected_optional_document_without_deleting_its_history(): void
    {
        Storage::fake('local');
        $owner = $this->rejectedOwnerWithDocuments();
        $removedPath = "shop_documents/{$owner->id}/old-lease.png";
        $legacyPath = "shop_documents/{$owner->id}/legacy-photo.png";
        $keptPath = "shop_documents/{$owner->id}/old-permit-photo.png";
        Storage::disk('local')->put($removedPath, 'lease');
        Storage::disk('local')->put($legacyPath, 'legacy');
        Storage::disk('local')->put($keptPath, 'photo');

        $removed = $owner->documents()->create([
            'document_type' => 'supporting_document',
            'logical_slot' => 'supporting_document:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'file_path' => $removedPath,
            'disk' => 'local',
            'status' => 'rejected',
        ]);
        $legacy = $owner->documents()->create([
            'document_type' => 'other_supporting_document',
            'file_path' => $legacyPath,
            'disk' => 'local',
            'status' => 'rejected',
        ]);
        $kept = $owner->documents()->create([
            'document_type' => 'supporting_document',
            'logical_slot' => 'supporting_document:bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'file_path' => $keptPath,
            'disk' => 'local',
            'status' => 'rejected',
        ]);

        $this->withHeaders(['Accept' => 'application/json'])
            ->post($this->resubmissionUrl($owner), $this->payload([
                'email' => $owner->email,
                'removed_other_document_ids' => [$removed->id, $legacy->id],
            ]))
            ->assertOk();

        $this->assertDatabaseHas('shop_documents', ['id' => $removed->id, 'status' => 'withdrawn']);
        $this->assertDatabaseHas('shop_documents', ['id' => $legacy->id, 'status' => 'withdrawn']);
        $this->assertDatabaseHas('shop_documents', ['id' => $kept->id, 'status' => 'rejected']);
        Storage::disk('local')->assertExists($removedPath);
        Storage::disk('local')->assertExists($legacyPath);
        Storage::disk('local')->assertExists($keptPath);
    }

    public function test_resubmission_cannot_withdraw_required_or_another_owners_document(): void
    {
        Storage::fake('local');
        $owner = $this->rejectedOwnerWithDocuments();
        $otherOwner = $this->rejectedOwnerWithDocuments();
        $required = $owner->documents()->firstOrFail();
        $foreign = $otherOwner->documents()->create([
            'document_type' => 'supporting_document',
            'logical_slot' => 'supporting_document:cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'file_path' => 'shop_documents/foreign.png',
            'disk' => 'local',
            'status' => 'rejected',
        ]);

        foreach ([$required->id, $foreign->id] as $documentId) {
            $this->withHeaders(['Accept' => 'application/json'])
                ->post($this->resubmissionUrl($owner), $this->payload([
                    'email' => $owner->email,
                    'removed_other_document_ids' => [$documentId],
                ]))
                ->assertUnprocessable();
        }

        $this->assertSame('rejected', $required->fresh()->status);
        $this->assertSame('rejected', $foreign->fresh()->status);
        $this->assertSame('rejected', $owner->fresh()->status->value);
    }

    /** @param array<int, string> $types */
    private function rejectedOwnerWithDocuments(array $types = [], array $storedOverrides = []): ShopOwner
    {
        $owner = ShopOwner::factory()->rejected()->create([
            'rejection_reason' => 'Please provide clearer documents.',
            'resubmission_count' => 0,
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);
        $types = $types === []
            ? ['dti_registration', 'mayors_permit', 'bir_certificate', 'valid_id']
            : $types;

        foreach ($types as $type) {
            $path = "shop_documents/{$owner->id}/{$type}.png";
            if ($storedOverrides[$type] ?? true) {
                Storage::disk('local')->put($path, 'document');
            }

            $document = ShopDocument::create([
                'shop_owner_id' => $owner->id,
                'document_type' => $type,
                'file_path' => $path,
                'status' => 'rejected',
            ]);
            $document->forceFill(['disk' => 'local'])->save();
        }

        return $owner->fresh();
    }

    private function resubmissionUrl(ShopOwner $owner): string
    {
        return URL::temporarySignedRoute(
            'shop-owner.resubmission.submit',
            now()->addDay(),
            ['shopOwner' => $owner->id],
        );
    }

    private function postResubmission(ShopOwner $owner)
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->post($this->resubmissionUrl($owner), $this->payload(['email' => $owner->email]));
    }

    private function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    }
}
