<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Notification;

/**
 * ERP Notification Controller
 * Handles notifications for ERP staff (employees with shop_owner_id)
 */
class ErpNotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get all notifications for authenticated ERP user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('user');
        
        // Ensure user is an ERP staff member
        if (!$user->shop_owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Not an ERP user'
            ], 403);
        }

        // Query notifications for this user, filtering by shop_id if it exists
        $query = Notification::where('user_id', $user->id)
            ->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            })
            ->orderBy('created_at', 'desc');

        // Filter by read status if requested
        if ($request->has('unread_only') && $request->boolean('unread_only')) {
            $query->unread();
        }

        // Filter by category if requested
        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        // Filter by action required if requested
        if ($request->has('requires_action') && $request->boolean('requires_action')) {
            $query->requiresAction();
        }

        // Paginate
        $perPage = $request->input('per_page', 15);
        $notifications = $query->paginate($perPage);

        return response()->json($notifications);
    }

    /**
     * Get unread notification count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user('user');
        
        if (!$user->shop_owner_id) {
            return response()->json(['count' => 0]);
        }

        $count = Notification::where('user_id', $user->id)
            ->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            })
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Get recent notifications (for dropdown/bell icon)
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $request->user('user');
        $limit = $request->input('limit', 5);
        
        if (!$user->shop_owner_id) {
            return response()->json([]);
        }

        $notifications = Notification::where('user_id', $user->id)
            ->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($notifications);
    }

    /**
     * Mark a notification as read
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user('user');
        
        if (!$user->shop_owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            })
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read'
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user('user');
        
        if (!$user->shop_owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $count = Notification::where('user_id', $user->id)
            ->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            })
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => "{$count} notifications marked as read",
            'count' => $count
        ]);
    }

    /**
     * Delete a notification
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user('user');
        
        if (!$user->shop_owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            })
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted'
        ]);
    }

    /**
     * Get notification statistics/summary
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user('user');

        if (!$user->shop_owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $baseQuery = Notification::where('user_id', $user->id)
            ->where(function($q) use ($user) {
                $q->where('shop_id', $user->shop_owner_id)
                  ->orWhereNull('shop_id');
            });

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'unread' => (clone $baseQuery)->unread()->count(),
            'by_category' => [
                'finance' => (clone $baseQuery)->byCategory('finance')->count(),
                'hr' => (clone $baseQuery)->byCategory('hr')->count(),
                'repairs' => (clone $baseQuery)->byCategory('repairs')->count(),
                'general' => (clone $baseQuery)->byCategory('general')->count(),
            ],
            'requires_action' => (clone $baseQuery)->requiresAction()->count(),
        ];

        return response()->json($stats);
    }
}
