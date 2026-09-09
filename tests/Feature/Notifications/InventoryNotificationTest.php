<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Events\LowStockAlert;
use App\Events\OutOfStockAlert;
use App\Listeners\SendLowStockNotification;
use App\Listeners\SendOutOfStockNotification;
use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\Notification as DatabaseNotification;
use App\Models\ShopOwner;
use App\Models\StockRequestApproval;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Notifications\OutOfStockNotification;
use App\Services\StockRequestApprovalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InventoryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_variant_alert_notifications_are_queued_and_only_reach_same_shop_inventory_viewers(): void
    {
        Mail::fake();

        $shop = ShopOwner::factory()->create();
        $otherShop = ShopOwner::factory()->create();
        $item = InventoryItem::factory()->create(['shop_owner_id' => $shop->id]);
        $color = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'Black',
            'quantity' => 3,
        ]);
        $size = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $color->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'reorder_level' => 5,
            'reorder_quantity' => 20,
        ]);
        $permission = Permission::findOrCreate('inventory.view', 'user');
        $viewer = User::factory()->for($shop)->create();
        $viewer->givePermissionTo($permission);
        $sameShopWithoutPermission = User::factory()->for($shop)->create();
        $otherShopViewer = User::factory()->for($otherShop)->create();
        $otherShopViewer->givePermissionTo($permission);
        $target = [
            'type' => 'size',
            'id' => $size->id,
            'inventory_color_variant_id' => $color->id,
            'inventory_size_id' => $size->id,
            'color_name' => 'Black',
            'size' => '8',
            'size_system' => 'US',
            'requested_size' => 'US 8',
            'reorder_level' => 5,
            'reorder_quantity' => 20,
        ];

        (new SendLowStockNotification())->handle(new LowStockAlert($item, 3, 5, $target));
        (new SendOutOfStockNotification())->handle(new OutOfStockAlert($item, $target));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $viewer->id,
            'type' => NotificationType::LOW_STOCK_ALERT->value,
            'title' => 'Low Stock Alert',
        ]);

        $notifications = DatabaseNotification::query()
            ->where('user_id', $viewer->id)
            ->where('type', NotificationType::LOW_STOCK_ALERT->value)
            ->get();
        self::assertCount(2, $notifications);

        $lowStock = $notifications->first(fn (DatabaseNotification $notification): bool => ($notification->data['type'] ?? null) === 'low_stock');
        self::assertNotNull($lowStock);
        self::assertSame($item->name . ' - Black - US 8', $lowStock->data['target_name']);
        self::assertSame($color->id, $lowStock->data['inventory_color_variant_id']);
        self::assertSame($size->id, $lowStock->data['inventory_size_id']);
        self::assertSame(3, $lowStock->data['current_quantity']);
        self::assertSame(5, $lowStock->data['reorder_level']);
        self::assertSame('medium', $lowStock->priority);

        $outOfStock = $notifications->first(fn (DatabaseNotification $notification): bool => ($notification->data['type'] ?? null) === 'out_of_stock');
        self::assertNotNull($outOfStock);
        self::assertSame($item->name . ' - Black - US 8', $outOfStock->data['target_name']);
        self::assertSame(20, $outOfStock->data['reorder_quantity']);
        self::assertSame('high', $outOfStock->priority);

        $this->assertDatabaseMissing('notifications', ['user_id' => $sameShopWithoutPermission->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $otherShopViewer->id]);
        self::assertInstanceOf(ShouldQueue::class, new SendLowStockNotification());
        self::assertInstanceOf(ShouldQueue::class, new SendOutOfStockNotification());
        self::assertInstanceOf(ShouldQueue::class, new LowStockNotification($item, 3, 5, $target));
        self::assertInstanceOf(ShouldQueue::class, new OutOfStockNotification($item, $target));
    }

    public function test_automatic_stock_request_notification_prefers_procurement_manager_and_keeps_variant_context(): void
    {
        Mail::fake();

        $shop = ShopOwner::factory()->create();
        $otherShop = ShopOwner::factory()->create();
        $requester = User::factory()->for($shop)->create();
        $procurement = User::factory()->for($shop)->create();
        $finance = User::factory()->for($shop)->create();
        $otherShopProcurement = User::factory()->for($otherShop)->create();
        $procurement->assignRole(Role::findOrCreate('Procurement Manager', 'user'));
        $finance->assignRole(Role::findOrCreate('Finance', 'user'));
        $otherShopProcurement->assignRole(Role::findOrCreate('Procurement Manager', 'user'));
        $item = InventoryItem::factory()->create(['shop_owner_id' => $shop->id]);
        $stockRequest = StockRequestApproval::factory()->create([
            'shop_owner_id' => $shop->id,
            'requested_by' => $requester->id,
            'inventory_item_id' => $item->id,
            'product_name' => $item->name,
            'sku_code' => $item->sku,
            'quantity_needed' => 17,
            'priority' => 'high',
            'status' => 'pending',
            'requested_color' => 'black',
            'requested_size' => 'US 8',
            'request_source' => 'manual',
            'is_auto_generated' => true,
        ]);

        app(StockRequestApprovalService::class)->notifyStockRequestSubmitted($stockRequest);

        $notification = DatabaseNotification::query()
            ->where('user_id', $procurement->id)
            ->where('type', NotificationType::PURCHASE_REQUEST_SUBMITTED->value)
            ->sole();
        self::assertSame($stockRequest->id, $notification->data['request_id']);
        self::assertSame('black', $notification->data['requested_color']);
        self::assertSame('US 8', $notification->data['requested_size']);
        self::assertSame(17, (int) $notification->data['quantity_needed']);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $finance->id,
            'type' => NotificationType::PURCHASE_REQUEST_SUBMITTED->value,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $otherShopProcurement->id,
            'type' => NotificationType::PURCHASE_REQUEST_SUBMITTED->value,
        ]);
    }

    public function test_automatic_stock_request_notification_falls_back_to_same_shop_finance(): void
    {
        Mail::fake();

        $shop = ShopOwner::factory()->create();
        $otherShop = ShopOwner::factory()->create();
        $requester = User::factory()->for($shop)->create();
        $finance = User::factory()->for($shop)->create();
        $otherShopFinance = User::factory()->for($otherShop)->create();
        $finance->assignRole(Role::findOrCreate('Finance', 'user'));
        $otherShopFinance->assignRole(Role::findOrCreate('Finance', 'user'));
        $item = InventoryItem::factory()->create(['shop_owner_id' => $shop->id]);
        $stockRequest = StockRequestApproval::factory()->create([
            'shop_owner_id' => $shop->id,
            'requested_by' => $requester->id,
            'inventory_item_id' => $item->id,
            'product_name' => $item->name,
            'sku_code' => $item->sku,
            'quantity_needed' => 8,
            'requested_color' => 'white',
            'requested_size' => 'US 9',
            'request_source' => 'manual',
            'is_auto_generated' => true,
        ]);

        app(StockRequestApprovalService::class)->notifyStockRequestSubmitted($stockRequest);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $finance->id,
            'type' => NotificationType::PURCHASE_REQUEST_SUBMITTED->value,
            'title' => 'New Stock Request Submitted',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $otherShopFinance->id,
            'type' => NotificationType::PURCHASE_REQUEST_SUBMITTED->value,
        ]);
    }
}

