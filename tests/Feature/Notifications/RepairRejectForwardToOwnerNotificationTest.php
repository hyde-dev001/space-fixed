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
    public function manager_forward_to_owner_creates_shop_owner_notification(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ]);

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
            'action_url' => '/shop-owner/repair-reject-approval',
            'requires_action' => true,
        ]);
    }
}
