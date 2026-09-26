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
    protected ?array $target;

    /**
     * Create a new notification instance.
     */
    public function __construct(InventoryItem $inventoryItem, int $currentQuantity, int $reorderLevel, ?array $target = null)
    {
        $this->inventoryItem = $inventoryItem;
        $this->currentQuantity = $currentQuantity;
        $this->reorderLevel = $reorderLevel;
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
            ->subject('Low Stock Alert: ' . $targetName)
            ->warning()
            ->line('The inventory item **' . $targetName . '** (SKU: ' . $this->inventoryItem->sku . ') is running low on stock.')
            ->line('Current Quantity: **' . $this->currentQuantity . '**')
            ->line('Reorder Level: **' . $this->reorderLevel . '**')
            ->line('Recommended Reorder Quantity: **' . $this->reorderQuantity() . '**')
            ->action('View Inventory', url('/erp/inventory/inventory-dashboard?inventory_item=' . $this->inventoryItem->id))
            ->line('Please consider placing a supplier order to replenish stock.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        $targetName = $this->targetName();

        return [
            'type' => 'low_stock',
            'inventory_item_id' => $this->inventoryItem->id,
            'inventory_color_variant_id' => $this->target['inventory_color_variant_id'] ?? null,
            'inventory_size_id' => $this->target['inventory_size_id'] ?? null,
            'item_name' => $this->inventoryItem->name,
            'target_name' => $targetName,
            'sku' => $this->inventoryItem->sku,
            'current_quantity' => $this->currentQuantity,
            'reorder_level' => $this->reorderLevel,
            'reorder_quantity' => $this->reorderQuantity(),
            'message' => "Low stock alert for {$targetName} (SKU: {$this->inventoryItem->sku}). Current: {$this->currentQuantity}, Reorder at: {$this->reorderLevel}",
        ];
    }
}
