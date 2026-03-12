<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Notification;
use App\Models\Order;
use App\Models\ShopOwner;

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
        $this->enrichNotificationsWithShoeName($notifications);

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
        $this->enrichNotificationsWithShoeName($notifications);

        return response()->json($notifications);
    }

    private function enrichNotificationsWithShoeName($notifications): void
    {
        $collection = method_exists($notifications, 'getCollection')
            ? $notifications->getCollection()
            : $notifications;

        if (!$collection || $collection->isEmpty()) {
            return;
        }

        $orderIds = $collection
            ->map(function ($notification) {
                $data = is_array($notification->data) ? $notification->data : [];
                return $data['order_id'] ?? null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            $this->enrichNotificationsWithShopPhoto($notifications);
            return;
        }

        $ordersById = Order::with('items:id,order_id,product_name')
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');

        $shoeNamesByOrderId = $ordersById
            ->mapWithKeys(function ($order) {
                $firstItemName = $order->items->first()?->product_name;
                return [$order->id => $firstItemName];
            })
            ->filter()
            ->toArray();

        $shopIds = $ordersById
            ->pluck('shop_owner_id')
            ->filter()
            ->unique()
            ->values();

        $shopPhotoById = ShopOwner::query()
            ->whereIn('id', $shopIds)
            ->get(['id', 'profile_photo'])
            ->mapWithKeys(function ($shopOwner) {
                $photo = $shopOwner->profile_photo;
                if (!$photo) {
                    return [$shopOwner->id => null];
                }

                $photoPath = str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://') || str_starts_with($photo, '/')
                    ? $photo
                    : '/storage/' . ltrim($photo, '/');

                return [$shopOwner->id => $photoPath];
            })
            ->toArray();

        $collection->transform(function ($notification) use ($shoeNamesByOrderId, $ordersById, $shopPhotoById) {
            $data = is_array($notification->data) ? $notification->data : [];
            $orderId = $data['order_id'] ?? null;

            if ($orderId && empty($data['shoe_name']) && !empty($shoeNamesByOrderId[$orderId])) {
                $data['shoe_name'] = $shoeNamesByOrderId[$orderId];
            }

            if ($orderId && empty($data['shop_profile_photo']) && isset($ordersById[$orderId])) {
                $shopId = $ordersById[$orderId]->shop_owner_id;
                if ($shopId && !empty($shopPhotoById[$shopId])) {
                    $data['shop_profile_photo'] = $shopPhotoById[$shopId];
                }
            }

            $notification->data = $data;

            return $notification;
        });

        if (method_exists($notifications, 'setCollection')) {
            $notifications->setCollection($collection);
        }

        $this->enrichNotificationsWithShopPhoto($notifications);
    }

    private function enrichNotificationsWithShopPhoto($notifications): void
    {
        $collection = method_exists($notifications, 'getCollection')
            ? $notifications->getCollection()
            : $notifications;

        if (!$collection || $collection->isEmpty()) {
            return;
        }

        $shopIds = $collection
            ->map(function ($notification) {
                $data = is_array($notification->data) ? $notification->data : [];
                return $data['shop_owner_id'] ?? $notification->shop_id ?? $notification->shop_owner_id ?? null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($shopIds->isEmpty()) {
            return;
        }

        $shopPhotoById = ShopOwner::query()
            ->whereIn('id', $shopIds)
            ->get(['id', 'profile_photo'])
            ->mapWithKeys(function ($shopOwner) {
                $photo = $shopOwner->profile_photo;
                if (!$photo) {
                    return [$shopOwner->id => null];
                }

                $photoPath = str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://') || str_starts_with($photo, '/')
                    ? $photo
                    : '/storage/' . ltrim($photo, '/');

                return [$shopOwner->id => $photoPath];
            })
            ->toArray();

        $collection->transform(function ($notification) use ($shopPhotoById) {
            $data = is_array($notification->data) ? $notification->data : [];

            if (!empty($data['shop_profile_photo'])) {
                return $notification;
            }

            $shopId = $data['shop_owner_id'] ?? $notification->shop_id ?? $notification->shop_owner_id ?? null;
            if ($shopId && !empty($shopPhotoById[$shopId])) {
                $data['shop_profile_photo'] = $shopPhotoById[$shopId];
                $notification->data = $data;
            }

            return $notification;
        });

        if (method_exists($notifications, 'setCollection')) {
            $notifications->setCollection($collection);
        }
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
