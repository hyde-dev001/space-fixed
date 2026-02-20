<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    /**
     * Get all notifications for authenticated user
     */
    public function index(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        $query = Notification::forUser($user->id)
            ->orderBy('created_at', 'desc');

        // Filter by read status
        if ($request->has('unread_only') && $request->unread_only) {
            $query->unread();
        }

        $notifications = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'notifications' => $notifications->items(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
            'unread_count' => $this->notificationService->getUnreadCount($user->id),
        ]);
    }

    /**
     * Get unread count
     */
    public function unreadCount()
    {
        $user = Auth::guard('user')->user();
        
        return response()->json([
            'count' => $this->notificationService->getUnreadCount($user->id),
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, int $id)
    {
        $user = Auth::guard('user')->user();
        
        $success = $this->notificationService->markAsRead($id, $user->id);

        if (!$success) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        return response()->json([
            'message' => 'Notification marked as read',
            'unread_count' => $this->notificationService->getUnreadCount($user->id),
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead()
    {
        $user = Auth::guard('user')->user();
        
        $count = $this->notificationService->markAllAsRead($user->id);

        return response()->json([
            'message' => "Marked {$count} notifications as read",
            'count' => $count,
        ]);
    }

    /**
     * Delete notification
     */
    public function destroy(int $id)
    {
        $user = Auth::guard('user')->user();
        
        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted',
            'unread_count' => $this->notificationService->getUnreadCount($user->id),
        ]);
    }

    /**
     * Get notification preferences
     */
    public function getPreferences()
    {
        $user = Auth::guard('user')->user();
        
        $preferences = NotificationPreference::getOrCreateForUser($user->id);

        return response()->json($preferences);
    }

    /**
     * Update notification preferences
     */
    public function updatePreferences(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        $validator = Validator::make($request->all(), [
            // Original preferences
            'email_expense_approval' => 'boolean',
            'email_leave_approval' => 'boolean',
            'email_invoice_created' => 'boolean',
            'email_delegation_assigned' => 'boolean',
            'browser_expense_approval' => 'boolean',
            'browser_leave_approval' => 'boolean',
            'browser_invoice_created' => 'boolean',
            'browser_delegation_assigned' => 'boolean',
            // Phase 6: Quiet hours
            'quiet_hours_enabled' => 'boolean',
            'quiet_hours_start' => 'nullable|date_format:H:i',
            'quiet_hours_end' => 'nullable|date_format:H:i',
            // Phase 6: Browser push
            'browser_push_enabled' => 'boolean',
            'push_subscription' => 'nullable|string',
            // Phase 6: Grouping
            'group_notifications' => 'boolean',
            // Phase 6: Auto-archive
            'auto_archive_enabled' => 'boolean',
            'auto_archive_days' => 'nullable|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $preferences = NotificationPreference::getOrCreateForUser($user->id);
        $preferences->update($request->only([
            'email_expense_approval',
            'email_leave_approval',
            'email_invoice_created',
            'email_delegation_assigned',
            'browser_expense_approval',
            'browser_leave_approval',
            'browser_invoice_created',
            'browser_delegation_assigned',
            'quiet_hours_enabled',
            'quiet_hours_start',
            'quiet_hours_end',
            'browser_push_enabled',
            'push_subscription',
            'group_notifications',
            'auto_archive_enabled',
            'auto_archive_days',
        ]));

        return response()->json([
            'message' => 'Preferences updated successfully',
            'preferences' => $preferences,
        ]);
    }
}
