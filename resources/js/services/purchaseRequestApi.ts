/**
 * Purchase Request API Service
 * 
 * Handles all API calls for Purchase Request management
 */

import axios, { AxiosResponse } from 'axios';
import {
    PurchaseRequest,
    PurchaseRequestFilters,
    PurchaseRequestMetrics,
    CreatePurchaseRequestPayload,
    UpdatePurchaseRequestPayload,
    ApprovePurchaseRequestPayload,
    RejectPurchaseRequestPayload,
    PaginatedResponse,
    ApiResponse,
} from '@/types/procurement';

const BASE_URL = '/api/erp/procurement/purchase-requests';

export const purchaseRequestApi = {
    /**
     * Get all purchase requests with optional filters
     */
    async getAll(filters?: PurchaseRequestFilters): Promise<PaginatedResponse<PurchaseRequest>> {
        const response: AxiosResponse<PaginatedResponse<PurchaseRequest>> = await axios.get(BASE_URL, {
            params: filters,
        });
        return response.data;
    },

    /**
     * Get a single purchase request by ID
     */
    async getById(id: number): Promise<PurchaseRequest> {
        const response: AxiosResponse<PurchaseRequest> = await axios.get(`${BASE_URL}/${id}`);
        return response.data;
    },

    /**
     * Create a new purchase request
     */
    async create(data: CreatePurchaseRequestPayload): Promise<PurchaseRequest> {
        const response: AxiosResponse<PurchaseRequest> = await axios.post(BASE_URL, data);
        return response.data;
    },

    /**
     * Update an existing purchase request
     */
    async update(id: number, data: UpdatePurchaseRequestPayload): Promise<PurchaseRequest> {
        const response: AxiosResponse<PurchaseRequest> = await axios.put(`${BASE_URL}/${id}`, data);
        return response.data;
    },

    /**
     * Delete a purchase request (soft delete)
     */
    async delete(id: number): Promise<ApiResponse> {
        const response: AxiosResponse<ApiResponse> = await axios.delete(`${BASE_URL}/${id}`);
        return response.data;
    },

    /**
     * Submit purchase request to finance for approval
     */
    async submitToFinance(id: number): Promise<PurchaseRequest> {
        const response: AxiosResponse<PurchaseRequest> = await axios.post(
            `${BASE_URL}/${id}/submit-to-finance`
        );
        return response.data;
    },

    /**
     * Approve a purchase request (finance role)
     */
    async approve(id: number, data?: ApprovePurchaseRequestPayload): Promise<PurchaseRequest> {
        const response: AxiosResponse<PurchaseRequest> = await axios.post(
            `${BASE_URL}/${id}/approve`,
            data || {}
        );
        return response.data;
    },

    /**
     * Reject a purchase request
     */
    async reject(id: number, data: RejectPurchaseRequestPayload): Promise<PurchaseRequest> {
        const response: AxiosResponse<PurchaseRequest> = await axios.post(
            `${BASE_URL}/${id}/reject`,
            data
        );
        return response.data;
    },

    /**
     * Get purchase request metrics
     */
    async getMetrics(): Promise<PurchaseRequestMetrics> {
        const response: AxiosResponse<PurchaseRequestMetrics> = await axios.get(`${BASE_URL}/metrics`);
        return response.data;
    },

    /**
     * Get approved purchase requests (for PO creation)
     */
    async getApproved(): Promise<PurchaseRequest[]> {
        const response: AxiosResponse<PurchaseRequest[]> = await axios.get(`${BASE_URL}/approved`);
        return response.data;
    },
};

export default purchaseRequestApi;
