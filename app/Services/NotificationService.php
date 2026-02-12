<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Mail\NotificationEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send a notification to a user
     */
    public function send(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        int $shopId = null
    ): ?Notification {
        try {
            $preferences = NotificationPreference::getOrCreateForUser($userId);
            
            // Create browser notification if enabled
            $notification = null;
            $browserPrefKey = 'browser_' . $type;
            if ($preferences->$browserPrefKey ?? true) {
                $notification = Notification::create([
                    'user_id' => $userId,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'action_url' => $actionUrl,
                    'shop_id' => $shopId,
                ]);
            }

            // Send email notification if enabled
            $emailPrefKey = 'email_' . $type;
            if ($preferences->$emailPrefKey ?? false) {
                $this->sendEmail($userId, $title, $message, $actionUrl);
            }

            return $notification;
        } catch (\Exception $e) {
            Log::error('Failed to send notification: ' . $e->getMessage(), [
                'user_id' => $userId,
                'type' => $type,
            ]);
            return null;
        }
    }

    /**
     * Send notification for expense approval request
     */
    public function notifyExpenseApproval(
        int $managerId,
        array $expenseData,
        int $shopId
    ): void {
        $this->send(
            userId: $managerId,
            type: 'expense_approval',
            title: 'New Expense Approval Required',
            message: "Expense of ₱{$expenseData['amount']} requires your approval",
            data: $expenseData,
            actionUrl: '/erp/finance/approvals',
            shopId: $shopId
        );
    }

    /**
     * Send notification for leave approval request
     */
    public function notifyLeaveApproval(
        int $managerId,
        array $leaveData,
        int $shopId
    ): void {
        $this->send(
            userId: $managerId,
            type: 'leave_approval',
            title: 'Leave Request Pending',
            message: "{$leaveData['employee_name']} has requested leave from {$leaveData['start_date']} to {$leaveData['end_date']}",
            data: $leaveData,
            actionUrl: '/erp/manager/dashboard',
            shopId: $shopId
        );
    }

    /**
     * Send notification when invoice is created from job
     */
    public function notifyInvoiceCreated(
        int $financeUserId,
        array $invoiceData,
        int $shopId
    ): void {
        $this->send(
            userId: $financeUserId,
            type: 'invoice_created',
            title: 'Invoice Auto-Generated',
            message: "Invoice {$invoiceData['reference']} was created from job order",
            data: $invoiceData,
            actionUrl: '/erp/finance/invoices',
            shopId: $shopId
        );
    }

    /**
     * Send notification when delegation is assigned
     */
    public function notifyDelegationAssigned(
        int $delegateId,
        array $delegationData,
        int $shopId
    ): void {
        $this->send(
            userId: $delegateId,
            type: 'delegation_assigned',
            title: 'Approval Authority Delegated',
            message: "{$delegationData['delegated_by']} has delegated approval authority to you from {$delegationData['start_date']} to {$delegationData['end_date']}",
            data: $delegationData,
            actionUrl: '/erp/finance/approvals',
            shopId: $shopId
        );
    }

    /**
     * Send email notification
     */
    private function sendEmail(
        int $userId,
        string $title,
        string $message,
        ?string $actionUrl
    ): void {
        try {
            $user = \App\Models\User::find($userId);
            if ($user && $user->email) {
                Mail::to($user->email)->send(
                    new NotificationEmail($title, $message, $actionUrl)
                );
            }
        } catch (\Exception $e) {
            Log::error('Failed to send email notification: ' . $e->getMessage());
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if ($notification) {
            $notification->markAsRead();
            return true;
        }

        return false;
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead(int $userId): int
    {
        return Notification::forUser($userId)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    /**
     * Get unread count for user
     */
    public function getUnreadCount(int $userId): int
    {
        return Notification::forUser($userId)->unread()->count();
    }

    /**
     * Get recent notifications for user
     */
    public function getRecent(int $userId, int $limit = 10): \Illuminate\Support\Collection
    {
        return Notification::forUser($userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
