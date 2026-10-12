<?php

namespace App\Observers;

use App\Models\OrderRefund;
use App\Services\PlatformFeeRefundService;

final class OrderRefundPlatformFeeObserver
{
    public function __construct(private readonly PlatformFeeRefundService $refunds) {}

    public function created(OrderRefund $refund): void
    {
        $this->sync($refund);
    }

    public function updated(OrderRefund $refund): void
    {
        if ($refund->wasChanged('status') || $refund->wasChanged('amount')) {
            $this->sync($refund);
        }
    }

    private function sync(OrderRefund $refund): void
    {
        if (in_array(strtolower((string) $refund->status), ['succeeded', 'successful', 'completed', 'paid', 'refunded'], true)) {
            $this->refunds->reverseOrderRefund($refund);
        }
    }
}
