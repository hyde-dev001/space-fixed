/**
 * Stock Request Approval API Service
 * 
 * Handles all API calls for Stock Request Approval management
 */

import axios, { AxiosResponse } from 'axios';
import {
    StockRequestApproval,
    StockRequestFilters,
    StockRequestMetrics,
    ApproveStockRequestPayload,
    RejectStockRequestPayload,
    RequestStockDetailsPayload,
    PaginatedResponse,
    ApiResponse,
} from '@/types/procurement';

const BASE_URL = '/api/erp/procurement/stock-requests';

export const stockRequestApi = {
    /**
     * Get all stock requests with optional filters
     */
    async getAll(filters?: StockRequestFilters): Promise<PaginatedResponse<StockRequestApproval>> {
        const response: AxiosResponse<PaginatedResponse<StockRequestApproval>> = await axios.get(
            BASE_URL,
            { params: filters }
        );
        return response.data;
    },

    /**
     * Get a single stock request by ID
     */
    async getById(id: number): Promise<StockRequestApproval> {
        const response: AxiosResponse<StockRequestApproval> = await axios.get(`${BASE_URL}/${id}`);
        return response.data;
    },

    /**
     * Approve a stock request
     */
    async approve(id: number, data?: ApproveStockRequestPayload): Promise<StockRequestApproval> {
        const response: AxiosResponse<StockRequestApproval> = await axios.post(
            `${BASE_URL}/${id}/approve`,
            data || {}
        );
        return response.data;
    },

    /**
     * Reject a stock request
     */
    async reject(id: number, data: RejectStockRequestPayload): Promise<StockRequestApproval> {
        const response: AxiosResponse<StockRequestApproval> = await axios.post(
            `${BASE_URL}/${id}/reject`,
            data
        );
        return response.data;
    },

    /**
     * Request additional details for a stock request
     */
    async requestDetails(id: number, data: RequestStockDetailsPayload): Promise<StockRequestApproval> {
        const response: AxiosResponse<StockRequestApproval> = await axios.post(
            `${BASE_URL}/${id}/request-details`,
            data
        );
        return response.data;
    },

    /**
     * Get stock request metrics
     */
    async getMetrics(): Promise<StockRequestMetrics> {
        const response: AxiosResponse<StockRequestMetrics> = await axios.get(`${BASE_URL}/metrics`);
        return response.data;
    },
};

export default stockRequestApi;
