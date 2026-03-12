/**
 * Replenishment Request API Service
 * 
 * Handles all API calls for Replenishment Request management
 */

import axios, { AxiosResponse } from 'axios';
import {
    ReplenishmentRequest,
    ReplenishmentRequestFilters,
    CreateReplenishmentRequestPayload,
    AcceptReplenishmentRequestPayload,
    RejectReplenishmentRequestPayload,
    RequestReplenishmentDetailsPayload,
    PaginatedResponse,
    ApiResponse,
} from '@/types/procurement';

const BASE_URL = '/api/erp/procurement/replenishment-requests';

export const replenishmentRequestApi = {
    /**
     * Get all replenishment requests with optional filters
     */
    async getAll(filters?: ReplenishmentRequestFilters): Promise<PaginatedResponse<ReplenishmentRequest>> {
        const response: AxiosResponse<PaginatedResponse<ReplenishmentRequest>> = await axios.get(
            BASE_URL,
            { params: filters }
        );
        return response.data;
    },

    /**
     * Get a single replenishment request by ID
     */
    async getById(id: number): Promise<ReplenishmentRequest> {
        const response: AxiosResponse<ReplenishmentRequest> = await axios.get(`${BASE_URL}/${id}`);
        return response.data;
    },

    /**
     * Create a new replenishment request
     */
    async create(data: CreateReplenishmentRequestPayload): Promise<ReplenishmentRequest> {
        const response: AxiosResponse<ReplenishmentRequest> = await axios.post(BASE_URL, data);
        return response.data;
    },

    /**
     * Update an existing replenishment request
     */
    async update(id: number, data: Partial<CreateReplenishmentRequestPayload>): Promise<ReplenishmentRequest> {
        const response: AxiosResponse<ReplenishmentRequest> = await axios.put(`${BASE_URL}/${id}`, data);
        return response.data;
    },

    /**
     * Delete a replenishment request
     */
    async delete(id: number): Promise<ApiResponse> {
        const response: AxiosResponse<ApiResponse> = await axios.delete(`${BASE_URL}/${id}`);
        return response.data;
    },

    /**
     * Accept a replenishment request
     */
    async accept(id: number, data?: AcceptReplenishmentRequestPayload): Promise<ReplenishmentRequest> {
        const response: AxiosResponse<ReplenishmentRequest> = await axios.post(
            `${BASE_URL}/${id}/accept`,
            data || {}
        );
        return response.data;
    },

    /**
     * Reject a replenishment request
     */
    async reject(id: number, data: RejectReplenishmentRequestPayload): Promise<ReplenishmentRequest> {
        const response: AxiosResponse<ReplenishmentRequest> = await axios.post(
            `${BASE_URL}/${id}/reject`,
            data
        );
        return response.data;
    },

    /**
     * Request additional details for a replenishment request
     */
    async requestDetails(id: number, data: RequestReplenishmentDetailsPayload): Promise<ReplenishmentRequest> {
        const response: AxiosResponse<ReplenishmentRequest> = await axios.post(
            `${BASE_URL}/${id}/request-details`,
            data
        );
        return response.data;
    },
};

export default replenishmentRequestApi;
