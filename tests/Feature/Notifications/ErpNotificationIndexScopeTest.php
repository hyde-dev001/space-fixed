<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ErpNotificationIndexScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function erp_staff_notification_index_includes_null_shop_id_notifications(): void
    {
        /** @var ShopOwner $shopOwner */
        $shopOwner = ShopOwner::factory()->approved()->create();

        /** @var User $staffUser */
        $staffUser = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        Notification::query()->create([
            'user_id' => $staffUser->id,
            'shop_id' => null,
            'type' => NotificationType::TASK_ASSIGNED->value,
            'priority' => 'medium',
            'title' => 'Task assigned',
            'message' => 'You have a new task.',
            'is_read' => false,
            'is_archived' => false,
        ]);

        $response = $this->actingAs($staffUser, 'user')
            ->getJson('/api/staff/notifications?page=1&unread_only=false');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('total', 1);
    }
}
