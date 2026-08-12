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
use Tests\TestCase;

final class ShopOwnerUpgradeReviewTest extends TestCase
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
}
