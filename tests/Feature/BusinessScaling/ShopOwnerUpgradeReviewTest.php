<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Enums\PrivilegedDeliveryType;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\Concerns\ResetsInMemoryDatabaseState;
use Tests\TestCase;

final class ShopOwnerUpgradeReviewTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use ResetsInMemoryDatabaseState;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
    }

    public function test_super_admin_can_list_filter_and_download_private_evidence_without_exposing_paths(): void
    {
        $admin = $this->createAdmin();
        [$owner, $upgradeRequest] = $this->submitRequest();

        $list = $this->actingAsCompletedPrivileged($admin)
            ->getJson(route('admin.business-upgrade-requests.index', ['status' => 'pending', 'search' => $owner->business_name]));

        $list->assertOk()
            ->assertJsonPath('data.0.id', $upgradeRequest->id)
            ->assertJsonMissing(['path' => $upgradeRequest->documents()->firstOrFail()->path]);

        $document = $upgradeRequest->documents()->firstOrFail();
        $download = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.business-upgrade-requests.documents.download', [$upgradeRequest, $document]));

        $download->assertOk();
        $this->assertStringNotContainsString($document->path, (string) $download->getContent());

        $view = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.business-upgrade-requests.documents.view', [$upgradeRequest, $document]));

        $view->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename='.$document->document_type.'.pdf');
        $this->assertStringNotContainsString($document->path, (string) $view->getContent());
    }

    public function test_upgrade_queue_is_capped_and_deterministic_for_equal_timestamps(): void
    {
        $admin = $this->createAdmin();
        $timestamp = now()->subDays(2);
        $requests = ShopOwnerUpgradeRequest::factory()->count(3)->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $expectedIds = $requests->sortByDesc('id')->values()->pluck('id')->all();

        $pageOne = $this->actingAsCompletedPrivileged($admin)
            ->getJson(route('admin.business-upgrade-requests.index', ['per_page' => 1, 'page' => 1]));
        $pageTwo = $this->actingAsCompletedPrivileged($admin)
            ->getJson(route('admin.business-upgrade-requests.index', ['per_page' => 1, 'page' => 2]));

        $pageOne->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.id', $expectedIds[0]);
        $pageTwo->assertOk()->assertJsonPath('data.0.id', $expectedIds[1]);

        foreach ([
            ['page' => 'abc'],
            ['page' => 0],
            ['per_page' => 'abc'],
            ['per_page' => 0],
            ['per_page' => 101],
            ['status' => 'not-a-status'],
        ] as $query) {
            $this->actingAsCompletedPrivileged($admin)
                ->getJson(route('admin.business-upgrade-requests.index', $query))
                ->assertUnprocessable();
        }
    }

    public function test_upgrade_queue_relation_queries_do_not_grow_per_row(): void
    {
        $admin = $this->createAdmin();
        ShopOwnerUpgradeRequest::factory()->create();

        $measure = function () use ($admin): int {
            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            $this->actingAsCompletedPrivileged($admin)
                ->getJson(route('admin.business-upgrade-requests.index'))
                ->assertOk();
            $count = count(DB::connection()->getQueryLog());
            DB::connection()->disableQueryLog();

            return $count;
        };

        $smallCount = $measure();
        ShopOwnerUpgradeRequest::factory()->count(30)->create();
        $largeCount = $measure();
        self::assertLessThanOrEqual($smallCount + 2, $largeCount);
    }

    public function test_owner_or_user_cannot_list_review_or_download_another_shop_evidence(): void
    {
        [$owner, $upgradeRequest] = $this->submitRequest();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $document = $upgradeRequest->documents()->firstOrFail();

        $this->actingAs($owner, 'shop_owner')
            ->get(route('admin.business-upgrade-requests.index'))
            ->assertRedirect(route('admin.login'));

        $this->actingAs($owner, 'shop_owner')
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
            ])
            ->assertUnauthorized();

        $this->actingAs($otherOwner, 'shop_owner')
            ->get(route('admin.business-upgrade-requests.documents.download', [$upgradeRequest, $document]))
            ->assertRedirect(route('admin.login'));
    }

    public function test_approval_requires_every_submitted_document_to_be_reviewed(): void
    {
        $admin = $this->createAdmin();
        [$owner, $upgradeRequest] = $this->submitRequest();

        $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents');

        $this->assertSame(ShopOwnerUpgradeRequest::STATUS_PENDING, $upgradeRequest->fresh()->status);
        $this->assertSame('individual', $owner->fresh()->registration_type);

        $partialReview = $this->reviewedDocuments($upgradeRequest);
        $partialReview[0]['viewed'] = false;

        $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
                'documents' => $partialReview,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents.0.viewed');

        $this->assertSame(ShopOwnerUpgradeRequest::STATUS_PENDING, $upgradeRequest->fresh()->status);

        $reviewWithUnknownDocument = $this->reviewedDocuments($upgradeRequest);
        $reviewWithUnknownDocument[] = ['id' => 999999, 'viewed' => true];

        $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
                'documents' => $reviewWithUnknownDocument,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents');

        $this->assertSame(ShopOwnerUpgradeRequest::STATUS_PENDING, $upgradeRequest->fresh()->status);
    }

    public function test_approval_updates_owner_provisions_only_new_modules_and_notifies_after_commit(): void
    {
        $admin = $this->createAdmin();
        [$owner, $upgradeRequest] = $this->submitRequest([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => false,
        ]);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
                'documents' => $this->reviewedDocuments($upgradeRequest),
            ]);

        $response->assertOk()
            ->assertJsonPath('request.status', ShopOwnerUpgradeRequest::STATUS_APPROVED)
            ->assertJsonPath('dormant_employee_permission_warning', true);
        $this->assertContains('hr_employees', $response->json('newly_enabled_module_keys'));

        $owner->refresh();
        $this->assertSame('company', $owner->registration_type);
        $this->assertSame('both', $owner->business_type);
        $this->assertFalse((bool) $owner->modules()->where('module_key', 'retail_operations')->value('enabled'));
        $this->assertDatabaseHas('shop_owner_modules', [
            'shop_owner_id' => $owner->id,
            'module_key' => 'repair_operations',
            'enabled' => 1,
        ]);

        DB::commit();
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($owner, $upgradeRequest): bool {
            return $job->deliveryType === PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REVIEWED
                && $job->recipientType === 'shop_owner'
                && $job->recipientId === $owner->id
                && $job->businessEventId === 'shop-owner-upgrade-reviewed:'.$upgradeRequest->id
                && $job->payload['decision'] === ShopOwnerUpgradeRequest::STATUS_APPROVED
                && $job->payload['dormant_employee_permission_warning'] === true;
        });
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ShopOwnerUpgradeRequest::class,
            'subject_id' => $upgradeRequest->id,
            'description' => 'shop_owner_upgrade_reviewed',
        ]);
        $this->assertDatabaseHas('notifications', [
            'shop_owner_id' => $owner->id,
            'type' => 'business_upgrade_request_approved',
            'action_url' => '/shop-owner/settings',
            'is_read' => false,
        ]);
        $this->actingAsCompletedPrivileged($admin)
            ->getJson(route('admin.business-upgrade-requests.index', ['status' => 'approved']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $upgradeRequest->id)
            ->assertJsonPath('data.0.status', ShopOwnerUpgradeRequest::STATUS_APPROVED);
        $this->actingAsCompletedPrivileged($admin)
            ->getJson(route('admin.business-upgrade-requests.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $upgradeRequest->id)
            ->assertJsonPath('data.0.status', ShopOwnerUpgradeRequest::STATUS_APPROVED);
    }

    public function test_rejection_requires_a_reason_and_leaves_owner_and_modules_unchanged(): void
    {
        $admin = $this->createAdmin();
        [$owner, $upgradeRequest] = $this->submitRequest();
        $before = $owner->only(['registration_type', 'business_type']);

        $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'rejected',
            ])
            ->assertUnprocessable();

        $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'rejected',
                'decision_reason' => 'Evidence did not meet the current requirements.',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', ShopOwnerUpgradeRequest::STATUS_REJECTED);
        DB::commit();

        $this->assertSame($before, $owner->fresh()->only(['registration_type', 'business_type']));
        $this->assertDatabaseCount('shop_owner_modules', 0);
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($owner): bool {
            return $job->deliveryType === PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REVIEWED
                && $job->recipientType === 'shop_owner'
                && $job->recipientId === $owner->id
                && $job->payload['decision'] === ShopOwnerUpgradeRequest::STATUS_REJECTED;
        });
        $this->assertDatabaseHas('notifications', [
            'shop_owner_id' => $owner->id,
            'type' => 'business_upgrade_request_rejected',
            'action_url' => '/shop-owner/settings',
            'is_read' => false,
        ]);
    }

    public function test_stale_evidence_blocks_approval_without_changing_owner_state(): void
    {
        $admin = $this->createAdmin();
        [$owner, $upgradeRequest] = $this->submitRequest();
        $document = $upgradeRequest->documents()->firstOrFail();
        Storage::disk('local')->put($document->path, 'tampered');

        $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
                'documents' => $this->reviewedDocuments($upgradeRequest),
            ])
            ->assertUnprocessable();

        $this->assertSame(ShopOwnerUpgradeRequest::STATUS_PENDING, $upgradeRequest->fresh()->status);
        $this->assertSame('individual', $owner->fresh()->registration_type);
        $this->assertDatabaseCount('shop_owner_modules', 0);
        Queue::assertNotPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($owner): bool {
            return $job->deliveryType === PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REVIEWED
                && $job->recipientId === $owner->id;
        });
    }

    public function test_owner_change_supersedes_a_pending_request_and_replay_returns_conflict(): void
    {
        $admin = $this->createAdmin();
        [$owner, $upgradeRequest] = $this->submitRequest();
        $owner->update(['business_type' => 'repair']);

        $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
                'documents' => $this->reviewedDocuments($upgradeRequest),
            ])
            ->assertStatus(409)
            ->assertJsonPath('request.status', ShopOwnerUpgradeRequest::STATUS_SUPERSEDED);
        DB::commit();

        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($owner): bool {
            return $job->deliveryType === PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REVIEWED
                && $job->recipientId === $owner->id
                && $job->payload['decision'] === ShopOwnerUpgradeRequest::STATUS_SUPERSEDED;
        });
        $this->assertSame('repair', $owner->fresh()->business_type);

        $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
                'documents' => $this->reviewedDocuments($upgradeRequest),
            ])
            ->assertStatus(409);

        $this->assertCount(1, Queue::pushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($owner): bool {
            return $job->deliveryType === PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REVIEWED
                && $job->recipientId === $owner->id;
        }));
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ShopOwnerUpgradeRequest::class,
            'subject_id' => $upgradeRequest->id,
            'description' => 'shop_owner_upgrade_superseded',
        ]);
    }

    /**
     * @param  array<string, string>  $ownerOverrides
     * @return array{0: ShopOwner, 1: ShopOwnerUpgradeRequest}
     */
    private function submitRequest(array $ownerOverrides = []): array
    {
        $owner = ShopOwner::factory()->approved()->create(array_merge([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ], $ownerOverrides));

        $this->actingAs($owner, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), [
                'requested_registration_type' => 'company',
                'requested_business_type' => 'both',
                'documents' => [
                    'dti_registration' => UploadedFile::fake()->createWithContent('dti_registration.pdf', 'dti-bytes'),
                    'mayors_permit' => UploadedFile::fake()->createWithContent('mayors_permit.pdf', 'permit-bytes'),
                    'bir_certificate' => UploadedFile::fake()->createWithContent('bir_certificate.pdf', 'bir-bytes'),
                    'valid_id' => UploadedFile::fake()->createWithContent('valid_id.pdf', 'id-bytes'),
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        return [$owner->fresh(), ShopOwnerUpgradeRequest::query()->latest('id')->firstOrFail()];
    }

    private function createAdmin(): SuperAdmin
    {
        return SuperAdmin::factory()->superAdmin()->create([
            'first_name' => 'Review',
            'last_name' => 'Admin',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09170000001',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    /**
     * @return array<int, array{id: int, viewed: bool}>
     */
    private function reviewedDocuments(ShopOwnerUpgradeRequest $upgradeRequest): array
    {
        return $upgradeRequest->documents()
            ->orderBy('id')
            ->get(['id'])
            ->map(fn ($document): array => [
                'id' => (int) $document->id,
                'viewed' => true,
            ])
            ->all();
    }
}
