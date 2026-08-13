<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\NotificationType;
use App\Enums\PrivilegedDeliveryType;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\Notification as AdminNotification;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class ShopDocumentRenewalReviewTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_admin_queue_lists_pending_renewals_with_safe_private_urls_and_bounded_pagination(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $renewal = $this->pendingRenewal();

        $response = $this->actingAsCompletedPrivileged($admin)
            ->getJson(route('admin.document-renewals.index', ['per_page' => 1]));

        $response->assertOk()
            ->assertJsonPath('data.0.id', $renewal->id)
            ->assertJsonPath('data.0.logical_slot', 'mayors_permit')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.url', route('admin.shop-documents.show', [$renewal->shopOwner, $renewal]))
            ->assertJsonPath('data.0.predecessor.url', route('admin.shop-documents.show', [$renewal->shopOwner, $renewal->predecessor]))
            ->assertJsonPath('meta.per_page', 1);

        $this->assertStringNotContainsString('file_path', $response->getContent());
        $this->assertStringNotContainsString('checksum_sha256', $response->getContent());
    }

    public function test_renewal_queue_rejects_malformed_pagination_and_document_filters(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();

        foreach ([
            ['page' => 'abc'],
            ['page' => 0],
            ['per_page' => 'abc'],
            ['per_page' => 0],
            ['per_page' => 101],
            ['document_id' => 'abc'],
            ['document_id' => 0],
        ] as $query) {
            $this->actingAsCompletedPrivileged($admin)
                ->getJson(route('admin.document-renewals.index', $query))
                ->assertUnprocessable();
        }
    }

    public function test_admin_can_approve_a_pending_renewal_and_notifies_the_owner_after_commit(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $renewal = $this->pendingRenewal();
        $owner = $renewal->shopOwner;
        $predecessor = $renewal->predecessor;

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.document-renewals.approve', $renewal), $this->approvalPayload($renewal));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('applied', true)
            ->assertJsonPath('document.status', 'approved');

        $this->assertDatabaseHas('shop_documents', [
            'id' => $renewal->id,
            'status' => 'approved',
            'is_current' => 1,
            'reviewed_by_super_admin_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('shop_documents', [
            'id' => $predecessor->id,
            'status' => 'approved',
            'is_current' => null,
        ]);
        $this->assertSame('approved', $owner->fresh()->status->value ?? (string) $owner->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'shop_owner_id' => $owner->id,
            'type' => NotificationType::SHOP_DOCUMENT_RENEWAL_REVIEWED->value,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'shop_document_renewal_approved',
            'subject_id' => $renewal->id,
        ]);
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($owner, $renewal): bool {
            return $job->deliveryType === PrivilegedDeliveryType::SHOP_DOCUMENT_RENEWAL_REVIEWED
                && $job->recipientType === 'shop_owner'
                && $job->recipientId === $owner->id
                && $job->businessEventId === 'shop-document-renewal-reviewed:'.$renewal->id.':approved';
        });
    }

    public function test_rejection_preserves_the_current_predecessor_and_shop_status(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $renewal = $this->pendingRenewal();
        $owner = $renewal->shopOwner;
        $predecessor = $renewal->predecessor;

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.document-renewals.reject', $renewal), [
                'rejection_reason' => 'The uploaded permit is unreadable.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('applied', true);

        $this->assertDatabaseHas('shop_documents', [
            'id' => $renewal->id,
            'status' => 'rejected',
            'is_current' => null,
            'rejection_reason' => 'The uploaded permit is unreadable.',
        ]);
        $this->assertDatabaseHas('shop_documents', [
            'id' => $predecessor->id,
            'status' => 'approved',
            'is_current' => 1,
        ]);
        $this->assertSame('approved', $owner->fresh()->status->value ?? (string) $owner->fresh()->status);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'shop_document_renewal_rejected',
            'subject_id' => $renewal->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'shop_owner_id' => $owner->id,
            'type' => NotificationType::SHOP_DOCUMENT_RENEWAL_REVIEWED->value,
        ]);
    }

    public function test_matching_terminal_replay_is_inert_and_conflicting_decision_returns_409(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $renewal = $this->pendingRenewal();
        $payload = ['rejection_reason' => 'The uploaded permit is unreadable.'];

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.document-renewals.reject', $renewal), $payload)
            ->assertOk()
            ->assertJsonPath('applied', true);

        $auditCount = Activity::query()->where('event', 'shop_document_renewal_rejected')->count();
        $notificationCount = AdminNotification::query()
            ->where('shop_owner_id', $renewal->shop_owner_id)
            ->where('type', NotificationType::SHOP_DOCUMENT_RENEWAL_REVIEWED->value)
            ->count();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.document-renewals.reject', $renewal), $payload)
            ->assertOk()
            ->assertJsonPath('applied', false);

        $this->assertSame($auditCount, Activity::query()->where('event', 'shop_document_renewal_rejected')->count());
        $this->assertSame($notificationCount, AdminNotification::query()
            ->where('shop_owner_id', $renewal->shop_owner_id)
            ->where('type', NotificationType::SHOP_DOCUMENT_RENEWAL_REVIEWED->value)
            ->count());

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.document-renewals.approve', $renewal), $this->approvalPayload($renewal))
            ->assertStatus(409);
    }

    public function test_rejected_replay_remains_idempotent_after_a_later_version_is_promoted(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $renewal = $this->pendingRenewal();
        $reason = 'The uploaded permit is unreadable.';

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.document-renewals.reject', $renewal), [
                'rejection_reason' => $reason,
            ])
            ->assertOk();

        $replacement = app(\App\Services\ShopDocumentLifecycleService::class)->createPendingVersion(
            shopOwner: $renewal->shopOwner->fresh(),
            metadata: [
                'document_type' => 'mayors_permit',
                'logical_slot' => 'mayors_permit',
                'issued_on' => '2026-09-01',
                'expiration_mode' => 'dated',
                'expires_on' => '2027-09-01',
            ],
            file: UploadedFile::fake()->create('replacement.png', 10, 'image/png'),
            predecessor: $renewal->predecessor->fresh(),
            submissionKey: (string) \Illuminate\Support\Str::uuid(),
        );

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.document-renewals.approve', $replacement), [
                'document_type' => 'mayors_permit',
                'logical_slot' => 'mayors_permit',
                'version_number' => $replacement->version_number,
                'issued_on' => '2026-09-01',
                'expiration_mode' => 'dated',
                'expires_on' => '2027-09-01',
                'viewed' => true,
            ])
            ->assertOk();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.document-renewals.reject', $renewal), [
                'rejection_reason' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('applied', false);
    }

    public function test_regular_admin_can_view_only_the_pending_renewal_and_its_exact_predecessor_for_an_approved_shop(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $renewal = $this->pendingRenewal();
        $other = $this->approvedDocument($renewal->shopOwner, 'valid_id', 'shop_documents/other-id.png');

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$renewal->shopOwner, $renewal]))
            ->assertOk();
        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$renewal->shopOwner, $renewal->predecessor]))
            ->assertOk();
        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.shop-documents.show', [$renewal->shopOwner, $other]))
            ->assertNotFound();
    }

    public function test_audit_failure_rolls_back_the_approval_and_owner_notification(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $renewal = $this->pendingRenewal();
        $audit = Mockery::mock(\App\Services\PrivilegedAudit::class)->makePartial();
        $audit->shouldReceive('shopDocumentRenewalApproved')
            ->once()
            ->andThrow(new \RuntimeException('audit failure secret'));
        $this->instance(\App\Services\PrivilegedAudit::class, $audit);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.document-renewals.approve', $renewal), $this->approvalPayload($renewal));

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'shop_document_renewal_approval_error');
        $this->assertStringNotContainsString('audit failure secret', $response->getContent());
        $this->assertDatabaseHas('shop_documents', [
            'id' => $renewal->id,
            'status' => 'pending',
            'is_current' => null,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'shop_owner_id' => $renewal->shop_owner_id,
            'type' => NotificationType::SHOP_DOCUMENT_RENEWAL_REVIEWED->value,
        ]);
        Queue::assertNothingPushed();
    }

    /** @return array<string, mixed> */
    private function approvalPayload(ShopDocument $renewal): array
    {
        return [
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'version_number' => $renewal->version_number,
            'issued_on' => '2026-08-13',
            'expiration_mode' => 'dated',
            'expires_on' => '2027-08-13',
            'viewed' => true,
        ];
    }

    private function pendingRenewal(): ShopDocument
    {
        $owner = ShopOwner::factory()->approved()->create();
        $predecessor = $this->approvedDocument($owner, 'mayors_permit', 'shop_documents/current-permit.png');
        Storage::disk('local')->put('shop_documents/renewal-permit.png', 'renewal-document');

        return ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'version_number' => 2,
            'predecessor_document_id' => $predecessor->id,
            'file_path' => 'shop_documents/renewal-permit.png',
            'disk' => 'local',
            'status' => 'pending',
            'is_current' => null,
            'issued_on' => '2026-08-13',
            'expiration_mode' => 'dated',
            'expires_on' => '2027-08-13',
            'submission_key' => (string) \Illuminate\Support\Str::uuid(),
            'checksum_sha256' => hash('sha256', 'renewal-document'),
        ])->load(['shopOwner', 'predecessor']);
    }

    private function approvedDocument(ShopOwner $owner, string $type, string $path): ShopDocument
    {
        Storage::disk('local')->put($path, 'approved-document-'.$type);

        return ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => $type,
            'logical_slot' => $type,
            'version_number' => 1,
            'file_path' => $path,
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
            'issued_on' => '2026-01-01',
            'expiration_mode' => $type === 'mayors_permit' ? 'dated' : 'none',
            'expires_on' => $type === 'mayors_permit' ? '2026-12-31' : null,
            'reviewed_by_super_admin_id' => SuperAdmin::factory()->admin()->create()->id,
            'reviewed_at' => '2026-01-02 00:00:00',
            'checksum_sha256' => hash('sha256', 'approved-document-'.$type),
        ]);
    }
}
