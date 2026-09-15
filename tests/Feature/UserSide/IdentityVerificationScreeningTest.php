<?php

namespace Tests\Feature\UserSide;

use App\Exceptions\IdentityDocumentScreeningException;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class IdentityVerificationScreeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_screened_card_submission_is_stored_privately_and_queued_for_review(): void
    {
        Storage::fake('local');

        $user = $this->customer();
        $verification = app(IdentityVerificationService::class)->screen(
            $user,
            $this->validPng('front.png', 'front'),
            $this->passedMetadata('drivers_license'),
            $this->validPng('back.png', 'back'),
        );

        $this->assertSame(IdentityVerification::SCREENING_AUTOMATED_CHECK_PASSED, $verification->screening_status);
        $this->assertSame(IdentityVerification::REVIEW_PENDING, $verification->review_status);
        $this->assertSame(User::IDENTITY_PENDING_REVIEW, $user->fresh()->identity_verification_status);
        $this->assertSame('drivers_license', $verification->document_type);
        $this->assertNull($verification->failure_reason);
        $this->assertSame($verification->file_path, $user->fresh()->valid_id_path);
        Storage::disk('local')->assertExists($verification->file_path);
        Storage::disk('local')->assertExists($verification->back_file_path);
        $this->assertDatabaseCount('identity_verifications', 1);
    }

    public function test_name_read_failure_is_stored_for_manual_review_instead_of_blocking_account_flow(): void
    {
        Storage::fake('local');

        $user = $this->customer();
        $verification = app(IdentityVerificationService::class)->screen(
            $user,
            $this->validPng('front.png', 'front'),
            array_merge($this->passedMetadata('drivers_license'), [
                'outcome' => 'manual_review_required',
                'name_match' => false,
            ]),
            $this->validPng('back.png', 'back'),
        );

        $this->assertSame(IdentityVerification::SCREENING_MANUAL_REVIEW_REQUIRED, $verification->screening_status);
        $this->assertSame(IdentityVerification::REVIEW_PENDING, $verification->review_status);
        $this->assertSame('name_unreadable_or_mismatch', $verification->failure_reason);
        $this->assertSame(User::IDENTITY_PENDING_REVIEW, $user->fresh()->identity_verification_status);
    }

    public function test_server_rejects_exact_duplicate_bytes_before_storage_regardless_of_filename(): void
    {
        Storage::fake('local');

        $user = $this->customer();
        $front = $this->validPng('front.png', 'same');
        $back = $this->validPng('renamed-back.png', 'same');

        $this->expectException(IdentityDocumentScreeningException::class);

        try {
            app(IdentityVerificationService::class)->screen(
                $user,
                $front,
                $this->passedMetadata('drivers_license'),
                $back,
            );
        } finally {
            $this->assertDatabaseCount('identity_verifications', 0);
            Storage::disk('local')->assertDirectoryEmpty('valid_ids');
        }
    }

    public function test_rejected_submission_does_not_create_a_record_or_store_files(): void
    {
        Storage::fake('local');

        $this->expectException(InvalidArgumentException::class);

        try {
            app(IdentityVerificationService::class)->screen(
                $this->customer(),
                $this->validPng('meme.png'),
                [
                    'document_type' => 'national_id',
                    'duplicate_kind' => 'none',
                    'sides' => [
                        'front' => $this->side('front', 'national_id', 'reject_upload', []),
                        'back' => $this->side('back', 'national_id', 'reject_upload', []),
                    ],
                ],
                $this->validPng('back.png', 'back'),
            );
        } finally {
            $this->assertDatabaseCount('identity_verifications', 0);
            Storage::disk('local')->assertDirectoryEmpty('valid_ids');
        }
    }

    public function test_screening_error_is_retryable_and_does_not_create_a_record_or_store_files(): void
    {
        Storage::fake('local');

        $this->expectException(IdentityDocumentScreeningException::class);

        try {
            app(IdentityVerificationService::class)->screen(
                $this->customer(),
                $this->validPng('unavailable.png'),
                [
                    'document_type' => 'passport',
                    'duplicate_kind' => 'none',
                    'sides' => [
                        'biodata' => $this->side('biodata', 'passport', 'screening_error', []),
                    ],
                ],
            );
        } finally {
            $this->assertDatabaseCount('identity_verifications', 0);
            Storage::disk('local')->assertDirectoryEmpty('valid_ids');
        }
    }

    public function test_passport_stores_one_private_biodata_file_without_a_back_image(): void
    {
        Storage::fake('local');

        $verification = app(IdentityVerificationService::class)->screen(
            $this->customer(),
            $this->validPng('passport.png', 'biodata'),
            $this->passedMetadata('passport'),
        );

        $this->assertSame('passport', $verification->document_type);
        $this->assertNull($verification->back_file_path);
        $this->assertNull($verification->back_file_disk);
        Storage::disk('local')->assertExists($verification->file_path);
    }

    public function test_digital_national_id_stores_private_front_and_back_images(): void
    {
        Storage::fake('local');

        $verification = app(IdentityVerificationService::class)->screen(
            $this->customer(),
            $this->validPng('digital-national-id-front.png', 'digital-front'),
            array_merge($this->passedMetadata('national_id'), [
                'national_id_format' => 'digital_image',
            ]),
            $this->validPng('digital-national-id-back.png', 'digital-back'),
        );

        $this->assertSame('national_id', $verification->document_type);
        $this->assertSame(IdentityVerification::SCREENING_AUTOMATED_CHECK_PASSED, $verification->screening_status);
        $this->assertNotNull($verification->back_file_path);
        $this->assertSame('local', $verification->back_file_disk);
        Storage::disk('local')->assertExists($verification->file_path);
        Storage::disk('local')->assertExists($verification->back_file_path);
    }

    public function test_passport_back_image_is_rejected_before_storage(): void
    {
        Storage::fake('local');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('back image is not used');

        try {
            app(IdentityVerificationService::class)->screen(
                $this->customer(),
                $this->validPng('passport.png', 'biodata'),
                $this->passedMetadata('passport'),
                $this->validPng('back.png', 'back'),
            );
        } finally {
            $this->assertDatabaseCount('identity_verifications', 0);
            Storage::disk('local')->assertDirectoryEmpty('valid_ids');
        }
    }

    public function test_only_customer_users_can_store_identity_evidence(): void
    {
        Storage::fake('local');

        $employee = User::factory()->unverified()->create([
            'shop_owner_id' => 123,
            'role' => 'STAFF',
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(IdentityVerificationService::class)->screen(
            $employee,
            $this->validPng('employee-id.png', 'employee'),
            $this->passedMetadata('national_id'),
            $this->validPng('employee-id-back.png', 'employee-back'),
        );

        $this->assertDatabaseCount('identity_verifications', 0);
    }

    public function test_raw_ocr_metadata_is_not_persisted_or_logged(): void
    {
        Storage::fake('local');
        Log::spy();

        $verification = app(IdentityVerificationService::class)->screen(
            $this->customer(),
            $this->validPng('front.png', 'front'),
            array_merge($this->passedMetadata('national_id'), [
                'ocr_text' => 'SENSITIVE NAME 1990-01-01 ID-123456789',
            ]),
            $this->validPng('back.png', 'back'),
        );

        $this->assertArrayNotHasKey('ocr_text', $verification->toArray());
        $this->assertArrayNotHasKey('ocr_text', $verification->getAttributes());
        Log::assertNotLogged('info', fn (string $message, array $context): bool => str_contains($message, 'SENSITIVE NAME') || str_contains(json_encode($context), 'SENSITIVE NAME'));
    }

    public function test_rejected_customer_can_resubmit_a_new_pending_version(): void
    {
        Storage::fake('local');

        $user = $this->customer();
        $user->forceFill(['email_verified_at' => now()])->save();
        $previous = app(IdentityVerificationService::class)->screen(
            $user,
            $this->validPng('previous-front.png', 'previous-front'),
            $this->passedMetadata('drivers_license'),
            $this->validPng('previous-back.png', 'previous-back'),
        );
        $previous->update([
            'review_status' => IdentityVerification::REVIEW_REJECTED,
            'rejection_reason' => 'id_unreadable',
        ]);
        $user->forceFill(['identity_verification_status' => User::IDENTITY_REJECTED])->save();

        $response = $this->actingAs($user, 'user')
            ->withHeader('Accept', 'application/json')
            ->post(route('customer.identity-verifications.resubmit'), [
                'valid_id' => $this->validPng('replacement-front.png', 'replacement-front'),
                'valid_id_back' => $this->validPng('replacement-back.png', 'replacement-back'),
                'document_type' => 'drivers_license',
                'national_id_format' => 'physical_card',
                'screening_metadata' => json_encode($this->passedMetadata('drivers_license'), JSON_THROW_ON_ERROR),
            ]);

        $response->assertOk()
            ->assertJsonPath('identity_verification.review_status', IdentityVerification::REVIEW_PENDING)
            ->assertJsonPath('identity_verification.rejection_reason', null);

        $replacement = IdentityVerification::query()->latest('id')->firstOrFail();
        $this->assertNotSame($previous->id, $replacement->id);
        $this->assertSame($previous->id, $replacement->supersedes_verification_id);
        $this->assertSame(User::IDENTITY_PENDING_REVIEW, $user->fresh()->identity_verification_status);
        $this->assertDatabaseCount('identity_verifications', 2);
        Storage::disk('local')->assertExists($replacement->file_path);
        Storage::disk('local')->assertExists($replacement->back_file_path);
    }

    public function test_customer_profile_keeps_approved_id_visible_without_resubmission(): void
    {
        Storage::fake('local');

        $user = $this->customer();
        $user->forceFill(['email_verified_at' => now()])->save();
        $verification = app(IdentityVerificationService::class)->screen(
            $user,
            $this->validPng('approved-front.png', 'approved-front'),
            $this->passedMetadata('drivers_license'),
            $this->validPng('approved-back.png', 'approved-back'),
        );
        $verification->update([
            'review_status' => IdentityVerification::REVIEW_APPROVED,
            'reviewed_at' => now(),
        ]);
        $user->forceFill(['identity_verification_status' => User::IDENTITY_APPROVED])->save();

        $this->actingAs($user, 'user')
            ->get(route('customer-profile'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('UserSide/Profile/customerProfile', false)
                ->where('identity_verification.status', User::IDENTITY_APPROVED)
                ->where('identity_verification.current.id', $verification->id)
                ->where('identity_verification.current.review_status', IdentityVerification::REVIEW_APPROVED)
                ->where('identity_verification.can_resubmit', false)
                ->where('identity_verification.history.0.id', $verification->id));
    }

    public function test_customer_can_view_only_their_own_identity_evidence(): void
    {
        Storage::fake('local');

        $user = $this->customer();
        $user->forceFill(['email_verified_at' => now()])->save();
        $otherUser = $this->customer();
        $otherUser->forceFill(['email_verified_at' => now()])->save();

        $ownVerification = app(IdentityVerificationService::class)->screen(
            $user,
            $this->validPng('own-front.png', 'own-front'),
            $this->passedMetadata('passport'),
        );
        $otherVerification = app(IdentityVerificationService::class)->screen(
            $otherUser,
            $this->validPng('other-front.png', 'other-front'),
            $this->passedMetadata('passport'),
        );

        $this->actingAs($user, 'user')
            ->get(route('customer.identity-verifications.front', $ownVerification))
            ->assertOk();

        $this->actingAs($user, 'user')
            ->get(route('customer.identity-verifications.front', $otherVerification))
            ->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function passedMetadata(string $documentType): array
    {
        $slots = match ($documentType) {
            'passport' => ['biodata'],
            'umid' => ['front'],
            default => ['front', 'back'],
        };

        return [
            'document_type' => $documentType,
            'duplicate_kind' => 'none',
            'outcome' => 'screening_passed',
            'name_match' => true,
            'sides' => array_combine(
                $slots,
                array_map(
                    fn (string $slot): array => $this->side($slot, $documentType),
                    $slots,
                ),
            ),
        ];
    }

    /**
     * @param array<int, string> $anchors
     * @return array<string, mixed>
     */
    private function side(
        string $slot,
        string $family,
        string $outcome = 'plausible',
        array $anchors = ['document_family'],
    ): array {
        return [
            'side' => $slot,
            'outcome' => $outcome,
            'detected_document_family' => $family,
            'detected_anchor_keys' => $anchors,
            'confidence_band' => 'high',
            'qr_detected' => false,
            'fingerprint' => 'fingerprint-'.$slot.'-'.$family,
        ];
    }

    private function customer(): User
    {
        return User::factory()->unverified()->create([
            'name' => 'Juan Dela Cruz',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);
    }

    private function validPng(string $name, string $marker = ''): UploadedFile
    {
        $content = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAwUBAO+X2ioAAAAASUVORK5CYII=',
            true,
        );

        if ($content === false) {
            throw new \RuntimeException('Unable to create image fixture.');
        }

        return UploadedFile::fake()->createWithContent($name, $content.$marker);
    }
}
