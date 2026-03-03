<?php

namespace App\Jobs;

use App\Mail\PurchaseOrderToSupplierMail;
use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPOToSupplierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $purchaseOrder;

    /**
     * Create a new job instance.
     */
    public function __construct(PurchaseOrder $purchaseOrder)
    {
        $this->purchaseOrder = $purchaseOrder;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $purchaseOrder = $this->purchaseOrder->fresh(['supplier', 'purchaseRequest']);

            if (!$purchaseOrder->supplier || !$purchaseOrder->supplier->email) {
                Log::warning('Cannot send PO to supplier: No email address', [
                    'po_id' => $purchaseOrder->id,
                    'supplier_id' => $purchaseOrder->supplier_id,
                ]);
                return;
            }

            // Send email to supplier
            Mail::to($purchaseOrder->supplier->email)->send(
                new PurchaseOrderToSupplierMail($purchaseOrder)
            );

            Log::info('PO sent to supplier via email', [
                'po_id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'supplier_id' => $purchaseOrder->supplier_id,
                'supplier_email' => $purchaseOrder->supplier->email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send PO to supplier', [
                'po_id' => $this->purchaseOrder->id,
                'error' => $e->getMessage(),
            ]);

            // Retry the job
            throw $e;
        }
    }
}
