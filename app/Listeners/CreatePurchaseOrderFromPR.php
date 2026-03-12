<?php

namespace App\Listeners;

use App\Events\PurchaseRequestApproved;
use App\Models\ProcurementSettings;
use App\Services\PurchaseOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CreatePurchaseOrderFromPR implements ShouldQueue
{
    use InteractsWithQueue;

    protected $purchaseOrderService;

    /**
     * Create the event listener.
     */
    public function __construct(PurchaseOrderService $purchaseOrderService)
    {
        $this->purchaseOrderService = $purchaseOrderService;
    }

    /**
     * Handle the event.
     */
    public function handle(PurchaseRequestApproved $event): void
    {
        try {
            $purchaseRequest = $event->purchaseRequest;
            
            // Check if auto-generation of PO is enabled
            $settings = ProcurementSettings::where('shop_owner_id', $purchaseRequest->shop_owner_id)->first();
            
            if ($settings && $settings->auto_generate_po) {
                // Auto-create purchase order
                $poData = [
                    'pr_id' => $purchaseRequest->id,
                    'shop_owner_id' => $purchaseRequest->shop_owner_id,
                    'supplier_id' => $purchaseRequest->supplier_id,
                    'product_name' => $purchaseRequest->product_name,
                    'inventory_item_id' => $purchaseRequest->inventory_item_id,
                    'quantity' => $purchaseRequest->quantity,
                    'unit_cost' => $purchaseRequest->unit_cost,
                    'total_cost' => $purchaseRequest->total_cost,
                    'payment_terms' => $settings->default_payment_terms ?? 'Net 30',
                    'ordered_by' => $purchaseRequest->approved_by,
                    'ordered_date' => now(),
                ];

                $this->purchaseOrderService->createPurchaseOrder($poData);

                Log::info('Auto-created PO from approved PR', [
                    'pr_id' => $purchaseRequest->id,
                    'pr_number' => $purchaseRequest->pr_number,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to auto-create PO from PR', [
                'pr_id' => $event->purchaseRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
