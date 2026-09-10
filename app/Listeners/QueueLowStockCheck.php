<?php

namespace App\Listeners;

use App\Events\StockMovementRecorded;
use App\Jobs\CheckLowStockJob;

class QueueLowStockCheck
{
    public function handle(StockMovementRecorded $event): void
    {
        $shopOwnerId = (int) $event->stockMovement->inventoryItem?->shop_owner_id;

        if ($shopOwnerId > 0) {
            CheckLowStockJob::dispatch($shopOwnerId)->afterCommit();
        }
    }
}
