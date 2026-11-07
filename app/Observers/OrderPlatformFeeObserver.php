<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\PlatformFeeLedgerService;

final class OrderPlatformFeeObserver
{
    public function __construct(
        private readonly PlatformFeeLedgerService $ledger,
    ) {}

    public function created(Order $order): void
    {
        $this->ledger->finalizeOrder($order);
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged(['status', 'payment_status'])) {
            $this->ledger->finalizeOrder($order);
        }
    }
}
