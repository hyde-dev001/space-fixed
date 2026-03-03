<?php

namespace App\Events;

use App\Models\SupplierOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierOrderDelivered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SupplierOrder $supplierOrder;
    public array $receivedItems;

    /**
     * Create a new event instance.
     */
    public function __construct(SupplierOrder $supplierOrder, array $receivedItems = [])
    {
        $this->supplierOrder = $supplierOrder;
        $this->receivedItems = $receivedItems;
    }
}
