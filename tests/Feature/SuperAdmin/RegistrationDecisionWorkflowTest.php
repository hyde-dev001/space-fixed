<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\PrivilegedDeliveryType;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\BusinessAccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class RegistrationDecisionWorkflowTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
    }

    public function test_pending_registration_approval_commits_documents_token_modules_audit_and_delivery(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $owner = $this->pendingRegistrationWithDocuments();

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner), $this->approvalPayload($owner));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('applied', true);
        DB::commit();

        $this->assertDatabaseHas('shop_owners', [
            'id' => $owner->id,
            'status' => 'approved',
            'rejection_reason' => null,
        ]);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $owner->email]);
        $this->assertSame(4, ShopDocument::query()->where('shop_owner_id', $owner->id)->where('status', 'approved')->count());
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
        $this->assertDeliveryQueued(
            PrivilegedDeliveryType::SHOP_REGISTRATION_APPROVED,
            $owner->id,
            ['setup_token'],
        );
    }

    public function test_approval_requires_reviewer_verified_document_metadata(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $owner = $this->pendingRegistrationWithDocuments();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner), [
                'documents' => [],
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('shop_owners', [
            'id' => $owner->id,
            'status' => 'pending',
        ]);
        $this->assertSame(0, ShopDocument::query()
            ->where('shop_owner_id', $owner->id)
            ->where('status', 'approved')
            ->count());
    }

    public function test_approval_requires_each_document_to_be_marked_viewed(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $owner = $this->pendingRegistrationWithDocuments();
        $payload = $this->approvalPayload($owner);
        $payload['documents'][0]['viewed'] = false;

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents.0.viewed']);

        $this->assertDatabaseHas('shop_owners', [
            'id' => $owner->id,
            'status' => 'pending',
        ]);
        $this->assertSame(0, ShopDocument::query()
            ->where('shop_owner_id', $owner->id)
            ->where('status', 'approved')
            ->count());
    }

    public function test_reviewer_can_correct_only_the_business_registration_authority(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $owner = $this->pendingRegistrationWithDocuments();
        $payload = $this->approvalPayload($owner);
        $businessRegistrationIndex = collect($payload['documents'])
            ->search(static fn (array $document): bool => $document['logical_slot'] === 'business_registration');
        $payload['documents'][$businessRegistrationIndex]['document_type'] = 'sec_registration';

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner), $payload)
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertDatabaseHas('shop_documents', [
            'shop_owner_id' => $owner->id,
            'logical_slot' => 'business_registration',
            'document_type' => 'sec_registration',
            'status' => 'approved',
            'is_current' => 1,
        ]);
    }

    public function test_rejection_requires_a_reason_and_updates_current_documents_atomically(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $owner = $this->pendingRegistrationWithDocuments();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.reject', $owner), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_reason']);

        $this->assertDatabaseHas('shop_owners', ['id' => $owner->id, 'status' => 'pending']);
        Queue::assertNothingPushed();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.reject', $owner), [
                'rejection_reason' => 'The submitted permit is not legible.',
            ])
            ->assertOk()
            ->assertJsonPath('applied', true);
        DB::commit();

        $this->assertDatabaseHas('shop_owners', [
            'id' => $owner->id,
            'status' => 'rejected',
            'rejection_reason' => 'The submitted permit is not legible.',
        ]);
        $this->assertSame(4, ShopDocument::query()->where('shop_owner_id', $owner->id)->where('status', 'rejected')->count());
        $this->assertDatabaseHas('activity_log', [
            'event' => 'shop_registration_rejected',
            'subject_type' => ShopOwner::class,
            'subject_id' => $owner->id,
        ]);
        $this->assertDeliveryQueued(
            PrivilegedDeliveryType::SHOP_REGISTRATION_REJECTED,
            $owner->id,
            ['rejection_reason'],
        );
    }

    public function test_rejection_retries_are_idempotent_only_for_the_same_reason(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $owner = $this->pendingRegistrationWithDocuments();
        $reason = 'The submitted permit is not legible.';

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.reject', $owner), ['rejection_reason' => $reason])
            ->assertOk()
            ->assertJsonPath('applied', true);
        DB::commit();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.reject', $owner->fresh()), ['rejection_reason' => $reason])
            ->assertOk()
            ->assertJsonPath('applied', false);

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.reject', $owner->fresh()), ['rejection_reason' => 'A different reason.'])
            ->assertStatus(409);

        Queue::assertPushed(SendPrivilegedWorkflowMail::class, 1);
        $this->assertSame(1, $this->activityCount('shop_registration_rejected', $owner));
    }

    public function test_approval_rejects_missing_stale_and_missing_on_disk_required_documents(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();

        $missing = ShopOwner::factory()->pending()->create();
        $this->createDocuments($missing, ['valid_id']);
        $this->assertApprovalValidationFailure($admin, $missing, 'mayors_permit');

        $stale = ShopOwner::factory()->pending()->create();
        $this->createDocuments($stale);
        $staleDocument = ShopDocument::query()
            ->where('shop_owner_id', $stale->id)
            ->where('document_type', 'mayors_permit')
            ->firstOrFail();
        $staleDocument->update(['status' => 'rejected']);
        $this->assertApprovalValidationFailure($admin, $stale, 'mayors_permit');

        $missingOnDisk = ShopOwner::factory()->pending()->create();
        $this->createDocuments($missingOnDisk, [], ['valid_id' => ['stored' => false]]);
        $this->assertApprovalValidationFailure($admin, $missingOnDisk, 'valid_id');

        $publicDocument = $this->pendingRegistrationWithDocuments();
        $publicValidId = ShopDocument::query()
            ->where('shop_owner_id', $publicDocument->id)
            ->where('document_type', 'valid_id')
            ->firstOrFail();
        Storage::disk('public')->put($publicValidId->file_path, 'public-document');
        $publicValidId->forceFill(['disk' => 'public'])->save();
        $this->assertApprovalValidationFailure($admin, $publicDocument, 'valid_id');
    }

    public function test_approval_is_idempotent_once_and_non_pending_decisions_conflict(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $owner = $this->pendingRegistrationWithDocuments();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner), $this->approvalPayload($owner))
            ->assertOk()
            ->assertJsonPath('applied', true);
        DB::commit();

        $token = (string) $this->assertDatabaseHasAndGetToken($owner);
        $auditCount = $this->activityCount('shop_registration_approved', $owner);

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner->fresh()))
            ->assertOk()
            ->assertJsonPath('applied', false);

        $this->assertSame($token, (string) $this->assertDatabaseHasAndGetToken($owner));
        $this->assertSame($auditCount, $this->activityCount('shop_registration_approved', $owner));
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, 1);

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.reject', $owner->fresh()), [
                'rejection_reason' => 'A conflicting decision must not apply.',
            ])
            ->assertStatus(409);

        $rejected = ShopOwner::factory()->rejected()->create();
        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $rejected), [])
            ->assertStatus(409);
    }

    public function test_approval_rolls_back_when_the_mandatory_audit_fails(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $owner = $this->pendingRegistrationWithDocuments();

        $this->mock(PrivilegedAudit::class, function ($mock): void {
            $mock->shouldReceive('shopRegistrationApproved')
                ->once()
                ->andThrow(new \RuntimeException('audit unavailable'));
        });

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner), $this->approvalPayload($owner))
            ->assertStatus(500);

        $this->assertDatabaseHas('shop_owners', ['id' => $owner->id, 'status' => 'pending']);
        $this->assertSame(0, ShopDocument::query()->where('shop_owner_id', $owner->id)->where('status', 'approved')->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        $this->assertDatabaseMissing('shop_owner_modules', ['shop_owner_id' => $owner->id]);
        Queue::assertNothingPushed();
    }

    public function test_approval_rolls_back_when_module_provisioning_fails(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $owner = $this->pendingRegistrationWithDocuments();

        $this->mock(BusinessAccessControlService::class, function ($mock): void {
            $mock->shouldReceive('normalizeBusinessType')
                ->once()
                ->andThrow(new \RuntimeException('module provisioning unavailable'));
        });

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner), $this->approvalPayload($owner))
            ->assertStatus(500);

        $this->assertDatabaseHas('shop_owners', ['id' => $owner->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        $this->assertDatabaseMissing('shop_owner_modules', ['shop_owner_id' => $owner->id]);
        Queue::assertNothingPushed();
    }

    public function test_registration_index_keeps_document_urls_protected(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $owner = $this->pendingRegistrationWithDocuments();
        $document = $owner->documents()->orderBy('id')->firstOrFail();

        $response = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.registrations.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('registrations', function ($registrations) use ($document, $owner): bool {
                    $registration = collect($registrations)->firstWhere('id', $owner->id);
                    $payload = collect($registration['documents'] ?? [])->firstWhere('id', $document->id);

                    return is_array($payload)
                        && $payload['id'] === $document->id
                        && $payload['type'] === $document->document_type
                        && $payload['documentType'] === $document->document_type
                        && $payload['logicalSlot'] === $document->logical_slot
                        && $payload['versionNumber'] === $document->version_number
                        && $payload['issuedOn'] === $document->issued_on?->toDateString()
                        && $payload['expirationMode'] === $document->expiration_mode
                        && $payload['expiresOn'] === $document->expires_on?->toDateString()
                        && $payload['validity'] === 'metadata_unverified'
                        && $payload['status'] === 'pending'
                        && $payload['url'] === route('admin.shop-documents.show', [
                            'shopOwner' => $owner->id,
                            'document' => $document->id,
                        ]);
                }));
        $this->assertStringNotContainsString('/storage/', $response->getContent());
        $this->assertStringNotContainsString('phase-two/', $response->getContent());
        $this->assertStringNotContainsString('checksum_sha256', $response->getContent());
        $this->assertStringNotContainsString('reviewed_by_super_admin_id', $response->getContent());
    }

    /**
     * @param array<int, string> $types
     * @param array<string, array{stored?: bool, status?: string, type?: string}> $overrides
     */
    private function createDocuments(ShopOwner $owner, array $types = [], array $overrides = []): void
    {
        $types = $types === []
            ? ['dti_registration', 'mayors_permit', 'bir_certificate', 'valid_id']
            : $types;

        foreach ($types as $type) {
            $options = $overrides[$type] ?? [];
            $path = "registration/{$owner->id}/{$type}.png";

            if (($options['stored'] ?? true) === true) {
                Storage::disk('local')->put($path, 'document');
            }

            $document = ShopDocument::create([
                'shop_owner_id' => $owner->id,
                'document_type' => $options['type'] ?? $type,
                'logical_slot' => in_array($type, ['dti_registration', 'sec_registration'], true)
                    ? 'business_registration'
                    : $type,
                'version_number' => 1,
                'is_current' => false,
                'file_path' => $path,
                'status' => $options['status'] ?? 'pending',
                'issued_on' => '2026-01-01',
                'expiration_mode' => $type === 'mayors_permit' ? 'dated' : 'none',
                'expires_on' => $type === 'mayors_permit' ? '2027-01-01' : null,
            ]);
            $document->forceFill(['disk' => 'local'])->save();
        }
    }

    private function pendingRegistrationWithDocuments(): ShopOwner
    {
        $owner = ShopOwner::factory()->pending()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $this->createDocuments($owner);

        return $owner->fresh();
    }

    /**
     * @return array{documents: array<int, array<string, int|string|bool|null>>}
     */
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

    private function assertApprovalValidationFailure(SuperAdmin $admin, ShopOwner $owner, string $type): void
    {
        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner), $this->approvalPayload($owner))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$type]);

        $this->assertDatabaseHas('shop_owners', ['id' => $owner->id, 'status' => 'pending']);
        Queue::assertNothingPushed();
    }

    private function assertDatabaseHasAndGetToken(ShopOwner $owner): string
    {
        $token = (string) \Illuminate\Support\Facades\DB::table('password_reset_tokens')
            ->where('email', $owner->email)
            ->value('token');

        $this->assertNotSame('', $token);

        return $token;
    }

    private function activityCount(string $event, ShopOwner $owner): int
    {
        return (int) \Spatie\Activitylog\Models\Activity::query()
            ->where('event', $event)
            ->where('subject_type', ShopOwner::class)
            ->where('subject_id', $owner->id)
            ->count();
    }

    /**
     * @param array<int, string> $requiredPayloadKeys
     */
    private function assertDeliveryQueued(
        PrivilegedDeliveryType $type,
        int $recipientId,
        array $requiredPayloadKeys,
    ): void {
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($type, $recipientId, $requiredPayloadKeys): bool {
            return $job->deliveryType === $type
                && $job->recipientType === 'shop_owner'
                && $job->recipientId === $recipientId
                && collect($requiredPayloadKeys)->every(fn (string $key): bool => array_key_exists($key, $job->payload));
        });
    }
}
