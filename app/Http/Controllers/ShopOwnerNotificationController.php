<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Notification;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Validator;

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
        
        $query = Notification::where('shop_owner_id', $shopOwner->id);

        // Filter by archived status first
        if ($request->has('archived') && $request->boolean('archived')) {
            $query->archived();
        } else {
            $query->active();
        }

        $query->orderBy('created_at', 'desc');

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

        // Filter by priority if requested
        if ($request->has('priority')) {
            $query->byPriority($request->priority);
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

        // Add unread count to response
        $unreadCount = Notification::where('shop_owner_id', $shopOwner->id)
            ->active()
            ->unread()
            ->count();

        $response = $notifications->toArray();
        $response['unread_count'] = $unreadCount;

        return response()->json($response);
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

        $notification->archive();

        return response()->json([
            'success' => true,
            'message' => 'Notification archived'
        ]);
    }

    /**
     * Unarchive a notification
     */
    public function unarchive(Request $request, int $id): JsonResponse
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

        $notification->unarchive();

        return response()->json([
            'success' => true,
            'message' => 'Notification unarchived'
        ]);
    }

    /**
     * Get notification preferences
     */
    public function getPreferences(Request $request): JsonResponse
    {
        $shopOwner = $request->user('shop_owner');
        $preferences = NotificationPreference::firstOrCreate(
            ['shop_owner_id' => $shopOwner->id],
            [
                'preferences' => [],
                'email_digest_frequency' => 'none',
                'sound_enabled' => true,
            ]
        );

        return response()->json($preferences);
    }

    /**
     * Update notification preferences
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $shopOwner = $request->user('shop_owner');
        
        $validator = Validator::make($request->all(), [
            'preferences' => 'nullable|array',
            'email_digest_frequency' => 'nullable|in:none,daily,weekly',
            'sound_enabled' => 'nullable|boolean',
            // Shop owner notification toggles
            'browser_new_orders' => 'nullable|boolean',
            'browser_repair_updates' => 'nullable|boolean',
            'browser_approvals' => 'nullable|boolean',
            'browser_alerts' => 'nullable|boolean',
            'browser_order_updates' => 'nullable|boolean',
            'browser_payment_updates' => 'nullable|boolean',
            // Email preferences
            'email_new_orders' => 'nullable|boolean',
            'email_repair_updates' => 'nullable|boolean',
            'email_approvals' => 'nullable|boolean',
            'email_alerts' => 'nullable|boolean',
            'email_order_updates' => 'nullable|boolean',
            'email_payment_updates' => 'nullable|boolean',
            // Phase 6 features
            'quiet_hours_enabled' => 'nullable|boolean',
            'quiet_hours_start' => 'nullable|date_format:H:i',
            'quiet_hours_end' => 'nullable|date_format:H:i',
            'browser_push_enabled' => 'nullable|boolean',
            'auto_archive_enabled' => 'nullable|boolean',
            'auto_archive_days' => 'nullable|integer|min:1|max:365',
            'group_notifications' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $preferences = NotificationPreference::firstOrCreate(
            ['shop_owner_id' => $shopOwner->id]
        );
        
        $preferences->update($request->only([
            'preferences',
            'email_digest_frequency',
            'sound_enabled',
            // Shop owner notification toggles
            'browser_new_orders',
            'browser_repair_updates',
            'browser_approvals',
            'browser_alerts',
            'browser_order_updates',
            'browser_payment_updates',
            // Email preferences
            'email_new_orders',
            'email_repair_updates',
            'email_approvals',
            'email_alerts',
            'email_order_updates',
            'email_payment_updates',
            // Phase 6 features
            'quiet_hours_enabled',
            'quiet_hours_start',
            'quiet_hours_end',
            'browser_push_enabled',
            'auto_archive_enabled',
            'auto_archive_days',
            'group_notifications',
        ]));

        return response()->json([
            'message' => 'Preferences updated successfully',
            'preferences' => $preferences,
        ]);
    }

    /**
     * Get notification statistics/summary
     */
    public function stats(Request $request): JsonResponse
    {
        $shopOwner = $request->user('shop_owner');

        $stats = [
            'total' => Notification::where('shop_owner_id', $shopOwner->id)->active()->count(),
            'unread' => $this->notificationService->getUnreadCount($shopOwner->id, true),
            'by_category' => [
                'orders' => Notification::where('shop_owner_id', $shopOwner->id)->active()->byCategory('orders')->count(),
                'finance' => Notification::where('shop_owner_id', $shopOwner->id)->active()->byCategory('finance')->count(),
                'crm' => Notification::where('shop_owner_id', $shopOwner->id)->active()->byCategory('crm')->count(),
                'general' => Notification::where('shop_owner_id', $shopOwner->id)->active()->byCategory('general')->count(),
            ],
            'requires_action' => Notification::where('shop_owner_id', $shopOwner->id)->active()->requiresAction()->count(),
        ];

        return response()->json($stats);
    }
}
