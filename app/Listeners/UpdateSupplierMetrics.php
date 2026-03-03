<?php

namespace App\Listeners;

use App\Events\PurchaseOrderCompleted;
use App\Services\SupplierPerformanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class UpdateSupplierMetrics implements ShouldQueue
{
    use InteractsWithQueue;

    protected $supplierPerformanceService;

    /**
     * Create the event listener.
     */
    public function __construct(SupplierPerformanceService $supplierPerformanceService)
    {
        $this->supplierPerformanceService = $supplierPerformanceService;
    }

    /**
     * Handle the event.
     */
    public function handle(PurchaseOrderCompleted $event): void
    {
        try {
            $purchaseOrder = $event->purchaseOrder;

            // Update supplier metrics
            $this->supplierPerformanceService->updateSupplierMetrics($purchaseOrder->supplier_id);

            Log::info('Supplier metrics updated on PO completion', [
                'po_id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'supplier_id' => $purchaseOrder->supplier_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update supplier metrics on PO completion', [
                'po_id' => $event->purchaseOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
