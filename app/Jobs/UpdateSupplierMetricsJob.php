<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\SupplierPerformanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateSupplierMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(SupplierPerformanceService $supplierPerformanceService): void
    {
        try {
            Log::info('Starting supplier metrics update job');

            // Get all active suppliers
            $suppliers = Supplier::where('is_active', true)->get();

            $updatedCount = 0;
            foreach ($suppliers as $supplier) {
                try {
                    $supplierPerformanceService->updateSupplierMetrics($supplier->id);
                    $updatedCount++;
                } catch (\Exception $e) {
                    Log::error('Failed to update metrics for supplier', [
                        'supplier_id' => $supplier->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Supplier metrics update job completed', [
                'total_suppliers' => $suppliers->count(),
                'updated_count' => $updatedCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Supplier metrics update job failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
