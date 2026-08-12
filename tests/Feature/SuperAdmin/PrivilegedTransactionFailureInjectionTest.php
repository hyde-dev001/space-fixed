<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\PrivilegedDeliveryType;
use App\Exceptions\PrivilegedDeliveryException;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\AccountSuspension;
use App\Models\PremiumPlan;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\ShopOwnerUpgradeRequestDocument;
use App\Models\ShopReportModerationAction;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use App\Services\AccountSuspensionService;
use App\Services\PrivilegedAudit;
use App\Services\ShopOwnerDocumentRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\Concerns\BuildsPhaseTwoWorkflowFixtures;
use Tests\TestCase;

final class PrivilegedTransactionFailureInjectionTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use BuildsPhaseTwoWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_administrator_invitation_audit_failure_rolls_back_and_returns_safe_correlation_response(): void
    {
        $actor = $this->phaseTwoSuperAdmin();
        $secret = 'invitation audit secret';
        $this->injectAuditFailure('privilegedInvitationCreated', $secret);
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $response = $this->postJson('/admin/create-admin', [
            'first_name' => 'Rollback',
            'last_name' => 'Invite',
            'email' => 'rollback-invite@example.test',
            'phone' => '09170000011',
            'role' => SuperAdmin::ROLE_ADMIN,
        ]);

        $this->assertSafeFailure($response, 'privileged_invitation_create_error', 'privileged_invitation_create', $secret);
        $this->assertSame(1, SuperAdmin::query()->count());
        $this->assertDatabaseCount('privileged_security_tokens', 0);
        $this->assertNoSuccessAudit('privileged_invitation_created');
        Queue::assertNothingPushed();
    }

    public function test_registration_approval_audit_failure_rolls_back_documents_tokens_and_modules(): void
    {
        $admin = $this->phaseTwoAdmin();
        $owner = $this->pendingRegistrationWithRequiredDocuments();
        $secret = 'registration audit secret';
        $this->injectAuditFailure('shopRegistrationApproved', $secret);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.shop-owner-approve', $owner));

        $this->assertSafeFailure($response, 'shop_registration_approval_error', 'shop_registration', $secret);
        $this->assertSame('pending', $owner->fresh()->getRawOriginal('status'));
        $this->assertSame(0, ShopDocument::query()->where('shop_owner_id', $owner->id)->where('status', 'approved')->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        $this->assertDatabaseMissing('shop_owner_modules', ['shop_owner_id' => $owner->id]);
        $this->assertNoSuccessAudit('shop_registration_approved');
        Queue::assertNothingPushed();
    }

    public function test_registration_rejection_audit_failure_rolls_back_documents_and_returns_safe_correlation_response(): void
    {
        $admin = $this->phaseTwoAdmin();
        $owner = $this->pendingRegistrationWithRequiredDocuments();
        $originalRejectionReason = $owner->rejection_reason;
        $secret = 'registration rejection audit secret';
        $this->injectAuditFailure('shopRegistrationRejected', $secret);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.shop-owner-reject', $owner), [
                'rejection_reason' => 'Failure injection rejection',
            ]);

        $this->assertSafeFailure($response, 'shop_registration_rejection_error', 'shop_registration', $secret);
        $this->assertSame('pending', $owner->fresh()->getRawOriginal('status'));
        $this->assertSame($originalRejectionReason, $owner->fresh()->rejection_reason);
        $this->assertSame(0, ShopDocument::query()->where('shop_owner_id', $owner->id)->where('status', 'rejected')->count());
        $this->assertNoSuccessAudit('shop_registration_rejected');
        Queue::assertNothingPushed();
    }

    public function test_account_suspension_audit_failure_rolls_back_suspension_identity_and_appeal(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $secret = 'suspension audit secret';
        $this->injectAuditFailure('userSuspended', $secret);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson("/admin/users/{$user->id}/suspend", [
                'suspension_reason' => 'Failure injection suspension',
            ]);

        $this->assertSafeFailure($response, 'account_lifecycle_error', 'account_lifecycle', $secret);
        $this->assertSame('active', $user->fresh()->getRawOriginal('status'));
        $this->assertNull($user->fresh()->current_suspension_id);
        $this->assertDatabaseCount('account_suspensions', 0);
        $this->assertDatabaseCount('suspension_appeals', 0);
        $this->assertNoSuccessAudit('user_suspended');
        Queue::assertNothingPushed();
    }

    public function test_shop_report_warning_audit_failure_rolls_back_report_decision(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $report = $this->openPhaseTwoShopReports($shop, 1)->sole();
        $secret = 'report warning audit secret';
        $this->injectAuditFailure('shopReportsModerated', $secret);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson("/admin/shop-reports/{$shop->id}/action", [
                'action' => 'warn',
                'report_ids' => [$report->id],
                'admin_notes' => 'Failure injection warning',
            ]);

        $this->assertSafeFailure($response, 'shop_report_error', 'shop_report', $secret);
        $this->assertSame('submitted', $report->fresh()->status);
        $this->assertSame('approved', $shop->fresh()->getRawOriginal('status'));
        $this->assertDatabaseCount('shop_report_moderation_actions', 0);
        $this->assertNoSuccessAudit('shop_reports_moderated');
        Queue::assertNothingPushed();
    }

    public function test_shop_report_suspension_audit_failure_rolls_back_reports_and_suspension(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $report = $this->openPhaseTwoShopReports($shop, 1)->sole();
        $secret = 'report suspension audit secret';
        $this->injectAuditFailure('shopReportsModerated', $secret);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson("/admin/shop-reports/{$shop->id}/action", [
                'action' => 'suspend',
                'report_ids' => [$report->id],
                'admin_notes' => 'Failure injection suspension',
            ]);

        $this->assertSafeFailure($response, 'shop_report_error', 'shop_report', $secret);
        $this->assertSame('submitted', $report->fresh()->status);
        $this->assertSame('approved', $shop->fresh()->getRawOriginal('status'));
        $this->assertNull($shop->fresh()->current_suspension_id);
        $this->assertDatabaseCount('shop_report_moderation_actions', 0);
        $this->assertDatabaseCount('account_suspensions', 0);
        $this->assertDatabaseCount('suspension_appeals', 0);
        $this->assertNoSuccessAudit('shop_reports_moderated');
        Queue::assertNothingPushed();
    }

    public function test_appeal_decision_audit_failure_rolls_back_account_restore_and_decision(): void
    {
        $superAdmin = $this->phaseTwoSuperAdmin();
        [$customer, $suspension, $appeal] = $this->submittedAppeal($superAdmin);
        $secret = 'appeal decision audit secret';
        $this->injectAuditFailure('suspensionAppealDecided', $secret);

        $response = $this->actingAsCompletedPrivileged($superAdmin)
            ->postJson("/admin/appeals/{$appeal->id}/approve", [
                'reviewer_notes' => 'Failure injection appeal decision',
            ]);

        $this->assertSafeFailure($response, 'suspension_appeal_error', 'suspension_appeal', $secret);
        $this->assertSame('submitted', $appeal->fresh()->status);
        $this->assertSame('suspended', $customer->fresh()->getRawOriginal('status'));
        $this->assertSame($suspension->id, (int) $customer->fresh()->current_suspension_id);
        $this->assertNull($suspension->fresh()->ended_at);
        $this->assertNoSuccessAudit('suspension_appeal_decided');
        Queue::assertNothingPushed();
    }

    public function test_premium_plan_update_audit_failure_rolls_back_plan_and_entitlement_changes(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $plan = PremiumPlan::query()->create([
            'plan_code' => 'failure-plan',
            'name' => 'Failure Plan',
            'description' => 'Failure injection plan',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => 48,
            'benefits' => [],
            'status' => 'active',
        ]);
        $owner = ShopOwner::factory()->approved()->create();
        $subscription = ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => 48,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(10),
        ]);
        $secret = 'premium plan audit secret';
        $this->injectAuditFailure('premiumPlanUpdated', $secret);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->putJson(route('admin.premium-plans.update', $plan), [
                'name' => 'Failure Plan Updated',
                'description' => 'Failure injection plan updated',
                'price' => 899,
                'duration_days' => 45,
                'showroom_slot_limit' => 100,
                'benefits' => ['Priority support'],
            ]);

        $this->assertSafeFailure($response, 'premium_plan_update_error', 'premium_plan_update', $secret);
        $this->assertSame('Failure Plan', $plan->fresh()->name);
        $this->assertSame(48, (int) $plan->fresh()->showroom_slot_limit);
        $this->assertSame(48, (int) $subscription->fresh()->showroom_slot_limit);
        $this->assertNoSuccessAudit('premium_plan_updated');
        Queue::assertNothingPushed();
    }

    public function test_business_upgrade_approval_audit_failure_rolls_back_owner_request_and_modules(): void
    {
        $admin = $this->phaseTwoAdmin();
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $upgradeRequest = $this->pendingUpgradeRequest($owner);
        $secret = 'business upgrade audit secret';
        $this->injectAuditFailure('shopOwnerUpgradeReviewed', $secret);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
            ]);

        $this->assertSafeFailure($response, 'shop_owner_upgrade_error', 'shop_owner_upgrade', $secret);
        $this->assertSame('individual', $owner->fresh()->registration_type);
        $this->assertSame('retail', $owner->fresh()->business_type);
        $this->assertSame(ShopOwnerUpgradeRequest::STATUS_PENDING, $upgradeRequest->fresh()->status);
        $this->assertDatabaseCount('shop_owner_modules', 0);
        $this->assertNoSuccessAudit('shop_owner_upgrade_reviewed');
        Queue::assertNothingPushed();
    }

    public function test_delivery_transport_failure_preserves_committed_business_state_and_audit(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $report = $this->openPhaseTwoShopReports($shop, 1)->sole();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson("/admin/shop-reports/{$shop->id}/action", [
                'action' => 'warn',
                'report_ids' => [$report->id],
                'admin_notes' => 'Committed before delivery failure',
            ])
            ->assertOk();
        DB::commit();

        $job = Queue::pushed(SendPrivilegedWorkflowMail::class)
            ->first(fn (SendPrivilegedWorkflowMail $candidate): bool => $candidate->deliveryType === PrivilegedDeliveryType::SHOP_REPORT_WARNING);
        $this->assertInstanceOf(SendPrivilegedWorkflowMail::class, $job);

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('transport leaked committed payload secret'));

        try {
            $job->handle();
            $this->fail('Expected the delivery failure to be sanitized.');
        } catch (PrivilegedDeliveryException $exception) {
            $this->assertSame('Privileged workflow delivery failed.', $exception->getMessage());
            $this->assertStringNotContainsString('committed payload secret', $exception->getMessage());
        }

        $this->assertSame('warned', $report->fresh()->status);
        $this->assertSame(1, ShopReportModerationAction::query()->count());
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'privileged',
            'description' => 'shop_reports_moderated',
            'subject_type' => ShopOwner::class,
            'subject_id' => $shop->id,
        ]);
    }

    private function injectAuditFailure(string $method, string $message): void
    {
        $audit = Mockery::mock(PrivilegedAudit::class)->makePartial();
        $audit->shouldReceive($method)
            ->once()
            ->andThrow(new \RuntimeException($message));
        $this->instance(PrivilegedAudit::class, $audit);
    }

    private function assertSafeFailure(
        TestResponse $response,
        string $code,
        string $operation,
        string $secret,
    ): string {
        $response
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', $code);

        $correlationId = (string) $response->json('correlation_id');
        $this->assertTrue(Str::isUuid($correlationId));
        $this->assertSame($correlationId, $response->headers->get('X-Correlation-ID'));
        $this->assertStringNotContainsString($secret, (string) $response->getContent());

        $failure = DB::table('activity_log')
            ->where('log_name', 'privileged')
            ->where('description', 'privileged_workflow_failed')
            ->latest('id')
            ->first();
        $this->assertNotNull($failure);
        $properties = json_decode((string) $failure->properties, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($operation, $properties['operation'] ?? null);
        $this->assertSame($correlationId, $properties['correlation_id'] ?? null);
        $this->assertArrayNotHasKey('exception', $properties);
        $this->assertArrayNotHasKey('input', $properties);
        $this->assertStringNotContainsString($secret, (string) $failure->properties);

        return $correlationId;
    }

    private function assertNoSuccessAudit(string $event): void
    {
        $this->assertSame(0, DB::table('activity_log')
            ->where('log_name', 'privileged')
            ->where('description', $event)
            ->count());
    }

    private function markRecentlyReauthenticated(SuperAdmin $admin): void
    {
        session()->put([
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => (int) $admin->security_version,
        ]);
        session()->save();
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }

    /** @return array{0: User, 1: AccountSuspension, 2: SuspensionAppeal} */
    private function submittedAppeal(SuperAdmin $actor): array
    {
        $customer = $this->activePhaseTwoUser();
        [$suspension, $appeal] = DB::transaction(function () use ($customer, $actor): array {
            $locked = User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $result = app(AccountSuspensionService::class)->suspendLocked(
                $locked,
                $actor,
                'Failure injection appeal fixture suspension.',
            );

            return [$result['suspension']->fresh(), $result['appeal']->fresh()];
        });

        $appeal->forceFill([
            'status' => 'submitted',
            'appeal_message' => 'Failure injection appeal message.',
            'submitted_at' => now()->subHour(),
        ])->save();

        return [$customer->fresh(), $suspension->fresh(), $appeal->fresh()];
    }

    private function pendingUpgradeRequest(ShopOwner $owner): ShopOwnerUpgradeRequest
    {
        $requirements = app(ShopOwnerDocumentRequirementService::class);
        $upgradeRequest = ShopOwnerUpgradeRequest::factory()->pending()->create([
            'shop_owner_id' => $owner->id,
            'current_registration_type' => 'individual',
            'current_business_type' => 'retail',
            'requested_registration_type' => 'company',
            'requested_business_type' => 'both',
            'required_document_set' => $requirements->requirementSnapshot(),
        ]);

        foreach ($requirements->requiredTypes() as $documentType) {
            $bytes = 'valid-evidence-'.$documentType;
            $path = 'failure-injection/'.$upgradeRequest->id.'/'.$documentType.'.pdf';
            Storage::disk('local')->put($path, $bytes);
            ShopOwnerUpgradeRequestDocument::query()->create([
                'shop_owner_upgrade_request_id' => $upgradeRequest->id,
                'document_type' => $documentType,
                'disk' => 'local',
                'path' => $path,
                'checksum_sha256' => hash('sha256', $bytes),
                'mime_type' => 'application/pdf',
                'size' => strlen($bytes),
                'source_status' => 'uploaded',
            ]);
        }

        return $upgradeRequest->fresh('documents');
    }
}
