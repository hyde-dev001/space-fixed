<?php

namespace App\Enums;

enum NotificationType: string
{
    // ==================== CUSTOMER NOTIFICATIONS ====================
    case ORDER_PLACED = 'order_placed';
    case ORDER_CONFIRMED = 'order_confirmed';
    case ORDER_SHIPPED = 'order_shipped';
    case ORDER_DELIVERED = 'order_delivered';
    case ORDER_CANCELLED = 'order_cancelled';
    case ORDER_STATUS_UPDATE = 'order_status_update';
    case REPAIR_SUBMITTED = 'repair_submitted';
    case REPAIR_ASSIGNED = 'repair_assigned';
    case REPAIR_ACCEPTED = 'repair_accepted';
    case REPAIR_REJECTED = 'repair_rejected';
    case REPAIR_IN_PROGRESS = 'repair_in_progress';
    case REPAIR_COMPLETED = 'repair_completed';
    case REPAIR_READY_PICKUP = 'repair_ready_pickup';
    case REPAIR_STATUS_UPDATE = 'repair_status_update';
    case PAYMENT_RECEIVED = 'payment_received';
    case PAYMENT_FAILED = 'payment_failed';
    case MESSAGE_RECEIVED = 'message_received';
    case REVIEW_REQUEST = 'review_request';
    
    // ==================== SHOP OWNER NOTIFICATIONS ====================
    case NEW_ORDER = 'new_order';
    case NEW_REPAIR_REQUEST = 'new_repair_request';
    case PRICE_CHANGE_REQUEST = 'price_change_request';
    case REPAIR_SERVICE_REQUEST = 'repair_service_request';
    case HIGH_VALUE_APPROVAL = 'high_value_approval';
    case REFUND_REQUEST = 'refund_request';
    case LOW_STOCK_ALERT = 'low_stock_alert';
    case EMPLOYEE_SUSPENSION_REQUEST = 'employee_suspension_request';
    case CUSTOMER_MESSAGE = 'customer_message';
    
    // ==================== ERP STAFF NOTIFICATIONS ====================
    case EXPENSE_APPROVAL = 'expense_approval';
    case LEAVE_APPROVAL = 'leave_approval';
    case INVOICE_CREATED = 'invoice_created';
    case DELEGATION_ASSIGNED = 'delegation_assigned';
    case TASK_ASSIGNED = 'task_assigned';
    case ORDER_ASSIGNED = 'order_assigned';
    case REPAIR_ASSIGNED_TO_ME = 'repair_assigned_to_me';
    case REPAIR_REJECTION_REVIEW = 'repair_rejection_review';
    case TRAINING_ASSIGNED = 'training_assigned';
    case ATTENDANCE_REMINDER = 'attendance_reminder';
    case DOCUMENT_EXPIRING = 'document_expiring';
    case PAYROLL_GENERATED = 'payroll_generated';
    
    // ==================== MANAGER NOTIFICATIONS ====================
    case LEAVE_REQUEST_PENDING = 'leave_request_pending';
    case EXPENSE_REQUEST_PENDING = 'expense_request_pending';
    case SUSPENSION_REQUEST_PENDING = 'suspension_request_pending';
    case PERFORMANCE_REVIEW_DUE = 'performance_review_due';
    
    // ==================== CRM NOTIFICATIONS ====================
    case NEW_LEAD = 'new_lead';
    case LEAD_UPDATED = 'lead_updated';
    case OPPORTUNITY_CREATED = 'opportunity_created';
    case CUSTOMER_SUPPORT_TICKET = 'customer_support_ticket';

    /**
     * Get human-readable label for notification type
     */
    public function label(): string
    {
        return match($this) {
            // Customer notifications
            self::ORDER_PLACED => 'Order Placed',
            self::ORDER_CONFIRMED => 'Order Confirmed',
            self::ORDER_SHIPPED => 'Order Shipped',
            self::ORDER_DELIVERED => 'Order Delivered',
            self::ORDER_CANCELLED => 'Order Cancelled',
            self::ORDER_STATUS_UPDATE => 'Order Status Update',
            self::REPAIR_SUBMITTED => 'Repair Submitted',
            self::REPAIR_ASSIGNED => 'Repair Assigned',
            self::REPAIR_ACCEPTED => 'Repair Accepted',
            self::REPAIR_REJECTED => 'Repair Rejected',
            self::REPAIR_IN_PROGRESS => 'Repair In Progress',
            self::REPAIR_COMPLETED => 'Repair Completed',
            self::REPAIR_READY_PICKUP => 'Repair Ready for Pickup',
            self::REPAIR_STATUS_UPDATE => 'Repair Status Update',
            self::PAYMENT_RECEIVED => 'Payment Received',
            self::PAYMENT_FAILED => 'Payment Failed',
            self::MESSAGE_RECEIVED => 'New Message',
            self::REVIEW_REQUEST => 'Review Request',
            
            // Shop Owner notifications
            self::NEW_ORDER => 'New Order',
            self::NEW_REPAIR_REQUEST => 'New Repair Request',
            self::PRICE_CHANGE_REQUEST => 'Price Change Request',
            self::REPAIR_SERVICE_REQUEST => 'Repair Service Request',
            self::HIGH_VALUE_APPROVAL => 'High Value Approval',
            self::REFUND_REQUEST => 'Refund Request',
            self::LOW_STOCK_ALERT => 'Low Stock Alert',
            self::EMPLOYEE_SUSPENSION_REQUEST => 'Employee Suspension Request',
            self::CUSTOMER_MESSAGE => 'Customer Message',
            
            // Staff notifications
            self::EXPENSE_APPROVAL => 'Expense Approval',
            self::LEAVE_APPROVAL => 'Leave Approval',
            self::INVOICE_CREATED => 'Invoice Created',
            self::DELEGATION_ASSIGNED => 'Delegation Assigned',
            self::TASK_ASSIGNED => 'Task Assigned',
            self::ORDER_ASSIGNED => 'Order Assigned',
            self::REPAIR_ASSIGNED_TO_ME => 'Repair Assigned',
            self::REPAIR_REJECTION_REVIEW => 'Repair Rejection Review',
            self::TRAINING_ASSIGNED => 'Training Assigned',
            self::ATTENDANCE_REMINDER => 'Attendance Reminder',
            self::DOCUMENT_EXPIRING => 'Document Expiring',
            self::PAYROLL_GENERATED => 'Payroll Generated',
            
            // Manager notifications
            self::LEAVE_REQUEST_PENDING => 'Leave Request Pending',
            self::EXPENSE_REQUEST_PENDING => 'Expense Request Pending',
            self::SUSPENSION_REQUEST_PENDING => 'Suspension Request Pending',
            self::PERFORMANCE_REVIEW_DUE => 'Performance Review Due',
            
            // CRM notifications
            self::NEW_LEAD => 'New Lead',
            self::LEAD_UPDATED => 'Lead Updated',
            self::OPPORTUNITY_CREATED => 'Opportunity Created',
            self::CUSTOMER_SUPPORT_TICKET => 'Customer Support Ticket',
        };
    }

    /**
     * Get notification category
     */
    public function category(): string
    {
        return match($this) {
            self::ORDER_PLACED, self::ORDER_CONFIRMED, self::ORDER_SHIPPED, 
            self::ORDER_DELIVERED, self::ORDER_CANCELLED, self::ORDER_STATUS_UPDATE => 'orders',
            
            self::REPAIR_SUBMITTED, self::REPAIR_ASSIGNED, self::REPAIR_ACCEPTED,
            self::REPAIR_REJECTED, self::REPAIR_IN_PROGRESS, self::REPAIR_COMPLETED,
            self::REPAIR_READY_PICKUP, self::REPAIR_STATUS_UPDATE => 'repairs',
            
            self::PAYMENT_RECEIVED, self::PAYMENT_FAILED => 'payments',
            
            self::MESSAGE_RECEIVED, self::CUSTOMER_MESSAGE => 'messages',
            
            self::REVIEW_REQUEST => 'reviews',
            
            self::EXPENSE_APPROVAL, self::EXPENSE_REQUEST_PENDING,
            self::INVOICE_CREATED => 'finance',
            
            self::LEAVE_APPROVAL, self::LEAVE_REQUEST_PENDING,
            self::ATTENDANCE_REMINDER, self::DOCUMENT_EXPIRING,
            self::PAYROLL_GENERATED, self::TRAINING_ASSIGNED => 'hr',
            
            self::NEW_LEAD, self::LEAD_UPDATED, self::OPPORTUNITY_CREATED,
            self::CUSTOMER_SUPPORT_TICKET => 'crm',
            
            default => 'general',
        };
    }

    /**
     * Check if notification type is for customers
     */
    public function isCustomerNotification(): bool
    {
        return in_array($this, [
            self::ORDER_PLACED,
            self::ORDER_CONFIRMED,
            self::ORDER_SHIPPED,
            self::ORDER_DELIVERED,
            self::ORDER_CANCELLED,
            self::ORDER_STATUS_UPDATE,
            self::REPAIR_SUBMITTED,
            self::REPAIR_ASSIGNED,
            self::REPAIR_ACCEPTED,
            self::REPAIR_REJECTED,
            self::REPAIR_IN_PROGRESS,
            self::REPAIR_COMPLETED,
            self::REPAIR_READY_PICKUP,
            self::REPAIR_STATUS_UPDATE,
            self::PAYMENT_RECEIVED,
            self::PAYMENT_FAILED,
            self::MESSAGE_RECEIVED,
            self::REVIEW_REQUEST,
        ]);
    }

    /**
     * Check if notification type is for shop owners
     */
    public function isShopOwnerNotification(): bool
    {
        return in_array($this, [
            self::NEW_ORDER,
            self::ORDER_DELIVERED,
            self::NEW_REPAIR_REQUEST,
            self::PRICE_CHANGE_REQUEST,
            self::REPAIR_SERVICE_REQUEST,
            self::HIGH_VALUE_APPROVAL,
            self::REFUND_REQUEST,
            self::LOW_STOCK_ALERT,
            self::EMPLOYEE_SUSPENSION_REQUEST,
            self::CUSTOMER_MESSAGE,
        ]);
    }

    /**
     * Check if notification type requires action
     */
    public function requiresAction(): bool
    {
        return in_array($this, [
            self::EXPENSE_APPROVAL,
            self::LEAVE_APPROVAL,
            self::PRICE_CHANGE_REQUEST,
            self::REPAIR_SERVICE_REQUEST,
            self::HIGH_VALUE_APPROVAL,
            self::REFUND_REQUEST,
            self::EMPLOYEE_SUSPENSION_REQUEST,
            self::REPAIR_REJECTION_REVIEW,
            self::LEAVE_REQUEST_PENDING,
            self::EXPENSE_REQUEST_PENDING,
            self::SUSPENSION_REQUEST_PENDING,
        ]);
    }
}
