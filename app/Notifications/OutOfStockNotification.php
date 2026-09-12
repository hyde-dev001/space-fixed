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
    protected ?array $target;

    /**
     * Create a new notification instance.
     */
    public function __construct(InventoryItem $inventoryItem, ?array $target = null)
    {
        $this->inventoryItem = $inventoryItem;
        $this->target = $target;
    }

    private function targetName(): string
    {
        if (! $this->target) {
            return $this->inventoryItem->name;
        }

        $parts = [$this->inventoryItem->name];
        if (! empty($this->target['color_name'])) {
            $parts[] = $this->target['color_name'];
        }
        if (! empty($this->target['size'])) {
            $parts[] = ! empty($this->target['requested_size'])
                ? $this->target['requested_size']
                : trim(($this->target['size_system'] ?? '') . ' ' . $this->target['size']);
        }

        return implode(' - ', $parts);
    }

    private function reorderQuantity(): int
    {
        return $this->target
            ? (int) $this->target['reorder_quantity']
            : (int) $this->inventoryItem->reorder_quantity;
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
        $targetName = $this->targetName();

        return (new MailMessage)
            ->subject('URGENT: Out of Stock - ' . $targetName)
            ->error()
            ->line('**URGENT:** The inventory item **' . $targetName . '** (SKU: ' . $this->inventoryItem->sku . ') is now OUT OF STOCK.')
            ->line('This item has 0 available quantity and needs immediate attention.')
            ->line('Recommended Reorder Quantity: **' . $this->reorderQuantity() . '**')
            ->action('Place Supplier Order', url('/erp/inventory/supplier-order-monitoring'))
            ->line('Please place a supplier order immediately to avoid business disruption.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        $targetName = $this->targetName();

        return [
            'type' => 'out_of_stock',
            'inventory_item_id' => $this->inventoryItem->id,
            'inventory_color_variant_id' => $this->target['inventory_color_variant_id'] ?? null,
            'inventory_size_id' => $this->target['inventory_size_id'] ?? null,
            'item_name' => $this->inventoryItem->name,
            'target_name' => $targetName,
            'sku' => $this->inventoryItem->sku,
            'reorder_quantity' => $this->reorderQuantity(),
            'message' => "URGENT: {$targetName} (SKU: {$this->inventoryItem->sku}) is out of stock. Please place a supplier order immediately.",
        ];
    }
}
