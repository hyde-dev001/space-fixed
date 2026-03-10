<?php

namespace App\Http\Controllers\Erp\HR;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $perPage = $request->get('per_page', 20);
        $filter = $request->get('filter', 'all'); // all, unread, read
        
        // Also support unread_only parameter from frontend
        if ($request->has('unread_only') && $request->boolean('unread_only')) {
            $filter = 'unread';
        }

        $query = Notification::where('user_id', $user->id);
        
        // Filter by shop_id if user is ERP staff
        if ($user->shop_owner_id) {
            $query->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            });
        }

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($filter === 'read') {
            $query->whereNotNull('read_at');
        }

        $notifications = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Count unread separately for the frontend badge/counter
        $unreadQuery = Notification::where('user_id', $user->id);
        if ($user->shop_owner_id) {
            $unreadQuery->where(function ($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            });
        }
        $unreadCount = $unreadQuery->where('is_read', false)->count();

        return response()->json([
            'data' => $notifications->items(),
            'current_page' => $notifications->currentPage(),
            'per_page' => $notifications->perPage(),
            'total' => $notifications->total(),
            'last_page' => $notifications->lastPage(),
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Get unread notification count.
     */
    public function unreadCount(): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = Notification::where('user_id', $user->id);
        
        // Filter by shop_id if user is ERP staff
        if ($user->shop_owner_id) {
            $query->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            });
        }
        
        $count = $query->where('is_read', false)->count();

        return response()->json([
            'success' => true,
            'count' => $count,
            'unread_count' => $count,
        ]);
    }

    /**
     * Get recent notifications (for dropdown).
     */
    public function recent(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $limit = $request->get('limit', 10);

        $query = Notification::where('user_id', $user->id);
        
        // Filter by shop_id if user is ERP staff
        if ($user->shop_owner_id) {
            $query->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            });
        }

        $notifications = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($notifications);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead($id): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $notification = Notification::where('user_id', $user->id)
            ->findOrFail($id);
        
        if (!$notification->is_read) {
            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = Notification::where('user_id', $user->id);
        
        // Filter by shop_id if user is ERP staff
        if ($user->shop_owner_id) {
            $query->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            });
        }
        
        $query->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
        ]);
    }

    /**
     * Delete a specific notification.
     */
    public function destroy($id): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = Notification::where('id', $id)
            ->where('user_id', $user->id);
            
        // Filter by shop_id if user is ERP staff
        if ($user->shop_owner_id) {
            $query->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            });
        }
        
        $notification = $query->firstOrFail();
        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted successfully'
        ]);
    }

    /**
     * Delete all read notifications.
     */
    public function clearRead(): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = Notification::where('user_id', $user->id);
        
        // Filter by shop_id if user is ERP staff
        if ($user->shop_owner_id) {
            $query->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            });
        }
        
        $query->whereNotNull('read_at')
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'All read notifications cleared'
        ]);
    }

    /**
     * Get notification statistics.
     */
    public function stats(): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $baseQuery = Notification::where('user_id', $user->id);
        
        // Filter by shop_id if user is ERP staff
        if ($user->shop_owner_id) {
            $baseQuery->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            });
        }

        $totalNotifications = (clone $baseQuery)->count();
        $unreadNotifications = (clone $baseQuery)->whereNull('read_at')->count();
        $readNotifications = (clone $baseQuery)->whereNotNull('read_at')->count();

        // Get notifications by type (last 30 days)
        $notificationsByType = (clone $baseQuery)
            ->where('created_at', '>=', now()->subDays(30))
            ->get()
            ->groupBy('type')
            ->map(function ($group) {
                return $group->count();
            });

        // Get notifications by priority (last 30 days)
        $notificationsByPriority = (clone $baseQuery)
            ->where('created_at', '>=', now()->subDays(30))
            ->get()
            ->groupBy('priority')
            ->map(function ($group) {
                return $group->count();
            });

        return response()->json([
            'success' => true,
            'stats' => [
                'total' => $totalNotifications,
                'unread' => $unreadNotifications,
                'read' => $readNotifications,
                'by_type' => $notificationsByType,
                'by_priority' => $notificationsByPriority,
            ]
        ]);
    }
}
