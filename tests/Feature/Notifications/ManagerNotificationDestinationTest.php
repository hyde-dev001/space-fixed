<?php

namespace Tests\Feature\Notifications;

use App\Models\ShopOwner;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ManagerNotificationDestinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_notifications_deep_link_to_canonical_operational_pages(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $manager = User::factory()->for($shop)->create(['role' => 'Manager']);
        $service = app(NotificationService::class);

        $service->notifyLeaveApproval(
            managerId: $manager->id,
            leaveData: [
                'employee_name' => 'Staff A',
                'start_date' => '2026-08-28',
                'end_date' => '2026-08-29',
            ],
            shopId: $shop->id,
        );
        $service->notifySuspensionRequestPending(
            managerId: $manager->id,
            suspensionData: ['employee_name' => 'Staff B'],
            shopId: $shop->id,
        );
        $service->notifyRepairRejectionReview(
            managerId: $manager->id,
            repairData: ['reason' => 'Repairer unavailable.'],
            shopId: $shop->id,
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->id,
            'action_url' => '/erp/manager/leave-approvals',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->id,
            'action_url' => '/erp/manager/suspension-approvals',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->id,
            'action_url' => '/erp/manager/repair-jobs',
        ]);
    }
}
