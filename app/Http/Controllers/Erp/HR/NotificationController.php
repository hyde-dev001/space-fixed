<?php

namespace App\Http\Controllers\Erp\HR;

use App\Http\Controllers\Controller;
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

        $query = $user->notifications();

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($filter === 'read') {
            $query->whereNotNull('read_at');
        }

        $notifications = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'notifications' => $notifications->items(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ]
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

        $count = $user->unreadNotifications()->count();

        return response()->json([
            'success' => true,
            'unread_count' => $count
        ]);
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

        $notification = $user->notifications()->findOrFail($id);
        
        if (!$notification->read_at) {
            $notification->markAsRead();
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read'
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

        $user->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read'
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

        $notification = $user->notifications()->findOrFail($id);
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

        $user->readNotifications()->delete();

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

        $totalNotifications = $user->notifications()->count();
        $unreadNotifications = $user->unreadNotifications()->count();
        $readNotifications = $user->readNotifications()->count();

        // Get notifications by type (last 30 days)
        $notificationsByType = $user->notifications()
            ->where('created_at', '>=', now()->subDays(30))
            ->get()
            ->groupBy(function ($notification) {
                return $notification->data['type'] ?? 'unknown';
            })
            ->map(function ($group) {
                return $group->count();
            });

        // Get notifications by priority (last 30 days)
        $notificationsByPriority = $user->notifications()
            ->where('created_at', '>=', now()->subDays(30))
            ->get()
            ->groupBy(function ($notification) {
                return $notification->data['priority'] ?? 'medium';
            })
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
