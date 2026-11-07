<?php

namespace App\Listeners;

use App\Events\LowStockAlert;
use App\Enums\NotificationType;
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

        // Send notification to all relevant users
        $notification = new LowStockNotification(
            $inventoryItem,
            $event->currentQuantity,
            $event->reorderLevel,
            $event->target,
        );
        $data = $notification->toArray($users->first());
        $mail = $notification->toMail($users->first());
        foreach ($users as $user) {
            app(NotificationService::class)->sendToUser(
                userId: $user->id, type: NotificationType::LOW_STOCK_ALERT,
                title: $mail->subject, message: $data['message'], data: $data,
                actionUrl: $mail->actionUrl, shopId: $inventoryItem->shop_owner_id,
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
