<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Http\Controllers\superAdmin\ShopOwnerRegistrationViewController;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class ShopModuleInitialApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_initializes_eligible_modules_without_touching_pending_or_rejected_rows(): void
    {
        Notification::fake();
        $pending = ShopOwner::factory()->pending()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
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
        $owner = ShopOwner::factory()->pending()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
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
        $request = Request::create('/superAdmin/shop-owner-registration/'.$owner->id.'/approve', 'POST');
        $request->headers->set('Accept', 'application/json');

        return app(ShopOwnerRegistrationViewController::class)->approve($request, $owner->id);
    }
}
