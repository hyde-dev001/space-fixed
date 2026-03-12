<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogController extends Controller
{
    /**
     * Get audit logs with filtering and pagination
     */
    public function index(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        // Build query
        $query = AuditLog::query();
        
        // Only show logs for the user's shop
        if ($user && $user->shop_owner_id) {
            $query->where('shop_owner_id', $user->shop_owner_id);
        }
        
        // Filter by action
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        
        // Filter by object type
        if ($request->filled('object_type')) {
            $query->where('object_type', $request->object_type);
        }
        
        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        
        // Search by action or object type
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', $search)
                  ->orWhere('object_type', 'like', $search);
            });
        }
        
        // Date range filter
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }
        
        // Get distinct actions and object types for filters
        $actions = AuditLog::where('shop_owner_id', $user->shop_owner_id ?? null)
            ->distinct('action')
            ->pluck('action');
            
        $objectTypes = AuditLog::where('shop_owner_id', $user->shop_owner_id ?? null)
            ->distinct('object_type')
            ->pluck('object_type');
        
        // Order by latest first and paginate
        $logs = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 50));
        
        return response()->json([
            'logs' => $logs,
            'actions' => $actions,
            'object_types' => $objectTypes,
            'total_count' => AuditLog::where('shop_owner_id', $user->shop_owner_id ?? null)->count(),
        ]);
    }
    
    /**
     * Get audit log statistics
     */
    public function stats(Request $request)
    {
        $user = Auth::guard('user')->user();
        $shopId = $user->shop_owner_id;
        
        // Actions in last 7 days
        $actionCounts = AuditLog::where('shop_owner_id', $shopId)
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy('action')
            ->selectRaw('action, COUNT(*) as count')
            ->pluck('count', 'action');
        
        // Object types in last 7 days
        $objectTypeCounts = AuditLog::where('shop_owner_id', $shopId)
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy('object_type')
            ->selectRaw('object_type, COUNT(*) as count')
            ->pluck('count', 'object_type')
            ->filter();
        
        // Total logs
        $totalLogs = AuditLog::where('shop_owner_id', $shopId)->count();
        
        // Logs in last 24 hours
        $logsLast24h = AuditLog::where('shop_owner_id', $shopId)
            ->where('created_at', '>=', now()->subHours(24))
            ->count();
        
        return response()->json([
            'action_counts' => $actionCounts,
            'object_type_counts' => $objectTypeCounts,
            'total_logs' => $totalLogs,
            'logs_last_24h' => $logsLast24h,
        ]);
    }
    
    /**
     * Export logs as CSV
     */
    public function export(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        $query = AuditLog::where('shop_owner_id', $user->shop_owner_id);
        
        // Apply same filters as index
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('object_type')) {
            $query->where('object_type', $request->object_type);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }
        
        $logs = $query->orderBy('created_at', 'desc')->get();
        
        // Create CSV
        $csvData = "ID,Action,Object Type,User ID,Created At,Metadata\n";
        foreach ($logs as $log) {
            $metadata = json_encode($log->metadata ?? []);
            $csvData .= "\"{$log->id}\",\"{$log->action}\",\"{$log->object_type}\",\"{$log->user_id}\",\"{$log->created_at}\",\"{$metadata}\"\n";
        }
        
        return response()->streamDownload(
            function () use ($csvData) {
                echo $csvData;
            },
            'audit-logs-' . now()->format('Y-m-d-His') . '.csv'
        );
    }
}
