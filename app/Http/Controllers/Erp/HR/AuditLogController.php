<?php

namespace App\Http\Controllers\Erp\HR;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HR\AuditLog;
use Illuminate\Support\Facades\Validator;

class AuditLogController extends Controller
{
    /**
     * Get audit logs with advanced filtering
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Authorization check - use permission instead of hardcoded roles
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has permission to view audit logs
        // Managers have all permissions, others need specific audit log permissions
        if (!$user->hasRole('Manager') && !$user->can('view-finance-audit-logs') && !$user->can('view-hr-audit-logs') && !$user->can('view-all-audit-logs')) {
            return response()->json(['error' => 'You don\'t have permission to access audit logs'], 403);
        }

        $shopOwnerId = $user->shop_owner_id;
        $query = AuditLog::forShopOwner($shopOwnerId);

        // Apply filters

        // Filter by user
        if ($request->filled('user_id')) {
            $query->byUser($request->user_id);
        }

        // Filter by employee
        if ($request->filled('employee_id')) {
            $query->forEmployee($request->employee_id);
        }

        // Filter by module
        if ($request->filled('module')) {
            $query->inModule($request->module);
        }

        // Filter by action
        if ($request->filled('action')) {
            $query->withAction($request->action);
        }

        // Filter by severity
        if ($request->filled('severity')) {
            $query->bySeverity($request->severity);
        }

        // Filter by date range
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        } elseif ($request->filled('recent_days')) {
            $query->recent((int) $request->recent_days);
        } else {
            // Default: last 30 days
            $query->recent(30);
        }

        // Filter by tag
        if ($request->filled('tag')) {
            $query->withTag($request->tag);
        }

        // Filter by IP address
        if ($request->filled('ip_address')) {
            $query->fromIp($request->ip_address);
        }

        // Search in description
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by entity
        if ($request->filled('entity_type') && $request->filled('entity_id')) {
            $query->forEntity($request->entity_type, $request->entity_id);
        }

        // Critical logs only
        if ($request->boolean('critical_only')) {
            $query->critical();
        }

        // Load relationships
        $query->with(['user:id,name,email', 'employee:id,first_name,last_name']);

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->input('per_page', 50);
        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs,
            'filters_applied' => $request->except(['page', 'per_page', 'sort_by', 'sort_order']),
        ]);
    }

    /**
     * Get audit log statistics
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics(Request $request)
    {
        // Authorization check - use permission instead of hardcoded roles
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has permission to view audit logs
        if (!$user->hasRole('Manager') && !$user->can('view-finance-audit-logs') && !$user->can('view-hr-audit-logs') && !$user->can('view-all-audit-logs')) {
            return response()->json(['error' => 'No permission to access audit log stats'], 403);
        }

        $shopOwnerId = $user->shop_owner_id;
        $days = $request->input('days', 30);

        $statistics = AuditLog::getStatistics($shopOwnerId, $days);

        return response()->json([
            'success' => true,
            'data' => $statistics,
            'period' => "{$days} days",
        ]);
    }

    /**
     * Get entity change history
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function entityHistory(Request $request)
    {
        // Authorization check - use permission instead of hardcoded roles
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has permission to view audit logs
        if (!$user->hasRole('Manager') && !$user->can('view-finance-audit-logs') && !$user->can('view-hr-audit-logs') && !$user->can('view-all-audit-logs')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $shopOwnerId = $user->shop_owner_id;
        
        $history = AuditLog::getEntityHistory($request->entity_type, $request->entity_id)
            ->filter(function ($log) use ($shopOwnerId) {
                return $log->shop_owner_id === $shopOwnerId;
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $history,
            'entity_type' => $request->entity_type,
            'entity_id' => $request->entity_id,
        ]);
    }

    /**
     * Get user activity summary
     * 
     * @param Request $request
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function userActivity(Request $request, $userId)
    {
        // Authorization check - use permission instead of hardcoded roles
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has permission to view audit logs
        if (!$user->hasRole('Manager') && !$user->can('view-finance-audit-logs') && !$user->can('view-hr-audit-logs') && !$user->can('view-all-audit-logs')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $shopOwnerId = $user->shop_owner_id;
        $days = $request->input('days', 30);

        $query = AuditLog::forShopOwner($shopOwnerId)
            ->byUser($userId)
            ->recent($days);

        $activity = [
            'total_actions' => $query->count(),
            'by_module' => (clone $query)->selectRaw('module, COUNT(*) as count')
                ->groupBy('module')
                ->pluck('count', 'module'),
            'by_action' => (clone $query)->selectRaw('action, COUNT(*) as count')
                ->groupBy('action')
                ->pluck('count', 'action'),
            'by_severity' => (clone $query)->selectRaw('severity, COUNT(*) as count')
                ->groupBy('severity')
                ->pluck('count', 'severity'),
            'recent_logs' => (clone $query)
                ->with(['employee:id,first_name,last_name'])
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $activity,
            'user_id' => $userId,
            'period' => "{$days} days",
        ]);
    }

    /**
     * Get employee activity summary
     * 
     * @param Request $request
     * @param int $employeeId
     * @return \Illuminate\Http\JsonResponse
     */
    public function employeeActivity(Request $request, $employeeId)
    {
        // Authorization check - use permission instead of hardcoded roles
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has permission to view audit logs
        if (!$user->hasRole('Manager') && !$user->can('view-finance-audit-logs') && !$user->can('view-hr-audit-logs') && !$user->can('view-all-audit-logs')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $shopOwnerId = $user->shop_owner_id;
        $days = $request->input('days', 30);

        $query = AuditLog::forShopOwner($shopOwnerId)
            ->forEmployee($employeeId)
            ->recent($days);

        $activity = [
            'total_actions' => $query->count(),
            'by_module' => (clone $query)->selectRaw('module, COUNT(*) as count')
                ->groupBy('module')
                ->pluck('count', 'module'),
            'by_action' => (clone $query)->selectRaw('action, COUNT(*) as count')
                ->groupBy('action')
                ->pluck('count', 'action'),
            'recent_logs' => (clone $query)
                ->with(['user:id,name'])
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $activity,
            'employee_id' => $employeeId,
            'period' => "{$days} days",
        ]);
    }

    /**
     * Get critical logs (security-relevant actions)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function criticalLogs(Request $request)
    {
        // Authorization check - shop_owner only for critical logs
        $user = auth()->user();
        if (!$user || $user->role !== 'shop_owner') {
            return response()->json(['error' => 'Unauthorized - shop_owner access only'], 403);
        }

        $shopOwnerId = $user->shop_owner_id;
        $days = $request->input('days', 7);

        $logs = AuditLog::forShopOwner($shopOwnerId)
            ->critical()
            ->recent($days)
            ->with(['user:id,name,email', 'employee:id,first_name,last_name'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $logs,
            'period' => "{$days} days",
        ]);
    }

    /**
     * Export audit logs (CSV)
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function export(Request $request)
    {
        // Authorization check - use permission instead of hardcoded roles
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has permission to view audit logs
        if (!$user->hasRole('Manager') && !$user->can('view-finance-audit-logs') && !$user->can('view-hr-audit-logs') && !$user->can('view-all-audit-logs')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $shopOwnerId = $user->shop_owner_id;
        $query = AuditLog::forShopOwner($shopOwnerId);

        // Apply same filters as index method
        if ($request->filled('user_id')) $query->byUser($request->user_id);
        if ($request->filled('employee_id')) $query->forEmployee($request->employee_id);
        if ($request->filled('module')) $query->inModule($request->module);
        if ($request->filled('action')) $query->withAction($request->action);
        if ($request->filled('severity')) $query->bySeverity($request->severity);
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        } else {
            $query->recent(30);
        }

        $logs = $query->with(['user:id,name', 'employee:id,first_name,last_name'])
            ->orderBy('created_at', 'desc')
            ->limit(10000) // Prevent memory issues
            ->get();

        // Log the export action
        AuditLog::createLog([
            'module' => 'audit',
            'action' => AuditLog::ACTION_EXPORTED,
            'description' => 'Exported audit logs to CSV',
            'severity' => AuditLog::SEVERITY_WARNING,
            'tags' => ['export', 'audit'],
        ]);

        // Generate CSV
        $filename = 'audit_logs_' . date('Y-m-d_His') . '.csv';
        $handle = fopen('php://temp', 'r+');

        // CSV Headers
        fputcsv($handle, [
            'ID', 'Date/Time', 'User', 'Employee', 'Module', 'Action', 
            'Description', 'Severity', 'IP Address', 'Entity Type', 'Entity ID'
        ]);

        // CSV Rows
        foreach ($logs as $log) {
            fputcsv($handle, [
                $log->id,
                $log->created_at->format('Y-m-d H:i:s'),
                $log->user ? $log->user->name : 'Unknown',
                $log->employee ? "{$log->employee->first_name} {$log->employee->last_name}" : 'N/A',
                $log->module,
                $log->action,
                $log->description,
                $log->severity,
                $log->ip_address,
                $log->entity_type,
                $log->entity_id,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Get available filter options (for frontend dropdown population)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function filterOptions(Request $request)
    {
        // Authorization check - use permission instead of hardcoded roles
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has permission to view audit logs
        if (!$user->hasRole('Manager') && !$user->can('view-finance-audit-logs') && !$user->can('view-hr-audit-logs') && !$user->can('view-all-audit-logs')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $shopOwnerId = $user->shop_owner_id;

        $options = [
            'modules' => AuditLog::forShopOwner($shopOwnerId)
                ->distinct()
                ->pluck('module')
                ->sort()
                ->values(),
            
            'actions' => AuditLog::forShopOwner($shopOwnerId)
                ->distinct()
                ->pluck('action')
                ->sort()
                ->values(),
            
            'severities' => ['info', 'warning', 'critical'],
            
            'users' => \App\Models\User::where('shop_owner_id', $shopOwnerId)
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get(),
            
            'tags' => AuditLog::forShopOwner($shopOwnerId)
                ->whereNotNull('tags')
                ->get()
                ->pluck('tags')
                ->flatten()
                ->unique()
                ->sort()
                ->values(),
        ];

        return response()->json([
            'success' => true,
            'data' => $options,
        ]);
    }

    /**
     * Get single audit log with full details
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        // Authorization check - use permission instead of hardcoded roles
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has permission to view audit logs
        if (!$user->hasRole('Manager') && !$user->can('view-finance-audit-logs') && !$user->can('view-hr-audit-logs') && !$user->can('view-all-audit-logs')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $shopOwnerId = $user->shop_owner_id;

        $log = AuditLog::forShopOwner($shopOwnerId)
            ->with(['user:id,name,email', 'employee:id,first_name,last_name'])
            ->find($id);

        if (!$log) {
            return response()->json(['error' => 'Audit log not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $log,
        ]);
    }
}
