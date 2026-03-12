<?php

namespace App\Events;

use App\Models\InventoryItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryItemUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public InventoryItem $inventoryItem;
    public array $changes;

    /**
     * Create a new event instance.
     */
    public function __construct(InventoryItem $inventoryItem, array $changes = [])
    {
        $this->inventoryItem = $inventoryItem;
        $this->changes = $changes;
    }
}
