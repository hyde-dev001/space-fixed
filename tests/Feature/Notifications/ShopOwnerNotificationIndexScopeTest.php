<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShopOwnerNotificationIndexScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shop_owner_notification_index_includes_linked_user_notifications(): void
    {
        /** @var ShopOwner $shopOwner */
        $shopOwner = ShopOwner::factory()->approved()->create([
            'email' => 'owner-linked@example.test',
        ]);

        /** @var User $linkedUser */
        $linkedUser = User::factory()->create([
            'email' => 'owner-linked@example.test',
            'shop_owner_id' => $shopOwner->id,
        ]);

        Notification::query()->create([
            'user_id' => $linkedUser->id,
            'shop_id' => $shopOwner->id,
            'type' => NotificationType::NEW_ORDER->value,
            'priority' => 'medium',
            'title' => 'New order assigned',
            'message' => 'You received a new order.',
            'is_read' => false,
            'is_archived' => false,
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/notifications?page=1&unread_only=false&archived=false');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('total', 1);
    }
}
