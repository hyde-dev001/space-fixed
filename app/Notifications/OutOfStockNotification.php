<?php

namespace App\Notifications;

use App\Models\InventoryItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OutOfStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected InventoryItem $inventoryItem;

    /**
     * Create a new notification instance.
     */
    public function __construct(InventoryItem $inventoryItem)
    {
        $this->inventoryItem = $inventoryItem;
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
            ->subject('URGENT: Out of Stock - ' . $this->inventoryItem->name)
            ->error()
            ->line('**URGENT:** The inventory item **' . $this->inventoryItem->name . '** (SKU: ' . $this->inventoryItem->sku . ') is now OUT OF STOCK.')
            ->line('This item has 0 available quantity and needs immediate attention.')
            ->line('Recommended Reorder Quantity: **' . $this->inventoryItem->reorder_quantity . '**')
            ->action('Place Supplier Order', url('/erp/inventory/supplier-orders/create'))
            ->line('Please place a supplier order immediately to avoid business disruption.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'out_of_stock',
            'inventory_item_id' => $this->inventoryItem->id,
            'item_name' => $this->inventoryItem->name,
            'sku' => $this->inventoryItem->sku,
            'reorder_quantity' => $this->inventoryItem->reorder_quantity,
            'message' => "URGENT: {$this->inventoryItem->name} (SKU: {$this->inventoryItem->sku}) is out of stock. Please place a supplier order immediately.",
        ];
    }
}
