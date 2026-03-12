/**
 * Inventory Module TypeScript Interfaces
 * Generated: March 3, 2026
 */

export interface InventoryItem {
    id: number;
    shop_owner_id: number;
    product_id?: number;
    name: string;
    sku: string;
    category: 'shoes' | 'accessories' | 'care_products' | 'cleaning_materials' | 'packaging' | 'repair_materials';
    brand?: string;
    description?: string;
    notes?: string;
    unit: string;
    available_quantity: number;
    reserved_quantity: number;
    total_quantity: number;
    reorder_level: number;
    reorder_quantity: number;
    price?: number;
    cost_price?: number;
    weight?: number;
    is_active: boolean;
    main_image?: string;
    status: 'In Stock' | 'Low Stock' | 'Out of Stock';
    sizes?: InventorySize[];
    color_variants?: InventoryColorVariant[];
    images?: InventoryImage[];
    created_at: string;
    updated_at: string;
    last_updated?: string;
}

export interface InventorySize {
    id: number;
    inventory_item_id: number;
    size: string;
    quantity: number;
    created_at?: string;
    updated_at?: string;
}

export interface InventoryColorVariant {
    id: number;
    inventory_item_id: number;
    color_name: string;
    color_code?: string;
    quantity: number;
    sku_suffix?: string;
    images: InventoryImage[];
    created_at?: string;
    updated_at?: string;
}

export interface InventoryImage {
    id: number;
    inventory_item_id?: number;
    inventory_color_variant_id?: number;
    image_path: string;
    is_thumbnail: boolean;
    sort_order: number;
    preview?: string;
    created_at?: string;
    updated_at?: string;
}

export interface StockMovement {
    id: number;
    inventory_item_id: number;
    movement_type: 'stock_in' | 'stock_out' | 'adjustment' | 'return' | 'repair_usage' | 'transfer' | 'damage' | 'initial';
    quantity_change: number;
    quantity_before: number;
    quantity_after: number;
    reference_type?: string;
    reference_id?: number;
    notes?: string;
    performed_by?: number;
    performer?: {
        id: number;
        name: string;
        email?: string;
    };
    performed_at: string;
    product?: InventoryItem;
    inventory_item?: InventoryItem;
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
    city?: string;
    country?: string;
    payment_terms?: string;
    lead_time_days?: number;
    is_active: boolean;
    notes?: string;
    created_at?: string;
    updated_at?: string;
    deleted_at?: string;
}

export interface SupplierOrder {
    id: number;
    shop_owner_id: number;
    supplier_id: number;
    supplier: Supplier;
    po_number: string;
    status: 'draft' | 'sent' | 'confirmed' | 'in_transit' | 'delivered' | 'completed' | 'cancelled';
    order_date: string;
    expected_delivery_date?: string;
    actual_delivery_date?: string;
    total_amount?: number;
    currency: string;
    payment_status: 'unpaid' | 'partial' | 'paid';
    remarks?: string;
    items: SupplierOrderItem[];
    days_to_delivery?: number;
    is_overdue?: boolean;
    created_at: string;
    updated_at: string;
    deleted_at?: string;
}

export interface SupplierOrderItem {
    id: number;
    supplier_order_id: number;
    inventory_item_id?: number;
    inventory_item?: InventoryItem;
    product_name: string;
    sku?: string;
    quantity: number;
    unit_price?: number;
    total_price?: number;
    quantity_received: number;
    notes?: string;
    created_at?: string;
    updated_at?: string;
}

export interface InventoryAlert {
    id: number;
    inventory_item_id: number;
    inventory_item?: InventoryItem;
    alert_type: 'low_stock' | 'out_of_stock' | 'overstock' | 'expiring_soon';
    threshold_value?: number;
    current_value?: number;
    is_resolved: boolean;
    resolved_at?: string;
    resolved_by?: number;
    created_at: string;
    updated_at: string;
}

export interface InventoryMetrics {
    total_items: number;
    total_value: number;
    low_stock_count: number;
    out_of_stock_count: number;
    stock_in_today: number;
    stock_out_today: number;
    active_supplier_orders: number;
    overdue_orders: number;
}

export interface StockMovementMetrics {
    total_movements: number;
    stock_in_total: number;
    stock_out_total: number;
    adjustments_total: number;
    returns_total: number;
    transfers_total: number;
    daily_breakdown?: Array<{
        date: string;
        stock_in: number;
        stock_out: number;
        net_change: number;
    }>;
}

export interface SupplierOrderMetrics {
    active_orders: number;
    due_today: number;
    overdue: number;
    arriving_soon: number;
    total_value: number;
}

export interface ChartData {
    categories: string[];
    series: Array<{
        name: string;
        data: number[];
    }>;
}

// Form Data Interfaces
export interface CreateInventoryItemData {
    name: string;
    sku?: string;
    category: string;
    brand?: string;
    description?: string;
    notes?: string;
    unit: string;
    available_quantity: number;
    reserved_quantity?: number;
    reorder_level: number;
    reorder_quantity: number;
    price?: number;
    cost_price?: number;
    weight?: number;
    sizes?: Array<{ size: string; quantity: number }>;
    color_variants?: Array<{
        color_name: string;
        color_code?: string;
        quantity: number;
        sku_suffix?: string;
        images?: File[];
    }>;
    images?: File[];
}

export interface UpdateQuantityData {
    available_quantity: number;
    movement_type: 'adjustment' | 'stock_in' | 'stock_out';
    notes?: string;
}

export interface CreateStockMovementData {
    inventory_item_id: number;
    movement_type: 'stock_in' | 'stock_out' | 'adjustment' | 'return' | 'repair_usage' | 'transfer';
    quantity_change: number;
    notes?: string;
}

export interface CreateSupplierOrderData {
    supplier_id: number;
    order_date: string;
    expected_delivery_date?: string;
    remarks?: string;
    items: Array<{
        inventory_item_id?: number;
        product_name: string;
        sku?: string;
        quantity: number;
        unit_price?: number;
    }>;
}

export interface ReceiveOrderData {
    items: Array<{
        supplier_order_item_id: number;
        quantity_received: number;
    }>;
    actual_delivery_date?: string;
}

// API Response Interfaces
export interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

export interface ApiResponse<T> {
    data: T;
    message?: string;
    errors?: Record<string, string[]>;
}

// Filter Interfaces
export interface InventoryFilters {
    search?: string;
    category?: string;
    brand?: string;
    status?: 'in_stock' | 'low_stock' | 'out_of_stock';
    sort_by?: 'name' | 'sku' | 'quantity' | 'updated_at';
    sort_order?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
}

export interface StockMovementFilters {
    search?: string;
    movement_type?: string;
    start_date?: string;
    end_date?: string;
    inventory_item_id?: number;
    page?: number;
    per_page?: number;
}

export interface SupplierOrderFilters {
    search?: string;
    supplier_id?: number;
    status?: string;
    start_date?: string;
    end_date?: string;
    overdue?: boolean;
    page?: number;
    per_page?: number;
}
