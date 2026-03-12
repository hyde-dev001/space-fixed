<?php

namespace App\Notifications;

use App\Models\InventoryItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected InventoryItem $inventoryItem;
    protected int $currentQuantity;
    protected int $reorderLevel;

    /**
     * Create a new notification instance.
     */
    public function __construct(InventoryItem $inventoryItem, int $currentQuantity, int $reorderLevel)
    {
        $this->inventoryItem = $inventoryItem;
        $this->currentQuantity = $currentQuantity;
        $this->reorderLevel = $reorderLevel;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Low Stock Alert: ' . $this->inventoryItem->name)
            ->warning()
            ->line('The inventory item **' . $this->inventoryItem->name . '** (SKU: ' . $this->inventoryItem->sku . ') is running low on stock.')
            ->line('Current Quantity: **' . $this->currentQuantity . '**')
            ->line('Reorder Level: **' . $this->reorderLevel . '**')
            ->line('Recommended Reorder Quantity: **' . $this->inventoryItem->reorder_quantity . '**')
            ->action('View Inventory', url('/erp/inventory/products/' . $this->inventoryItem->id))
            ->line('Please consider placing a supplier order to replenish stock.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'low_stock',
            'inventory_item_id' => $this->inventoryItem->id,
            'item_name' => $this->inventoryItem->name,
            'sku' => $this->inventoryItem->sku,
            'current_quantity' => $this->currentQuantity,
            'reorder_level' => $this->reorderLevel,
            'reorder_quantity' => $this->inventoryItem->reorder_quantity,
            'message' => "Low stock alert for {$this->inventoryItem->name} (SKU: {$this->inventoryItem->sku}). Current: {$this->currentQuantity}, Reorder at: {$this->reorderLevel}",
        ];
    }
}
