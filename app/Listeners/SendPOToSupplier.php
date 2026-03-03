<?php

namespace App\Listeners;

use App\Events\PurchaseOrderSent;
use App\Jobs\SendPOToSupplierJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendPOToSupplier implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(PurchaseOrderSent $event): void
    {
        try {
            $purchaseOrder = $event->purchaseOrder;

            // Dispatch job to send PO email to supplier
            SendPOToSupplierJob::dispatch($purchaseOrder);

            Log::info('PO email job dispatched to supplier', [
                'po_id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'supplier_id' => $purchaseOrder->supplier_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch PO email to supplier', [
                'po_id' => $event->purchaseOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
