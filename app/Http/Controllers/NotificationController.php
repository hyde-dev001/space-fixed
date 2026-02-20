<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Notification;

/**
 * Customer Notification Controller
 * Handles notifications for customers (users without shop_owner_id)
 */
class NotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get all notifications for authenticated customer
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('user');
        
        $query = Notification::where('user_id', $user->id)
            ->whereNull('shop_owner_id') // Ensure customer notifications only
            ->active() // Only active (not archived) by default
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
            $query->where('requires_action', true);
        }

        // Filter by priority if requested
        if ($request->has('priority')) {
            $query->byPriority($request->priority);
        }

        // Filter by archived status
        if ($request->has('archived') && $request->boolean('archived')) {
            $query = Notification::where('user_id', $user->id)->archived();
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('created_at', '<=', $request->end_date);
        }

        // Search by title or message
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('message', 'like', "%{$search}%");
            });
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
        $count = $this->notificationService->getUnreadCount($user->id, false);

        return response()->json(['count' => $count]);
    }

    /**
     * Get recent notifications (for dropdown/bell icon)
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $request->user('user');
        $limit = $request->input('limit', 5);
        
        $notifications = $this->notificationService->getRecent($user->id, $limit, false);

        return response()->json($notifications);
    }

    /**
     * Mark a notification as read
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user('user');
        
        $success = $this->notificationService->markAsRead($id, $user->id, false);

        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found or already read'
            ], 404);
        }

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
        
        $count = $this->notificationService->markAllAsRead($user->id, false);

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
        
        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->whereNull('shop_owner_id')
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

        $stats = [
            'total' => Notification::where('user_id', $user->id)->whereNull('shop_owner_id')->count(),
            'unread' => $this->notificationService->getUnreadCount($user->id, false),
            'by_category' => [
                'orders' => Notification::where('user_id', $user->id)->byCategory('orders')->count(),
                'repairs' => Notification::where('user_id', $user->id)->byCategory('repairs')->count(),
                'payments' => Notification::where('user_id', $user->id)->byCategory('payments')->count(),
                'messages' => Notification::where('user_id', $user->id)->byCategory('messages')->count(),
                'reviews' => Notification::where('user_id', $user->id)->byCategory('reviews')->count(),
            ],
            'requires_action' => Notification::where('user_id', $user->id)->requiresAction()->count(),
        ];

        return response()->json($stats);
    }

    // ==================== PHASE 6: ADVANCED FEATURES ====================

    /**
     * Bulk mark notifications as read
     */
    public function bulkMarkAsRead(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:notifications,id',
        ]);

        $user = $request->user('user');
        $count = $this->notificationService->bulkMarkAsRead($request->ids, $user->id, false);

        return response()->json([
            'success' => true,
            'message' => "{$count} notifications marked as read",
            'count' => $count
        ]);
    }

    /**
     * Bulk delete notifications
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:notifications,id',
        ]);

        $user = $request->user('user');
        $count = $this->notificationService->bulkDelete($request->ids, $user->id, false);

        return response()->json([
            'success' => true,
            'message' => "{$count} notifications deleted",
            'count' => $count
        ]);
    }

    /**
     * Bulk archive notifications
     */
    public function bulkArchive(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:notifications,id',
        ]);

        $user = $request->user('user');
        $count = $this->notificationService->bulkArchive($request->ids, $user->id, false);

        return response()->json([
            'success' => true,
            'message' => "{$count} notifications archived",
            'count' => $count
        ]);
    }

    /**
     * Archive a single notification
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        $user = $request->user('user');
        
        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->whereNull('shop_owner_id')
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        $notification->archive();

        return response()->json([
            'success' => true,
            'message' => 'Notification archived'
        ]);
    }

    /**
     * Get grouped notifications
     */
    public function grouped(Request $request): JsonResponse
    {
        $user = $request->user('user');
        $grouped = $this->notificationService->getGrouped($user->id, false);

        return response()->json($grouped);
    }

    /**
     * Export notifications
     */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user('user');
        
        $filters = $request->only(['start_date', 'end_date', 'type', 'priority', 'is_read']);
        $notifications = $this->notificationService->exportNotifications($user->id, false, $filters);

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'count' => count($notifications)
        ]);
    }
}
