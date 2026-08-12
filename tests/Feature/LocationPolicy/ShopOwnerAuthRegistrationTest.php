<?php

namespace Tests\Feature\LocationPolicy;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Services\CaviteLocationPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'auth-register@solespaceph.com',
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
        ]);
        $this->assertSame(4, ShopDocument::query()->where('shop_owner_id', $owner->id)->count());
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
        $this->assertSame(4, ShopDocument::query()->where('shop_owner_id', $owner->id)->count());
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

    public function test_phase_two_resubmission_updates_current_rows_until_phase_six_immutability(): void
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

        $currentIds = ShopDocument::query()
            ->where('shop_owner_id', $owner->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame($originalIds, $currentIds, 'Immutable document rows begin in Phase 6; Phase 2 updates the current row.');
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
