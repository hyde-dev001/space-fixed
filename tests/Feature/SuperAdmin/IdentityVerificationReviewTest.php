<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\IdentityVerification;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class IdentityVerificationReviewTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_admin_approval_changes_only_the_human_review_status(): void
    {
        $admin = $this->createAdmin();
        [$user, $verification] = $this->manualReview();

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.users.identity-verifications.approve', [$user, $verification]));

        $response->assertOk()
            ->assertJsonPath('identity_verification.screening_status', 'manual_review_required')
            ->assertJsonPath('identity_verification.review_status', 'approved');

        $verification->refresh();
        $this->assertSame('manual_review_required', $verification->screening_status);
        $this->assertSame('approved', $verification->review_status);
        $this->assertSame($admin->id, $verification->reviewed_by);
        $this->assertNotNull($verification->reviewed_at);
        $this->assertSame('active', $user->fresh()->status);

        $activity = Activity::query()
            ->where('log_name', 'privileged')
            ->where('event', 'identity_verification_approved')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($verification->id, $activity->subject_id);
        $this->assertSame($user->id, $activity->properties['customer_user_id']);
    }

    public function test_admin_rejection_changes_only_the_human_review_status(): void
    {
        $admin = $this->createAdmin();
        [$user, $verification] = $this->manualReview();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.users.identity-verifications.reject', [$user, $verification]), [
                'rejection_reason' => 'id_unreadable',
            ])
            ->assertOk()
            ->assertJsonPath('identity_verification.screening_status', 'manual_review_required')
            ->assertJsonPath('identity_verification.review_status', 'rejected')
            ->assertJsonPath('identity_verification.rejection_reason', 'id_unreadable');

        $this->assertDatabaseHas('identity_verifications', [
            'id' => $verification->id,
            'screening_status' => 'manual_review_required',
            'review_status' => 'rejected',
            'rejection_reason' => 'id_unreadable',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $admin = $this->createAdmin();
        [$user, $verification] = $this->manualReview();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.users.identity-verifications.reject', [$user, $verification]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rejection_reason');

        $this->assertDatabaseHas('identity_verifications', [
            'id' => $verification->id,
            'review_status' => 'pending',
            'rejection_reason' => null,
        ]);
    }

    public function test_rejection_notifies_customer_and_updates_account_status(): void
    {
        $admin = $this->createAdmin();
        [$user, $verification] = $this->manualReview();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.users.identity-verifications.reject', [$user, $verification]), [
                'rejection_reason' => 'incomplete_details',
                'rejection_notes' => 'The name on the image is not readable.',
            ])
            ->assertOk();

        $this->assertSame(User::IDENTITY_REJECTED, $user->fresh()->identity_verification_status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'identity_verification_rejected',
            'requires_action' => true,
        ]);
    }

    public function test_review_queue_requires_inspection_before_bulk_approval(): void
    {
        $admin = $this->createAdmin();
        [$firstUser, $firstVerification] = $this->manualReview();
        [$secondUser, $secondVerification] = $this->manualReview();

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.identity-verification-reviews.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('superAdmin/IdentityVerificationReviews/Index', false)
                ->where('stats.pending', 2)
                ->where('filters.status', 'pending'));

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.identity-verification-reviews.bulk-approve'), [
                'verification_ids' => [$firstVerification->id, $secondVerification->id],
            ])
            ->assertStatus(409);

        foreach ([$firstUser, $secondUser] as $user) {
            $verification = $user->identityVerifications()->firstOrFail();

            $this->actingAsCompletedPrivileged($admin)
                ->postJson(route('admin.users.identity-verifications.inspect', [$user, $verification]))
                ->assertOk()
                ->assertJsonPath('identity_verification.id', $verification->id);
        }

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.identity-verification-reviews.bulk-approve'), [
                'verification_ids' => [$firstVerification->id, $secondVerification->id],
            ])
            ->assertOk()
            ->assertJsonPath('approved_count', 2);

        $this->assertSame(User::IDENTITY_APPROVED, $firstUser->fresh()->identity_verification_status);
        $this->assertSame(User::IDENTITY_APPROVED, $secondUser->fresh()->identity_verification_status);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_non_customer_user_cannot_be_reviewed_through_customer_identity_routes(): void
    {
        $admin = $this->createAdmin();
        $employee = User::factory()->create([
            'shop_owner_id' => 123,
        ]);
        $verification = $this->createVerification($employee);

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.users.identity-verifications.approve', [$employee, $verification]))
            ->assertNotFound();

        $this->assertDatabaseHas('identity_verifications', [
            'id' => $verification->id,
            'review_status' => 'pending',
        ]);
    }

    public function test_verification_belonging_to_another_customer_cannot_be_reviewed(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $verification = $this->manualReview()[1];
        $verification->update(['user_id' => $otherUser->id]);

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.users.identity-verifications.approve', [$user, $verification]))
            ->assertNotFound();
    }

    public function test_automated_pass_can_be_approved_as_a_human_review(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $verification = $this->createVerification($user, [
            'screening_status' => 'automated_check_passed',
            'review_status' => 'not_required',
        ]);

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.users.identity-verifications.approve', [$user, $verification]))
            ->assertOk()
            ->assertJsonPath('identity_verification.screening_status', 'automated_check_passed')
            ->assertJsonPath('identity_verification.review_status', 'approved');

        $this->assertDatabaseHas('identity_verifications', [
            'id' => $verification->id,
            'screening_status' => 'automated_check_passed',
            'review_status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);
    }

    public function test_admin_customer_list_exposes_only_safe_identity_state(): void
    {
        $admin = $this->createAdmin();
        [$user, $verification] = $this->manualReview();

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('superAdmin/Users/SuperAdminUserManagement', false)
                ->where('users.data.0.id', $user->id)
                ->where('users.data.0.identityVerification.id', $verification->id)
                ->where('users.data.0.identityVerification.screeningStatus', 'manual_review_required')
                ->where('users.data.0.identityVerification.reviewStatus', 'pending')
                ->missing('users.data.0.identityVerification.filePath')
                ->missing('users.data.0.identityVerification.ocrConfidence'));
    }

    private function createAdmin(): SuperAdmin
    {
        return SuperAdmin::factory()->superAdmin()->create();
    }

    /** @return array{0: User, 1: IdentityVerification} */
    private function manualReview(): array
    {
        $user = User::factory()->create(['shop_owner_id' => null]);

        return [$user, $this->createVerification($user)];
    }

    /** @param array<string, mixed> $overrides */
    private function createVerification(User $user, array $overrides = []): IdentityVerification
    {
        return IdentityVerification::create(array_merge([
            'user_id' => $user->id,
            'document_type' => 'passport',
            'screening_status' => 'manual_review_required',
            'review_status' => 'pending',
            'file_path' => 'valid_ids/test-id.png',
            'file_disk' => 'local',
            'ocr_confidence' => 0.55,
            'classification_confidence' => 0.52,
        ], $overrides));
    }
}
