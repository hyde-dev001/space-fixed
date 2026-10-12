<?php

namespace App\Observers;

use App\Models\PosRefund;
use App\Services\PlatformFeeRefundService;

final class PosRefundPlatformFeeObserver
{
    public function __construct(private readonly PlatformFeeRefundService $refunds) {}

    public function created(PosRefund $refund): void
    {
        $this->sync($refund);
    }

    public function updated(PosRefund $refund): void
    {
        if ($refund->wasChanged('status') || $refund->wasChanged('approved_amount') || $refund->wasChanged('execution_amount')) {
            $this->sync($refund);
        }
    }

    private function sync(PosRefund $refund): void
    {
        if (in_array(strtolower((string) $refund->status), ['succeeded', 'successful', 'completed', 'paid', 'refunded'], true)) {
            $this->refunds->reverseRepairRefund($refund);
        }
    }
}
