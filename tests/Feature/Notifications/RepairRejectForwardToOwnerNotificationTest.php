<?php

namespace Tests\Feature\Notifications;

use App\Models\ProcurementSettings;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RepairRejectForwardToOwnerNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function manager_forward_to_owner_creates_shop_owner_notification(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ]);
        $this->setRepairRejectPolicy($shopOwner, true);

        Permission::findOrCreate('access-repair-reject-review', 'user');

        /** @var User $manager */
        $manager = User::factory()->createOne([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Manager Reviewer',
        ]);
        $manager->givePermissionTo('access-repair-reject-review');

        $repairRequest = RepairRequest::factory()
            ->for($shopOwner)
            ->create([
                'request_id' => 'REP-FWD-001',
                'status' => 'repairer_rejected',
                'requires_owner_approval' => true,
                'customer_name' => 'Miguel Dela Rosa',
                'repairer_rejection_reason' => 'Cannot proceed due to unavailable materials.',
                'repairer_rejected_at' => now()->subHour(),
            ]);

        $response = $this->actingAs($manager, 'user')
            ->postJson("/api/manager/repairs/{$repairRequest->id}/approve-rejection", [
                'notes' => 'Forwarding to owner for final review.',
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('notifications', [
            'shop_owner_id' => $shopOwner->id,
            'type' => 'repair_rejection_review',
            'title' => 'Repair Rejection Awaiting Your Review',
            'action_url' => "/shop-owner/action-center?bucket=needs_my_decision&approval=repair_rejection:{$repairRequest->id}",
            'requires_action' => true,
        ]);
    }

    #[Test]
    public function manager_approval_when_policy_is_off_does_not_create_shop_owner_notification(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ]);
        $this->setRepairRejectPolicy($shopOwner, false);

        Permission::findOrCreate('access-repair-reject-review', 'user');

        /** @var User $manager */
        $manager = User::factory()->createOne([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Manager Reviewer',
        ]);
        $manager->givePermissionTo('access-repair-reject-review');

        $repairRequest = RepairRequest::factory()
            ->for($shopOwner)
            ->create([
                'request_id' => 'REP-FWD-OFF-001',
                'status' => 'repairer_rejected',
                'requires_owner_approval' => false,
                'repairer_rejection_reason' => 'Cannot proceed due to unavailable materials.',
                'repairer_rejected_at' => now()->subHour(),
            ]);

        $response = $this->actingAs($manager, 'user')
            ->postJson("/api/manager/repairs/{$repairRequest->id}/approve-rejection", [
                'notes' => 'Initial review complete; final manager review remains.',
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('manager_reviewing', $repairRequest->fresh()->status);
        $this->assertDatabaseMissing('notifications', [
            'shop_owner_id' => $shopOwner->id,
            'type' => 'repair_rejection_review',
            'requires_action' => true,
        ]);
    }

    private function setRepairRejectPolicy(ShopOwner $shopOwner, bool $enabled): void
    {
        $settings = ProcurementSettings::getForShopOwner($shopOwner->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['repair_reject_approval']['enabled'] = $enabled;
        $settings->update(['settings_json' => $settingsJson]);
    }
}
