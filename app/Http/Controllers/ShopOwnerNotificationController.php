<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Notification;

/**
 * Shop Owner Notification Controller
 * Handles notifications for shop owners
 */
class ShopOwnerNotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get all notifications for authenticated shop owner
     */
    public function index(Request $request): JsonResponse
    {
        $shopOwner = $request->user('shop_owner');
        
        $query = Notification::where('shop_owner_id', $shopOwner->id)
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
        $shopOwner = $request->user('shop_owner');
        $count = $this->notificationService->getUnreadCount($shopOwner->id, true);

        return response()->json(['count' => $count]);
    }

    /**
     * Get recent notifications (for dropdown/bell icon)
     */
    public function recent(Request $request): JsonResponse
    {
        $shopOwner = $request->user('shop_owner');
        $limit = $request->input('limit', 5);
        
        $notifications = $this->notificationService->getRecent($shopOwner->id, $limit, true);

        return response()->json($notifications);
    }

    /**
     * Mark a notification as read
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $shopOwner = $request->user('shop_owner');
        
        $success = $this->notificationService->markAsRead($id, $shopOwner->id, true);

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
        $shopOwner = $request->user('shop_owner');
        
        $count = $this->notificationService->markAllAsRead($shopOwner->id, true);

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
        $shopOwner = $request->user('shop_owner');
        
        $notification = Notification::where('id', $id)
            ->where('shop_owner_id', $shopOwner->id)
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
        $shopOwner = $request->user('shop_owner');

        $stats = [
            'total' => Notification::where('shop_owner_id', $shopOwner->id)->count(),
            'unread' => $this->notificationService->getUnreadCount($shopOwner->id, true),
            'by_category' => [
                'orders' => Notification::where('shop_owner_id', $shopOwner->id)->byCategory('orders')->count(),
                'finance' => Notification::where('shop_owner_id', $shopOwner->id)->byCategory('finance')->count(),
                'crm' => Notification::where('shop_owner_id', $shopOwner->id)->byCategory('crm')->count(),
                'general' => Notification::where('shop_owner_id', $shopOwner->id)->byCategory('general')->count(),
            ],
            'requires_action' => Notification::where('shop_owner_id', $shopOwner->id)->requiresAction()->count(),
        ];

        return response()->json($stats);
    }
}
