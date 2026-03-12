<?php

namespace App\Events;

use App\Models\SupplierOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierOrderOverdue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SupplierOrder $supplierOrder;
    public int $daysOverdue;

    /**
     * Create a new event instance.
     */
    public function __construct(SupplierOrder $supplierOrder, int $daysOverdue)
    {
        $this->supplierOrder = $supplierOrder;
        $this->daysOverdue = $daysOverdue;
    }
}
