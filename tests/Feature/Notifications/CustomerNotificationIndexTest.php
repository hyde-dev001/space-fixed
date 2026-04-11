<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerNotificationIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_notification_index_returns_user_owned_notifications_even_with_shop_owner_id_set(): void
    {
        /** @var User $customer */
        $customer = User::factory()->create();
        /** @var ShopOwner $shopOwner */
        $shopOwner = ShopOwner::factory()->approved()->create();

        Notification::query()->create([
            'user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'type' => NotificationType::REPAIR_STATUS_UPDATE->value,
            'priority' => 'medium',
            'title' => 'Repair Status Updated',
            'message' => 'Repair request is under review.',
            'is_read' => false,
            'is_archived' => false,
        ]);

        Notification::query()->create([
            'user_id' => $customer->id,
            'type' => NotificationType::ORDER_PLACED->value,
            'priority' => 'medium',
            'title' => 'Order Placed',
            'message' => 'Your order has been placed.',
            'is_read' => false,
            'is_archived' => false,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->getJson('/api/notifications?page=1&unread_only=false&archived=false');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('total', 2);
    }
}
