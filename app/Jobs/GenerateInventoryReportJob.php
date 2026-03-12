<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class GenerateInventoryReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $shopOwnerId;
    public Carbon $startDate;
    public Carbon $endDate;
    public string $reportType;

    /**
     * Create a new job instance.
     */
    public function __construct(int $shopOwnerId, Carbon $startDate, Carbon $endDate, string $reportType = 'general')
    {
        $this->shopOwnerId = $shopOwnerId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->reportType = $reportType;
    }

    /**
     * Execute the job.
     */
    public function handle(InventoryService $inventoryService): void
    {
        Log::info("Starting inventory report generation for shop_owner_id: {$this->shopOwnerId}", [
            'start_date' => $this->startDate->format('Y-m-d'),
            'end_date' => $this->endDate->format('Y-m-d'),
            'report_type' => $this->reportType
        ]);

        try {
            // Generate comprehensive report data
            $reportData = $inventoryService->generateStockReport(
                $this->shopOwnerId,
                $this->startDate,
                $this->endDate
            );

            // Add additional metrics
            $reportData['metrics'] = $inventoryService->getDashboardMetrics($this->shopOwnerId);
            $reportData['total_value'] = $inventoryService->calculateStockValue($this->shopOwnerId);
            $reportData['turnover'] = $inventoryService->getInventoryTurnover($this->shopOwnerId, 30);
            $reportData['reorder_needed'] = $inventoryService->getItemsNeedingReorder($this->shopOwnerId);

            // Get users with inventory view permissions
            $users = User::permission('inventory.view_reports')
                ->where('shop_owner_id', $this->shopOwnerId)
                ->get();

            if ($users->isEmpty()) {
                Log::warning("No users found with inventory.view_reports permission for shop_owner_id: {$this->shopOwnerId}");
                return;
            }

            // Send email to each user
            foreach ($users as $user) {
                try {
                    Mail::to($user->email)->send(new \App\Mail\InventoryReportMail($reportData, $user, $this->reportType));
                    Log::info("Inventory report emailed to: {$user->email}");
                } catch (\Exception $e) {
                    Log::error("Failed to email inventory report to: {$user->email}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info("Inventory report generation completed for shop_owner_id: {$this->shopOwnerId}", [
                'report_type' => $this->reportType,
                'emails_sent' => $users->count()
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to generate inventory report for shop_owner_id: {$this->shopOwnerId}", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("GenerateInventoryReportJob failed for shop_owner_id: {$this->shopOwnerId}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
