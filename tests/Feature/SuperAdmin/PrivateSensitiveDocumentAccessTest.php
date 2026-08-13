<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\PrivilegedAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Models\Activity as ActivityModel;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

class PrivateSensitiveDocumentAccessTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_disk_metadata_defaults_to_public_for_legacy_and_new_rows(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $document = ShopDocument::create([
            'shop_owner_id' => $shopOwner->id,
            'document_type' => 'mayors_permit',
            'file_path' => 'shop_documents/legacy-permit.pdf',
            'status' => 'pending',
        ]);
        $user = User::factory()->create([
            'valid_id_path' => 'valid-ids/legacy-id.jpg',
        ]);

        $this->assertSame('public', $document->fresh()->disk);
        $this->assertSame('public', $user->fresh()->valid_id_disk);
        $this->assertDatabaseHas('shop_documents', [
            'id' => $document->id,
            'disk' => 'public',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'valid_id_disk' => 'public',
        ]);
    }

    public function test_trusted_application_code_can_persist_private_disk_metadata(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $document = ShopDocument::create([
            'shop_owner_id' => $shopOwner->id,
            'document_type' => 'bir_certificate',
            'file_path' => 'shop_documents/private-bir.pdf',
            'status' => 'pending',
        ]);
        $document->disk = 'local';
        $document->save();

        $user = User::factory()->create([
            'valid_id_path' => 'valid-ids/private-id.jpg',
        ]);
        $user->valid_id_disk = 'local';
        $user->save();

        $this->assertSame('local', $document->fresh()->disk);
        $this->assertSame('local', $user->fresh()->valid_id_disk);
    }

    public function test_disk_metadata_hides_sensitive_storage_paths_from_model_serialization(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $document = ShopDocument::create([
            'shop_owner_id' => $shopOwner->id,
            'document_type' => 'valid_id',
            'file_path' => 'shop_documents/private-id.jpg',
            'status' => 'pending',
        ]);
        $user = User::factory()->create([
            'valid_id_path' => 'valid-ids/private-id.jpg',
        ]);

        $this->assertArrayNotHasKey('file_path', $document->toArray());
        $this->assertArrayNotHasKey('valid_id_path', $user->toArray());
    }

    public function test_canonical_shop_registration_ignores_client_disk_fields_and_stores_documents_locally(): void
    {
        $email = 'private-shop-registration@solespaceph.com';
        Cache::put('shop_owner_registration_email_otp:' . sha1($email), [
            'verified' => true,
            'verified_at' => now()->timestamp,
            'attempts' => 0,
            'expires_at' => now()->addHour()->timestamp,
            'otp_hash' => null,
        ], now()->addHour());

        $response = $this->postJson('/shop-owner/register', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => $email,
            'phone' => '09171234567',
            'business_name' => 'Juan Shoes',
            'business_address' => 'Dasmarinas, Cavite',
            'business_type' => 'repair',
            'registration_type' => 'individual',
            'shop_latitude' => 14.3294,
            'shop_longitude' => 120.9367,
            'disk' => 'public',
            'valid_id_disk' => 'public',
            'business_registration' => $this->uploadedPng('dti.png'),
            'business_registration_type' => 'dti_registration',
            'mayors_permit' => $this->uploadedPng('permit.png'),
            'bir_certificate' => $this->uploadedPng('bir.png'),
            'valid_id' => $this->uploadedPng('id.png'),
            'document_metadata' => [
                'business_registration' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
                'mayors_permit' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'dated', 'expires_on' => '2027-01-01'],
                'bir_certificate' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
                'valid_id' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
            ],
        ]);

        $response->assertCreated();

        $owner = ShopOwner::query()->where('email', $email)->firstOrFail();
        $documents = $owner->documents()->get();

        $this->assertCount(4, $documents);
        foreach ($documents as $document) {
            $this->assertSame('local', $document->disk);
            Storage::disk('local')->assertExists($document->file_path);
            Storage::disk('public')->assertMissing($document->file_path);
        }
    }

    public function test_customer_registration_ignores_client_disk_fields_and_stores_valid_id_locally(): void
    {
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

        $response = $this->post('/user/register', [
            'first_name' => 'Juan',
            'last_name' => 'Customer',
            'email' => 'private-customer-registration@solespaceph.com',
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
            'valid_id_disk' => 'public',
            'valid_id' => $this->uploadedPng('customer-id.png'),
        ]);

        $response->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'private-customer-registration@solespaceph.com')->firstOrFail();
        $this->assertSame('local', $user->valid_id_disk);
        Storage::disk('local')->assertExists($user->valid_id_path);
        Storage::disk('public')->assertMissing($user->valid_id_path);
    }

    public function test_rejected_resubmission_creates_a_new_version_and_retains_historical_files(): void
    {
        $owner = ShopOwner::factory()->rejected()->create([
            'business_type' => 'retail',
            'registration_type' => 'individual',
            'shop_latitude' => 14.3294,
            'shop_longitude' => 120.9367,
        ]);
        $oldPath = 'shop_documents/old-dti.png';
        Storage::disk('public')->put($oldPath, 'old-dti');
        $oldDocument = $this->createDocument($owner, 'dti_registration', 'public', $oldPath, 'old-dti', true, 'rejected');
        $this->createDocument($owner, 'mayors_permit', 'local', 'shop_documents/old-permit.png', null, true, 'rejected');
        $this->createDocument($owner, 'bir_certificate', 'local', 'shop_documents/old-bir.png', null, true, 'rejected');
        $this->createDocument($owner, 'valid_id', 'local', 'shop_documents/old-id.png', null, true, 'rejected');

        $url = URL::temporarySignedRoute(
            'shop-owner.resubmission.submit',
            now()->addDay(),
            ['shopOwner' => $owner->id],
        );

        $response = $this->post($url, [
            'first_name' => $owner->first_name,
            'last_name' => $owner->last_name,
            'phone' => '09171234567',
            'business_name' => $owner->business_name,
            'business_address' => 'Dasmarinas, Cavite',
            'business_type' => 'retail',
            'registration_type' => 'individual',
            'shop_latitude' => 14.3294,
            'shop_longitude' => 120.9367,
            'shop_address' => 'Dasmarinas, Cavite',
            'business_registration' => $this->uploadedPng('replacement-dti.png'),
            'business_registration_type' => 'dti_registration',
            'document_metadata' => [
                'business_registration' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
                'mayors_permit' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'dated', 'expires_on' => '2027-01-01'],
                'bir_certificate' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
                'valid_id' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
            ],
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('success', true);

        $replacement = $owner->documents()->where('document_type', 'dti_registration')->latest('id')->firstOrFail();
        $this->assertNotSame($oldDocument->file_path, $replacement->file_path);
        $this->assertSame('local', $replacement->disk);
        Storage::disk('local')->assertExists($replacement->file_path);
        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame('rejected', $oldDocument->fresh()->status);
        $this->assertSame($oldDocument->id, $replacement->predecessor_document_id);
        $this->assertSame('pending', $owner->fresh()->status->value);
    }

    public function test_resubmission_database_failure_preserves_old_reference_and_file_and_removes_only_new_orphan(): void
    {
        $owner = ShopOwner::factory()->rejected()->create([
            'business_type' => 'retail',
            'registration_type' => 'individual',
            'shop_latitude' => 14.3294,
            'shop_longitude' => 120.9367,
        ]);
        $oldPath = 'shop_documents/old-dti.png';
        Storage::disk('public')->put($oldPath, 'old-dti');
        $oldDocument = $this->createDocument($owner, 'dti_registration', 'public', $oldPath, 'old-dti', true, 'rejected');
        $this->createDocument($owner, 'mayors_permit', 'local', 'shop_documents/old-permit.png', null, true, 'rejected');
        $this->createDocument($owner, 'bir_certificate', 'local', 'shop_documents/old-bir.png', null, true, 'rejected');
        $this->createDocument($owner, 'valid_id', 'local', 'shop_documents/old-id.png', null, true, 'rejected');

        ShopOwner::updating(static function (): void {
            throw new \RuntimeException('database mutation failed');
        });

        $url = URL::temporarySignedRoute(
            'shop-owner.resubmission.submit',
            now()->addDay(),
            ['shopOwner' => $owner->id],
        );

        $response = $this->post($url, [
            'first_name' => $owner->first_name,
            'last_name' => $owner->last_name,
            'phone' => '09171234567',
            'business_name' => $owner->business_name,
            'business_address' => 'Dasmarinas, Cavite',
            'business_type' => 'retail',
            'registration_type' => 'individual',
            'shop_latitude' => 14.3294,
            'shop_longitude' => 120.9367,
            'shop_address' => 'Dasmarinas, Cavite',
            'business_registration' => $this->uploadedPng('replacement-dti.png'),
            'business_registration_type' => 'dti_registration',
            'document_metadata' => [
                'business_registration' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
                'mayors_permit' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'dated', 'expires_on' => '2027-01-01'],
                'bir_certificate' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
                'valid_id' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
            ],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(500);
        $freshDocument = $oldDocument->fresh();
        $this->assertSame($oldPath, $freshDocument->file_path);
        $this->assertSame('public', $freshDocument->disk);
        $this->assertSame('rejected', $owner->fresh()->status->value);
        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame([
            'shop_documents/old-bir.png',
            'shop_documents/old-id.png',
            'shop_documents/old-permit.png',
        ], Storage::disk('local')->allFiles());
    }

    public function test_private_document_access_applies_status_ownership_and_actor_rules(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $document = $this->createDocument($owner, 'valid_id', 'local', 'shop_documents/document.png', null, true, 'approved');
        $admin = SuperAdmin::factory()->admin()->create();
        $superAdmin = SuperAdmin::factory()->superAdmin()->create();

        $this->get(route('admin.shop-documents.show', [$owner, $document]))->assertRedirect(route('admin.login'));

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$owner, $document]))
            ->assertNotFound();

        $superAdminResponse = $this->actingAsCompletedPrivileged($superAdmin)
            ->get(route('admin.shop-documents.show', [$owner, $document]));
        $superAdminResponse->assertOk();

        $owner->update(['status' => 'pending']);
        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$owner, $document]))
            ->assertOk();

        $owner->update(['status' => 'rejected']);
        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$owner, $document]))
            ->assertOk();

        $otherOwner = ShopOwner::factory()->pending()->create();
        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$otherOwner, $document]))
            ->assertNotFound();

        $customer = User::factory()->create();
        $customer->valid_id_path = 'valid_ids/customer.png';
        $customer->valid_id_disk = 'local';
        $customer->save();
        Storage::disk('local')->put($customer->valid_id_path, $this->pngBytes());

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.users.valid-id.show', $customer))
            ->assertOk();
        $this->actingAsCompletedPrivileged($superAdmin)
            ->get(route('admin.users.valid-id.show', $customer))
            ->assertOk();
    }

    public function test_suspended_privileged_actor_cannot_reach_private_documents_or_create_access_audit(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $document = $this->createDocument($owner, 'valid_id', 'local', 'shop_documents/suspended-admin.png');
        $admin = SuperAdmin::factory()->admin()->suspended()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$owner, $document]))
            ->assertRedirect(route('admin.login'));

        $this->assertSame(0, ActivityModel::query()->where('event', 'document_access_initiated')->count());
    }

    public function test_invalid_private_paths_and_missing_customer_files_fail_closed_without_audit(): void
    {
        $owner = ShopOwner::factory()->pending()->create();
        $document = $this->createDocument(
            $owner,
            'valid_id',
            'local',
            '../outside-private-document.png',
            null,
            false,
        );
        $customer = User::factory()->create([
            'valid_id_path' => 'valid_ids/missing-customer-id.png',
            'valid_id_disk' => 'local',
        ]);
        $admin = SuperAdmin::factory()->admin()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$owner, $document]))
            ->assertNotFound();

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.users.valid-id.show', $customer))
            ->assertNotFound();

        $this->assertSame(0, ActivityModel::query()->where('event', 'document_access_initiated')->count());
    }

    public function test_range_requests_keep_private_download_headers_and_do_not_bypass_content_inspection(): void
    {
        $owner = ShopOwner::factory()->pending()->create();
        $document = $this->createDocument($owner, 'valid_id', 'local', 'shop_documents/range.png');
        $admin = SuperAdmin::factory()->admin()->create();

        $response = $this->actingAsCompletedPrivileged($admin)
            ->withHeader('Range', 'bytes=0-1')
            ->get(route('admin.shop-documents.show', [$owner, $document]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertNull($response->headers->get('Content-Range'));
        $this->assertSame($this->pngBytes(), $response->getContent());
    }

    public function test_shop_owner_and_signed_resubmission_access_are_scoped_and_audited(): void
    {
        $owner = ShopOwner::factory()->rejected()->create();
        $document = $this->createDocument($owner, 'valid_id');
        $otherOwner = ShopOwner::factory()->rejected()->create();
        $shopOwnerUrl = route('shop-owner.documents.show', [$owner, $document]);

        $this->actingAs($owner, 'shop_owner')->get($shopOwnerUrl)->assertOk();
        $this->actingAs($otherOwner, 'shop_owner')->get($shopOwnerUrl)->assertNotFound();

        $signedUrl = URL::temporarySignedRoute(
            'shop-owner.resubmission.document',
            now()->addDay(),
            ['shopOwner' => $owner->id, 'document' => $document->id],
        );
        $this->get($signedUrl)->assertOk();
        $this->get($signedUrl.'&tampered=1')->assertForbidden();

        $expiredUrl = URL::temporarySignedRoute(
            'shop-owner.resubmission.document',
            now()->subMinute(),
            ['shopOwner' => $owner->id, 'document' => $document->id],
        );
        $this->get($expiredUrl)->assertForbidden();

        $events = ActivityModel::query()->whereIn('event', [
            'shop_owner_document_access_initiated',
            'signed_resubmission_document_access_initiated',
        ])->count();
        $this->assertSame(2, $events);
        $signedActivity = ActivityModel::query()
            ->where('event', 'signed_resubmission_document_access_initiated')
            ->latest('id')
            ->firstOrFail();
        $this->assertArrayNotHasKey('token', $signedActivity->properties->toArray());
        $this->assertArrayNotHasKey('signature', $signedActivity->properties->toArray());
        $this->assertArrayNotHasKey('expires', $signedActivity->properties->toArray());
    }

    public function test_missing_file_does_not_create_a_privileged_access_audit(): void
    {
        $owner = ShopOwner::factory()->pending()->create();
        $document = $this->createDocument($owner, 'pending', 'local', 'missing.png', null, false);
        $admin = SuperAdmin::factory()->admin()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$owner, $document]))
            ->assertNotFound();

        $this->assertSame(0, ActivityModel::query()->where('event', 'document_access_initiated')->count());
    }

    public function test_safe_and_unsafe_documents_use_server_inspected_content_headers(): void
    {
        $owner = ShopOwner::factory()->pending()->create();
        $admin = SuperAdmin::factory()->admin()->create();
        $safe = $this->createDocument($owner, 'valid_id', 'local', 'client-name.png', $this->pngBytes());
        $unsafe = $this->createDocument($owner, 'valid_id', 'local', 'unsafe.jpg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $safeResponse = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$owner, $safe]));
        $safeResponse->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline;', (string) $safeResponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString("valid_id-{$safe->id}.png", (string) $safeResponse->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('client-name', (string) $safeResponse->headers->get('Content-Disposition'));

        $unsafeResponse = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$owner, $unsafe]));
        $unsafeResponse->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringContainsString('attachment;', (string) $unsafeResponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString("valid_id-{$unsafe->id}.bin", (string) $unsafeResponse->headers->get('Content-Disposition'));
    }

    public function test_privileged_audit_failure_prevents_document_bytes_from_being_returned(): void
    {
        $owner = ShopOwner::factory()->pending()->create();
        $document = $this->createDocument($owner, 'valid_id');
        $admin = SuperAdmin::factory()->admin()->create();

        $this->mock(PrivilegedAudit::class, function (MockInterface $mock): void {
            $mock->shouldReceive('documentAccessInitiated')->once()->andThrow(new \RuntimeException('audit unavailable'));
        });

        $response = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$owner, $document]));

        $response->assertStatus(500);
        $this->assertStringNotContainsString($this->pngBytes(), $response->getContent());
    }

    public function test_shop_owner_activity_failure_prevents_document_bytes_from_being_returned(): void
    {
        $owner = ShopOwner::factory()->rejected()->create();
        $document = $this->createDocument($owner, 'valid_id');
        $this->app->bind(ActivityLogger::class, fn (): never => throw new \RuntimeException('audit unavailable'));

        $response = $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.documents.show', [$owner, $document]));

        $response->assertStatus(500);
        $this->assertStringNotContainsString($this->pngBytes(), $response->getContent());
    }

    public function test_signed_resubmission_activity_failure_prevents_document_bytes_from_being_returned(): void
    {
        $owner = ShopOwner::factory()->rejected()->create();
        $document = $this->createDocument($owner, 'valid_id');
        $this->app->bind(ActivityLogger::class, fn (): never => throw new \RuntimeException('audit unavailable'));
        $signedUrl = URL::temporarySignedRoute(
            'shop-owner.resubmission.document',
            now()->addDay(),
            ['shopOwner' => $owner->id, 'document' => $document->id],
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(500);
        $this->assertStringNotContainsString($this->pngBytes(), $response->getContent());
    }

    public function test_sensitive_payloads_use_route_urls_without_raw_storage_paths(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $owner = ShopOwner::factory()->pending()->create();
        $document = $this->createDocument($owner, 'valid_id');
        $customer = User::factory()->create([
            'valid_id_path' => 'valid_ids/customer.png',
        ]);
        $customer->valid_id_disk = 'local';
        $customer->save();

        $registrationResponse = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.registrations.index'));
        $registrationResponse->assertInertia(fn (Assert $page) => $page
            ->where('registrations.data.0.documents.0.url', route('admin.shop-documents.show', [$owner, $document]))
            ->missing('registrations.data.0.documents.0.file_path'));
        $this->assertStringNotContainsString('/storage/', $registrationResponse->getContent());
        $this->assertStringNotContainsString($document->file_path, $registrationResponse->getContent());

        $detailsResponse = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shops.show', ['shopOwner' => $owner->id]));
        $detailsResponse->assertOk()
            ->assertJsonPath('shop.documentUrls.0', route('admin.shop-documents.show', [$owner, $document]));
        $this->assertStringNotContainsString($document->file_path, $detailsResponse->getContent());

        $userManagementResponse = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.users.index'));
        $userManagementResponse->assertInertia(fn (Assert $page) => $page
            ->where('users.data.0.validIdUrl', route('admin.users.valid-id.show', $customer))
            ->missing('users.data.0.validIdPath'));

        $legacyUserManagementResponse = $this->actingAsCompletedPrivileged($admin)
            ->get(route('superAdmin.super-admin-user-management'));
        $legacyUserManagementResponse->assertRedirect(route('admin.users.index'));
        $this->assertStringNotContainsString('valid_ids/customer.png', $userManagementResponse->getContent());
        $this->assertStringNotContainsString('valid_ids/customer.png', $legacyUserManagementResponse->getContent());
    }

    public function test_owner_resubmission_payload_uses_expiring_document_routes(): void
    {
        $owner = ShopOwner::factory()->rejected()->create();
        $document = $this->createDocument($owner, 'valid_id');
        $signedUrl = URL::temporarySignedRoute(
            'shop-owner.resubmission.form',
            now()->addDay(),
            ['shopOwner' => $owner->id],
        );

        $response = $this->get($signedUrl);

        $response->assertInertia(fn (Assert $page) => $page
            ->where('resubmission.documents.valid_id.url', fn (string $url): bool => str_contains($url, route('shop-owner.resubmission.document', [$owner, $document])))
            ->missing('resubmission.documents.valid_id.file_path'));
        $this->assertStringNotContainsString('/storage/', $response->getContent());
        $this->assertStringNotContainsString($document->file_path, $response->getContent());
    }

    private function uploadedPng(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngBytes());
    }

    private function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    }

    private function createDocument(
        ShopOwner $owner,
        string $type = 'valid_id',
        string $disk = 'local',
        string $path = 'shop_documents/document.png',
        ?string $bytes = null,
        bool $store = true,
        string $status = 'pending',
    ): ShopDocument {
        if ($store) {
            Storage::disk($disk)->put($path, $bytes ?? $this->pngBytes());
        }

        $document = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => $type,
            'file_path' => $path,
            'status' => $status,
        ]);
        $document->disk = $disk;
        $document->save();

        return $document->fresh();
    }
}
