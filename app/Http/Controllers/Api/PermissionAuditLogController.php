<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PermissionAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Permission Audit Log API Controller
 * 
 * Provides endpoints for viewing and exporting permission audit logs
 * Critical for compliance requirements (GDPR, SOX, HIPAA, etc.)
 * 
 * Access Control:
 * - Managers: Full access to all logs
 * - Shop Owners: Full access to their shop logs
 * - Others: No access (compliance restriction)
 */
class PermissionAuditLogController extends Controller
{
    /**
     * Get paginated permission audit logs with filtering
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            // Only Managers and Shop Owners can view audit logs
            if (!$user->hasRole('Manager') && !$user->hasRole('Shop Owner')) {
                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => 'Only Managers and Shop Owners can view permission audit logs'
                ], 403);
            }

            $shopOwnerId = $user->shop_owner_id;

            // Build query
            $query = PermissionAuditLog::forShop($shopOwnerId)
                ->with(['actor:id,name,email', 'subject:id,name,email'])
                ->orderBy('created_at', 'desc');

            // Apply filters
            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
            }

            if ($request->filled('action')) {
                $query->byAction($request->action);
            }

            if ($request->filled('subject_id')) {
                $query->forUser($request->subject_id);
            }

            if ($request->filled('actor_id')) {
                $query->byActor($request->actor_id);
            }

            if ($request->filled('severity')) {
                $query->where('severity', $request->severity);
            }

            if ($request->boolean('high_severity_only')) {
                $query->highSeverity();
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('subject_name', 'like', "%{$search}%")
                      ->orWhere('actor_name', 'like', "%{$search}%")
                      ->orWhere('role_name', 'like', "%{$search}%")
                      ->orWhere('permission_name', 'like', "%{$search}%")
                      ->orWhere('position_name', 'like', "%{$search}%")
                      ->orWhere('reason', 'like', "%{$search}%");
                });
            }

            // Paginate
            $perPage = $request->input('per_page', 20);
            $logs = $query->paginate($perPage);

            return response()->json($logs);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch audit logs',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get audit log statistics
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function stats(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user || (!$user->hasRole('Manager') && !$user->hasRole('Shop Owner'))) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $shopOwnerId = $user->shop_owner_id;
            $days = $request->input('days', 30);

            $from = now()->subDays($days);
            $to = now();

            $logs = PermissionAuditLog::forShop($shopOwnerId)
                ->dateRange($from, $to)
                ->get();

            $stats = [
                'period' => [
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                    'days' => $days,
                ],
                'total_changes' => $logs->count(),
                'changes_today' => $logs->where('created_at', '>=', now()->startOfDay())->count(),
                'changes_this_week' => $logs->where('created_at', '>=', now()->startOfWeek())->count(),
                'changes_this_month' => $logs->where('created_at', '>=', now()->startOfMonth())->count(),
                
                'by_severity' => [
                    'low' => $logs->where('severity', 'low')->count(),
                    'medium' => $logs->where('severity', 'medium')->count(),
                    'high' => $logs->where('severity', 'high')->count(),
                    'critical' => $logs->where('severity', 'critical')->count(),
                ],
                
                'by_action' => $logs->groupBy('action')->map->count()->toArray(),
                
                'most_active_actors' => $logs->groupBy('actor_name')
                    ->map->count()
                    ->sortDesc()
                    ->take(5)
                    ->toArray(),
                
                'most_affected_users' => $logs->groupBy('subject_name')
                    ->map->count()
                    ->sortDesc()
                    ->take(5)
                    ->toArray(),
                
                'recent_critical' => $logs->where('severity', 'critical')
                    ->sortByDesc('created_at')
                    ->take(10)
                    ->values()
                    ->toArray(),
            ];

            return response()->json($stats);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get compliance report for a specific date range
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function complianceReport(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user || (!$user->hasRole('Manager') && !$user->hasRole('Shop Owner'))) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $validated = $request->validate([
                'date_from' => 'required|date',
                'date_to' => 'required|date|after_or_equal:date_from',
            ]);

            $shopOwnerId = $user->shop_owner_id;
            $report = PermissionAuditLog::getComplianceReport(
                $shopOwnerId,
                $validated['date_from'],
                $validated['date_to']
            );

            return response()->json([
                'success' => true,
                'report' => $report,
                'generated_at' => now()->toISOString(),
                'generated_by' => $user->name,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate compliance report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get permission history for a specific user
     * 
     * @param int $userId
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function userHistory(int $userId, Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user || (!$user->hasRole('Manager') && !$user->hasRole('Shop Owner'))) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $limit = $request->input('limit', 50);
            $history = PermissionAuditLog::getUserHistory($userId, $limit);

            return response()->json([
                'success' => true,
                'user_id' => $userId,
                'history' => $history,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch user history',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get changes requiring review
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function requiresReview(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user || (!$user->hasRole('Manager') && !$user->hasRole('Shop Owner'))) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $shopOwnerId = $user->shop_owner_id;
            $days = $request->input('days', 7);

            $logs = PermissionAuditLog::forShop($shopOwnerId)
                ->recent($days)
                ->where(function($query) {
                    $query->highSeverity()
                          ->orWhereIn('action', ['role_removed', 'permissions_synced']);
                })
                ->with(['actor:id,name,email', 'subject:id,name,email'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'count' => $logs->count(),
                'changes' => $logs->map(function($log) {
                    return [
                        'id' => $log->id,
                        'date' => $log->created_at->format('Y-m-d H:i:s'),
                        'action' => $log->action,
                        'severity' => $log->severity,
                        'actor' => $log->actor_name,
                        'subject' => $log->subject_name,
                        'description' => $log->getChangeDescription(),
                        'reason' => $log->reason,
                        'requires_review' => $log->requiresReview(),
                    ];
                }),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch changes requiring review',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export audit logs to CSV
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function export(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user || (!$user->hasRole('Manager') && !$user->hasRole('Shop Owner'))) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $shopOwnerId = $user->shop_owner_id;

            // Build query with filters
            $query = PermissionAuditLog::forShop($shopOwnerId)
                ->orderBy('created_at', 'desc');

            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
            }

            $logs = $query->limit(10000)->get(); // Safety limit

            // Generate CSV
            $csv = "Date,Action,Severity,Actor,Subject,Role,Permission,Position,Reason,IP Address\n";
            
            foreach ($logs as $log) {
                $csv .= implode(',', [
                    '"' . $log->created_at->format('Y-m-d H:i:s') . '"',
                    '"' . $log->action . '"',
                    '"' . $log->severity . '"',
                    '"' . ($log->actor_name ?? 'System') . '"',
                    '"' . $log->subject_name . '"',
                    '"' . ($log->role_name ?? '') . '"',
                    '"' . ($log->permission_name ?? '') . '"',
                    '"' . ($log->position_name ?? '') . '"',
                    '"' . str_replace('"', '""', $log->reason ?? '') . '"',
                    '"' . ($log->ip_address ?? '') . '"',
                ]) . "\n";
            }

            $filename = 'permission_audit_log_' . now()->format('Y-m-d_His') . '.csv';

            return response($csv, 200)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to export audit logs',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
