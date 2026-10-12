<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Http\Controllers\superAdmin\ShopOwnerRegistrationViewController;
use App\Http\Requests\SuperAdmin\ApproveShopOwnerRegistrationRequest;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\Concerns\BuildsPhaseTwoWorkflowFixtures;
use Tests\TestCase;

final class ShopModuleInitialApprovalTest extends TestCase
{
    use RefreshDatabase;
    use AuthenticatesPrivilegedUsers;
    use BuildsPhaseTwoWorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_approval_initializes_eligible_modules_without_touching_pending_or_rejected_rows(): void
    {
        Notification::fake();
        $pending = $this->pendingRegistrationWithRequiredDocuments();
        $pending->forceFill([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ])->save();
        $rejected = ShopOwner::factory()->rejected()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $this->assertDatabaseMissing('shop_owner_modules', ['shop_owner_id' => $pending->id]);
        $this->assertDatabaseMissing('shop_owner_modules', ['shop_owner_id' => $rejected->id]);

        $response = $this->approve($pending);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('shop_owner_modules', [
            'shop_owner_id' => $pending->id,
            'module_key' => 'retail_operations',
            'enabled' => 1,
        ]);
        $this->assertDatabaseMissing('shop_owner_modules', [
            'shop_owner_id' => $pending->id,
            'module_key' => 'repair_operations',
        ]);
        $this->assertDatabaseMissing('shop_owner_modules', ['shop_owner_id' => $rejected->id]);
    }

    public function test_repeated_approval_preserves_an_existing_disabled_choice(): void
    {
        Notification::fake();
        $owner = $this->pendingRegistrationWithRequiredDocuments();
        $owner->forceFill([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ])->save();
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => false,
        ]);

        $this->approve($owner);
        $this->assertFalse((bool) $owner->modules()->where('module_key', 'retail_operations')->value('enabled'));

        $this->approve($owner->fresh());
        $this->assertFalse((bool) $owner->modules()->where('module_key', 'retail_operations')->value('enabled'));
    }

    private function approve(ShopOwner $owner)
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $this->actingAsCompletedPrivileged($admin);
        $request = ApproveShopOwnerRegistrationRequest::create(
            '/admin/registrations/'.$owner->id.'/approve',
            'POST',
            $this->approvalPayloadFor($owner),
        );
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(static fn (?string $guard = null) => $guard === 'super_admin' ? $admin : null);

        return app(ShopOwnerRegistrationViewController::class)->approve($request, $owner->id);
    }
}
