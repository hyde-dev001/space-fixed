<?php

namespace Tests\Feature\Notifications;

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
    public function manager_approval_creates_no_shop_owner_notification(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ]);
        Permission::findOrCreate('review-manager-repair-jobs', 'user');

        /** @var User $manager */
        $manager = User::factory()->createOne([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Manager Reviewer',
        ]);
        $manager->givePermissionTo('review-manager-repair-jobs');

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

        $this->assertSame('rejected', $repairRequest->fresh()->status);
        $this->assertDatabaseMissing('notifications', [
            'shop_owner_id' => $shopOwner->id,
            'type' => 'repair_rejection_review',
            'requires_action' => true,
        ]);
    }

    #[Test]
    public function manager_approval_remains_final_when_legacy_policy_is_off(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ]);
        Permission::findOrCreate('review-manager-repair-jobs', 'user');

        /** @var User $manager */
        $manager = User::factory()->createOne([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Manager Reviewer',
        ]);
        $manager->givePermissionTo('review-manager-repair-jobs');

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

        $this->assertSame('rejected', $repairRequest->fresh()->status);
        $this->assertDatabaseMissing('notifications', [
            'shop_owner_id' => $shopOwner->id,
            'type' => 'repair_rejection_review',
            'requires_action' => true,
        ]);
    }

}
