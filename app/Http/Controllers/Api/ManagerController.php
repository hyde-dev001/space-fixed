<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ManagerController extends Controller
{
    private function userHasManagerAccess($user): bool
    {
        if (!$user) {
            return false;
        }

        $roleColumn = strtoupper((string) $user->role);
        if (in_array($roleColumn, ['MANAGER', 'FINANCE_MANAGER', 'SUPER_ADMIN'], true)) {
            return true;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['Manager', 'Finance Manager', 'Super Admin'])) {
            return true;
        }

        return false;
    }

    /**
     * Get dashboard statistics for Manager
     */
    public function getDashboardStats(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            // Check if user has manager role
            if (!$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }
            
            // Get actual sales data from finance_invoices
            $totalSales = DB::table('finance_invoices')
                ->where('shop_id', $shopOwnerId)
                ->where('status', 'posted')
                ->sum('total');
                
            // Get previous month sales for comparison
            $previousSales = DB::table('finance_invoices')
                ->where('shop_id', $shopOwnerId)
                ->where('status', 'posted')
                ->where('created_at', '<', now()->subMonth())
                ->where('created_at', '>=', now()->subMonths(2))
                ->sum('total');
                
            $salesChange = $previousSales > 0 
                ? (($totalSales - $previousSales) / $previousSales) * 100 
                : 0;
            
            // Get actual repair/job data from orders table
            $totalRepairs = DB::table('orders')
                ->where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', ['delivered', 'completed', 'shipped'])
                ->count();
                
            $pendingJobOrders = DB::table('orders')
                ->where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', ['pending', 'processing'])
                ->count();
            
            // Get active staff count
            $activeStaff = DB::table('employees')
                ->where('shop_owner_id', $shopOwnerId)
                ->where('status', 'active')
                ->count();
                
            // Get monthly revenue trend (last 6 months)
            $monthlyRevenue = DB::table('finance_invoices')
                ->where('shop_id', $shopOwnerId)
                ->where('status', 'posted')
                ->where('created_at', '>=', now()->subMonths(6))
                ->select(
                    DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                    DB::raw('SUM(total) as revenue')
                )
                ->groupBy('month')
                ->orderBy('month', 'asc')
                ->get();
            
            // Get pending approvals from multiple sources
            $pendingExpenses = DB::table('finance_expenses')
                ->where('shop_id', $shopOwnerId)
                ->where('status', 'submitted')
                ->count();
                
            $pendingLeaves = DB::table('leave_requests')
                ->where('shop_owner_id', $shopOwnerId)
                ->where('status', 'pending')
                ->count();
                
            $pendingApprovals = $pendingExpenses + $pendingLeaves;
            
            // Get approval summary with details
            $approvalSummary = [
                'expenses' => [
                    'count' => $pendingExpenses,
                    'total_amount' => DB::table('finance_expenses')
                        ->where('shop_id', $shopOwnerId)
                        ->where('status', 'submitted')
                        ->sum('amount')
                ],
                'leave_requests' => [
                    'count' => $pendingLeaves,
                    'details' => DB::table('leave_requests as lr')
                        ->join('employees as e', 'lr.employee_id', '=', 'e.id')
                        ->where('lr.shop_owner_id', $shopOwnerId)
                        ->where('lr.status', 'pending')
                        ->select([
                            'lr.id',
                            'lr.leave_type',
                            'lr.start_date',
                            'lr.end_date',
                            'lr.no_of_days',
                            'lr.reason',
                            'lr.created_at',
                            'e.id as employee_id',
                            'e.name as employee_name',
                            'e.email as employee_email',
                            'e.position as employee_position',
                            DB::raw('DATEDIFF(NOW(), lr.created_at) as days_pending')
                        ])
                        ->orderBy('lr.created_at', 'asc')
                        ->limit(5)
                        ->get()
                ]
            ];
            
            // Get recent activities from audit logs
            $recentActivities = DB::table('audit_logs')
                ->where('shop_owner_id', $shopOwnerId)
                ->whereIn('action', ['created', 'updated', 'approved', 'rejected', 'posted'])
                ->select([
                    'id',
                    'user_id',
                    'action',
                    'object_type',
                    'object_id',
                    'target_type',
                    'target_id',
                    'data',
                    'metadata',
                    'created_at'
                ])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function($log) {
                    // Use object_type or target_type for entity name
                    $entityType = $log->target_type ?: $log->object_type;
                    if ($entityType) {
                        $parts = explode('\\', $entityType);
                        $entityType = end($parts);
                    } else {
                        $entityType = 'Item';
                    }
                    
                    // Get user name
                    $user = DB::table('users')->where('id', $log->user_id)->first();
                    $userName = $user ? $user->name : 'Unknown User';
                    
                    return [
                        'id' => $log->id,
                        'user_name' => $userName,
                        'action' => $log->action,
                        'entity_type' => $entityType,
                        'entity_id' => $log->target_id ?: $log->object_id,
                        'description' => ucfirst($log->action) . ' ' . strtolower($entityType),
                        'timestamp' => $log->created_at,
                        'time_ago' => \Carbon\Carbon::parse($log->created_at)->diffForHumans()
                    ];
                });
            
            return response()->json([
                'totalSales' => floatval($totalSales),
                'salesChange' => round($salesChange, 1),
                'totalRepairs' => $totalRepairs,
                'pendingJobOrders' => $pendingJobOrders,
                'activeStaff' => $activeStaff,
                'pendingApprovals' => $pendingApprovals,
                'monthlyRevenue' => $monthlyRevenue,
                'approvalSummary' => $approvalSummary,
                'recentActivities' => $recentActivities,
                'lastUpdated' => now()->toIso8601String()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to get manager dashboard stats: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to load dashboard statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get staff performance metrics
     */
    public function getStaffPerformance(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            if (!$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }
            
            // Get staff performance data
            $performance = DB::table('employees as e')
                ->leftJoin('orders as o', function($join) {
                    $join->on('o.shop_owner_id', '=', 'e.shop_owner_id');
                })
                ->where('e.shop_owner_id', $shopOwnerId)
                ->where('e.status', 'active')
                ->select([
                    'e.id',
                    'e.name',
                    'e.email',
                    'e.position',
                    DB::raw('COUNT(o.id) as total_jobs'),
                    DB::raw('SUM(CASE WHEN o.status IN ("completed", "delivered") THEN 1 ELSE 0 END) as completed_jobs'),
                    DB::raw('SUM(CASE WHEN o.status IN ("pending", "processing") THEN 1 ELSE 0 END) as pending_jobs'),
                    DB::raw('COALESCE(SUM(CASE WHEN o.status IN ("completed", "delivered") THEN CAST(o.total AS DECIMAL(10,2)) ELSE 0 END), 0) as total_revenue')
                ])
                ->groupBy('e.id', 'e.name', 'e.email', 'e.position')
                ->orderBy('completed_jobs', 'desc')
                ->get();
            
            return response()->json($performance);
            
        } catch (\Exception $e) {
            Log::error('Failed to get staff performance: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to load staff performance',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get analytics data
     */
    public function getAnalytics(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            if (!$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }
            
            // Get order status breakdown
            $ordersByStatus = DB::table('orders')
                ->where('shop_owner_id', $shopOwnerId)
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get();
            
            // Get top products
            $topProducts = DB::table('orders')
                ->where('shop_owner_id', $shopOwnerId)
                ->select(
                    'product',
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(CAST(total AS DECIMAL(10,2))) as total_revenue')
                )
                ->groupBy('product')
                ->orderBy('order_count', 'desc')
                ->limit(10)
                ->get();
            
            // Get recent activities (last 10)
            $recentActivities = DB::table('orders')
                ->where('shop_owner_id', $shopOwnerId)
                ->select('id', 'customer', 'product', 'status', 'total', 'created_at')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
            
            return response()->json([
                'ordersByStatus' => $ordersByStatus,
                'topProducts' => $topProducts,
                'recentActivities' => $recentActivities
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to get analytics: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to load analytics',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
