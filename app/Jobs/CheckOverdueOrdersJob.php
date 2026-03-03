<?php

namespace App\Jobs;

use App\Events\SupplierOrderOverdue;
use App\Models\SupplierOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CheckOverdueOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $shopOwnerId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $shopOwnerId)
    {
        $this->shopOwnerId = $shopOwnerId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Starting overdue orders check for shop_owner_id: {$this->shopOwnerId}");

        // Get all active supplier orders that should have been delivered
        $overdueOrders = SupplierOrder::where('shop_owner_id', $this->shopOwnerId)
            ->whereIn('status', ['sent', 'confirmed', 'in_transit'])
            ->whereNotNull('expected_delivery_date')
            ->where('expected_delivery_date', '<', now())
            ->whereNull('actual_delivery_date')
            ->get();

        if ($overdueOrders->isEmpty()) {
            Log::info("No overdue orders found for shop_owner_id: {$this->shopOwnerId}");
            return;
        }

        $overdueCount = 0;

        foreach ($overdueOrders as $order) {
            $expectedDate = Carbon::parse($order->expected_delivery_date);
            $daysOverdue = now()->diffInDays($expectedDate, false);

            // Ensure daysOverdue is positive
            $daysOverdue = abs($daysOverdue);

            // Fire event for overdue order
            event(new SupplierOrderOverdue($order, $daysOverdue));

            $overdueCount++;

            Log::warning("Overdue supplier order detected: PO {$order->po_number}, {$daysOverdue} days overdue");
        }

        Log::info("Overdue orders check completed for shop_owner_id: {$this->shopOwnerId}", [
            'total_overdue' => $overdueCount
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("CheckOverdueOrdersJob failed for shop_owner_id: {$this->shopOwnerId}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
