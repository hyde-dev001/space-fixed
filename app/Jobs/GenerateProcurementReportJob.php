<?php

namespace App\Jobs;

use App\Mail\ProcurementMonthlyReportMail;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GenerateProcurementReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $shopOwnerId;
    protected $month;
    protected $year;

    /**
     * Create a new job instance.
     */
    public function __construct($shopOwnerId = null, $month = null, $year = null)
    {
        $this->shopOwnerId = $shopOwnerId;
        $this->month = $month ?? now()->subMonth()->month;
        $this->year = $year ?? now()->subMonth()->year;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting procurement report generation', [
                'shop_owner_id' => $this->shopOwnerId,
                'month' => $this->month,
                'year' => $this->year,
            ]);

            // Define date range for the report
            $startDate = date('Y-m-01', strtotime("{$this->year}-{$this->month}-01"));
            $endDate = date('Y-m-t', strtotime("{$this->year}-{$this->month}-01"));

            // Build query
            $prQuery = PurchaseRequest::whereBetween('created_at', [$startDate, $endDate]);
            $poQuery = PurchaseOrder::whereBetween('created_at', [$startDate, $endDate]);

            if ($this->shopOwnerId) {
                $prQuery->where('shop_owner_id', $this->shopOwnerId);
                $poQuery->where('shop_owner_id', $this->shopOwnerId);
            }

            // Collect metrics
            $reportData = [
                'month' => date('F Y', strtotime("{$this->year}-{$this->month}-01")),
                'total_prs' => $prQuery->count(),
                'approved_prs' => (clone $prQuery)->where('status', 'approved')->count(),
                'rejected_prs' => (clone $prQuery)->where('status', 'rejected')->count(),
                'total_pr_value' => $prQuery->sum('total_cost'),
                'total_pos' => $poQuery->count(),
                'completed_pos' => (clone $poQuery)->where('status', 'completed')->count(),
                'cancelled_pos' => (clone $poQuery)->where('status', 'cancelled')->count(),
                'total_po_value' => $poQuery->sum('total_cost'),
                'avg_po_value' => $poQuery->avg('total_cost'),
                'top_suppliers' => (clone $poQuery)
                    ->selectRaw('supplier_id, COUNT(*) as order_count, SUM(total_cost) as total_value')
                    ->groupBy('supplier_id')
                    ->orderByDesc('total_value')
                    ->limit(5)
                    ->with('supplier')
                    ->get(),
            ];

            // Get management users to send report to
            $managementUsers = User::whereHas('roles', function ($query) {
                $query->whereIn('name', ['admin', 'finance', 'procurement']);
            })->get();

            // Send report emails
            foreach ($managementUsers as $user) {
                Mail::to($user->email)->send(new ProcurementMonthlyReportMail($reportData));
            }

            Log::info('Procurement report generated and sent', [
                'shop_owner_id' => $this->shopOwnerId,
                'month' => $this->month,
                'year' => $this->year,
                'recipients' => $managementUsers->count(),
                'total_prs' => $reportData['total_prs'],
                'total_pos' => $reportData['total_pos'],
            ]);
        } catch (\Exception $e) {
            Log::error('Procurement report generation failed', [
                'shop_owner_id' => $this->shopOwnerId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
