<?php

namespace App\Listeners;

use App\Events\SupplierOrderDelivered;
use App\Jobs\GenerateInventoryReportJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class GenerateInventoryReport implements ShouldQueue
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
    public function handle(SupplierOrderDelivered $event): void
    {
        $supplierOrder = $event->supplierOrder;
        
        Log::info("Queueing inventory report generation after order delivery: PO {$supplierOrder->po_number}");

        // Dispatch job to generate inventory report
        GenerateInventoryReportJob::dispatch(
            $supplierOrder->shop_owner_id,
            now()->subDays(30),
            now(),
            'supplier_order_delivery'
        );

        Log::info("Inventory report generation job dispatched for shop_owner_id: {$supplierOrder->shop_owner_id}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(SupplierOrderDelivered $event, \Throwable $exception): void
    {
        Log::error("Failed to queue inventory report for supplier order ID: {$event->supplierOrder->id}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
