<?php

namespace App\Jobs;

use App\Models\ProcurementSettings;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AutoApproveLowValuePRsJob implements ShouldQueue
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
    public function handle(PurchaseRequestService $purchaseRequestService): void
    {
        try {
            Log::info('Starting auto-approval of low-value PRs');

            // Get all pending PRs
            $pendingPRs = PurchaseRequest::where('status', 'pending_finance')
                ->with(['shopOwner'])
                ->get();

            $approvedCount = 0;
            foreach ($pendingPRs as $pr) {
                try {
                    // Get procurement settings for this shop owner
                    $settings = ProcurementSettings::where('shop_owner_id', $pr->shop_owner_id)->first();

                    if ($settings && $settings->auto_pr_approval_threshold) {
                        // Check if PR is below threshold
                        if ($pr->total_cost <= $settings->auto_pr_approval_threshold) {
                            // Auto-approve
                            $purchaseRequestService->approvePurchaseRequest(
                                $pr->id,
                                1, // System user ID (you may want to create a dedicated system user)
                                'Auto-approved: Below threshold of ' . number_format($settings->auto_pr_approval_threshold, 2)
                            );

                            $approvedCount++;

                            Log::info('Auto-approved low-value PR', [
                                'pr_id' => $pr->id,
                                'pr_number' => $pr->pr_number,
                                'total_cost' => $pr->total_cost,
                                'threshold' => $settings->auto_pr_approval_threshold,
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to auto-approve PR', [
                        'pr_id' => $pr->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Auto-approval job completed', [
                'total_pending' => $pendingPRs->count(),
                'approved_count' => $approvedCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Auto-approval job failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
