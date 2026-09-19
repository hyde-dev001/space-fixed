<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\PrivilegedDeliveryType;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class ShopOwnerRegistrationApprovalEndToEndTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_real_registration_can_be_approved_while_pending_owner_guard_remains_in_session(): void
    {
        $email = 'approval-regression@solespaceph.com';
        $this->markRegistrationEmailVerified($email);

        $this->postJson('/shop-owner/register', $this->registrationPayload($email))
            ->assertCreated()
            ->assertJsonPath('success', true);

        $owner = ShopOwner::query()->where('email', $email)->firstOrFail();
        $this->assertAuthenticatedAs($owner, 'shop_owner');
        $this->assertSame('pending', $owner->status->value);
        $this->assertSame(4, $owner->documents()->count());

        $admin = SuperAdmin::factory()->admin()->create();
        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson(
                route('admin.registrations.approve', $owner),
                $this->approvalPayload($owner),
            );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('applied', true);
        DB::commit();

        $this->assertGuest('shop_owner');
        $this->assertAuthenticatedAs($admin, 'super_admin');
        $this->assertDatabaseHas('shop_owners', [
            'id' => $owner->id,
            'status' => 'approved',
            'rejection_reason' => null,
        ]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $owner->email]);
        $this->assertSame(4, ShopDocument::query()
            ->where('shop_owner_id', $owner->id)
            ->where('status', 'approved')
            ->where('is_current', true)
            ->where('reviewed_by_super_admin_id', $admin->id)
            ->whereNotNull('reviewed_at')
            ->count());
        $this->assertDatabaseHas('shop_owner_modules', [
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => 1,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'shop_registration_approved',
            'subject_type' => ShopOwner::class,
            'subject_id' => $owner->id,
        ]);
        Queue::assertPushed(
            SendPrivilegedWorkflowMail::class,
            fn (SendPrivilegedWorkflowMail $job): bool => $job->deliveryType === PrivilegedDeliveryType::SHOP_REGISTRATION_APPROVED
                && $job->recipientType === 'shop_owner'
                && $job->recipientId === $owner->id
                && array_key_exists('setup_token', $job->payload),
        );
    }

    /** @return array<string, mixed> */
    private function registrationPayload(string $email): array
    {
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );

        return [
            'first_name' => 'Approval',
            'last_name' => 'Regression',
            'email' => $email,
            'phone' => '09171234567',
            'business_name' => 'Approval Regression Shoes',
            'business_address' => 'Dasmarinas, Cavite',
            'business_type' => 'retail',
            'registration_type' => 'individual',
            'attendance_geofence_enabled' => true,
            'shop_latitude' => 14.3294,
            'shop_longitude' => 120.9367,
            'shop_address' => 'Dasmarinas, Cavite',
            'shop_geofence_radius' => 150,
            'business_registration_type' => 'dti_registration',
            'business_registration' => UploadedFile::fake()->createWithContent('dti.png', $png),
            'mayors_permit' => UploadedFile::fake()->createWithContent('permit.png', $png),
            'bir_certificate' => UploadedFile::fake()->createWithContent('bir.png', $png),
            'valid_id' => UploadedFile::fake()->createWithContent('id.png', $png),
            'document_metadata' => [
                'business_registration' => $this->documentMetadata(),
                'mayors_permit' => $this->documentMetadata('dated', '2027-01-01'),
                'bir_certificate' => $this->documentMetadata(),
                'valid_id' => $this->documentMetadata(),
            ],
        ];
    }

    /** @return array{issued_on: string, expiration_mode: string, expires_on: ?string} */
    private function documentMetadata(string $expirationMode = 'none', ?string $expiresOn = null): array
    {
        return [
            'issued_on' => '2026-01-01',
            'expiration_mode' => $expirationMode,
            'expires_on' => $expiresOn,
        ];
    }

    /** @return array{documents: array<int, array<string, int|string|bool|null>>} */
    private function approvalPayload(ShopOwner $owner): array
    {
        return [
            'documents' => $owner->documents()
                ->orderBy('id')
                ->get()
                ->map(static fn (ShopDocument $document): array => [
                    'id' => (int) $document->getKey(),
                    'document_type' => (string) $document->document_type,
                    'logical_slot' => (string) $document->logical_slot,
                    'version_number' => (int) $document->version_number,
                    'issued_on' => $document->issued_on?->toDateString(),
                    'expiration_mode' => (string) $document->expiration_mode,
                    'expires_on' => $document->expires_on?->toDateString(),
                    'viewed' => true,
                ])
                ->all(),
        ];
    }

    private function markRegistrationEmailVerified(string $email): void
    {
        Cache::put(
            'shop_owner_registration_email_otp:'.sha1(strtolower(trim($email))),
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
}
