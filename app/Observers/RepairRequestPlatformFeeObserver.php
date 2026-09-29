<?php

namespace App\Observers;

use App\Models\RepairRequest;
use App\Services\PlatformFeeLedgerService;

final class RepairRequestPlatformFeeObserver
{
    public function __construct(
        private readonly PlatformFeeLedgerService $ledger,
    ) {}

    public function created(RepairRequest $repair): void
    {
        $this->ledger->finalizeRepair($repair);
    }

    public function updated(RepairRequest $repair): void
    {
        if ($repair->wasChanged(['status', 'payment_status', 'total_paid_amount'])) {
            $this->ledger->finalizeRepair($repair);
        }
    }
}
