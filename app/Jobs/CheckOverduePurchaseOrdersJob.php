<?php

namespace App\Jobs;

use App\Listeners\NotifyOverduePOs;
use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckOverduePurchaseOrdersJob implements ShouldQueue
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
    public function handle(): void
    {
        try {
            Log::info('Starting overdue PO check job');

            // Get count of overdue POs
            $overdueCount = PurchaseOrder::where('status', '!=', 'completed')
                ->where('status', '!=', 'cancelled')
                ->whereNotNull('expected_delivery_date')
                ->where('expected_delivery_date', '<', now())
                ->count();

            if ($overdueCount > 0) {
                // Trigger notification listener
                $listener = new NotifyOverduePOs();
                $listener->handle();

                Log::info('Overdue PO check completed', [
                    'overdue_count' => $overdueCount,
                ]);
            } else {
                Log::info('No overdue POs found');
            }
        } catch (\Exception $e) {
            Log::error('Overdue PO check job failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
