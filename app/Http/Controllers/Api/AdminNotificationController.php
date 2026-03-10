<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AdminNotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated super admin.
     */
    public function index(Request $request): JsonResponse
    {
        $admin = Auth::guard('super_admin')->user();

        $query = Notification::where('super_admin_id', $admin->id)
            ->orderBy('created_at', 'desc');

        if ($request->boolean('unread_only')) {
            $query->where('is_read', false);
        }

        $notifications = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'notifications' => $notifications->items(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'per_page'     => $notifications->perPage(),
                'total'        => $notifications->total(),
                'last_page'    => $notifications->lastPage(),
            ],
            'unread_count' => Notification::where('super_admin_id', $admin->id)
                ->where('is_read', false)
                ->count(),
        ]);
    }

    /**
     * Return unread notification count.
     */
    public function unreadCount(): JsonResponse
    {
        $admin = Auth::guard('super_admin')->user();

        return response()->json([
            'count' => Notification::where('super_admin_id', $admin->id)
                ->where('is_read', false)
                ->count(),
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(int $id): JsonResponse
    {
        $admin = Auth::guard('super_admin')->user();

        $notification = Notification::where('id', $id)
            ->where('super_admin_id', $admin->id)
            ->first();

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'message'      => 'Notification marked as read',
            'unread_count' => Notification::where('super_admin_id', $admin->id)
                ->where('is_read', false)
                ->count(),
        ]);
    }

    /**
     * Mark all notifications for this admin as read.
     */
    public function markAllAsRead(): JsonResponse
    {
        $admin = Auth::guard('super_admin')->user();

        $count = Notification::where('super_admin_id', $admin->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json([
            'message' => "Marked {$count} notifications as read",
            'count'   => $count,
        ]);
    }

    /**
     * Delete a notification.
     */
    public function destroy(int $id): JsonResponse
    {
        $admin = Auth::guard('super_admin')->user();

        $notification = Notification::where('id', $id)
            ->where('super_admin_id', $admin->id)
            ->first();

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->delete();

        return response()->json([
            'message'      => 'Notification deleted',
            'unread_count' => Notification::where('super_admin_id', $admin->id)
                ->where('is_read', false)
                ->count(),
        ]);
    }
}
