<?php

namespace App\Events;

use App\Models\StockMovement;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockMovementRecorded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public StockMovement $stockMovement;

    /**
     * Create a new event instance.
     */
    public function __construct(StockMovement $stockMovement)
    {
        $this->stockMovement = $stockMovement;
    }
}
