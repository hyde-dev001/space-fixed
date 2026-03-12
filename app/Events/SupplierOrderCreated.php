<?php

namespace App\Events;

use App\Models\SupplierOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierOrderCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SupplierOrder $supplierOrder;

    /**
     * Create a new event instance.
     */
    public function __construct(SupplierOrder $supplierOrder)
    {
        $this->supplierOrder = $supplierOrder;
    }
}
