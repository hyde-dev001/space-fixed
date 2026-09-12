<?php

namespace App\Events;

use App\Models\InventoryItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowStockAlert
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public InventoryItem $inventoryItem;
    public int $currentQuantity;
    public int $reorderLevel;
    public ?array $target;

    /**
     * Create a new event instance.
     */
    public function __construct(InventoryItem $inventoryItem, int $currentQuantity, int $reorderLevel, ?array $target = null)
    {
        $this->inventoryItem = $inventoryItem;
        $this->currentQuantity = $currentQuantity;
        $this->reorderLevel = $reorderLevel;
        $this->target = $target;
    }
}
