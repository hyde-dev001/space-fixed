/**
 * Procurement Module TypeScript Interfaces
 * 
 * This file contains all TypeScript interfaces for the Procurement Management System
 */

export interface User {
    id: number;
    name: string;
    email: string;
}

export interface InventoryItem {
    id: number;
    product_name: string;
    sku_code: string;
    stock_quantity: number;
}

export interface PurchaseRequest {
    id: number;
    pr_number: string;
    shop_owner_id: number;
    supplier_id: number;
    supplier?: Supplier;
    product_name: string;
    inventory_item_id?: number;
    inventory_item?: InventoryItem;
    quantity: number;
    unit_cost: number;
    total_cost: number;
    priority: 'high' | 'medium' | 'low';
    priority_label?: string;
    justification: string;
    status: 'draft' | 'pending_finance' | 'approved' | 'rejected';
    status_label?: string;
    rejection_reason?: string;
    requested_by: number;
    requester?: User;
    requested_date: string;
    reviewed_by?: number;
    reviewer?: User;
    reviewed_date?: string;
    approved_by?: number;
    approver?: User;
    approved_date?: string;
    notes?: string;
    days_pending?: number;
    created_at: string;
    updated_at: string;
}

export interface PurchaseOrder {
    id: number;
    po_number: string;
    pr_id?: number;
    purchase_request?: PurchaseRequest;
    shop_owner_id: number;
    supplier_id: number;
    supplier?: Supplier;
    product_name: string;
    inventory_item_id?: number;
    inventory_item?: InventoryItem;
    quantity: number;
    unit_cost: number;
    total_cost: number;
    expected_delivery_date?: string;
    actual_delivery_date?: string;
    payment_terms: string;
    status: 'draft' | 'sent' | 'confirmed' | 'in_transit' | 'delivered' | 'completed' | 'cancelled';
    status_label?: string;
    cancellation_reason?: string;
    ordered_by: number;
    orderer?: User;
    ordered_date: string;
    confirmed_by?: number;
    confirmed_date?: string;
    delivered_by?: number;
    delivered_date?: string;
    completed_by?: number;
    completed_date?: string;
    notes?: string;
    is_overdue?: boolean;
    days_until_delivery?: number;
    days_since_delivery?: number;
    created_at: string;
    updated_at: string;
}

export interface ReplenishmentRequest {
    id: number;
    request_number: string;
    shop_owner_id: number;
    inventory_item_id: number;
    inventory_item?: InventoryItem;
    product_name: string;
    sku_code: string;
    quantity_needed: number;
    priority: 'high' | 'medium' | 'low';
    priority_label?: string;
    status: 'pending' | 'accepted' | 'rejected' | 'needs_details';
    status_label?: string;
    requested_by: number;
    requester?: User;
    requested_date: string;
    reviewed_by?: number;
    reviewer?: User;
    reviewed_date?: string;
    notes?: string;
    response_notes?: string;
    days_pending?: number;
    created_at: string;
    updated_at: string;
}

export interface StockRequestApproval {
    id: number;
    request_number: string;
    shop_owner_id: number;
    inventory_item_id: number;
    inventory_item?: InventoryItem;
    product_name: string;
    sku_code: string;
    quantity_needed: number;
    priority: 'high' | 'medium' | 'low';
    priority_label?: string;
    status: 'pending' | 'accepted' | 'rejected' | 'needs_details';
    status_label?: string;
    requested_by: number;
    requester?: User;
    requested_date: string;
    approved_by?: number;
    approver?: User;
    approved_date?: string;
    notes?: string;
    approval_notes?: string;
    rejection_reason?: string;
    days_pending?: number;
    created_at: string;
    updated_at: string;
}

export interface Supplier {
    id: number;
    shop_owner_id: number;
    name: string;
    contact_person?: string;
    email?: string;
    phone?: string;
    address?: string;
    products_supplied?: string;
    purchase_order_count: number;
    last_order_date?: string;
    total_order_value: number;
    performance_rating?: number;
    is_active: boolean;
    notes?: string;
    created_at: string;
    updated_at: string;
}

export interface ProcurementSettings {
    id: number;
    shop_owner_id: number;
    auto_pr_approval_threshold: number;
    require_finance_approval: boolean;
    default_payment_terms: string;
    auto_generate_po: boolean;
    notification_emails?: string[];
    settings_json?: Record<string, any>;
    created_at: string;
    updated_at: string;
}

// Metrics interfaces
export interface ProcurementMetrics {
    total_purchase_requests: number;
    pending_finance: number;
    approved_requests: number;
    total_purchase_orders: number;
    active_orders: number;
    completed_orders: number;
    total_replenishment_requests?: number;
    pending_stock_requests?: number;
    accepted_stock_requests?: number;
}

export interface PurchaseRequestMetrics {
    total: number;
    pending_finance: number;
    approved: number;
    rejected: number;
    draft: number;
}

export interface PurchaseOrderMetrics {
    total: number;
    active: number;
    completed: number;
    cancelled: number;
    overdue: number;
}

export interface StockRequestMetrics {
    total: number;
    pending: number;
    accepted: number;
    rejected: number;
}

export interface SupplierPerformanceMetrics {
    total_orders: number;
    total_value: number;
    average_order_value: number;
    on_time_delivery_rate: number;
    average_delivery_time: number;
    performance_rating: number;
    last_order_date?: string;
}

// Filter interfaces
export interface PurchaseRequestFilters {
    search?: string;
    status?: string;
    priority?: string;
    date_from?: string;
    date_to?: string;
    supplier_id?: number;
    page?: number;
    per_page?: number;
}

export interface PurchaseOrderFilters {
    search?: string;
    status?: string;
    supplier_id?: number;
    date_from?: string;
    date_to?: string;
    overdue_only?: boolean;
    page?: number;
    per_page?: number;
}

export interface ReplenishmentRequestFilters {
    search?: string;
    status?: string;
    priority?: string;
    page?: number;
    per_page?: number;
}

export interface StockRequestFilters {
    search?: string;
    status?: string;
    priority?: string;
    page?: number;
    per_page?: number;
}

export interface SupplierFilters {
    search?: string;
    is_active?: boolean;
    page?: number;
    per_page?: number;
}

// Request payload interfaces
export interface CreatePurchaseRequestPayload {
    product_name: string;
    supplier_id: number;
    inventory_item_id?: number;
    quantity: number;
    unit_cost: number;
    priority: 'high' | 'medium' | 'low';
    justification: string;
    submit_to_finance?: boolean;
}

export interface UpdatePurchaseRequestPayload {
    product_name?: string;
    supplier_id?: number;
    inventory_item_id?: number;
    quantity?: number;
    unit_cost?: number;
    priority?: 'high' | 'medium' | 'low';
    justification?: string;
}

export interface ApprovePurchaseRequestPayload {
    approval_notes?: string;
}

export interface RejectPurchaseRequestPayload {
    rejection_reason: string;
}

export interface CreatePurchaseOrderPayload {
    pr_id: number;
    expected_delivery_date?: string;
    payment_terms: string;
    notes?: string;
}

export interface UpdatePurchaseOrderPayload {
    expected_delivery_date?: string;
    payment_terms?: string;
    notes?: string;
}

export interface UpdatePurchaseOrderStatusPayload {
    status: 'sent' | 'confirmed' | 'in_transit' | 'delivered' | 'completed';
    notes?: string;
    actual_delivery_date?: string;
}

export interface CancelPurchaseOrderPayload {
    cancellation_reason: string;
}

export interface CreateReplenishmentRequestPayload {
    inventory_item_id: number;
    product_name: string;
    sku_code: string;
    quantity_needed: number;
    priority: 'high' | 'medium' | 'low';
    notes: string;
}

export interface AcceptReplenishmentRequestPayload {
    response_notes?: string;
}

export interface RejectReplenishmentRequestPayload {
    response_notes: string;
}

export interface RequestReplenishmentDetailsPayload {
    response_notes: string;
}

export interface ApproveStockRequestPayload {
    approval_notes?: string;
    auto_create_pr?: boolean;
}

export interface RejectStockRequestPayload {
    rejection_reason: string;
}

export interface RequestStockDetailsPayload {
    response_notes: string;
}

export interface CreateSupplierPayload {
    name: string;
    contact_person?: string;
    email?: string;
    phone?: string;
    address?: string;
    products_supplied?: string;
    notes?: string;
}

export interface UpdateSupplierPayload {
    name?: string;
    contact_person?: string;
    email?: string;
    phone?: string;
    address?: string;
    products_supplied?: string;
    is_active?: boolean;
    notes?: string;
}

export interface UpdateSupplierRatingPayload {
    performance_rating: number;
}

export interface UpdateProcurementSettingsPayload {
    auto_pr_approval_threshold?: number;
    require_finance_approval?: boolean;
    default_payment_terms?: string;
    auto_generate_po?: boolean;
    notification_emails?: string[];
    settings_json?: Record<string, any>;
}

// Paginated response interface
export interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

// API response interfaces
export interface ApiResponse<T = any> {
    success: boolean;
    message?: string;
    data?: T;
    errors?: Record<string, string[]>;
}

export interface ApiErrorResponse {
    success: false;
    message: string;
    errors?: Record<string, string[]>;
}
