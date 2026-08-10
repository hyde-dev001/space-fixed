<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\SuperAdmin;
use App\Notifications\ShopOwnerUpgradeRequested;
use App\Notifications\ShopOwnerUpgradeReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ShopOwnerUpgradeReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
    }

    public function test_super_admin_can_list_filter_and_download_private_evidence_without_exposing_paths(): void
    {
        $admin = $this->createAdmin();
        [$owner, $upgradeRequest] = $this->submitRequest();

        $list = $this->actingAs($admin, 'super_admin')
            ->getJson(route('admin.business-upgrade-requests.index', ['status' => 'pending', 'search' => $owner->business_name]));

        $list->assertOk()
            ->assertJsonPath('data.0.id', $upgradeRequest->id)
            ->assertJsonMissing(['path' => $upgradeRequest->documents()->firstOrFail()->path]);

        $document = $upgradeRequest->documents()->firstOrFail();
        $download = $this->actingAs($admin, 'super_admin')
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
            ->assertRedirect(route('admin.login'));

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

        $response = $this->actingAs($admin, 'super_admin')
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

        Notification::assertSentTo($owner, ShopOwnerUpgradeReviewed::class);
        Notification::assertSentTo($admin, ShopOwnerUpgradeRequested::class);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ShopOwnerUpgradeRequest::class,
            'subject_id' => $upgradeRequest->id,
            'description' => 'shop_owner_upgrade_approved',
        ]);
    }

    public function test_rejection_requires_a_reason_and_leaves_owner_and_modules_unchanged(): void
    {
        $admin = $this->createAdmin();
        [$owner, $upgradeRequest] = $this->submitRequest();
        $before = $owner->only(['registration_type', 'business_type']);

        $this->actingAs($admin, 'super_admin')
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'rejected',
            ])
            ->assertUnprocessable();

        $this->actingAs($admin, 'super_admin')
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'rejected',
                'decision_reason' => 'Evidence did not meet the current requirements.',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', ShopOwnerUpgradeRequest::STATUS_REJECTED);

        $this->assertSame($before, $owner->fresh()->only(['registration_type', 'business_type']));
        $this->assertDatabaseCount('shop_owner_modules', 0);
        Notification::assertSentTo($owner, ShopOwnerUpgradeReviewed::class);
    }

    public function test_stale_evidence_blocks_approval_without_changing_owner_state(): void
    {
        $admin = $this->createAdmin();
        [$owner, $upgradeRequest] = $this->submitRequest();
        $document = $upgradeRequest->documents()->firstOrFail();
        Storage::disk('local')->put($document->path, 'tampered');

        $this->actingAs($admin, 'super_admin')
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
            ])
            ->assertUnprocessable();

        $this->assertSame(ShopOwnerUpgradeRequest::STATUS_PENDING, $upgradeRequest->fresh()->status);
        $this->assertSame('individual', $owner->fresh()->registration_type);
        $this->assertDatabaseCount('shop_owner_modules', 0);
        Notification::assertNotSentTo($owner, ShopOwnerUpgradeReviewed::class);
    }

    public function test_owner_change_supersedes_a_pending_request_and_replay_returns_conflict(): void
    {
        $admin = $this->createAdmin();
        [$owner, $upgradeRequest] = $this->submitRequest();
        $owner->update(['business_type' => 'repair']);

        $this->actingAs($admin, 'super_admin')
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
            ])
            ->assertStatus(409)
            ->assertJsonPath('request.status', ShopOwnerUpgradeRequest::STATUS_SUPERSEDED);

        Notification::assertSentTo($owner, ShopOwnerUpgradeReviewed::class);
        $this->assertSame('repair', $owner->fresh()->business_type);

        $this->actingAs($admin, 'super_admin')
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
            ])
            ->assertStatus(409);

        Notification::assertSentToTimes($owner, ShopOwnerUpgradeReviewed::class, 1);
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
        return SuperAdmin::create([
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
