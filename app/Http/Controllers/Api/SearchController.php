<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    /**
     * Unified search across all ERP modules
     * 
     * Searches: Job Orders, Invoices, Expenses
     * Returns: Categorized results with quick actions
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|min:2|max:100',
            'limit' => 'integer|min:1|max:50'
        ]);

        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $shopOwnerId = $user->shop_owner_id ?? $user->id;
        $query = strtolower(trim($validated['query']));
        $limit = $validated['limit'] ?? 10;

        try {
            $results = [
                'jobs' => $this->searchJobs($shopOwnerId, $query, $limit),
                'invoices' => $this->searchInvoices($shopOwnerId, $query, $limit),
                'expenses' => $this->searchExpenses($shopOwnerId, $query, $limit),
                'query' => $request->query,
                'total' => 0
            ];

            $results['total'] = count($results['jobs']) + count($results['invoices']) + count($results['expenses']);

            return response()->json($results);

        } catch (\Exception $e) {
            Log::error('Search failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'query' => $query
            ]);
            return response()->json(['error' => 'Search failed'], 500);
        }
    }

    /**
     * Search job orders
     */
    private function searchJobs($shopOwnerId, $query, $limit)
    {
        $jobs = DB::table('orders')
            ->where('shop_owner_id', $shopOwnerId)
            ->where(function ($q) use ($query) {
                $q->whereRaw('LOWER(customer) LIKE ?', ["%{$query}%"])
                  ->orWhereRaw('LOWER(product) LIKE ?', ["%{$query}%"])
                  ->orWhereRaw('LOWER(status) LIKE ?', ["%{$query}%"])
                  ->orWhereRaw('CAST(id AS CHAR) LIKE ?', ["%{$query}%"]);
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get(['id', 'customer', 'product', 'status', 'total', 'created_at']);

        return $jobs->map(function ($job) {
            return [
                'id' => $job->id,
                'type' => 'job',
                'title' => $job->customer,
                'subtitle' => $job->product,
                'status' => $job->status,
                'amount' => $job->total,
                'date' => $job->created_at,
                'url' => '/erp/staff/jobs',
                'icon' => '🔧',
                'badge' => strtoupper($job->status),
                'badgeColor' => $this->getJobStatusColor($job->status)
            ];
        })->toArray();
    }

    /**
     * Search invoices
     */
    private function searchInvoices($shopOwnerId, $query, $limit)
    {
        $invoices = DB::table('finance_invoices')
            ->where('shop_id', $shopOwnerId)
            ->where(function ($q) use ($query) {
                $q->whereRaw('LOWER(reference) LIKE ?', ["%{$query}%"])
                  ->orWhereRaw('LOWER(customer_name) LIKE ?', ["%{$query}%"])
                  ->orWhereRaw('LOWER(customer_email) LIKE ?', ["%{$query}%"])
                  ->orWhereRaw('LOWER(status) LIKE ?', ["%{$query}%"]);
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get(['id', 'reference', 'customer_name', 'status', 'total', 'created_at']);

        return $invoices->map(function ($invoice) {
            return [
                'id' => $invoice->id,
                'type' => 'invoice',
                'title' => $invoice->reference,
                'subtitle' => $invoice->customer_name,
                'status' => $invoice->status,
                'amount' => $invoice->total,
                'date' => $invoice->created_at,
                'url' => '/erp/finance/invoices',
                'icon' => '📄',
                'badge' => strtoupper($invoice->status),
                'badgeColor' => $this->getInvoiceStatusColor($invoice->status)
            ];
        })->toArray();
    }

    /**
     * Search expenses
     */
    private function searchExpenses($shopOwnerId, $query, $limit)
    {
        $expenses = DB::table('finance_expenses')
            ->where('shop_id', $shopOwnerId)
            ->where(function ($q) use ($query) {
                $q->whereRaw('LOWER(reference) LIKE ?', ["%{$query}%"])
                  ->orWhereRaw('LOWER(description) LIKE ?', ["%{$query}%"])
                  ->orWhereRaw('LOWER(vendor) LIKE ?', ["%{$query}%"])
                  ->orWhereRaw('LOWER(status) LIKE ?', ["%{$query}%"]);
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get(['id', 'reference', 'description', 'vendor', 'status', 'amount', 'created_at']);

        return $expenses->map(function ($expense) {
            return [
                'id' => $expense->id,
                'type' => 'expense',
                'title' => $expense->reference,
                'subtitle' => $expense->description ?? $expense->vendor,
                'status' => $expense->status,
                'amount' => $expense->amount,
                'date' => $expense->created_at,
                'url' => '/erp/finance/expenses',
                'icon' => '💰',
                'badge' => strtoupper($expense->status),
                'badgeColor' => $this->getExpenseStatusColor($expense->status)
            ];
        })->toArray();
    }

    /**
     * Get status badge color for jobs
     */
    private function getJobStatusColor($status)
    {
        return match (strtolower($status)) {
            'completed', 'delivered' => 'green',
            'processing', 'ongoing' => 'blue',
            'pending' => 'yellow',
            'cancelled' => 'red',
            default => 'gray'
        };
    }

    /**
     * Get status badge color for invoices
     */
    private function getInvoiceStatusColor($status)
    {
        return match (strtolower($status)) {
            'posted', 'paid' => 'green',
            'submitted' => 'blue',
            'draft' => 'yellow',
            'cancelled', 'rejected' => 'red',
            default => 'gray'
        };
    }

    /**
     * Get status badge color for expenses
     */
    private function getExpenseStatusColor($status)
    {
        return match (strtolower($status)) {
            'approved', 'posted' => 'green',
            'submitted' => 'blue',
            'draft' => 'yellow',
            'rejected' => 'red',
            default => 'gray'
        };
    }
}
