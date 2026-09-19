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
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InventoryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_in_transit_notifies_active_same_shop_inventory_viewers_once(): void
    {
        $shop = ShopOwner::factory()->create();
        $otherShop = ShopOwner::factory()->create();
        $permission = Permission::findOrCreate('inventory.view', 'user');
        $viewer = User::factory()->for($shop)->create(['status' => 'active']);
        $viewer->givePermissionTo($permission);
        $inactiveViewer = User::factory()->for($shop)->create(['status' => 'inactive']);
        $inactiveViewer->givePermissionTo($permission);
        $otherShopViewer = User::factory()->for($otherShop)->create(['status' => 'active']);
        $otherShopViewer->givePermissionTo($permission);
        $sameShopWithoutPermission = User::factory()->for($shop)->create(['status' => 'active']);

        $payload = [
            'purchase_order_id' => 17,
            'po_number' => 'PO-2026-017',
            'supplier_id' => 23,
            'supplier_name' => 'Supplier Trading',
            'expected_delivery' => '2026-09-20',
            'status' => 'in_transit',
            'shop_id' => $shop->id,
        ];

        app(NotificationService::class)->notifyPurchaseOrderInTransit($shop->id, $payload);
        app(NotificationService::class)->notifyPurchaseOrderInTransit($shop->id, $payload);

        $notification = DatabaseNotification::query()->where('user_id', $viewer->id)->sole();
        $this->assertSame(NotificationType::PURCHASE_ORDER_IN_TRANSIT->value, $notification->type->value);
        $this->assertSame($payload, $notification->data);
        $this->assertSame('purchase-order:17:in-transit', $notification->group_key);
        $this->assertDatabaseMissing('notifications', ['user_id' => $inactiveViewer->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $otherShopViewer->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $sameShopWithoutPermission->id]);
        $this->assertSame(1, DatabaseNotification::query()->where('group_key', 'purchase-order:17:in-transit')->count());
    }

    public function test_supplier_replacement_in_transit_notifies_inventory_receivers_even_when_role_label_differs(): void
    {
        $shop = ShopOwner::factory()->create();
        $otherShop = ShopOwner::factory()->create();
        $inventoryRole = Role::findOrCreate('Inventory', 'user');
        $inventoryRole->syncPermissions([
            Permission::findOrCreate('view-inventory', 'user'),
            Permission::findOrCreate('procurement.receive_purchase_orders', 'user'),
        ]);

        $inventoryReceiver = User::factory()->for($shop)->create(['status' => 'active']);
        $inventoryReceiver->assignRole($inventoryRole);
        $inactiveReceiver = User::factory()->for($shop)->create(['status' => 'inactive']);
        $inactiveReceiver->assignRole($inventoryRole);
        $otherShopReceiver = User::factory()->for($otherShop)->create(['status' => 'active']);
        $otherShopReceiver->assignRole($inventoryRole);

        $payload = [
            'adjustment_id' => 41,
            'purchase_order_id' => 17,
            'po_number' => 'PO-2026-017',
            'status' => 'replacement_in_transit',
        ];

        app(NotificationService::class)->notifySupplierReplacementInTransit($shop->id, $payload);
        app(NotificationService::class)->notifySupplierReplacementInTransit($shop->id, $payload);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $inventoryReceiver->id,
            'title' => 'Supplier Replacement In Transit',
            'action_url' => '/erp/inventory/supplier-order-monitoring?purchase_order=17&adjustment=41',
            'group_key' => 'supplier-replacement-in-transit:41',
        ]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $inactiveReceiver->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $otherShopReceiver->id]);
        $this->assertSame(1, DatabaseNotification::query()->where('group_key', 'supplier-replacement-in-transit:41')->count());
        $this->actingAs($inventoryReceiver, 'user')
            ->getJson('/api/hr/notifications/recent?limit=10')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Supplier Replacement In Transit']);
    }

    public function test_variant_alert_notifications_are_queued_and_only_reach_same_shop_inventory_viewers(): void
    {
        Notification::fake();

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

        Notification::assertSentTo($viewer, LowStockNotification::class, function (LowStockNotification $notification, array $channels) use ($viewer, $item, $color, $size): bool {
            $data = $notification->toArray($viewer);

            return in_array('database', $channels, true)
                && $data['inventory_item_id'] === $item->id
                && $data['inventory_color_variant_id'] === $color->id
                && $data['inventory_size_id'] === $size->id
                && $data['target_name'] === $item->name . ' - Black - US 8'
                && $data['current_quantity'] === 3
                && $data['reorder_level'] === 5;
        });
        Notification::assertSentTo($viewer, OutOfStockNotification::class, function (OutOfStockNotification $notification, array $channels) use ($viewer, $item, $color, $size): bool {
            $data = $notification->toArray($viewer);

            return in_array('database', $channels, true)
                && $data['inventory_item_id'] === $item->id
                && $data['inventory_color_variant_id'] === $color->id
                && $data['inventory_size_id'] === $size->id
                && $data['target_name'] === $item->name . ' - Black - US 8'
                && $data['reorder_quantity'] === 20;
        });
        Notification::assertNotSentTo($sameShopWithoutPermission, LowStockNotification::class);
        Notification::assertNotSentTo($sameShopWithoutPermission, OutOfStockNotification::class);
        Notification::assertNotSentTo($otherShopViewer, LowStockNotification::class);
        Notification::assertNotSentTo($otherShopViewer, OutOfStockNotification::class);
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
