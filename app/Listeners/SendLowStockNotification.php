<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\LowStockAlert;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendLowStockNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(LowStockAlert $event): void
    {
        $inventoryItem = $event->inventoryItem;
        
        Log::info("Low stock alert triggered for item: {$inventoryItem->name} (SKU: {$inventoryItem->sku})");

        // Get users with inventory management permissions
        $users = User::permission('inventory.view')
            ->where('shop_owner_id', $inventoryItem->shop_owner_id)
            ->get();

        if ($users->isEmpty()) {
            Log::warning("No users found with inventory.view permission for shop_owner_id: {$inventoryItem->shop_owner_id}");
            return;
        }

        $notificationService = app(NotificationService::class);
        foreach ($users as $user) {
            $notification = new LowStockNotification(
                $inventoryItem,
                $event->currentQuantity,
                $event->reorderLevel,
                $event->target,
            );
            $data = $notification->toArray($user);

            $notificationService->sendToUser(
                userId: (int) $user->id,
                type: NotificationType::LOW_STOCK_ALERT,
                title: 'Low Stock Alert',
                message: $data['message'],
                data: $data,
                actionUrl: "/erp/inventory/inventory-dashboard?inventory_item={$inventoryItem->id}",
                shopId: (int) $inventoryItem->shop_owner_id,
                priority: 'medium',
            );
        }

        Log::info("Low stock notification sent to " . $users->count() . " users for item: {$inventoryItem->name}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(LowStockAlert $event, \Throwable $exception): void
    {
        Log::error("Failed to send low stock notification for item ID: {$event->inventoryItem->id}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
