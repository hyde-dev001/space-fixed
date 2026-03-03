/**
 * Inventory Module API Service
 * Handles all API calls for the inventory management system
 */

import axios, { AxiosError } from 'axios';
import {
    InventoryItem,
    StockMovement,
    Supplier,
    SupplierOrder,
    InventoryMetrics,
    StockMovementMetrics,
    SupplierOrderMetrics,
    ChartData,
    PaginatedResponse,
    ApiResponse,
    InventoryFilters,
    StockMovementFilters,
    SupplierOrderFilters,
    CreateInventoryItemData,
    UpdateQuantityData,
    CreateStockMovementData,
    CreateSupplierOrderData,
    ReceiveOrderData,
    InventoryAlert,
} from '@/types/inventory';

const API_BASE = '/api/erp/inventory';

// Helper function to handle API errors
const handleApiError = (error: unknown): never => {
    if (axios.isAxiosError(error)) {
        const axiosError = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
        throw {
            message: axiosError.response?.data?.message || axiosError.message,
            errors: axiosError.response?.data?.errors || {},
            status: axiosError.response?.status,
        };
    }
    throw error;
};

// Dashboard APIs
export const dashboardAPI = {
    /**
     * Get dashboard overview
     */
    async getOverview(): Promise<{
        metrics: InventoryMetrics;
        chartData: ChartData;
        recentItems: InventoryItem[];
    }> {
        try {
            const response = await axios.get(`${API_BASE}/dashboard`);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Get dashboard metrics only
     */
    async getMetrics(): Promise<InventoryMetrics> {
        try {
            const response = await axios.get(`${API_BASE}/dashboard/metrics`);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Get chart data for visualization
     */
    async getChartData(): Promise<ChartData> {
        try {
            const response = await axios.get(`${API_BASE}/dashboard/chart-data`);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },
};

// Product Inventory APIs
export const productInventoryAPI = {
    /**
     * Get paginated list of inventory items
     */
    async getAll(filters?: InventoryFilters): Promise<PaginatedResponse<InventoryItem>> {
        try {
            const response = await axios.get(`${API_BASE}/products`, { params: filters });
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Get single inventory item by ID
     */
    async getById(id: number): Promise<InventoryItem> {
        try {
            const response = await axios.get(`${API_BASE}/products/${id}`);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Update inventory item quantity
     */
    async updateQuantity(id: number, data: UpdateQuantityData): Promise<InventoryItem> {
        try {
            const response = await axios.put(`${API_BASE}/products/${id}/quantity`, data);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Bulk update multiple item quantities
     */
    async bulkUpdateQuantities(updates: Array<{ id: number } & UpdateQuantityData>): Promise<ApiResponse<{ updated: number }>> {
        try {
            const response = await axios.post(`${API_BASE}/products/bulk-update`, { updates });
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },
};

// Stock Movement APIs
export const stockMovementAPI = {
    /**
     * Get paginated list of stock movements
     */
    async getAll(filters?: StockMovementFilters): Promise<PaginatedResponse<StockMovement>> {
        try {
            const response = await axios.get(`${API_BASE}/movements`, { params: filters });
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Create new stock movement
     */
    async create(data: CreateStockMovementData): Promise<StockMovement> {
        try {
            const response = await axios.post(`${API_BASE}/movements`, data);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Get stock movement metrics
     */
    async getMetrics(): Promise<StockMovementMetrics> {
        try {
            const response = await axios.get(`${API_BASE}/movements/metrics`);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Export stock movement report
     */
    async exportReport(filters?: StockMovementFilters): Promise<Blob> {
        try {
            const response = await axios.get(`${API_BASE}/movements/export`, {
                params: filters,
                responseType: 'blob',
            });
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },
};

// Upload/Manage Inventory APIs
export const inventoryItemAPI = {
    /**
     * Get all inventory items
     */
    async getAll(filters?: InventoryFilters): Promise<PaginatedResponse<InventoryItem>> {
        try {
            const response = await axios.get(`${API_BASE}/items`, { params: filters });
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Create new inventory item
     */
    async create(data: CreateInventoryItemData): Promise<InventoryItem> {
        try {
            const formData = new FormData();
            
            // Append basic fields
            Object.entries(data).forEach(([key, value]) => {
                if (key === 'images' || key === 'sizes' || key === 'color_variants') return;
                if (value !== null && value !== undefined) {
                    formData.append(key, String(value));
                }
            });

            // Append sizes
            if (data.sizes) {
                formData.append('sizes', JSON.stringify(data.sizes));
            }

            // Append color variants
            if (data.color_variants) {
                formData.append('color_variants', JSON.stringify(data.color_variants));
            }

            // Append images
            if (data.images) {
                data.images.forEach((image, index) => {
                    formData.append(`images[${index}]`, image);
                });
            }

            const response = await axios.post(`${API_BASE}/items`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Update inventory item
     */
    async update(id: number, data: Partial<CreateInventoryItemData>): Promise<InventoryItem> {
        try {
            const response = await axios.put(`${API_BASE}/items/${id}`, data);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Delete inventory item
     */
    async delete(id: number): Promise<void> {
        try {
            await axios.delete(`${API_BASE}/items/${id}`);
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Upload images
     */
    async uploadImages(itemId: number, images: File[]): Promise<ApiResponse<{ images: any[] }>> {
        try {
            const formData = new FormData();
            formData.append('inventory_item_id', String(itemId));
            images.forEach((image, index) => {
                formData.append(`images[${index}]`, image);
            });

            const response = await axios.post(`${API_BASE}/items/images`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Delete image
     */
    async deleteImage(imageId: number): Promise<void> {
        try {
            await axios.delete(`${API_BASE}/items/images/${imageId}`);
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Set thumbnail
     */
    async setThumbnail(imageId: number): Promise<void> {
        try {
            await axios.put(`${API_BASE}/items/images/${imageId}/thumbnail`);
        } catch (error) {
            return handleApiError(error);
        }
    },
};

// Supplier APIs
export const supplierAPI = {
    /**
     * Get all suppliers
     */
    async getAll(): Promise<Supplier[]> {
        try {
            const response = await axios.get(`${API_BASE}/suppliers`);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Get supplier by ID
     */
    async getById(id: number): Promise<Supplier> {
        try {
            const response = await axios.get(`${API_BASE}/suppliers/${id}`);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Create supplier
     */
    async create(data: Partial<Supplier>): Promise<Supplier> {
        try {
            const response = await axios.post(`${API_BASE}/suppliers`, data);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Update supplier
     */
    async update(id: number, data: Partial<Supplier>): Promise<Supplier> {
        try {
            const response = await axios.put(`${API_BASE}/suppliers/${id}`, data);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Delete supplier
     */
    async delete(id: number): Promise<void> {
        try {
            await axios.delete(`${API_BASE}/suppliers/${id}`);
        } catch (error) {
            return handleApiError(error);
        }
    },
};

// Supplier Order APIs
export const supplierOrderAPI = {
    /**
     * Get all supplier orders
     */
    async getAll(filters?: SupplierOrderFilters): Promise<PaginatedResponse<SupplierOrder>> {
        try {
            const response = await axios.get(`${API_BASE}/supplier-orders`, { params: filters });
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Get supplier order by ID
     */
    async getById(id: number): Promise<SupplierOrder> {
        try {
            const response = await axios.get(`${API_BASE}/supplier-orders/${id}`);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Create supplier order
     */
    async create(data: CreateSupplierOrderData): Promise<SupplierOrder> {
        try {
            const response = await axios.post(`${API_BASE}/supplier-orders`, data);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Update supplier order
     */
    async update(id: number, data: Partial<CreateSupplierOrderData>): Promise<SupplierOrder> {
        try {
            const response = await axios.put(`${API_BASE}/supplier-orders/${id}`, data);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Delete supplier order
     */
    async delete(id: number): Promise<void> {
        try {
            await axios.delete(`${API_BASE}/supplier-orders/${id}`);
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Update order status
     */
    async updateStatus(id: number, status: string): Promise<SupplierOrder> {
        try {
            const response = await axios.put(`${API_BASE}/supplier-orders/${id}/status`, { status });
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Receive order
     */
    async receiveOrder(id: number, data: ReceiveOrderData): Promise<SupplierOrder> {
        try {
            const response = await axios.post(`${API_BASE}/supplier-orders/${id}/receive`, data);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Get supplier order metrics
     */
    async getMetrics(): Promise<SupplierOrderMetrics> {
        try {
            const response = await axios.get(`${API_BASE}/supplier-orders/metrics`);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Generate PO number
     */
    async generatePO(): Promise<{ po_number: string }> {
        try {
            const response = await axios.post(`${API_BASE}/supplier-orders/generate-po`);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },
};

// Inventory Alerts APIs
export const inventoryAlertAPI = {
    /**
     * Get all alerts
     */
    async getAll(): Promise<InventoryAlert[]> {
        try {
            const response = await axios.get(`${API_BASE}/alerts`);
            return response.data;
        } catch (error) {
            return handleApiError(error);
        }
    },

    /**
     * Resolve alert
     */
    async resolve(id: number): Promise<void> {
        try {
            await axios.put(`${API_BASE}/alerts/${id}/resolve`);
        } catch (error) {
            return handleApiError(error);
        }
    },
};

// Export all APIs
export const inventoryAPI = {
    dashboard: dashboardAPI,
    products: productInventoryAPI,
    movements: stockMovementAPI,
    items: inventoryItemAPI,
    suppliers: supplierAPI,
    orders: supplierOrderAPI,
    alerts: inventoryAlertAPI,
};

export default inventoryAPI;
