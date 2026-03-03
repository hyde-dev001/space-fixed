/**
 * Purchase Order API Service
 * 
 * Handles all API calls for Purchase Order management
 */

import axios, { AxiosResponse } from 'axios';
import {
    PurchaseOrder,
    PurchaseOrderFilters,
    PurchaseOrderMetrics,
    CreatePurchaseOrderPayload,
    UpdatePurchaseOrderPayload,
    UpdatePurchaseOrderStatusPayload,
    CancelPurchaseOrderPayload,
    PaginatedResponse,
    ApiResponse,
} from '@/types/procurement';

const BASE_URL = '/api/erp/procurement/purchase-orders';

export const purchaseOrderApi = {
    /**
     * Get all purchase orders with optional filters
     */
    async getAll(filters?: PurchaseOrderFilters): Promise<PaginatedResponse<PurchaseOrder>> {
        const response: AxiosResponse<PaginatedResponse<PurchaseOrder>> = await axios.get(BASE_URL, {
            params: filters,
        });
        return response.data;
    },

    /**
     * Get a single purchase order by ID
     */
    async getById(id: number): Promise<PurchaseOrder> {
        const response: AxiosResponse<PurchaseOrder> = await axios.get(`${BASE_URL}/${id}`);
        return response.data;
    },

    /**
     * Create a new purchase order from approved PR
     */
    async create(data: CreatePurchaseOrderPayload): Promise<PurchaseOrder> {
        const response: AxiosResponse<PurchaseOrder> = await axios.post(BASE_URL, data);
        return response.data;
    },

    /**
     * Update an existing purchase order
     */
    async update(id: number, data: UpdatePurchaseOrderPayload): Promise<PurchaseOrder> {
        const response: AxiosResponse<PurchaseOrder> = await axios.put(`${BASE_URL}/${id}`, data);
        return response.data;
    },

    /**
     * Delete a purchase order (soft delete)
     */
    async delete(id: number): Promise<ApiResponse> {
        const response: AxiosResponse<ApiResponse> = await axios.delete(`${BASE_URL}/${id}`);
        return response.data;
    },

    /**
     * Update purchase order status
     */
    async updateStatus(id: number, data: UpdatePurchaseOrderStatusPayload): Promise<PurchaseOrder> {
        const response: AxiosResponse<PurchaseOrder> = await axios.post(
            `${BASE_URL}/${id}/update-status`,
            data
        );
        return response.data;
    },

    /**
     * Send purchase order to supplier
     */
    async sendToSupplier(id: number): Promise<PurchaseOrder> {
        const response: AxiosResponse<PurchaseOrder> = await axios.post(
            `${BASE_URL}/${id}/send-to-supplier`
        );
        return response.data;
    },

    /**
     * Mark purchase order as delivered
     */
    async markAsDelivered(id: number, actualDate?: string): Promise<PurchaseOrder> {
        const response: AxiosResponse<PurchaseOrder> = await axios.post(
            `${BASE_URL}/${id}/mark-delivered`,
            { actual_delivery_date: actualDate || new Date().toISOString().split('T')[0] }
        );
        return response.data;
    },

    /**
     * Cancel a purchase order
     */
    async cancel(id: number, data: CancelPurchaseOrderPayload): Promise<PurchaseOrder> {
        const response: AxiosResponse<PurchaseOrder> = await axios.post(
            `${BASE_URL}/${id}/cancel`,
            data
        );
        return response.data;
    },

    /**
     * Get purchase order metrics
     */
    async getMetrics(): Promise<PurchaseOrderMetrics> {
        const response: AxiosResponse<PurchaseOrderMetrics> = await axios.get(`${BASE_URL}/metrics`);
        return response.data;
    },
};

export default purchaseOrderApi;
