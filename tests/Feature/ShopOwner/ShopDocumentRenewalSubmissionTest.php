<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner;

use App\Enums\NotificationType;
use App\Enums\PrivilegedDeliveryType;
use App\Models\Notification as AdminNotification;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Jobs\SendPrivilegedWorkflowMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ShopDocumentRenewalSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_approved_owner_submits_a_private_immutable_renewal_and_notifies_eligible_reviewers(): void
    {
        $reviewer = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $owner = ShopOwner::factory()->approved()->create();
        $predecessor = $this->currentDocument($owner);

        $response = $this->actingAs($owner, 'shop_owner')->postJson(
            '/shop-owner/compliance-documents/'.$predecessor->id.'/renewals',
            $this->renewalPayload(),
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('document.status', 'pending')
            ->assertJsonPath('document.version_number', 2);

        $renewal = ShopDocument::query()->where('predecessor_document_id', $predecessor->id)->firstOrFail();

        $this->assertSame('approved', $predecessor->fresh()->status);
        $this->assertTrue((bool) $predecessor->fresh()->is_current);
        $this->assertSame($predecessor->file_path, $predecessor->fresh()->file_path);
        $this->assertSame('pending', $renewal->status);
        $this->assertFalse((bool) $renewal->is_current);
        $this->assertSame('mayors_permit', $renewal->logical_slot);
        $this->assertSame('local', $renewal->disk);
        Storage::disk('local')->assertExists($renewal->file_path);

        $this->assertDatabaseHas('notifications', [
            'super_admin_id' => $reviewer->id,
            'type' => NotificationType::SHOP_DOCUMENT_RENEWAL_PENDING->value,
            'requires_action' => 1,
        ]);
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($reviewer, $renewal): bool {
            return $job->deliveryType === PrivilegedDeliveryType::SHOP_DOCUMENT_RENEWAL_SUBMITTED
                && $job->recipientType === 'super_admin'
                && $job->recipientId === $reviewer->id
                && $job->businessEventId === 'shop-document-renewal-submitted:'.$renewal->id;
        });
    }

    public function test_wrong_owner_cannot_submit_against_another_owner_document(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $predecessor = $this->currentDocument($owner);

        $this->actingAs($otherOwner, 'shop_owner')
            ->postJson('/shop-owner/compliance-documents/'.$predecessor->id.'/renewals', $this->renewalPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('shop_documents', 1);
    }

    public function test_only_approved_shop_owners_can_submit_renewals(): void
    {
        $owner = ShopOwner::factory()->pending()->create();
        $predecessor = $this->currentDocument($owner);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/compliance-documents/'.$predecessor->id.'/renewals', $this->renewalPayload())
            ->assertForbidden();
    }

    public function test_mayors_permit_requires_a_dated_expiration(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $predecessor = $this->currentDocument($owner);
        $payload = $this->renewalPayload();
        $payload['expiration_mode'] = 'none';
        $payload['expires_on'] = null;

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/compliance-documents/'.$predecessor->id.'/renewals', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mayors_permit']);

        $this->assertDatabaseCount('shop_documents', 1);
    }

    public function test_a_second_pending_renewal_for_the_same_slot_conflicts_without_mutating_history(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $predecessor = $this->currentDocument($owner);
        $payload = $this->renewalPayload();

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/compliance-documents/'.$predecessor->id.'/renewals', $payload)
            ->assertOk();

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/compliance-documents/'.$predecessor->id.'/renewals', [
                ...$payload,
                'submission_key' => (string) \Illuminate\Support\Str::uuid(),
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('shop_documents', 2);
        $this->assertDatabaseHas('shop_documents', [
            'id' => $predecessor->id,
            'status' => 'approved',
            'is_current' => 1,
        ]);
    }

    public function test_exact_submission_key_replay_returns_the_original_pending_version(): void
    {
        $reviewer = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $owner = ShopOwner::factory()->approved()->create();
        $predecessor = $this->currentDocument($owner);
        $payload = $this->renewalPayload();

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/compliance-documents/'.$predecessor->id.'/renewals', $payload)
            ->assertOk();
        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/compliance-documents/'.$predecessor->id.'/renewals', $payload)
            ->assertOk();

        $this->assertDatabaseCount('shop_documents', 2);
        $this->assertSame(1, AdminNotification::query()->where('super_admin_id', $reviewer->id)->count());
    }

    public function test_conflicting_submission_key_reuse_returns_conflict(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $predecessor = $this->currentDocument($owner);
        $payload = $this->renewalPayload();

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/compliance-documents/'.$predecessor->id.'/renewals', $payload)
            ->assertOk();

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/shop-owner/compliance-documents/'.$predecessor->id.'/renewals', [
                ...$payload,
                'expires_on' => '2028-08-13',
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('shop_documents', 2);
    }

    public function test_settings_expose_safe_compliance_state_and_private_history_urls(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $current = $this->currentDocument($owner);

        $response = $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.settings'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shop_settings.document_compliance.0.logical_slot', 'business_registration')
                ->where('shop_settings.document_compliance.1.logical_slot', 'mayors_permit')
                ->where('shop_settings.document_compliance.1.current.id', $current->id)
                ->where('shop_settings.document_compliance.1.current.validity', 'valid')
                ->where('shop_settings.document_compliance.1.current.url', route('shop-owner.documents.show', [
                    'shopOwner' => $owner->id,
                    'document' => $current->id,
                ])));

        $this->assertStringNotContainsString('file_path', $response->getContent());
        $this->assertStringNotContainsString('checksum_sha256', $response->getContent());
    }

    public function test_reconciled_legacy_supporting_document_renews_in_the_same_stable_slot(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $path = 'shop_documents/'.$owner->id.'/legacy-supporting.png';
        Storage::disk('local')->put($path, 'legacy-supporting-document');
        $predecessor = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'other_supporting_document',
            'logical_slot' => 'supporting_document:legacy:42',
            'version_number' => 1,
            'file_path' => $path,
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
            'expiration_mode' => 'none',
            'reviewed_by_super_admin_id' => $reviewer->id,
            'reviewed_at' => '2026-01-02 00:00:00',
            'checksum_sha256' => hash('sha256', 'legacy-supporting-document'),
        ]);

        $response = $this->actingAs($owner, 'shop_owner')->postJson(
            route('shop-owner.compliance-documents.renewals.store', $predecessor),
            [
                'document_type' => 'supporting_document',
                'logical_slot' => 'supporting_document:legacy:42',
                'issued_on' => '2026-08-13',
                'expiration_mode' => 'none',
                'expires_on' => null,
                'submission_key' => (string) \Illuminate\Support\Str::uuid(),
                'file' => UploadedFile::fake()->create('replacement.png', 10, 'image/png'),
            ],
        );

        $response->assertOk()->assertJsonPath('document.logical_slot', 'supporting_document:legacy:42');
        $this->assertDatabaseHas('shop_documents', [
            'document_type' => 'supporting_document',
            'logical_slot' => 'supporting_document:legacy:42',
            'predecessor_document_id' => $predecessor->id,
            'status' => 'pending',
        ]);
    }

    public function test_ambiguous_legacy_business_registration_can_be_classified_by_renewal(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $path = 'shop_documents/'.$owner->id.'/legacy-business.png';
        Storage::disk('local')->put($path, 'legacy-business-document');
        $predecessor = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'legacy_dti_sec_registration',
            'logical_slot' => 'business_registration',
            'version_number' => 1,
            'file_path' => $path,
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
            'expiration_mode' => 'unknown',
            'checksum_sha256' => hash('sha256', 'legacy-business-document'),
        ]);

        $payload = [
            'document_type' => 'sec_registration',
            'logical_slot' => 'business_registration',
            'issued_on' => '2026-08-13',
            'expiration_mode' => 'none',
            'expires_on' => null,
            'submission_key' => (string) \Illuminate\Support\Str::uuid(),
            'file' => UploadedFile::fake()->create('sec-registration.png', 10, 'image/png'),
        ];

        $this->actingAs($owner, 'shop_owner')
            ->postJson(route('shop-owner.compliance-documents.renewals.store', $predecessor), $payload)
            ->assertOk()
            ->assertJsonPath('document.document_type', 'sec_registration');

        $this->assertDatabaseHas('shop_documents', [
            'document_type' => 'sec_registration',
            'logical_slot' => 'business_registration',
            'predecessor_document_id' => $predecessor->id,
            'status' => 'pending',
        ]);
    }

    /** @return array<string, mixed> */
    private function renewalPayload(): array
    {
        return [
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'issued_on' => '2026-08-13',
            'expiration_mode' => 'dated',
            'expires_on' => '2027-08-13',
            'submission_key' => (string) \Illuminate\Support\Str::uuid(),
            'file' => UploadedFile::fake()->create('renewed-permit.png', 10, 'image/png'),
        ];
    }

    private function currentDocument(ShopOwner $owner): ShopDocument
    {
        $path = 'shop_documents/'.$owner->id.'/current-permit.png';
        Storage::disk('local')->put($path, 'current-document');

        return ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'version_number' => 1,
            'file_path' => $path,
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
            'issued_on' => '2026-01-01',
            'expiration_mode' => 'dated',
            'expires_on' => '2026-12-31',
            'reviewed_by_super_admin_id' => SuperAdmin::factory()->admin()->create()->id,
            'reviewed_at' => '2026-01-02 00:00:00',
            'checksum_sha256' => hash('sha256', 'current-document'),
        ]);
    }
}
