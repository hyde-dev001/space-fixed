<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\NotificationType;
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

        $perPage = filter_var($request->query('per_page'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) ?: 20;
        $perPage = min($perPage, 100);

        $query = Notification::query()
            ->forSuperAdmin((int) $admin->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->boolean('unread_only')) {
            $query->where('is_read', false);
        }

        $notifications = $query->paginate($perPage);

        return response()->json([
            'notifications' => collect($notifications->items())
                ->map(fn (Notification $notification): array => $this->serializeNotification($notification))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'per_page'     => $notifications->perPage(),
                'total'        => $notifications->total(),
                'last_page'    => $notifications->lastPage(),
            ],
            'unread_count' => Notification::query()
                ->forSuperAdmin((int) $admin->getKey())
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
            'count' => Notification::query()
                ->forSuperAdmin((int) $admin->getKey())
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

        $notification = Notification::query()
            ->forSuperAdmin((int) $admin->getKey())
            ->whereKey($id)
            ->first();

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'message'      => 'Notification marked as read',
            'unread_count' => Notification::query()
                ->forSuperAdmin((int) $admin->getKey())
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

        $count = Notification::query()
            ->forSuperAdmin((int) $admin->getKey())
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

        $notification = Notification::query()
            ->forSuperAdmin((int) $admin->getKey())
            ->whereKey($id)
            ->first();

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->delete();

        return response()->json([
            'message'      => 'Notification dismissed',
            'unread_count' => Notification::query()
                ->forSuperAdmin((int) $admin->getKey())
                ->where('is_read', false)
                ->count(),
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeNotification(Notification $notification): array
    {
        $type = $notification->type;

        return [
            'id' => (int) $notification->getKey(),
            'type' => $type instanceof NotificationType ? $type->value : (string) $type,
            'title' => (string) $notification->title,
            'message' => (string) $notification->message,
            'action_url' => $this->safeActionUrl($notification->action_url),
            'is_read' => (bool) $notification->is_read,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    private function safeActionUrl(?string $actionUrl): ?string
    {
        if ($actionUrl === null || $actionUrl === '') {
            return null;
        }

        if (preg_match('/[\\x00-\\x1F\\x7F]/', $actionUrl) === 1
            || str_contains($actionUrl, '\\')
            || !str_starts_with($actionUrl, '/')
            || str_starts_with($actionUrl, '//')
            || preg_match('/%(?![0-9a-fA-F]{2})/', $actionUrl) === 1) {
            return null;
        }

        $parts = parse_url($actionUrl);

        if ($parts === false
            || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'], $parts['port'])) {
            return null;
        }

        return $actionUrl;
    }
}
