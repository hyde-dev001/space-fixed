<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Notification;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Validator;

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

        $query = Notification::where('user_id', $user->id)
            ->where('shop_id', $user->shop_owner_id)
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
            ->where('shop_id', $user->shop_owner_id)
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
            ->where('shop_id', $user->shop_owner_id)
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
            ->where('shop_id', $user->shop_owner_id)
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
            ->where('shop_id', $user->shop_owner_id)
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
            ->where('shop_id', $user->shop_owner_id)
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
     * Get notification preferences
     */
    public function getPreferences(Request $request): JsonResponse
    {
        $user = $request->user('user');
        
        if (!$user->shop_owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $preferences = NotificationPreference::getOrCreateForUser($user->id);
        return response()->json($preferences);
    }

    /**
     * Update notification preferences
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user('user');
        
        if (!$user->shop_owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'preferences' => 'nullable|array',
            'email_digest_frequency' => 'nullable|in:none,daily,weekly',
            'sound_enabled' => 'nullable|boolean',
            // Staff notification toggles
            'browser_repair_updates' => 'nullable|boolean',
            'browser_tasks' => 'nullable|boolean',
            'browser_hr_updates' => 'nullable|boolean',
            'browser_approvals' => 'nullable|boolean',
            'browser_alerts' => 'nullable|boolean',
            'browser_order_updates' => 'nullable|boolean',
            'browser_payment_updates' => 'nullable|boolean',
            'browser_new_orders' => 'nullable|boolean',
            // Email preferences
            'email_repair_updates' => 'nullable|boolean',
            'email_tasks' => 'nullable|boolean',
            'email_hr_updates' => 'nullable|boolean',
            'email_approvals' => 'nullable|boolean',
            'email_alerts' => 'nullable|boolean',
            'email_order_updates' => 'nullable|boolean',
            'email_payment_updates' => 'nullable|boolean',
            'email_new_orders' => 'nullable|boolean',
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

        $preferences = NotificationPreference::getOrCreateForUser($user->id);
        $preferences->update($request->only([
            'preferences',
            'email_digest_frequency',
            'sound_enabled',
            // Staff notification toggles
            'browser_repair_updates',
            'browser_tasks',
            'browser_hr_updates',
            'browser_approvals',
            'browser_alerts',
            'browser_order_updates',
            'browser_payment_updates',
            'browser_new_orders',
            // Email preferences
            'email_repair_updates',
            'email_tasks',
            'email_hr_updates',
            'email_approvals',
            'email_alerts',
            'email_order_updates',
            'email_payment_updates',
            'email_new_orders',
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
        $user = $request->user('user');

        if (!$user->shop_owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $baseQuery = Notification::where('user_id', $user->id)
            ->where('shop_id', $user->shop_owner_id);

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
