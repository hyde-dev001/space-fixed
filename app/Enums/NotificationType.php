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
    case EMPLOYEE_TERMINATION_REQUEST = 'employee_termination_request';
    case EMPLOYEE_REHIRE_REQUEST = 'employee_rehire_request';
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
    
    // ==================== ERP EMPLOYEE (self) NOTIFICATIONS ====================
    case LEAVE_REQUEST_APPROVED = 'leave_request_approved';
    case LEAVE_REQUEST_REJECTED = 'leave_request_rejected';
    case OVERTIME_REQUEST_APPROVED = 'overtime_request_approved';
    case OVERTIME_REQUEST_REJECTED = 'overtime_request_rejected';
    case PAYSLIP_READY = 'payslip_ready';
    case PAYSLIP_REJECTED = 'payslip_rejected';
    case PRICE_CHANGE_REJECTED = 'price_change_rejected';
    case EXPENSE_REJECTED = 'expense_rejected';

    // ==================== MANAGER NOTIFICATIONS ====================
    case LEAVE_REQUEST_PENDING = 'leave_request_pending';
    case OVERTIME_REQUEST_PENDING = 'overtime_request_pending';
    case EXPENSE_REQUEST_PENDING = 'expense_request_pending';
    case SUSPENSION_REQUEST_PENDING = 'suspension_request_pending';
    case TERMINATION_REQUEST_PENDING = 'termination_request_pending';
    case REHIRE_REQUEST_PENDING = 'rehire_request_pending';
    case PERFORMANCE_REVIEW_DUE = 'performance_review_due';

    // ==================== HR NOTIFICATIONS ====================
    case LEAVE_SUBMITTED = 'leave_submitted';
    case OVERTIME_SUBMITTED = 'overtime_submitted';
    case SALARY_CHANGE_SUBMITTED = 'salary_change_submitted';
    case SALARY_CHANGE_APPROVED = 'salary_change_approved';

    // ==================== FINANCE NOTIFICATIONS ====================
    case INVOICE_CREATED_FINANCE = 'invoice_created_finance';
    case EXPENSE_SUBMITTED = 'expense_submitted';
    case PURCHASE_REQUEST_SUBMITTED = 'purchase_request_submitted';
    
    // ==================== SUPER ADMIN NOTIFICATIONS ====================
    case SHOP_REGISTRATION_PENDING = 'shop_registration_pending';
    case SHOP_DOCUMENT_RENEWAL_PENDING = 'shop_document_renewal_pending';
    case SHOP_DOCUMENT_RENEWAL_REVIEWED = 'shop_document_renewal_reviewed';
    case SHOP_DOCUMENT_EXPIRING = 'shop_document_expiring';
    case BUSINESS_UPGRADE_REQUEST_PENDING = 'business_upgrade_request_pending';
    case BUSINESS_UPGRADE_REQUEST_APPROVED = 'business_upgrade_request_approved';
    case BUSINESS_UPGRADE_REQUEST_REJECTED = 'business_upgrade_request_rejected';
    case SHOP_REPORT_FILED = 'shop_report_filed';
    case REVIEW_REPORTED = 'review_reported';
    case SUSPENSION_APPEAL_SUBMITTED = 'suspension_appeal_submitted';

    // ==================== CRM NOTIFICATIONS ====================
    case NEW_LEAD = 'new_lead';
    case LEAD_UPDATED = 'lead_updated';
    case OPPORTUNITY_CREATED = 'opportunity_created';
    case CUSTOMER_SUPPORT_TICKET = 'customer_support_ticket';

    // ==================== LOGISTICS NOTIFICATIONS ====================
    case LOGISTICS_SHIPMENT_REQUESTED = 'logistics_shipment_requested';
    case LOGISTICS_ASSIGNED = 'logistics_assigned';
    case LOGISTICS_BATCH_OFFERED = 'logistics_batch_offered';
    case LOGISTICS_BATCH_REJECTED = 'logistics_batch_rejected';
    case LOGISTICS_PICKUP_SCHEDULED = 'logistics_pickup_scheduled';
    case LOGISTICS_IN_TRANSIT = 'logistics_in_transit';
    case LOGISTICS_DELIVERY_FAILED = 'logistics_delivery_failed';
    case LOGISTICS_PROOF_REQUIRED = 'logistics_proof_required';
    case LOGISTICS_DELIVERED = 'logistics_delivered';
    case LOGISTICS_EXCEPTION = 'logistics_exception';

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
            self::EMPLOYEE_TERMINATION_REQUEST => 'Employee Termination Request',
            self::EMPLOYEE_REHIRE_REQUEST => 'Employee Rehire Request',
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
            self::OVERTIME_REQUEST_PENDING => 'Overtime Request Pending',
            self::EXPENSE_REQUEST_PENDING => 'Expense Request Pending',
            self::SUSPENSION_REQUEST_PENDING => 'Suspension Request Pending',
            self::TERMINATION_REQUEST_PENDING => 'Termination Request Pending',
            self::REHIRE_REQUEST_PENDING => 'Rehire Request Pending',
            self::PERFORMANCE_REVIEW_DUE => 'Performance Review Due',

            // ERP Employee (self) notifications
            self::LEAVE_REQUEST_APPROVED => 'Leave Request Approved',
            self::LEAVE_REQUEST_REJECTED => 'Leave Request Rejected',
            self::OVERTIME_REQUEST_APPROVED => 'Overtime Request Approved',
            self::OVERTIME_REQUEST_REJECTED => 'Overtime Request Rejected',
            self::PAYSLIP_READY => 'Payslip Ready',
            self::PAYSLIP_REJECTED => 'Payslip Rejected',
            self::PRICE_CHANGE_REJECTED => 'Price Change Rejected',
            self::EXPENSE_REJECTED => 'Expense Rejected',

            // HR notifications
            self::LEAVE_SUBMITTED => 'New Leave Request',
            self::OVERTIME_SUBMITTED => 'New Overtime Request',
            self::SALARY_CHANGE_SUBMITTED => 'New Salary Change Request',
            self::SALARY_CHANGE_APPROVED => 'Salary Change Approved',

            // Finance notifications
            self::INVOICE_CREATED_FINANCE => 'New Invoice Created',
            self::EXPENSE_SUBMITTED => 'New Expense Submitted',
            self::PURCHASE_REQUEST_SUBMITTED => 'New Purchase Request',
            
            // Super admin notifications
            self::SHOP_REGISTRATION_PENDING => 'New Shop Registration',
            self::SHOP_DOCUMENT_RENEWAL_PENDING => 'Document Renewal Pending',
            self::SHOP_DOCUMENT_RENEWAL_REVIEWED => 'Document Renewal Reviewed',
            self::SHOP_DOCUMENT_EXPIRING => 'Business Document Expiring',
            self::BUSINESS_UPGRADE_REQUEST_PENDING => 'Business Upgrade Request',
            self::BUSINESS_UPGRADE_REQUEST_APPROVED => 'Business Upgrade Request Approved',
            self::BUSINESS_UPGRADE_REQUEST_REJECTED => 'Business Upgrade Request Rejected',
            self::SHOP_REPORT_FILED => 'Shop Report Filed',
            self::REVIEW_REPORTED => 'Review Reported',
            self::SUSPENSION_APPEAL_SUBMITTED => 'Suspension Appeal Submitted',

            // CRM notifications
            self::NEW_LEAD => 'New Lead',
            self::LEAD_UPDATED => 'Lead Updated',
            self::OPPORTUNITY_CREATED => 'Opportunity Created',
            self::CUSTOMER_SUPPORT_TICKET => 'Customer Support Ticket',

            // Logistics notifications
            self::LOGISTICS_SHIPMENT_REQUESTED => 'Shipment Requested',
            self::LOGISTICS_ASSIGNED => 'Delivery Assigned',
            self::LOGISTICS_BATCH_OFFERED => 'Delivery Batch Offered',
            self::LOGISTICS_BATCH_REJECTED => 'Batch Offer Rejected',
            self::LOGISTICS_PICKUP_SCHEDULED => 'Pickup Scheduled',
            self::LOGISTICS_IN_TRANSIT => 'Delivery In Transit',
            self::LOGISTICS_DELIVERY_FAILED => 'Delivery Attempt Failed',
            self::LOGISTICS_PROOF_REQUIRED => 'Delivery Proof Required',
            self::LOGISTICS_DELIVERED => 'Delivered',
            self::LOGISTICS_EXCEPTION => 'Delivery Exception',
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
            self::INVOICE_CREATED, self::INVOICE_CREATED_FINANCE,
            self::EXPENSE_SUBMITTED, self::EXPENSE_REJECTED,
            self::PRICE_CHANGE_REJECTED,
            self::PURCHASE_REQUEST_SUBMITTED => 'finance',
            
            self::LEAVE_APPROVAL, self::LEAVE_REQUEST_PENDING,
            self::LEAVE_SUBMITTED, self::LEAVE_REQUEST_APPROVED, self::LEAVE_REQUEST_REJECTED,
            self::OVERTIME_SUBMITTED, self::OVERTIME_REQUEST_PENDING,
            self::SALARY_CHANGE_SUBMITTED, self::SALARY_CHANGE_APPROVED,
            self::OVERTIME_REQUEST_APPROVED, self::OVERTIME_REQUEST_REJECTED,
            self::ATTENDANCE_REMINDER, self::DOCUMENT_EXPIRING,
            self::PAYROLL_GENERATED, self::PAYSLIP_READY, self::PAYSLIP_REJECTED, self::TRAINING_ASSIGNED,
            self::EMPLOYEE_TERMINATION_REQUEST, self::EMPLOYEE_REHIRE_REQUEST,
            self::TERMINATION_REQUEST_PENDING, self::REHIRE_REQUEST_PENDING => 'hr',
            
            self::NEW_LEAD, self::LEAD_UPDATED, self::OPPORTUNITY_CREATED,
            self::CUSTOMER_SUPPORT_TICKET => 'crm',

            self::LOGISTICS_SHIPMENT_REQUESTED, self::LOGISTICS_ASSIGNED, self::LOGISTICS_BATCH_OFFERED,
            self::LOGISTICS_BATCH_REJECTED,
            self::LOGISTICS_PICKUP_SCHEDULED, self::LOGISTICS_IN_TRANSIT,
            self::LOGISTICS_DELIVERY_FAILED, self::LOGISTICS_PROOF_REQUIRED,
            self::LOGISTICS_DELIVERED, self::LOGISTICS_EXCEPTION => 'logistics',

            self::SHOP_REGISTRATION_PENDING, self::SHOP_DOCUMENT_RENEWAL_PENDING, self::SHOP_DOCUMENT_RENEWAL_REVIEWED, self::SHOP_DOCUMENT_EXPIRING, self::BUSINESS_UPGRADE_REQUEST_PENDING,
            self::SHOP_REPORT_FILED, self::REVIEW_REPORTED, self::SUSPENSION_APPEAL_SUBMITTED => 'admin',
            
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
            self::LOGISTICS_IN_TRANSIT,
            self::LOGISTICS_DELIVERY_FAILED,
            self::LOGISTICS_DELIVERED,
            self::LOGISTICS_EXCEPTION,
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
            self::EMPLOYEE_TERMINATION_REQUEST,
            self::EMPLOYEE_REHIRE_REQUEST,
            self::SALARY_CHANGE_SUBMITTED,
            self::CUSTOMER_MESSAGE,
            self::SHOP_DOCUMENT_RENEWAL_REVIEWED,
            self::SHOP_DOCUMENT_EXPIRING,
            self::BUSINESS_UPGRADE_REQUEST_APPROVED,
            self::BUSINESS_UPGRADE_REQUEST_REJECTED,
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
            self::EMPLOYEE_TERMINATION_REQUEST,
            self::EMPLOYEE_REHIRE_REQUEST,
            self::REPAIR_REJECTION_REVIEW,
            self::LEAVE_REQUEST_PENDING,
            self::EXPENSE_REQUEST_PENDING,
            self::SUSPENSION_REQUEST_PENDING,
            self::TERMINATION_REQUEST_PENDING,
            self::REHIRE_REQUEST_PENDING,
            self::SALARY_CHANGE_SUBMITTED,
            self::SHOP_REGISTRATION_PENDING,
            self::SHOP_DOCUMENT_RENEWAL_PENDING,
            self::SHOP_DOCUMENT_EXPIRING,
            self::BUSINESS_UPGRADE_REQUEST_PENDING,
            self::SHOP_REPORT_FILED,
            self::REVIEW_REPORTED,
            self::SUSPENSION_APPEAL_SUBMITTED,
        ]);
    }

    /**
     * Check if notification type is for super admins
     */
    public function isSuperAdminNotification(): bool
    {
        return in_array($this, [
            self::SHOP_REGISTRATION_PENDING,
            self::SHOP_DOCUMENT_RENEWAL_PENDING,
            self::BUSINESS_UPGRADE_REQUEST_PENDING,
            self::SHOP_REPORT_FILED,
            self::REVIEW_REPORTED,
            self::SUSPENSION_APPEAL_SUBMITTED,
        ]);
    }
}
