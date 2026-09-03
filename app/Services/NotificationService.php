<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Enums\NotificationType;
use App\Mail\NotificationEmail;
use App\Services\Notifications\RecipientResolver;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * Unified Notification Service
 * 
 * Handles all notification sending across the platform:
 * - Customer notifications (orders, repairs, payments)
 * - Shop Owner notifications (new orders, approvals, alerts)
 * - ERP Staff notifications (finance, HR, tasks, repairs)
 * 
 * Respects user notification preferences for email and browser notifications
 */
class NotificationService
{
    public function __construct(
        private ?RecipientResolver $recipientResolver = null
    ) {
        $this->recipientResolver ??= app(RecipientResolver::class);
    }

    public function ownerApprovalActionUrl(string $sourceType, mixed $sourceId): string
    {
        $allowedSourceTypes = [
            'order_refund',
            'repair_refund',
            'product_price_change',
            'repair_price_change',
            'repair_package_price_change',
            'payslip',
            'salary_change',
            'purchase_request',
            'expense',
            'repair_rejection',
            'suspension_request',
            'termination_request',
            'rehire_request',
        ];

        if (! in_array($sourceType, $allowedSourceTypes, true)) {
            return '/shop-owner/action-center';
        }

        $sourceId = filter_var($sourceId, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 9_007_199_254_740_991,
            ],
        ]);

        return $sourceId === false
            ? '/shop-owner/action-center'
            : "/shop-owner/action-center?bucket=needs_my_decision&approval={$sourceType}:{$sourceId}";
    }

    // ==================== CORE METHODS ====================

    /**
     * Send notification to a user (customer or ERP staff)
     */
    public function sendToUser(
        int $userId,
        NotificationType $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        ?int $shopId = null,
        string $priority = 'medium',
        ?string $groupKey = null,
        bool $requiresAction = false,
        ?\DateTime $expiresAt = null
    ): ?Notification {
        try {
            $user = User::find($userId);
            if (!$user) {
                Log::warning("User not found for notification", ['user_id' => $userId]);
                return null;
            }

            $preferences = NotificationPreference::getOrCreateForUser($userId);
            
            // Check quiet hours
            if ($preferences->isQuietHours() && $priority !== 'high') {
                Log::info("Notification suppressed due to quiet hours", ['user_id' => $userId]);
                // Still create but don't push
            }
            
            // Create browser notification if enabled
            $notification = null;
            if ($this->shouldSendBrowserNotification($type, $preferences)) {
                $notification = Notification::create([
                    'user_id' => $userId,
                    'type' => $type->value,
                    'priority' => $priority,
                    'group_key' => $groupKey,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'action_url' => $actionUrl,
                    'requires_action' => $requiresAction,
                    'expires_at' => $expiresAt,
                    'shop_id' => $shopId ?? $user->shop_owner_id ?? null,
                ]);
            }

            // Send email if enabled (not during quiet hours unless high priority)
            if ($this->shouldSendEmail($type, $preferences) && $user->email) {
                if (!$preferences->isQuietHours() || $priority === 'high') {
                    $this->sendEmailToAddress($user->email, $title, $message, $actionUrl);
                }
            }

            return $notification;
        } catch (\Exception $e) {
            Log::error('Failed to send user notification: ' . $e->getMessage(), [
                'user_id' => $userId,
                'type' => $type->value,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send notification to a shop owner
     */
    public function sendToShopOwner(
        int $shopOwnerId,
        NotificationType $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        string $priority = 'medium',
        ?string $groupKey = null,
        bool $requiresAction = false
    ): ?Notification {
        try {
            $shopOwner = ShopOwner::find($shopOwnerId);
            if (!$shopOwner) {
                Log::warning("Shop owner not found for notification", ['shop_owner_id' => $shopOwnerId]);
                return null;
            }

            // Get or create shop owner preferences
            $preferences = NotificationPreference::firstOrCreate([
                'shop_owner_id' => $shopOwnerId,
            ]);

            // Check quiet hours
            if ($preferences->isQuietHours() && $priority !== 'high') {
                Log::info("Shop owner notification suppressed due to quiet hours", ['shop_owner_id' => $shopOwnerId]);
                // Still create but don't push
            }

            // Create notification if enabled in preferences
            $notification = null;
            if ($this->shouldSendBrowserNotification($type, $preferences)) {
                $notification = Notification::create([
                    'shop_owner_id' => $shopOwnerId,
                    'type' => $type->value,
                    'priority' => $priority,
                    'group_key' => $groupKey,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'action_url' => $actionUrl,
                    'requires_action' => $requiresAction,
                    'shop_id' => $shopOwner->id,
                ]);
            }

            // Send email if enabled and not in quiet hours (unless high priority)
            if ($this->shouldSendEmail($type, $preferences) && $shopOwner->email) {
                if (!$preferences->isQuietHours() || $priority === 'high') {
                    $this->sendEmailToAddress($shopOwner->email, $title, $message, $actionUrl);
                }
            }

            return $notification;
        } catch (\Exception $e) {
            Log::error('Failed to send shop owner notification: ' . $e->getMessage(), [
                'shop_owner_id' => $shopOwnerId,
                'type' => $type->value,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Resolve recipients for shop-owner-scoped events and fan out notifications.
     * Governance events always include owner records even for company registrations.
     */
    private function sendToResolvedShopOwnerRecipients(
        string $eventType,
        int $shopOwnerId,
        NotificationType $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        string $priority = 'medium',
        ?string $groupKey = null,
        bool $requiresAction = false
    ): ?Notification {
        $shopOwner = ShopOwner::find($shopOwnerId);
        if (!$shopOwner) {
            Log::warning('Shop owner not found for recipient resolution', ['shop_owner_id' => $shopOwnerId]);
            return null;
        }

        $recipients = $this->recipientResolver->resolveShopOwnerRecipients(
            $eventType,
            $shopOwnerId,
            (string) ($shopOwner->registration_type ?? 'individual')
        );

        $shopOwnerIds = array_values(array_unique(array_map('intval', $recipients['shop_owner_ids'] ?? [])));
        $userIds = array_values(array_unique(array_map('intval', $recipients['user_ids'] ?? [])));

        $notification = null;

        foreach ($shopOwnerIds as $resolvedShopOwnerId) {
            $sent = $this->sendToShopOwner(
                shopOwnerId: $resolvedShopOwnerId,
                type: $type,
                title: $title,
                message: $message,
                data: $data,
                actionUrl: $actionUrl,
                priority: $priority,
                groupKey: $groupKey,
                requiresAction: $requiresAction
            );

            $notification ??= $sent;
        }

        foreach ($userIds as $resolvedUserId) {
            $sent = $this->sendToUser(
                userId: $resolvedUserId,
                type: $type,
                title: $title,
                message: $message,
                data: $data,
                actionUrl: $actionUrl,
                shopId: $shopOwnerId,
                priority: $priority,
                groupKey: $groupKey,
                requiresAction: $requiresAction
            );

            $notification ??= $sent;
        }

        return $notification;
    }

    /**
     * Legacy method for backward compatibility
     */
    public function send(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        ?int $shopId = null,
        bool $requiresAction = false
    ): ?Notification {
        // Convert string type to enum
        $notificationType = NotificationType::tryFrom($type);
        if (!$notificationType) {
            Log::warning("Unknown notification type: {$type}");
            return null;
        }

        return $this->sendToUser(
            userId: $userId,
            type: $notificationType,
            title: $title,
            message: $message,
            data: $data,
            actionUrl: $actionUrl,
            shopId: $shopId,
            requiresAction: $requiresAction,
        );
    }

    // ==================== CUSTOMER NOTIFICATIONS ====================

    /**
     * Notify customer when order is placed
     */
    public function notifyOrderPlaced(int $userId, array $orderData): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::ORDER_PLACED,
            title: 'Order Placed Successfully',
            message: "Your order #{$orderData['order_number']} has been placed. Total: ₱{$orderData['total']}",
            data: $orderData,
            actionUrl: '/my-orders'
        );
    }

    /**
     * Notify customer when order is confirmed by shop
     */
    public function notifyOrderConfirmed(int $userId, array $orderData): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::ORDER_CONFIRMED,
            title: 'Order Confirmed',
            message: "Your order #{$orderData['order_number']} has been confirmed and is being prepared.",
            data: $orderData,
            actionUrl: '/my-orders'
        );
    }

    /**
     * Notify customer when order is shipped
     */
    public function notifyOrderShipped(int $userId, array $orderData): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::ORDER_SHIPPED,
            title: 'Order Shipped',
            message: "Your order #{$orderData['order_number']} has been shipped and is on the way!",
            data: $orderData,
            actionUrl: '/my-orders'
        );
    }

    /**
     * Notify customer when order is delivered
     */
    public function notifyOrderDelivered(int $userId, array $orderData): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::ORDER_DELIVERED,
            title: 'Order Delivered',
            message: "Your order #{$orderData['order_number']} has been delivered. Enjoy your purchase!",
            data: $orderData,
            actionUrl: '/my-orders'
        );
    }

    /**
     * Notify customer when order is cancelled
     */
    public function notifyOrderCancelled(int $userId, array $orderData): ?Notification
    {
        $reason = $orderData['reason'] ?? '';
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::ORDER_CANCELLED,
            title: 'Order Cancelled',
            message: "Your order #{$orderData['order_number']} has been cancelled. {$reason}",
            data: $orderData,
            actionUrl: '/my-orders'
        );
    }

    /**
     * Notify customer about repair status update
     */
    public function notifyRepairStatusUpdate(int $userId, array $repairData): ?Notification
    {
        $statusMessages = [
            'assigned_to_repairer' => 'Repair request is under review. Please wait for the shop to review it.',
            'repairer_accepted' => 'Repair request is under review. Please wait for the shop to review it.',
            'waiting_customer_confirmation' => 'Please confirm the repair details and pricing.',
            'owner_approval_pending' => 'Repair is pending shop owner approval.',
            'owner_approved' => 'Your repair has been approved!',
            'received' => 'Your item has been received by the shop.',
            'in_progress' => 'Your repair work is now in progress.',
            'awaiting_parts' => 'Repair is awaiting parts.',
            'completed' => 'Your repair has been completed!',
            'ready_for_pickup' => 'Your shoes are ready for pickup!',
            'shipped' => 'Your repaired item is now on the way.',
            'picked_up' => 'Your repair has been marked as received. Thank you!',
        ];

        $message = $statusMessages[$repairData['status']] ?? 'Your repair status has been updated.';

        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::REPAIR_STATUS_UPDATE,
            title: 'Repair Status Updated',
            message: $message,
            data: $repairData,
            actionUrl: '/my-repairs'
        );
    }

    /**
     * Notify customer when repair is assigned to repairer
     */
    public function notifyRepairAssigned(int $userId, array $repairData): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::REPAIR_ASSIGNED,
            title: 'Repair Request Under Review',
            message: 'Repair request is under review. Please wait for the shop to review it.',
            data: $repairData,
            actionUrl: '/my-repairs'
        );
    }

    /**
     * Notify customer when repairer accepts repair
     */
    public function notifyRepairAccepted(int $userId, array $repairData): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::REPAIR_ACCEPTED,
            title: 'Repair Request Under Review',
            message: 'Repair request is under review. Please wait for the shop to review it.',
            data: $repairData,
            actionUrl: '/my-repairs'
        );
    }

    /**
     * Notify customer when repair is rejected
     */
    public function notifyRepairRejected(int $userId, array $repairData): ?Notification
    {
        $reason = $repairData['reason'] ?? '';
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::REPAIR_REJECTED,
            title: 'Repair Request Rejected',
            message: "We're unable to process your repair request. {$reason}",
            data: $repairData,
            actionUrl: '/my-repairs'
        );
    }

    /**
     * Notify customer when repair work starts
     */
    public function notifyRepairInProgress(int $userId, array $repairData): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::REPAIR_IN_PROGRESS,
            title: 'Repair Work Started',
            message: "Your repair is now being worked on.",
            data: $repairData,
            actionUrl: '/my-repairs'
        );
    }

    /**
     * Notify customer when repair is completed
     */
    public function notifyRepairCompleted(int $userId, array $repairData): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::REPAIR_COMPLETED,
            title: 'Repair Completed!',
            message: "Your repair has been completed successfully!",
            data: $repairData,
            actionUrl: '/my-repairs'
        );
    }

    /**
     * Notify customer when shoes are ready for pickup
     */
    public function notifyRepairReadyForPickup(int $userId, array $repairData): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::REPAIR_READY_PICKUP,
            title: 'Ready for Pickup!',
            message: "Your repaired shoes are ready for pickup!",
            data: $repairData,
            actionUrl: '/my-repairs'
        );
    }

    public function notifyRepairReturnRecovery(
        RepairRequest $repair,
        string $state,
        string $recoveryKey,
    ): ?Notification {
        if ((int) $repair->user_id <= 0) {
            return null;
        }

        $groupKey = "repair-return-recovery-{$state}-{$repair->id}-".substr(sha1($recoveryKey), 0, 12);
        $existing = Notification::query()
            ->where('user_id', $repair->user_id)
            ->where('group_key', $groupKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        [$title, $message, $requiresAction] = match ($state) {
            'awaiting_payment' => [
                'Choose and Pay for Re-delivery',
                'Your repaired shoes are at the shop. Confirm your address and pay the new delivery fee to schedule re-delivery.',
                true,
            ],
            'shop_pickup' => [
                'Ready for Shop Pickup',
                'Your repaired shoes are ready for collection at the shop.',
                true,
            ],
            'ready_for_dispatch' => [
                'Re-delivery Payment Received',
                'Your new delivery fee was received. The shop can now assign your repaired shoes for delivery.',
                false,
            ],
            default => [
                'Repaired Shoes Returned to Shop',
                'Your repaired shoes are safely back at the shop and are awaiting a new delivery or pickup arrangement.',
                false,
            ],
        };

        return $this->sendToUser(
            userId: (int) $repair->user_id,
            type: NotificationType::REPAIR_STATUS_UPDATE,
            title: $title,
            message: $message,
            data: [
                'repair_id' => (int) $repair->id,
                'request_id' => (string) $repair->request_id,
                'recovery_state' => $state,
                'recovery_key' => $recoveryKey,
            ],
            actionUrl: '/my-repairs',
            shopId: (int) $repair->shop_owner_id,
            priority: $requiresAction ? 'high' : 'medium',
            groupKey: $groupKey,
            requiresAction: $requiresAction,
        );
    }

    public function notifyRepairPickupRecovery(
        RepairRequest $repair,
        string $state,
        string $planKey,
    ): ?Notification {
        if ((int) $repair->user_id <= 0) {
            return null;
        }

        $groupKey = "repair-pickup-recovery-{$state}-{$repair->id}-".substr($planKey, 0, 12);
        $existing = Notification::query()
            ->where('user_id', $repair->user_id)
            ->where('group_key', $groupKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        $awaitingPayment = $state === 'awaiting_payment';

        return $this->sendToUser(
            userId: (int) $repair->user_id,
            type: NotificationType::REPAIR_STATUS_UPDATE,
            title: $awaitingPayment ? 'Pay for the New Pickup' : 'Warranty Pickup Plan Updated',
            message: $awaitingPayment
                ? 'Confirm and pay the new shop pickup fee before a rider can be assigned.'
                : 'Your warranty repair is reopened with your selected intake method.',
            data: [
                'repair_id' => (int) $repair->id,
                'request_id' => (string) $repair->request_id,
                'recovery_state' => $state,
                'plan_key' => $planKey,
            ],
            actionUrl: '/my-repairs',
            shopId: (int) $repair->shop_owner_id,
            priority: $awaitingPayment ? 'high' : 'medium',
            groupKey: $groupKey,
            requiresAction: $awaitingPayment,
        );
    }

    public function notifyRepairDeliveryReconciliation(
        RepairRequest $repair,
        string $phase,
        string $event,
        float $amount,
        string $reconciliationKey,
    ): void {
        $resolvedEvent = $event === 'resolved' ? 'resolved' : 'created';
        $groupKey = "repair-delivery-reconciliation-{$resolvedEvent}-{$repair->id}-{$phase}-"
            .substr(sha1($reconciliationKey), 0, 12);
        $phaseLabel = $phase === 'return' ? 'return delivery' : 'pickup delivery';
        $amountLabel = number_format($amount, 2);
        $title = $resolvedEvent === 'resolved'
            ? 'Repair Delivery Compensation Resolved'
            : 'Repair Delivery Compensation Required';
        $customerMessage = $resolvedEvent === 'resolved'
            ? "The ₱{$amountLabel} {$phaseLabel} fee adjustment has been completed. You may now update the delivery plan."
            : "The ₱{$amountLabel} {$phaseLabel} fee needs Finance adjustment before the delivery plan can be changed.";
        $financeMessage = $resolvedEvent === 'resolved'
            ? "Repair {$repair->request_id} {$phaseLabel} compensation has been resolved."
            : "Repair {$repair->request_id} needs a ₱{$amountLabel} {$phaseLabel} compensation decision.";
        $data = [
            'repair_id' => (int) $repair->id,
            'request_id' => (string) $repair->request_id,
            'phase' => $phase,
            'event' => $resolvedEvent,
            'amount' => round($amount, 2),
        ];

        if ((int) $repair->user_id > 0
            && ! Notification::query()->where('user_id', $repair->user_id)->where('group_key', $groupKey)->exists()) {
            $this->sendToUser(
                userId: (int) $repair->user_id,
                type: NotificationType::REPAIR_STATUS_UPDATE,
                title: $title,
                message: $customerMessage,
                data: $data,
                actionUrl: '/my-repairs',
                shopId: (int) $repair->shop_owner_id,
                priority: 'high',
                groupKey: $groupKey,
                requiresAction: $resolvedEvent === 'created',
            );
        }

        User::query()
            ->where('shop_owner_id', $repair->shop_owner_id)
            ->where('status', 'active')
            ->get()
            ->filter(fn (User $user): bool => $user->can('access-refund-approval'))
            ->each(function (User $user) use ($repair, $title, $financeMessage, $data, $groupKey, $resolvedEvent): void {
                if (Notification::query()->where('user_id', $user->id)->where('group_key', $groupKey)->exists()) {
                    return;
                }

                $this->sendToUser(
                    userId: (int) $user->id,
                    type: NotificationType::REFUND_REQUEST,
                    title: $title,
                    message: $financeMessage,
                    data: $data,
                    actionUrl: '/finance?section=refund-approvals',
                    shopId: (int) $repair->shop_owner_id,
                    priority: 'high',
                    groupKey: $groupKey,
                    requiresAction: $resolvedEvent === 'created',
                );
            });
    }

    /**
     * Notify customer when receive confirmation is activated for return delivery.
     */
    public function notifyRepairReceiveConfirmationActivated(int $userId, array $repairData): ?Notification
    {
        $orderNumber = trim((string) ($repairData['order_number'] ?? ''));
        $orderSuffix = $orderNumber !== '' ? " #{$orderNumber}" : '';
        $isWarrantyRework = (bool) ($repairData['is_warranty_job'] ?? false)
            || strtolower((string) ($repairData['billing_mode'] ?? '')) === 'warranty_no_charge';

        $title = $isWarrantyRework
            ? 'Warranty Re-Repair In Transit'
            : 'Return Delivery Update';

        $message = $isWarrantyRework
            ? "Your warranty re-repair{$orderSuffix} is on the way back. Please confirm once received."
            : "Your repaired item{$orderSuffix} is on the way back. Please confirm once received.";

        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::REPAIR_STATUS_UPDATE,
            title: $title,
            message: $message,
            data: $repairData,
            actionUrl: '/my-repairs'
        );
    }

    /**
     * Notify customer when payment is received
     */
    public function notifyPaymentReceived(int $userId, array $paymentData): ?Notification
    {
        $actionUrl = $paymentData['action_url'] ?? '/my-orders';
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::PAYMENT_RECEIVED,
            title: 'Payment Received',
            message: "Your payment of ₱{$paymentData['amount']} has been received.",
            data: $paymentData,
            actionUrl: $actionUrl
        );
    }

    /**
     * Notify customer when payment fails
     */
    public function notifyPaymentFailed(int $userId, array $paymentData): ?Notification
    {
        $reason = $paymentData['reason'] ?? 'Please try again.';
        $actionUrl = $paymentData['action_url'] ?? '/my-orders';
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::PAYMENT_FAILED,
            title: 'Payment Failed',
            message: "Payment of ₱{$paymentData['amount']} failed. {$reason}",
            data: $paymentData,
            actionUrl: $actionUrl
        );
    }

    /**
     * Notify customer of new message
     */
    public function notifyMessageReceived(int $userId, array $messageData): ?Notification
    {
        $actionUrl = $messageData['action_url'] ?? '/messages';
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::MESSAGE_RECEIVED,
            title: 'New Message',
            message: "You have a new message from {$messageData['sender_name']}",
            data: $messageData,
            actionUrl: $actionUrl
        );
    }

    /**
     * Request customer to leave a review
     */
    public function notifyReviewRequest(int $userId, array $reviewData): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::REVIEW_REQUEST,
            title: 'How Was Your Experience?',
            message: "Please share your feedback on {$reviewData['item_name']}",
            data: $reviewData,
            actionUrl: $reviewData['action_url']
        );
    }

    // ==================== SHOP OWNER NOTIFICATIONS ====================
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
            actionUrl: $this->financeExpenseActionUrl($expenseData),
            shopId: $shopId,
            requiresAction: true
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
            actionUrl: '/erp/manager/leave-approvals',
            shopId: $shopId,
            requiresAction: true
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
            actionUrl: $this->financeInvoiceActionUrl($invoiceData),
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
    ): ?Notification {
        return $this->sendToUser(
            userId: $delegateId,
            type: NotificationType::DELEGATION_ASSIGNED,
            title: 'Approval Authority Delegated',
            message: "{$delegationData['delegated_by']} delegated approval authority to you ({$delegationData['start_date']} to {$delegationData['end_date']})",
            data: $delegationData,
            actionUrl: '/finance',
            shopId: $shopId
        );
    }

    // ==================== SHOP OWNER NOTIFICATIONS ====================

    // ==================== ERP LIVE NOTIFICATION HELPERS ====================

    /**
     * Send a notification to all users of a given Spatie role within a shop.
     * Used to fan-out events to HR, Finance, Manager, etc.
     */
    public function sendToErpRole(
        string $roleName,
        int $shopId,
        NotificationType $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        string $priority = 'medium',
        ?string $groupKey = null,
        bool $requiresAction = false,
    ): void {
        $users = User::where('shop_owner_id', $shopId)
            ->whereHas('roles', fn ($q) => $q->whereRaw('LOWER(name) = ?', [strtolower($roleName)]))
            ->get();

        foreach ($users as $user) {
            try {
                if ($groupKey !== null && Notification::query()
                    ->forUser((int) $user->id)
                    ->byGroup($groupKey)
                    ->exists()) {
                    continue;
                }

                $this->sendToUser(
                    userId: (int) $user->id,
                    type: $type,
                    title: $title,
                    message: $message,
                    data: $data,
                    actionUrl: $actionUrl,
                    shopId: $shopId,
                    priority: $priority,
                    groupKey: $groupKey,
                    requiresAction: $requiresAction,
                );
            } catch (\Exception $e) {
                Log::error("Failed to send ERP role notification to user #{$user->id}", [
                    'role' => $roleName,
                    'type' => $type->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ==================== LEAVE NOTIFICATIONS ====================

    /** Notify HR when an employee submits a leave request */
    public function notifyLeaveSubmitted(int $shopId, array $leaveData): void
    {
        $employeeName = $leaveData['employee_name'] ?? 'An employee';
        $title = 'New Leave Request';
        $message = "{$employeeName} submitted a {$leaveData['leave_type']} leave request for {$leaveData['no_of_days']} day(s).";

        // Use permission/role targeting so notifications still work even when role labels vary.
        $recipients = User::query()
            ->where('shop_owner_id', $shopId)
            ->where(function ($query) {
                $query->whereHas('permissions', function ($permissionQuery) {
                    $permissionQuery->whereIn('name', ['access-leave-approvals', 'access-hr-dashboard']);
                })->orWhereHas('roles', function ($roleQuery) {
                    $roleQuery->whereIn('name', ['HR', 'Manager', 'Shop Owner', 'Finance Manager']);
                });
            })
            ->get();

        if ($recipients->isEmpty()) {
            // Backward-compatible fallback.
            $this->sendToErpRole(
                roleName: 'HR',
                shopId: $shopId,
                type: NotificationType::LEAVE_SUBMITTED,
                title: $title,
                message: $message,
                data: $leaveData,
                actionUrl: '/erp/hr?section=leaves',
                priority: 'medium',
                requiresAction: true,
            );
            return;
        }

        foreach ($recipients as $recipient) {
            $this->sendToUser(
                userId: (int) $recipient->id,
                type: NotificationType::LEAVE_SUBMITTED,
                title: $title,
                message: $message,
                data: $leaveData,
                actionUrl: '/erp/hr?section=leaves',
                shopId: $shopId,
                priority: 'medium',
                requiresAction: true,
            );
        }
    }

    /** Notify employee their leave was approved */
    public function notifyLeaveApproved(int $userId, int $shopId, array $leaveData): void
    {
        $this->sendToUser($userId, NotificationType::LEAVE_REQUEST_APPROVED,
            'Leave Request Approved',
            "Your {$leaveData['leave_type']} leave from {$leaveData['start_date']} to {$leaveData['end_date']} has been approved.",
            $leaveData, '/erp/my-payslips', $shopId
        );
    }

    /** Notify employee their leave was rejected */
    public function notifyLeaveRejected(int $userId, int $shopId, array $leaveData): void
    {
        $reason = $leaveData['reason'] ?? '';
        $this->sendToUser($userId, NotificationType::LEAVE_REQUEST_REJECTED,
            'Leave Request Rejected',
            "Your {$leaveData['leave_type']} leave request was rejected. {$reason}",
            $leaveData, '/erp/my-payslips', $shopId
        );
    }

    // ==================== OVERTIME NOTIFICATIONS ====================

    /** Notify HR when an employee submits an OT request */
    public function notifyOvertimeSubmitted(int $shopId, array $otData): void
    {
        $employeeName = $otData['employee_name'] ?? 'An employee';
        $this->sendToErpRole(
            roleName: 'HR',
            shopId: $shopId,
            type: NotificationType::OVERTIME_SUBMITTED,
            title: 'New Overtime Request',
            message: "{$employeeName} requested {$otData['hours']} hour(s) of overtime on {$otData['overtime_date']}.",
            data: $otData,
            actionUrl: '/erp/hr?section=overtime',
            priority: 'medium',
            requiresAction: true,
        );
    }

    /** Notify Shop Owner when HR submits a salary change request */
    public function notifySalaryChangeSubmittedToShopOwner(int $shopId, array $salaryData): void
    {
        $employeeName = $salaryData['employee_name'] ?? 'An employee';
        $proposedBy = $salaryData['proposed_by_name'] ?? 'HR';
        $newSalary = $salaryData['new_salary'] ?? 0;
        $effectiveDate = $salaryData['effective_date'] ?? null;

        $dateText = $effectiveDate ? " Effective: {$effectiveDate}." : '';

        $this->sendToResolvedShopOwnerRecipients(
            eventType: 'salary_change_submitted',
            shopOwnerId: $shopId,
            type: NotificationType::SALARY_CHANGE_SUBMITTED,
            title: 'New Salary Change Request',
            message: "{$proposedBy} submitted a salary change for {$employeeName} to ₱{$newSalary}.{$dateText}",
            data: $salaryData,
            actionUrl: $this->ownerApprovalActionUrl('salary_change', $salaryData['salary_change_id'] ?? null),
            priority: 'high',
            groupKey: 'salary-change-request',
            requiresAction: true
        );
    }

    /** Notify HR proposer when Shop Owner approves salary change */
    public function notifySalaryChangeApprovedToHr(int $userId, int $shopId, array $salaryData): void
    {
        $employeeName = $salaryData['employee_name'] ?? 'Employee';
        $newSalary = $salaryData['new_salary'] ?? 0;

        $this->sendToUser(
            userId: $userId,
            type: NotificationType::SALARY_CHANGE_APPROVED,
            title: 'Salary Change Approved by Shop Owner',
            message: "Your salary change request for {$employeeName} (₱{$newSalary}) was approved. Please finalize it in HR.",
            data: $salaryData,
            actionUrl: '/erp/hr?section=salary-changes',
            shopId: $shopId,
            priority: 'high',
            groupKey: 'salary-change-approved',
            requiresAction: true
        );
    }

    /** Notify employee their OT was rejected */
    public function notifyOvertimeRejected(int $userId, int $shopId, array $otData): void
    {
        $reason = $otData['rejection_reason'] ?? '';
        $this->sendToUser($userId, NotificationType::OVERTIME_REQUEST_REJECTED,
            'Overtime Request Rejected',
            "Your overtime request for {$otData['overtime_date']} was rejected. {$reason}",
            $otData, '/erp/my-payslips', $shopId
        );
    }

    // ==================== PAYROLL NOTIFICATIONS ====================

    /** Notify employee their payslip is ready (approved) */
    public function notifyPayslipReady(int $userId, int $shopId, array $payrollData): void
    {
        $this->sendToUser($userId, NotificationType::PAYSLIP_READY,
            'Your Payslip is Ready',
            "Your payslip for {$payrollData['period']} (₱{$payrollData['net_salary']}) has been approved.",
            $payrollData, '/erp/my-payslips', $shopId
        );
    }

    /** Notify employee when their payslip is rejected */
    public function notifyPayslipRejected(int $userId, int $shopId, array $payrollData): void
    {
        $reason = $payrollData['rejection_reason'] ?? $payrollData['reason'] ?? '';
        $reasonText = $reason ? " Reason: {$reason}" : '';
        $this->sendToUser($userId, NotificationType::PAYSLIP_REJECTED,
            'Payslip Rejected',
            "Your payslip for {$payrollData['period']} was rejected and requires correction.{$reasonText}",
            $payrollData, '/erp/my-payslips', $shopId
        );
    }

    // ==================== FINANCE NOTIFICATIONS ====================

    /** Notify Finance users when a new invoice is created */
    public function notifyInvoiceCreatedToFinance(int $shopId, array $invoiceData): void
    {
        $this->sendToErpRole('Finance', $shopId, NotificationType::INVOICE_CREATED_FINANCE,
            'New Invoice Created',
            "Invoice {$invoiceData['reference']} (₱{$invoiceData['total']}) has been created.",
            $invoiceData, $this->financeInvoiceActionUrl($invoiceData), 'medium'
        );
    }

    /** Notify Finance users when an expense is submitted */
    public function notifyExpenseSubmitted(int $shopId, array $expenseData): void
    {
        $isProcurementExpense = ($expenseData['source'] ?? null) === 'procurement_receipt';
        $title = $isProcurementExpense ? 'Procurement Expense Recorded' : 'New Expense Submitted';
        $message = $isProcurementExpense
            ? "Procurement expense {$expenseData['reference']} of ₱{$expenseData['amount']} is ready for Finance review."
            : "Expense {$expenseData['reference']} of ₱{$expenseData['amount']} ({$expenseData['category']}) needs review.";

        $this->sendToErpRole(
            roleName: 'Finance',
            shopId: $shopId,
            type: NotificationType::EXPENSE_SUBMITTED,
            title: $title,
            message: $message,
            data: $expenseData,
            actionUrl: $this->financeExpenseActionUrl($expenseData),
            priority: 'medium',
            requiresAction: true,
        );
    }

    /** Notify requester when their expense is rejected */
    public function notifyExpenseRejected(int $userId, int $shopId, array $expenseData): void
    {
        $reason = $expenseData['rejection_reason'] ?? $expenseData['reason'] ?? '';
        $reasonText = $reason ? " Reason: {$reason}" : '';
        $this->sendToUser($userId, NotificationType::EXPENSE_REJECTED,
            'Expense Request Rejected',
            "Your expense request of ₱{$expenseData['amount']} ({$expenseData['category']}) was rejected.{$reasonText}",
            $expenseData, $this->financeExpenseActionUrl($expenseData), $shopId
        );
    }

    private function financeExpenseActionUrl(array $expenseData): string
    {
        $actionUrl = '/finance?section=expense-tracking';
        $expenseId = (int) ($expenseData['expense_id'] ?? 0);

        return $expenseId > 0
            ? "{$actionUrl}&expense={$expenseId}"
            : $actionUrl;
    }

    private function financeInvoiceActionUrl(array $invoiceData): string
    {
        $actionUrl = '/finance?section=invoice-generation';
        $invoiceId = (int) ($invoiceData['invoice_id'] ?? $invoiceData['id'] ?? 0);

        return $invoiceId > 0
            ? "{$actionUrl}&invoice={$invoiceId}"
            : $actionUrl;
    }

    /** Notify Finance users when a purchase request is submitted */
    public function notifyPurchaseRequestSubmitted(int $shopId, array $prData): void
    {
        $this->sendToErpRole('Finance', $shopId, NotificationType::PURCHASE_REQUEST_SUBMITTED,
            'New Purchase Request',
            "Purchase request {$prData['reference']} of ₱{$prData['total_cost']} requires finance review.",
            $prData, "/finance?section=purchase-request-approval&purchase_request={$prData['purchase_request_id']}", 'medium', null, true
        );
    }

    /** Notify Finance when a repair service or package price change is submitted. */
    public function notifyRepairPriceChangeSubmittedToFinance(int $shopId, array $priceChangeData): void
    {
        $sourceId = (int) ($priceChangeData['service_id'] ?? $priceChangeData['package_id'] ?? 0);
        if ($sourceId < 1) {
            Log::warning('Repair price change notification is missing its source id', [
                'shop_id' => $shopId,
                'data' => $priceChangeData,
            ]);
            return;
        }

        $serviceName = (string) ($priceChangeData['service_name'] ?? $priceChangeData['package_name'] ?? 'Repair service');
        $oldPrice = number_format((float) ($priceChangeData['old_price'] ?? $priceChangeData['current_price'] ?? 0), 2);
        $proposedPrice = number_format((float) ($priceChangeData['proposed_price'] ?? $priceChangeData['new_price'] ?? 0), 2);

        $this->sendToErpRole(
            roleName: 'Finance',
            shopId: $shopId,
            type: NotificationType::PRICE_CHANGE_REQUEST,
            title: 'Repair Price Change Requires Review',
            message: "Repair service '{$serviceName}' price change (PHP {$oldPrice} to PHP {$proposedPrice}) requires Finance review.",
            data: $priceChangeData,
            actionUrl: '/finance?section=repair-pricing',
            priority: 'medium',
            groupKey: "repair-price-change:{$sourceId}:finance-initial",
            requiresAction: true,
        );

        $this->sendToShopOwner(
            shopOwnerId: $shopId,
            type: NotificationType::PRICE_CHANGE_REQUEST,
            title: 'Repair Price Change Submitted',
            message: "Repair service '{$serviceName}' price change (PHP {$oldPrice} to PHP {$proposedPrice}) was submitted and is awaiting Finance review.",
            data: array_merge($priceChangeData, ['workflow_stage' => 'finance_initial']),
            actionUrl: '/shop-owner/erp/repair/services',
            priority: 'medium',
            groupKey: "repair-price-change:{$sourceId}:owner-submitted",
            requiresAction: false,
        );
    }

    /** Notify Finance when the Shop Owner has approved the second workflow step. */
    public function notifyRepairPriceChangeFinalApprovalToFinance(int $shopId, array $priceChangeData): void
    {
        $sourceId = (int) ($priceChangeData['service_id'] ?? $priceChangeData['package_id'] ?? 0);
        if ($sourceId < 1) {
            Log::warning('Final repair price change notification is missing its source id', [
                'shop_id' => $shopId,
                'data' => $priceChangeData,
            ]);
            return;
        }

        $serviceName = (string) ($priceChangeData['service_name'] ?? $priceChangeData['package_name'] ?? 'Repair service');
        $oldPrice = number_format((float) ($priceChangeData['old_price'] ?? $priceChangeData['current_price'] ?? 0), 2);
        $proposedPrice = number_format((float) ($priceChangeData['proposed_price'] ?? $priceChangeData['new_price'] ?? 0), 2);

        $this->sendToErpRole(
            roleName: 'Finance',
            shopId: $shopId,
            type: NotificationType::PRICE_CHANGE_REQUEST,
            title: 'Repair Price Change Final Approval',
            message: "Repair service '{$serviceName}' price change (PHP {$oldPrice} to PHP {$proposedPrice}) is ready for final Finance approval.",
            data: $priceChangeData,
            actionUrl: '/finance?section=repair-pricing',
            priority: 'medium',
            groupKey: "repair-price-change:{$sourceId}:finance-final",
            requiresAction: true,
        );
    }

    /** Notify Procurement when repair auto-reorder creates a stock request */
    public function notifyProcurementAutoReorderTriggered(int $shopId, array $reorderData): void
    {
        $priority = ($reorderData['remaining_quantity'] ?? 0) <= 0 ? 'high' : 'medium';
        $title = 'Auto-Reorder Triggered';
        $message = "Auto-reorder created stock request {$reorderData['request_number']} for {$reorderData['product_name']} (Qty: {$reorderData['quantity_needed']}).";

        $procurementUsers = User::where('shop_owner_id', $shopId)
            ->whereHas('roles', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['procurement manager']))
            ->get();

        if ($procurementUsers->isEmpty()) {
            $procurementUsers = User::where('shop_owner_id', $shopId)
                ->whereHas('roles', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['finance']))
                ->get();
        }

        $stockRequestId = (int) ($reorderData['stock_request_id'] ?? $reorderData['request_id'] ?? 0);
        $actionUrl = '/erp/inventory/request-material-approval';
        if ($stockRequestId > 0) {
            $actionUrl .= '?stock_request=' . $stockRequestId;
        }

        foreach ($procurementUsers as $user) {
            try {
                $this->sendToUser(
                    $user->id,
                    NotificationType::PURCHASE_REQUEST_SUBMITTED,
                    $title,
                    $message,
                    $reorderData,
                    $actionUrl,
                    $shopId,
                    $priority,
                    null,
                    true
                );
            } catch (\Exception $e) {
                Log::error("Failed to send auto-reorder procurement notification to user #{$user->id}", [
                    'shop_id' => $shopId,
                    'request_number' => $reorderData['request_number'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** Notify employee their overtime request was approved */
    public function notifyOvertimeApproved(int $userId, int $shopId, array $otData): void
    {
        $this->sendToUser($userId, NotificationType::OVERTIME_REQUEST_APPROVED,
            'Overtime Request Approved',
            "Your overtime request for {$otData['date']} has been approved.",
            $otData, '/erp/my-payslips', $shopId
        );
    }

    /** Notify Manager role users when a suspension request is submitted */
    public function notifySuspensionSubmitted(int $shopId, array $suspensionData): void
    {
        $this->sendToErpRole('Manager', $shopId, NotificationType::SUSPENSION_REQUEST_PENDING,
            'Suspension Request Pending',
            "A suspension request has been submitted for {$suspensionData['employee_name']}.",
            $suspensionData, '/erp/manager/suspension-approvals', 'high', null, true
        );
    }

    /** Notify Manager role users when HR submits a termination or rehire request. */
    public function notifyEmployeeLifecycleSubmitted(int $shopId, array $lifecycleData): void
    {
        $requestType = strtolower(trim((string) ($lifecycleData['request_type'] ?? '')));
        $isRehire = $requestType === 'rehire';
        $type = $isRehire ? NotificationType::REHIRE_REQUEST_PENDING : NotificationType::TERMINATION_REQUEST_PENDING;
        $label = $isRehire ? 'rehire' : 'termination';

        $this->sendToErpRole(
            roleName: 'Manager',
            shopId: $shopId,
            type: $type,
            title: ucfirst($label).' Request Pending',
            message: 'A '.$label.' request has been submitted for '.($lifecycleData['employee_name'] ?? 'an employee').'.',
            data: $lifecycleData,
            actionUrl: $isRehire ? '/erp/manager/rehire-approvals' : '/erp/manager/termination-approvals',
            priority: 'high',
            requiresAction: true,
        );
    }

    /** Notify the next reviewer and the submitting HR user after a lifecycle decision. */
    public function notifyEmployeeLifecycleReviewed(int $shopId, array $lifecycleData): ?Notification
    {
        $requestType = strtolower(trim((string) ($lifecycleData['request_type'] ?? '')));
        $isRehire = $requestType === 'rehire';
        $ownerType = $isRehire
            ? NotificationType::EMPLOYEE_REHIRE_REQUEST
            : NotificationType::EMPLOYEE_TERMINATION_REQUEST;
        $label = $isRehire ? 'rehire' : 'termination';
        $employeeName = (string) ($lifecycleData['employee_name'] ?? 'the employee');
        $notification = null;

        if (($lifecycleData['manager_decision'] ?? null) === 'approved') {
            $notification = $this->sendToResolvedShopOwnerRecipients(
                eventType: $isRehire ? 'employee_rehire_request' : 'employee_termination_request',
                shopOwnerId: $shopId,
                type: $ownerType,
                title: ucfirst($label).' Approval Required',
                message: 'Review the '.$label.' request for '.$employeeName.'.',
                data: $lifecycleData,
                actionUrl: $this->ownerApprovalActionUrl(
                    $isRehire ? 'rehire_request' : 'termination_request',
                    $lifecycleData['employee_lifecycle_request_id'] ?? null,
                ),
                priority: 'high',
                requiresAction: true,
            );
        }

        $requesterId = (int) ($lifecycleData['requester_id'] ?? 0);
        $decision = $lifecycleData['owner_decision'] ?? $lifecycleData['manager_decision'] ?? null;
        if ($requesterId > 0 && is_string($decision) && $decision !== '') {
            $this->sendToUser(
                userId: $requesterId,
                type: $ownerType,
                title: ucfirst($label).' Request '.ucfirst($decision),
                message: 'The '.$label.' request for '.$employeeName.' was '.$decision.'.',
                data: $lifecycleData,
                actionUrl: '/erp/hr/employees',
                shopId: $shopId,
                priority: 'medium',
            );
        }

        return $notification;
    }

    /** Notify Manager role users when a repairer rejects a repair (needs review) */
    public function notifyRepairRejectedToManager(int $shopId, array $repairData): void
    {
        $orderNumber = $repairData['order_number'] ?? $repairData['repair_id'] ?? 'N/A';
        $missingSkills = collect($repairData['missing_skills'] ?? [])
            ->map(fn ($skill) => trim((string) $skill))
            ->filter(fn ($skill) => $skill !== '')
            ->unique()
            ->values()
            ->all();

        $message = "Repairer rejected repair #{$orderNumber}. Review required.";

        if (!empty($missingSkills)) {
            $message .= ' Missing skills: ' . implode(', ', $missingSkills) . '.';
        }

        $title = 'Repair Rejection Needs Review';
        $actionUrl = '/erp/manager/repair-jobs';
        $excludedUserId = (int) ($repairData['rejected_by_user_id'] ?? 0);

        $recipientIds = User::query()
            ->where('shop_owner_id', $shopId)
            ->where(function ($query) {
                $query->whereHas('permissions', function ($permissionQuery) {
                    $permissionQuery->whereIn('name', [
                        'access-manager-repair-jobs',
                        'access-repair-reject-review',
                    ]);
                })->orWhereHas('roles', function ($roleQuery) {
                    $roleQuery->whereIn('name', [
                        'Manager',
                        'MANAGER',
                        'Finance Manager',
                        'FINANCE_MANAGER',
                        'Super Admin',
                        'SUPER_ADMIN',
                    ]);
                });
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && $id !== $excludedUserId)
            ->unique()
            ->values();

        $assignedManagerId = (int) ($repairData['assigned_manager_id'] ?? $repairData['manager_id'] ?? 0);
        if ($assignedManagerId > 0 && $assignedManagerId !== $excludedUserId) {
            $assignedManagerExists = User::query()
                ->where('id', $assignedManagerId)
                ->where('shop_owner_id', $shopId)
                ->exists();

            if ($assignedManagerExists) {
                $recipientIds = $recipientIds
                    ->push($assignedManagerId)
                    ->unique()
                    ->values();
            }
        }

        if ($recipientIds->isEmpty()) {
            // Backward-compatible fallback for shops still relying on classic role labels.
            $this->sendToErpRole(
                'Manager',
                $shopId,
                NotificationType::REPAIR_REJECTION_REVIEW,
                $title,
                $message,
                $repairData,
                $actionUrl,
                'high',
                null,
                true
            );
            return;
        }

        foreach ($recipientIds as $recipientId) {
            $this->sendToUser(
                (int) $recipientId,
                NotificationType::REPAIR_REJECTION_REVIEW,
                $title,
                $message,
                $repairData,
                $actionUrl,
                $shopId,
                'high',
                null,
                true
            );
        }
    }

    /**
     * Notify workflow actors when a warranty claim is filed.
     */
    public function notifyRepairWarrantyClaimFiled(int $shopOwnerId, array $claimData): void
    {
        $claimNo = (string) ($claimData['claim_no'] ?? 'N/A');
        $requestId = (string) ($claimData['request_id'] ?? $claimData['order_number'] ?? 'N/A');
        $handlerUserId = (int) ($claimData['repair_handler_user_id'] ?? 0);
        $sourceChannel = (string) ($claimData['source_channel'] ?? 'customer_portal');
        $isPosChannel = $sourceChannel === 'manual_pos_walk_in';

        $ownerMessage = $isPosChannel
            ? "Warranty claim {$claimNo} was filed at POS for repair #{$requestId}."
            : "Warranty claim {$claimNo} was filed for repair #{$requestId}.";

        $this->sendToResolvedShopOwnerRecipients(
            eventType: 'high_value_approval',
            shopOwnerId: $shopOwnerId,
            type: NotificationType::NEW_REPAIR_REQUEST,
            title: 'Warranty Claim Filed',
            message: $ownerMessage,
            data: $claimData,
            actionUrl: $this->warrantyOwnerActionUrl($shopOwnerId),
            priority: 'medium'
        );

        if ($handlerUserId > 0) {
            $this->sendToUser(
                userId: $handlerUserId,
                type: NotificationType::REPAIR_ASSIGNED_TO_ME,
                title: 'Warranty Claim Needs Review',
                message: "Review warranty claim {$claimNo} for repair #{$requestId}.",
                data: $claimData,
                actionUrl: '/erp/staff/job-orders-repair',
                shopId: $shopOwnerId,
                priority: 'high',
                groupKey: "warranty-claim-{$claimNo}",
                requiresAction: true
            );
        }
    }

    /**
     * Notify workflow actors when a warranty claim is approved.
     */
    public function notifyRepairWarrantyClaimApproved(int $shopOwnerId, array $claimData): void
    {
        $claimNo = (string) ($claimData['claim_no'] ?? 'N/A');
        $requestId = (string) ($claimData['request_id'] ?? $claimData['order_number'] ?? 'N/A');
        $linkedRequestId = (string) ($claimData['linked_request_id'] ?? '');
        $customerUserId = (int) ($claimData['customer_user_id'] ?? 0);

        $ownerMessage = $linkedRequestId !== ''
            ? "Warranty claim {$claimNo} for repair #{$requestId} was approved. Linked job: {$linkedRequestId}."
            : "Warranty claim {$claimNo} for repair #{$requestId} was approved.";

        $this->sendToResolvedShopOwnerRecipients(
            eventType: 'high_value_approval',
            shopOwnerId: $shopOwnerId,
            type: NotificationType::NEW_REPAIR_REQUEST,
            title: 'Warranty Claim Approved',
            message: $ownerMessage,
            data: $claimData,
            actionUrl: $this->warrantyOwnerActionUrl($shopOwnerId),
            priority: 'medium'
        );

        if ($customerUserId > 0) {
            $customerMessage = $linkedRequestId !== ''
                ? "Your warranty claim {$claimNo} was approved. Rework job {$linkedRequestId} has been created with no additional charge."
                : "Your warranty claim {$claimNo} was approved. Rework job has been created with no additional charge.";

            $this->sendToUser(
                userId: $customerUserId,
                type: NotificationType::REPAIR_STATUS_UPDATE,
                title: 'Warranty Claim Approved',
                message: $customerMessage,
                data: $claimData,
                actionUrl: '/my-repairs',
                shopId: $shopOwnerId,
                priority: 'high',
                groupKey: "warranty-claim-{$claimNo}"
            );
        }
    }

    /**
     * Notify workflow actors when a warranty claim is rejected.
     */
    public function notifyRepairWarrantyClaimRejected(int $shopOwnerId, array $claimData): void
    {
        $claimNo = (string) ($claimData['claim_no'] ?? 'N/A');
        $requestId = (string) ($claimData['request_id'] ?? $claimData['order_number'] ?? 'N/A');
        $customerUserId = (int) ($claimData['customer_user_id'] ?? 0);
        $reason = trim((string) ($claimData['rejection_reason'] ?? ''));

        $ownerMessage = "Warranty claim {$claimNo} for repair #{$requestId} was rejected.";
        if ($reason !== '') {
            $ownerMessage .= " Reason: {$reason}";
        }

        $this->sendToResolvedShopOwnerRecipients(
            eventType: 'high_value_approval',
            shopOwnerId: $shopOwnerId,
            type: NotificationType::NEW_REPAIR_REQUEST,
            title: 'Warranty Claim Rejected',
            message: $ownerMessage,
            data: $claimData,
            actionUrl: $this->warrantyOwnerActionUrl($shopOwnerId),
            priority: 'medium'
        );

        if ($customerUserId > 0) {
            $customerMessage = "Your warranty claim {$claimNo} was rejected.";
            if ($reason !== '') {
                $customerMessage .= " Reason: {$reason}";
            }

            $this->sendToUser(
                userId: $customerUserId,
                type: NotificationType::REPAIR_STATUS_UPDATE,
                title: 'Warranty Claim Rejected',
                message: $customerMessage,
                data: $claimData,
                actionUrl: '/my-repairs',
                shopId: $shopOwnerId,
                priority: 'high',
                groupKey: "warranty-claim-{$claimNo}"
            );
        }
    }

    private function warrantyOwnerActionUrl(int $shopOwnerId): string
    {
        $shopOwner = ShopOwner::query()->find($shopOwnerId);

        return strtolower((string) ($shopOwner?->registration_type ?? 'individual')) === 'individual'
            ? '/shop-owner/warranty-queue'
            : '/shop-owner/job-orders-repair';
    }

    // ==================== SHOP OWNER NOTIFICATIONS ====================

    /**
     * Notify shop owner of new order
     * Only sends notification to individual shop owners, not companies
     */
    public function notifyNewOrder(int $shopOwnerId, array $orderData): ?Notification
    {
        // Check if shop owner is individual type
        $shopOwner = ShopOwner::find($shopOwnerId);
        if (!$shopOwner || $shopOwner->registration_type !== 'individual') {
            Log::info("Skipping new order notification for shop owner #{$shopOwnerId} - not an individual registration type");
            return null;
        }

        return $this->sendToShopOwner(
            shopOwnerId: $shopOwnerId,
            type: NotificationType::NEW_ORDER,
            title: 'New Order Received',
            message: "New order #{$orderData['order_number']} - ₱{$orderData['total']}",
            data: $orderData,
            actionUrl: '/shop-owner/job-orders-retail'
        );
    }

    /**
     * Notify shop owner of new repair request
     * Only sends notification to individual shop owners, not companies
     */
    public function notifyNewRepairRequest(int $shopOwnerId, array $repairData): ?Notification
    {
        // Check if shop owner is individual type
        $shopOwner = ShopOwner::find($shopOwnerId);
        if (!$shopOwner || $shopOwner->registration_type !== 'individual') {
            Log::info("Skipping new repair request notification for shop owner #{$shopOwnerId} - not an individual registration type");
            return null;
        }

        return $this->sendToShopOwner(
            shopOwnerId: $shopOwnerId,
            type: NotificationType::NEW_REPAIR_REQUEST,
            title: 'New Repair Request',
            message: "New repair request - {$repairData['service_type']}",
            data: $repairData,
            actionUrl: '/shop-owner/job-orders-repair'
        );
    }

    /**
     * Notify all active repairers of new repair request
     */
    public function notifyAllRepairersNewRequest(int $shopOwnerId, array $repairData): int
    {
        try {
            // Find all active repairers in the shop
            $repairers = User::where('shop_owner_id', $shopOwnerId)
                ->whereHas('employee', function($query) {
                    $query->where('status', 'active');
                })
                ->whereHas('roles', function($query) {
                    $query->where('name', 'Repairer');
                })
                ->where('status', 'active')
                ->get();

            $notifiedCount = 0;
            foreach ($repairers as $repairer) {
                $notification = $this->sendToUser(
                    userId: $repairer->id,
                    type: NotificationType::NEW_REPAIR_REQUEST,
                    title: 'New Repair Request',
                    message: "New repair request #{$repairData['order_number']} - {$repairData['customer_name']}",
                    data: $repairData,
                    actionUrl: '/erp/staff/job-orders-repair',
                    shopId: $shopOwnerId,
                    priority: 'medium'
                );
                
                if ($notification) {
                    $notifiedCount++;
                }
            }

            Log::info("Notified {$notifiedCount} repairers about new repair request");
            return $notifiedCount;
        } catch (\Exception $e) {
            Log::error('Failed to notify repairers: ' . $e->getMessage(), [
                'shop_owner_id' => $shopOwnerId,
                'repair_data' => $repairData
            ]);
            return 0;
        }
    }

    /**
     * Notify all active staff with order management permissions about new order
     */
    public function notifyAllStaffNewOrder(int $shopOwnerId, array $orderData): int
    {
        try {
            // Find all active Staff accounts with access to the staff retail orders page.
            $staffMembers = User::where('shop_owner_id', $shopOwnerId)
                ->whereHas('employee', function($query) {
                    $query->where('status', 'active');
                })
                ->whereHas('roles', function($query) {
                    $query->where('name', 'Staff');
                })
                ->where('status', 'active')
                ->get()
                ->filter(function($user) {
                    // The route and Staff role use this canonical permission.
                    return $user->hasPermissionTo('access-staff-job-orders');
                });

            $notifiedCount = 0;
            foreach ($staffMembers as $staff) {
                $notification = $this->sendToUser(
                    userId: $staff->id,
                    type: NotificationType::NEW_ORDER,
                    title: 'New Order Received',
                    message: "New order #{$orderData['order_number']} - ₱{$orderData['total']} ({$orderData['items_count']} items)",
                    data: $orderData,
                    actionUrl: '/erp/staff/job-orders',
                    shopId: $shopOwnerId,
                    priority: 'high'
                );
                
                if ($notification) {
                    $notifiedCount++;
                }
            }

            Log::info("Notified {$notifiedCount} staff members about new order");
            return $notifiedCount;
        } catch (\Exception $e) {
            Log::error('Failed to notify staff: ' . $e->getMessage(), [
                'shop_owner_id' => $shopOwnerId,
                'order_data' => $orderData
            ]);
            return 0;
        }
    }

    /**
     * Notify a specific staff member about order assignment
     * Used when order is auto-assigned or manually assigned to a staff
     */
    public function notifyAssignedStaff(Order $order, User $staff): ?Notification
    {
        try {
            $orderData = [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name ?? $order->customer?->name ?? 'Guest',
                'total' => number_format($order->total_amount, 2),
                'status' => $order->status,
                'assignment_method' => $order->assignment_method ?? 'auto',
                'assigned_staff_id' => $staff->id, // Include for reference
                'assigned_to' => $staff->name, // Include staff name for clarity
            ];

            $notification = $this->sendToUser(
                userId: $staff->id,
                type: NotificationType::ORDER_ASSIGNED,
                title: 'Order Assigned to You',
                message: "You've been assigned order #{$order->order_number} - ₱{$orderData['total']}",
                data: $orderData,
                actionUrl: '/erp/staff/job-orders',
                shopId: $order->shop_owner_id,
                priority: 'high'
            );

            Log::info('Notified staff about order assignment', [
                'staff_id' => $staff->id,
                'staff_name' => $staff->name,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            return $notification;
        } catch (\Exception $e) {
            Log::error('Failed to notify assigned staff: ' . $e->getMessage(), [
                'staff_id' => $staff->id,
                'order_id' => $order->id,
            ]);
            return null;
        }
    }

    /**
     * Notify all active staff with order management permissions about new repair request
     */
    public function notifyAllStaffNewRepair(int $shopOwnerId, array $repairData): int
    {
        try {
            // Find all active Staff accounts with access to the staff repair orders page.
            $staffMembers = User::where('shop_owner_id', $shopOwnerId)
                ->whereHas('employee', function($query) {
                    $query->where('status', 'active');
                })
                ->whereHas('roles', function($query) {
                    $query->where('name', 'Staff');
                })
                ->where('status', 'active')
                ->get()
                ->filter(function($user) {
                    // The route and Staff role use this canonical permission.
                    return $user->hasPermissionTo('access-staff-job-orders');
                });

            $notifiedCount = 0;
            foreach ($staffMembers as $staff) {
                $notification = $this->sendToUser(
                    userId: $staff->id,
                    type: NotificationType::NEW_REPAIR_REQUEST,
                    title: 'New Repair Request',
                    message: "New repair request #{$repairData['order_number']} - {$repairData['service_type']}",
                    data: $repairData,
                    actionUrl: '/erp/staff/job-orders-repair',
                    shopId: $shopOwnerId,
                    priority: 'high'
                );
                
                if ($notification) {
                    $notifiedCount++;
                }
            }

            Log::info("Notified {$notifiedCount} staff members about new repair request");
            return $notifiedCount;
        } catch (\Exception $e) {
            Log::error('Failed to notify staff about repair: ' . $e->getMessage(), [
                'shop_owner_id' => $shopOwnerId,
                'repair_data' => $repairData
            ]);
            return 0;
        }
    }

    /**
     * Notify shop owner of price change request
     */
    public function notifyPriceChangeRequest(int $shopOwnerId, array $requestData): ?Notification
    {
        $sourceType = array_key_exists('package_id', $requestData)
            ? 'repair_package_price_change'
            : (array_key_exists('service_id', $requestData) ? 'repair_price_change' : 'product_price_change');
        $sourceId = $requestData['package_id'] ?? $requestData['service_id'] ?? $requestData['price_change_id'] ?? $requestData['id'] ?? null;

        return $this->sendToShopOwner(
            shopOwnerId: $shopOwnerId,
            type: NotificationType::PRICE_CHANGE_REQUEST,
            title: 'Price Change Approval Required',
            message: "{$requestData['product_name']}: ₱{$requestData['old_price']} → ₱{$requestData['new_price']}",
            data: $requestData,
            actionUrl: $this->ownerApprovalActionUrl($sourceType, $sourceId),
            priority: 'medium',
            requiresAction: true,
        );
    }

    /**
     * Notify when a price change request is rejected
     */
    public function notifyPriceChangeRejected(int $shopOwnerId, array $requestData): ?Notification
    {
        $reason = $requestData['rejection_reason'] ?? $requestData['reason'] ?? '';
        $reasonText = $reason ? " Reason: {$reason}" : '';
        $sourceType = array_key_exists('package_id', $requestData)
            ? 'repair_package_price_change'
            : (array_key_exists('service_id', $requestData) ? 'repair_price_change' : 'product_price_change');
        $sourceId = $requestData['package_id'] ?? $requestData['service_id'] ?? $requestData['price_change_id'] ?? $requestData['id'] ?? null;

        return $this->sendToShopOwner(
            shopOwnerId: $shopOwnerId,
            type: NotificationType::PRICE_CHANGE_REJECTED,
            title: 'Price Change Rejected',
            message: "{$requestData['product_name']} price change (₱{$requestData['old_price']} → ₱{$requestData['new_price']}) was rejected.{$reasonText}",
            data: $requestData,
            actionUrl: $this->ownerApprovalActionUrl($sourceType, $sourceId)
        );
    }

    /** Notify a shop owner of a repair service or package approval request. */
    public function notifyRepairServiceRequest(int $shopOwnerId, array $serviceData): ?Notification
    {
        $displayPrice = $serviceData['price']
            ?? $serviceData['proposed_price']
            ?? $serviceData['current_price']
            ?? '0.00';

        return $this->sendToShopOwner(
            shopOwnerId: $shopOwnerId,
            type: NotificationType::REPAIR_SERVICE_REQUEST,
            title: 'Repair Service Approval Required',
            message: "New repair service '{$serviceData['service_name']}' requires approval - ₱{$displayPrice}",
            data: $serviceData,
            actionUrl: $this->ownerApprovalActionUrl(
                array_key_exists('package_id', $serviceData)
                    ? 'repair_package_price_change'
                    : 'repair_price_change',
                $serviceData['package_id'] ?? $serviceData['service_id'] ?? null,
            ),
            priority: 'medium',
            requiresAction: true,
        );
    }

    /**
     * Notify shop owner when repair service price change is rejected
     */
    public function notifyRepairServiceRejected(int $shopOwnerId, array $serviceData): ?Notification
    {
        $reason = $serviceData['rejection_reason'] ?? $serviceData['reason'] ?? '';
        $reasonText = $reason ? " Reason: {$reason}" : '';
        return $this->sendToShopOwner(
            shopOwnerId: $shopOwnerId,
            type: NotificationType::REPAIR_SERVICE_REQUEST,
            title: 'Repair Service Price Change Rejected',
            message: "Repair service '{$serviceData['service_name']}' price change (₱{$serviceData['old_price']} → ₱{$serviceData['price']}) was rejected.{$reasonText}",
            data: $serviceData,
            actionUrl: $this->ownerApprovalActionUrl(
                array_key_exists('package_id', $serviceData)
                    ? 'repair_package_price_change'
                    : 'repair_price_change',
                $serviceData['package_id'] ?? $serviceData['service_id'] ?? null,
            )
        );
    }

    /**
     * Notify shop owner of high-value repair needing approval.
     */
    public function notifyHighValueRepairApproval(int $shopOwnerId, array $repairData): ?Notification
    {
        return $this->sendToResolvedShopOwnerRecipients(
            eventType: 'high_value_approval',
            shopOwnerId: $shopOwnerId,
            type: NotificationType::HIGH_VALUE_APPROVAL,
            title: 'High-Value Repair Approval',
            message: "Repair (₱{$repairData['total']}) requires your approval",
            data: $repairData,
            actionUrl: $this->highValueRepairActionUrl($repairData),
            priority: 'high',
            requiresAction: true,
        );
    }

    private function highValueRepairActionUrl(array $repairData): string
    {
        $repairId = (int) ($repairData['repair_id'] ?? 0);

        return $repairId > 0
            ? "/shop-owner/high-value-repairs?repair_id={$repairId}"
            : '/shop-owner/high-value-repairs';
    }

    /**
     * Notify shop owner when manager forwards a repairer rejection for owner review.
     */
    public function notifyRepairRejectApprovalRequest(int $shopOwnerId, array $repairData): ?Notification
    {
        $orderNumber = (string) ($repairData['order_number'] ?? $repairData['request_id'] ?? $repairData['repair_id'] ?? 'N/A');
        $reason = trim((string) ($repairData['reason'] ?? $repairData['repairer_rejection_reason'] ?? ''));

        $message = "Repair rejection for #{$orderNumber} was forwarded by manager and needs your review.";
        if ($reason !== '') {
            $message .= " Reason: {$reason}";
        }

        return $this->sendToResolvedShopOwnerRecipients(
            eventType: 'repair_reject_approval',
            shopOwnerId: $shopOwnerId,
            type: NotificationType::REPAIR_REJECTION_REVIEW,
            title: 'Repair Rejection Awaiting Your Review',
            message: $message,
            data: $repairData,
            actionUrl: $this->ownerApprovalActionUrl('repair_rejection', $repairData['repair_id'] ?? null),
            priority: 'high',
            groupKey: "repair-reject-owner-{$orderNumber}",
            requiresAction: true
        );
    }

    /**
     * Notify shop owner of refund request.
     */
    public function notifyRefundRequest(int $shopOwnerId, array $refundData): ?Notification
    {
        $sourceType = ($refundData['source_type'] ?? null) === 'repair_refund'
            ? 'repair_refund'
            : 'order_refund';

        return $this->sendToResolvedShopOwnerRecipients(
            eventType: 'refund_request',
            shopOwnerId: $shopOwnerId,
            type: NotificationType::REFUND_REQUEST,
            title: 'Refund Request',
            message: "Refund request for order #{$refundData['order_number']} - ₱{$refundData['amount']}",
            data: $refundData,
            actionUrl: $this->ownerApprovalActionUrl($sourceType, $refundData['refund_id'] ?? null),
            priority: 'high',
            requiresAction: true,
        );
    }

    /**
     * Notify shop owner of low stock alert
     */
    public function notifyLowStockAlert(int $shopOwnerId, array $stockData): ?Notification
    {
        return $this->sendToShopOwner(
            shopOwnerId: $shopOwnerId,
            type: NotificationType::LOW_STOCK_ALERT,
            title: 'Low Stock Alert',
            message: "{$stockData['product_name']}: Only {$stockData['quantity']} units remaining",
            data: $stockData,
            actionUrl: '/shop-owner/products'
        );
    }

    /**
     * Notify shop owner of employee suspension request
     */
    public function notifyEmployeeSuspensionRequest(int $shopOwnerId, array $suspensionData): ?Notification
    {
        return $this->sendToResolvedShopOwnerRecipients(
            eventType: 'employee_suspension_request',
            shopOwnerId: $shopOwnerId,
            type: NotificationType::EMPLOYEE_SUSPENSION_REQUEST,
            title: 'Employee Suspension Request',
            message: "Suspension request for {$suspensionData['employee_name']} from {$suspensionData['requested_by']}",
            data: $suspensionData,
            actionUrl: $this->ownerApprovalActionUrl(
                'suspension_request',
                $suspensionData['suspension_request_id'] ?? $suspensionData['request_id'] ?? null,
            ),
            priority: 'high',
            requiresAction: true,
        );
    }

    /**
     * Notify shop owner of customer message
     * Only sends notification to individual shop owners, not companies
     */
    public function notifyCustomerMessage(int $shopOwnerId, array $messageData): ?Notification
    {
        // Check if shop owner is individual type
        $shopOwner = ShopOwner::find($shopOwnerId);
        if (!$shopOwner || $shopOwner->registration_type !== 'individual') {
            Log::info("Skipping customer message notification for shop owner #{$shopOwnerId} - not an individual registration type");
            return null;
        }

        $actionUrl = $messageData['action_url'] ?? '/shop-owner/messages';
        return $this->sendToShopOwner(
            shopOwnerId: $shopOwnerId,
            type: NotificationType::CUSTOMER_MESSAGE,
            title: 'New Customer Message',
            message: "Message from {$messageData['customer_name']}",
            data: $messageData,
            actionUrl: $actionUrl
        );
    }

    // ==================== ADDITIONAL ERP NOTIFICATIONS ====================

    /**
     * Notify repairer of new repair assignment
     */
    public function notifyRepairerAssignment(int $repairerId, array $repairData, int $shopId): ?Notification
    {
        $orderNumber = $repairData['order_number'] ?? $repairData['request_id'] ?? $repairData['repair_id'] ?? 'N/A';
        $customerName = $repairData['customer_name'] ?? 'Customer';

        return $this->sendToUser(
            userId: $repairerId,
            type: NotificationType::REPAIR_ASSIGNED_TO_ME,
            title: 'New Repair Assigned',
            message: "Repair request {$orderNumber} has been assigned to you - {$customerName}.",
            data: $repairData,
            actionUrl: '/erp/staff/job-orders-repair',
            shopId: $shopId
        );
    }

    /**
     * Notify manager of repair rejection needing review
     */
    public function notifyRepairRejectionReview(int $managerId, array $repairData, int $shopId): ?Notification
    {
        return $this->sendToUser(
            userId: $managerId,
            type: NotificationType::REPAIR_REJECTION_REVIEW,
            title: 'Repair Rejection Review Required',
            message: "Repairer rejected repair: {$repairData['reason']}",
            data: $repairData,
            actionUrl: '/erp/manager/repair-jobs',
            shopId: $shopId,
            priority: 'high',
            requiresAction: true
        );
    }

    /**
     * Notify repairer that their rejection was approved by manager
     */
    public function notifyRepairerRejectionApproved(int $repairerId, array $repairData, int $shopId): ?Notification
    {
        $orderNumber = $repairData['order_number'] ?? $repairData['request_id'] ?? $repairData['repair_id'] ?? 'N/A';
        $customerName = $repairData['customer_name'] ?? 'Customer';

        return $this->sendToUser(
            userId: $repairerId,
            type: NotificationType::REPAIR_REJECTION_REVIEW,
            title: 'Rejection Approved',
            message: "Repair request {$orderNumber} for {$customerName} was approved by the manager.",
            data: $repairData,
            actionUrl: '/erp/staff/job-orders-repair',
            shopId: $shopId
        );
    }

    /**
     * Notify repairer that their rejection was overridden by manager
     */
    public function notifyRepairerRejectionOverridden(int $repairerId, array $repairData, int $shopId): ?Notification
    {
        $orderNumber = $repairData['order_number'] ?? $repairData['request_id'] ?? $repairData['repair_id'] ?? 'N/A';
        $customerName = $repairData['customer_name'] ?? 'Customer';

        return $this->sendToUser(
            userId: $repairerId,
            type: NotificationType::REPAIR_REJECTION_REVIEW,
            title: 'Rejection Overridden',
            message: "Repair request {$orderNumber} for {$customerName} was overridden and reassigned.",
            data: $repairData,
            actionUrl: '/erp/staff/job-orders-repair',
            shopId: $shopId
        );
    }

    /**
     * Notify staff member of task assignment
     */
    public function notifyTaskAssigned(int $userId, array $taskData, int $shopId): ?Notification
    {
        $actionUrl = $taskData['action_url'] ?? '/erp/staff/dashboard';
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::TASK_ASSIGNED,
            title: 'New Task Assigned',
            message: "You have been assigned: {$taskData['task_name']}",
            data: $taskData,
            actionUrl: $actionUrl,
            shopId: $shopId
        );
    }

    /**
     * Notify employee of training assignment
     */
    public function notifyTrainingAssigned(int $userId, array $trainingData, int $shopId): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::TRAINING_ASSIGNED,
            title: 'Training Assigned',
            message: "You have been assigned to: {$trainingData['training_name']}",
            data: $trainingData,
            actionUrl: '/training',
            shopId: $shopId
        );
    }

    /**
     * Notify employee of attendance reminder
     */
    public function notifyAttendanceReminder(int $userId, array $attendanceData, int $shopId): ?Notification
    {
        $message = $attendanceData['message'] ?? 'Please remember to clock in/out.';
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::ATTENDANCE_REMINDER,
            title: 'Attendance Reminder',
            message: $message,
            data: $attendanceData,
            actionUrl: '/erp/time-in',
            shopId: $shopId
        );
    }

    /**
     * Notify employee of expiring document
     */
    public function notifyDocumentExpiring(int $userId, array $documentData, int $shopId): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::DOCUMENT_EXPIRING,
            title: 'Document Expiring Soon',
            message: "Your {$documentData['document_type']} expires on {$documentData['expiry_date']}",
            data: $documentData,
            actionUrl: '/erp/hr',
            shopId: $shopId
        );
    }

    /**
     * Notify employee of payroll generation
     */
    public function notifyPayrollGenerated(int $userId, array $payrollData, int $shopId): ?Notification
    {
        return $this->sendToUser(
            userId: $userId,
            type: NotificationType::PAYROLL_GENERATED,
            title: 'Payroll Generated',
            message: "Your payroll for {$payrollData['period']} is ready - ₱{$payrollData['net_pay']}",
            data: $payrollData,
            actionUrl: '/erp/hr',
            shopId: $shopId
        );
    }

    /**
     * Notify manager of leave request
     */
    public function notifyLeaveRequestPending(int $managerId, array $leaveData, int $shopId): ?Notification
    {
        return $this->sendToUser(
            userId: $managerId,
            type: NotificationType::LEAVE_REQUEST_PENDING,
            title: 'Leave Request Pending',
            message: "{$leaveData['employee_name']} requests leave: {$leaveData['leave_type']}",
            data: $leaveData,
            actionUrl: '/erp/manager/leave-approvals',
            shopId: $shopId,
            priority: 'medium',
            requiresAction: true
        );
    }

    /**
     * Notify manager of expense request
     */
    public function notifyExpenseRequestPending(int $managerId, array $expenseData, int $shopId): ?Notification
    {
        return $this->sendToUser(
            userId: $managerId,
            type: NotificationType::EXPENSE_REQUEST_PENDING,
            title: 'Expense Request Pending',
            message: "{$expenseData['submitted_by']} submitted expense: ₱{$expenseData['amount']}",
            data: $expenseData,
            actionUrl: '/erp/manager/dashboard',
            shopId: $shopId,
            priority: 'medium',
            requiresAction: true
        );
    }

    /**
     * Notify manager of suspension request
     */
    public function notifySuspensionRequestPending(int $managerId, array $suspensionData, int $shopId): ?Notification
    {
        return $this->sendToUser(
            userId: $managerId,
            type: NotificationType::SUSPENSION_REQUEST_PENDING,
            title: 'Suspension Request Pending',
            message: "Suspension request for {$suspensionData['employee_name']}",
            data: $suspensionData,
            actionUrl: '/erp/manager/suspension-approvals',
            shopId: $shopId,
            priority: 'high',
            requiresAction: true
        );
    }

    // ==================== HELPER METHODS ====================

    /**
     * Check if browser notification should be sent based on preferences
     */
    private function shouldSendBrowserNotification(NotificationType $type, NotificationPreference $preferences): bool
    {
        // First check granular preferences (Phase 7)
        if (!empty($preferences->preferences)) {
            $typeValue = $type->value;
            if (isset($preferences->preferences[$typeValue])) {
                return $preferences->preferences[$typeValue];
            }
        }

        // Fallback to legacy column-based preferences
        // Map notification types to preference fields
        $preferenceMap = [
            'order_placed' => 'browser_order_updates',
            'order_confirmed' => 'browser_order_updates',
            'order_shipped' => 'browser_order_updates',
            'order_delivered' => 'browser_order_updates',
            'order_cancelled' => 'browser_order_updates',
            'repair_submitted' => 'browser_repair_updates',
            'repair_assigned' => 'browser_repair_updates',
            'repair_accepted' => 'browser_repair_updates',
            'repair_rejected' => 'browser_repair_updates',
            'repair_in_progress' => 'browser_repair_updates',
            'repair_completed' => 'browser_repair_updates',
            'repair_ready_pickup' => 'browser_repair_updates',
            'repair_status_update' => 'browser_repair_updates',
            'repair_rejection_review' => 'browser_approvals',
            'payment_received' => 'browser_payment_updates',
            'payment_failed' => 'browser_payment_updates',
            'message_received' => 'browser_alerts',
            'review_request' => 'browser_alerts',
            'identity_verification_approved' => 'browser_alerts',
            'identity_verification_rejected' => 'browser_alerts',
            // Shop Owner notifications
            'new_order' => 'browser_new_orders',
            'new_repair_request' => 'browser_new_orders',
            'price_change_request' => 'browser_approvals',
            'salary_change_submitted' => 'browser_approvals',
            'repair_service_request' => 'browser_approvals',
            'high_value_approval' => 'browser_approvals',
            'refund_request' => 'browser_approvals',
            'low_stock_alert' => 'browser_alerts',
            'employee_suspension_request' => 'browser_approvals',
            'employee_termination_request' => 'browser_approvals',
            'employee_rehire_request' => 'browser_approvals',
            'customer_message' => 'browser_alerts',
            // Finance/HR/Staff notifications
            'expense_approval' => 'browser_expense_approval',
            'leave_approval' => 'browser_leave_approval',
            'invoice_created' => 'browser_invoice_created',
            'invoice_created_finance' => 'browser_invoice_created',
            'expense_submitted' => 'browser_expense_approval',
            'purchase_request_submitted' => 'browser_approvals',
            'leave_request_pending' => 'browser_leave_approval',
            'leave_submitted' => 'browser_leave_approval',
            'overtime_request_pending' => 'browser_hr_updates',
            'overtime_submitted' => 'browser_hr_updates',
            'expense_request_pending' => 'browser_expense_approval',
            'suspension_request_pending' => 'browser_approvals',
            'termination_request_pending' => 'browser_approvals',
            'rehire_request_pending' => 'browser_approvals',
            'delegation_assigned' => 'browser_delegation_assigned',
            'task_assigned' => 'browser_tasks',
            'repair_assigned_to_me' => 'browser_tasks',
            'training_assigned' => 'browser_hr_updates',
            'attendance_reminder' => 'browser_hr_updates',
            'document_expiring' => 'browser_hr_updates',
            'payroll_generated' => 'browser_hr_updates',
            'salary_change_approved' => 'browser_hr_updates',
        ];

        $prefKey = $preferenceMap[$type->value] ?? null;
        return $prefKey ? ($preferences->$prefKey ?? true) : true; // Default to true
    }

    /**
     * Check if email notification should be sent based on preferences
     */
    private function shouldSendEmail(NotificationType $type, NotificationPreference $preferences): bool
    {
        // Map notification types to preference fields
        $preferenceMap = [
            'order_placed' => 'email_order_updates',
            'order_confirmed' => 'email_order_updates',
            'order_shipped' => 'email_order_updates',
            'order_delivered' => 'email_order_updates',
            'order_cancelled' => 'email_order_updates',
            'repair_submitted' => 'email_repair_updates',
            'repair_assigned' => 'email_repair_updates',
            'repair_accepted' => 'email_repair_updates',
            'repair_rejected' => 'email_repair_updates',
            'repair_in_progress' => 'email_repair_updates',
            'repair_completed' => 'email_repair_updates',
            'repair_ready_pickup' => 'email_repair_updates',
            'repair_status_update' => 'email_repair_updates',
            'repair_rejection_review' => 'email_approvals',
            'payment_received' => 'email_payment_updates',
            'payment_failed' => 'email_payment_updates',
            'message_received' => 'email_alerts',
            'review_request' => 'email_alerts',
            'identity_verification_approved' => 'email_alerts',
            'identity_verification_rejected' => 'email_alerts',
            // Shop Owner notifications
            'new_order' => 'email_new_orders',
            'new_repair_request' => 'email_new_orders',
            'price_change_request' => 'email_approvals',
            'salary_change_submitted' => 'email_approvals',
            'repair_service_request' => 'email_approvals',
            'high_value_approval' => 'email_approvals',
            'refund_request' => 'email_approvals',
            'low_stock_alert' => 'email_alerts',
            'employee_suspension_request' => 'email_approvals',
            'employee_termination_request' => 'email_approvals',
            'employee_rehire_request' => 'email_approvals',
            'customer_message' => 'email_alerts',
            // Finance/HR/Staff notifications
            'expense_approval' => 'email_expense_approval',
            'leave_approval' => 'email_leave_approval',
            'invoice_created' => 'email_invoice_created',
            'invoice_created_finance' => 'email_invoice_created',
            'expense_submitted' => 'email_expense_approval',
            'purchase_request_submitted' => 'email_approvals',
            'leave_request_pending' => 'email_leave_approval',
            'leave_submitted' => 'email_leave_approval',
            'overtime_request_pending' => 'email_hr_updates',
            'overtime_submitted' => 'email_hr_updates',
            'expense_request_pending' => 'email_expense_approval',
            'suspension_request_pending' => 'email_approvals',
            'termination_request_pending' => 'email_approvals',
            'rehire_request_pending' => 'email_approvals',
            'delegation_assigned' => 'email_delegation_assigned',
            'task_assigned' => 'email_tasks',
            'repair_assigned_to_me' => 'email_tasks',
            'training_assigned' => 'email_hr_updates',
            'attendance_reminder' => 'email_hr_updates',
            'document_expiring' => 'email_hr_updates',
            'payroll_generated' => 'email_hr_updates',
            'salary_change_approved' => 'email_hr_updates',
        ];

        $prefKey = $preferenceMap[$type->value] ?? null;
        return $prefKey ? ($preferences->$prefKey ?? false) : false; // Default to false for email
    }

    /**
     * Send email to a specific address
     */
    private function sendEmailToAddress(
        string $email,
        string $title,
        string $message,
        ?string $actionUrl
    ): void {
        try {
            Mail::to($email)->send(
                new NotificationEmail($title, $message, $actionUrl)
            );
        } catch (\Exception $e) {
            Log::error('Failed to send email notification: ' . $e->getMessage(), [
                'email' => $email,
                'title' => $title
            ]);
        }
    }

    /**
     * Legacy send email method for backward compatibility
     */
    private function sendEmail(
        int $userId,
        string $title,
        string $message,
        ?string $actionUrl
    ): void {
        $user = User::find($userId);
        if ($user && $user->email) {
            $this->sendEmailToAddress($user->email, $title, $message, $actionUrl);
        }
    }

    // ==================== UTILITY METHODS ====================

    /**
     * Mark notification as read
     */
    public function markAsRead(int $notificationId, int $userId, bool $isShopOwner = false): bool
    {
        $query = Notification::where('id', $notificationId);
        
        if ($isShopOwner) {
            $query->where('shop_owner_id', $userId);
        } else {
            $query->where('user_id', $userId);
        }

        $notification = $query->first();

        if ($notification) {
            $notification->markAsRead();
            return true;
        }

        return false;
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead(int $userId, bool $isShopOwner = false): int
    {
        $query = Notification::unread();
        
        if ($isShopOwner) {
            $query->where('shop_owner_id', $userId);
        } else {
            $query->where('user_id', $userId);
        }

        return $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    /**
     * Get unread count for user
     */
    public function getUnreadCount(int $userId, bool $isShopOwner = false): int
    {
        $query = Notification::unread();
        
        if ($isShopOwner) {
            $query->where('shop_owner_id', $userId);
        } else {
            $query->where('user_id', $userId);
        }

        return $query->count();
    }

    /**
     * Get recent notifications for user
     */
    public function getRecent(int $userId, int $limit = 10, bool $isShopOwner = false): \Illuminate\Support\Collection
    {
        $query = Notification::active(); // Only active (not archived)
        
        if ($isShopOwner) {
            $query->where('shop_owner_id', $userId);
        } else {
            $query->where('user_id', $userId);
        }

        return $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    // ==================== PHASE 6: ADVANCED FEATURES ====================

    /**
     * Archive old notifications based on user preferences
     */
    public function autoArchive(int $userId, bool $isShopOwner = false): int
    {
        $preferences = NotificationPreference::getOrCreateForUser($userId);
        
        if (!$preferences->auto_archive_enabled) {
            return 0;
        }

        $cutoffDate = now()->subDays($preferences->auto_archive_days);
        
        $query = Notification::active()
            ->where('created_at', '<', $cutoffDate);
            
        if ($isShopOwner) {
            $query->where('shop_owner_id', $userId);
        } else {
            $query->where('user_id', $userId);
        }

        return $query->update([
            'is_archived' => true,
            'archived_at' => now(),
        ]);
    }

    /**
     * Bulk delete notifications
     */
    public function bulkDelete(array $notificationIds, int $userId, bool $isShopOwner = false): int
    {
        $query = Notification::whereIn('id', $notificationIds);
        
        if ($isShopOwner) {
            $query->where('shop_owner_id', $userId);
        } else {
            $query->where('user_id', $userId);
        }

        return $query->delete();
    }

    /**
     * Bulk mark as read
     */
    public function bulkMarkAsRead(array $notificationIds, int $userId, bool $isShopOwner = false): int
    {
        $query = Notification::whereIn('id', $notificationIds)
            ->where('is_read', false);
        
        if ($isShopOwner) {
            $query->where('shop_owner_id', $userId);
        } else {
            $query->where('user_id', $userId);
        }

        return $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    /**
     * Bulk archive notifications
     */
    public function bulkArchive(array $notificationIds, int $userId, bool $isShopOwner = false): int
    {
        $query = Notification::whereIn('id', $notificationIds)
            ->where('is_archived', false);
        
        if ($isShopOwner) {
            $query->where('shop_owner_id', $userId);
        } else {
            $query->where('user_id', $userId);
        }

        return $query->update([
            'is_archived' => true,
            'archived_at' => now(),
        ]);
    }

    /**
     * Get grouped notifications
     */
    public function getGrouped(int $userId, bool $isShopOwner = false): \Illuminate\Support\Collection
    {
        $query = Notification::active()
            ->whereNotNull('group_key');
            
        if ($isShopOwner) {
            $query->where('shop_owner_id', $userId);
        } else {
            $query->where('user_id', $userId);
        }

        return $query->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('group_key');
    }

    /**
     * Get notification statistics
     */
    public function getStatistics(int $userId, bool $isShopOwner = false): array
    {
        $query = Notification::query();
        
        if ($isShopOwner) {
            $query->where('shop_owner_id', $userId);
        } else {
            $query->where('user_id', $userId);
        }

        $total = $query->count();
        $unread = (clone $query)->unread()->count();
        $archived = (clone $query)->archived()->count();
        $requiresAction = (clone $query)->where('requires_action', true)->count();
        $highPriority = (clone $query)->highPriority()->count();

        $byPriority = (clone $query)
            ->selectRaw('priority, count(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        $byType = (clone $query)
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'type')
            ->toArray();

        return [
            'total' => $total,
            'unread' => $unread,
            'archived' => $archived,
            'requires_action' => $requiresAction,
            'high_priority' => $highPriority,
            'by_priority' => $byPriority,
            'by_type' => $byType,
            'read_percentage' => $total > 0 ? round((($total - $unread) / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Export notifications to array
     */
    public function exportNotifications(int $userId, bool $isShopOwner = false, array $filters = []): array
    {
        $query = Notification::query();
        
        if ($isShopOwner) {
            $query->where('shop_owner_id', $userId);
        } else {
            $query->where('user_id', $userId);
        }

        // Apply filters
        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (isset($filters['is_read'])) {
            $query->where('is_read', $filters['is_read']);
        }

        return $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'priority' => $notification->priority,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'is_read' => $notification->is_read,
                    'requires_action' => $notification->requires_action,
                    'created_at' => $notification->created_at->format('Y-m-d H:i:s'),
                    'read_at' => $notification->read_at?->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();
    }

    /**
     * Clean up expired notifications
     */
    public function cleanupExpired(): int
    {
        return Notification::where('expires_at', '<', now())
            ->where('is_archived', false)
            ->update([
                'is_archived' => true,
                'archived_at' => now(),
            ]);
    }
}
