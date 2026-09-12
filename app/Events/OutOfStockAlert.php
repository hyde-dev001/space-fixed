<?php

namespace App\Events;

use App\Models\InventoryItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OutOfStockAlert
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public InventoryItem $inventoryItem;
    public ?array $target;

    /**
     * Create a new event instance.
     */
    public function __construct(InventoryItem $inventoryItem, ?array $target = null)
    {
        $this->inventoryItem = $inventoryItem;
        $this->target = $target;
    }
}
