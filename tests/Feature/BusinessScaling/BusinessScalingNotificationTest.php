<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\SuperAdmin;
use App\Notifications\ShopOwnerUpgradeRequested;
use App\Notifications\ShopOwnerUpgradeReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BusinessScalingNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
    }

    public function test_submission_notifies_only_active_super_admins_after_commit(): void
    {
        $active = $this->admin('active');
        $inactive = $this->admin('suspended');
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), [
                'requested_registration_type' => 'company',
                'requested_business_type' => 'both',
                'documents' => [
                    'dti_registration' => UploadedFile::fake()->createWithContent('dti_registration.pdf', 'dti'),
                    'mayors_permit' => UploadedFile::fake()->createWithContent('mayors_permit.pdf', 'permit'),
                    'bir_certificate' => UploadedFile::fake()->createWithContent('bir_certificate.pdf', 'bir'),
                    'valid_id' => UploadedFile::fake()->createWithContent('valid_id.pdf', 'id'),
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        Notification::assertSentTo($active, ShopOwnerUpgradeRequested::class);
        Notification::assertNotSentTo($inactive, ShopOwnerUpgradeRequested::class);
        $this->assertDatabaseHas('shop_owner_upgrade_requests', [
            'shop_owner_id' => $owner->id,
            'status' => ShopOwnerUpgradeRequest::STATUS_PENDING,
        ]);
    }

    public function test_review_notification_contains_no_private_path_or_employee_data(): void
    {
        $admin = $this->admin('active');
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), [
                'requested_registration_type' => 'company',
                'requested_business_type' => 'both',
                'documents' => [
                    'dti_registration' => UploadedFile::fake()->createWithContent('dti_registration.pdf', 'dti'),
                    'mayors_permit' => UploadedFile::fake()->createWithContent('mayors_permit.pdf', 'permit'),
                    'bir_certificate' => UploadedFile::fake()->createWithContent('bir_certificate.pdf', 'bir'),
                    'valid_id' => UploadedFile::fake()->createWithContent('valid_id.pdf', 'id'),
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $request = ShopOwnerUpgradeRequest::query()->latest('id')->firstOrFail();
        $paths = $request->documents()->pluck('path')->all();

        $this->actingAs($admin, 'super_admin')
            ->patchJson(route('admin.business-upgrade-requests.update', $request), ['decision' => 'rejected', 'decision_reason' => 'Please update the permit.'])
            ->assertOk();

        Notification::assertSentTo($owner, ShopOwnerUpgradeReviewed::class, function (ShopOwnerUpgradeReviewed $notification): bool {
            $payload = $notification->toArray(null);

            return ! array_key_exists('path', $payload)
                && ! array_key_exists('employee_id', $payload)
                && $payload['decision'] === ShopOwnerUpgradeRequest::STATUS_REJECTED;
        });

        foreach ($paths as $path) {
            $this->assertStringNotContainsString($path, json_encode($request->fresh()->toArray()));
        }
    }

    private function admin(string $status): SuperAdmin
    {
        return SuperAdmin::create([
            'first_name' => 'Notification',
            'last_name' => 'Admin',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09170000002',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => $status,
        ]);
    }
}
