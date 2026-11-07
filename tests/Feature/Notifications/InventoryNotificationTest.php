<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Events\LowStockAlert;
use App\Events\OutOfStockAlert;
use App\Events\SupplierOrderOverdue;
use App\Listeners\SendLowStockNotification;
use App\Listeners\SendOutOfStockNotification;
use App\Listeners\NotifySupplierOrderOverdue;
use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\Notification as DatabaseNotification;
use App\Models\ShopOwner;
use App\Models\StockRequestApproval;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Notifications\OutOfStockNotification;
use App\Services\StockRequestApprovalService;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InventoryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_stock_material_entry_saves_with_real_notification_mail_builder(): void
    {
        Mail::fake();
        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company', 'business_type' => 'repair',
        ]);
        $viewer = User::factory()->for($shop)->create(['status' => 'active', 'role' => 'INVENTORY']);
        $viewer->givePermissionTo([
            Permission::findOrCreate('access-upload-inventory', 'user'),
            Permission::findOrCreate('inventory.create', 'user'),
            Permission::findOrCreate('inventory.view', 'user'),
        ]);
        $this->clockInEmployee($viewer);

        $response = $this->actingAs($viewer, 'user')->postJson('/api/erp/inventory/items', [
            'name' => 'Repair Glue', 'category' => 'repair_materials', 'available_quantity' => 1,
            'reorder_level' => 10, 'reorder_quantity' => 50, 'unit' => 'grams',
        ]);
        $this->assertSame(201, $response->status(), $response->getContent());

        $itemId = $response->json('item.id');
        $this->assertDatabaseHas('inventory_items', [
            'id' => $itemId, 'shop_owner_id' => $shop->id, 'available_quantity' => 1,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $itemId, 'movement_type' => 'initial', 'quantity_after' => 1,
        ]);
        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $itemId, 'alert_type' => 'low_stock', 'is_resolved' => false,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $viewer->id, 'shop_id' => $shop->id, 'type' => 'low_stock_alert',
        ]);
    }

    public function test_overdue_supplier_alert_uses_the_central_inbox_and_same_shop_permission(): void
    {
        Mail::fake();
        $shop = ShopOwner::factory()->create();
        $otherShop = ShopOwner::factory()->create();
        $permission = Permission::findOrCreate('inventory.manage_orders', 'user');
        $viewer = User::factory()->for($shop)->create();
        $viewer->givePermissionTo($permission);
        $otherViewer = User::factory()->for($otherShop)->create();
        $otherViewer->givePermissionTo($permission);
        $unpermitted = User::factory()->for($shop)->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        $order = SupplierOrder::factory()->create([
            'shop_owner_id' => $shop->id, 'supplier_id' => $supplier->id,
            'status' => 'sent', 'expected_delivery_date' => now()->subDays(3),
        ]);

        (new NotifySupplierOrderOverdue())->handle(new SupplierOrderOverdue($order, 3));

        $notification = DatabaseNotification::where('user_id', $viewer->id)->sole();
        $this->assertSame('supplier_order_overdue', $notification->type->value);
        $this->assertSame('Overdue Supplier Order', $notification->type->label());
        $this->assertSame($order->id, $notification->data['supplier_order_id']);
        $this->assertSame(3, $notification->data['days_overdue']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $otherViewer->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $unpermitted->id]);
    }

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

        $notifications = DatabaseNotification::where('user_id', $viewer->id)->orderBy('id')->get();
        $this->assertCount(2, $notifications);
        $this->assertSame(['low_stock', 'out_of_stock'], $notifications->pluck('data.type')->all());
        foreach ($notifications as $notification) {
            $this->assertSame(NotificationType::LOW_STOCK_ALERT, $notification->type);
            $this->assertSame($shop->id, $notification->shop_id);
            $this->assertSame($item->id, $notification->data['inventory_item_id']);
            $this->assertSame($color->id, $notification->data['inventory_color_variant_id']);
            $this->assertSame($size->id, $notification->data['inventory_size_id']);
            $this->assertSame($item->name.' - Black - US 8', $notification->data['target_name']);
            $this->assertSame(20, $notification->data['reorder_quantity']);
        }
        $this->assertSame(3, $notifications->first()->data['current_quantity']);
        $this->assertSame(5, $notifications->first()->data['reorder_level']);
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
