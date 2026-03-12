/**
 * Supplier API Service
 * 
 * Handles all API calls for Supplier management and procurement-specific operations
 */

import axios, { AxiosResponse } from 'axios';
import {
    Supplier,
    SupplierFilters,
    SupplierPerformanceMetrics,
    CreateSupplierPayload,
    UpdateSupplierPayload,
    UpdateSupplierRatingPayload,
    PurchaseOrder,
    PaginatedResponse,
    ApiResponse,
} from '@/types/procurement';

const BASE_URL = '/api/erp/procurement/suppliers';

export const supplierApi = {
    /**
     * Get all suppliers with optional filters
     */
    async getAll(filters?: SupplierFilters): Promise<PaginatedResponse<Supplier>> {
        const response: AxiosResponse<PaginatedResponse<Supplier>> = await axios.get(BASE_URL, {
            params: filters,
        });
        return response.data;
    },

    /**
     * Get a single supplier by ID
     */
    async getById(id: number): Promise<Supplier> {
        const response: AxiosResponse<Supplier> = await axios.get(`${BASE_URL}/${id}`);
        return response.data;
    },

    /**
     * Create a new supplier
     */
    async create(data: CreateSupplierPayload): Promise<Supplier> {
        const response: AxiosResponse<Supplier> = await axios.post(BASE_URL, data);
        return response.data;
    },

    /**
     * Update an existing supplier
     */
    async update(id: number, data: UpdateSupplierPayload): Promise<Supplier> {
        const response: AxiosResponse<Supplier> = await axios.put(`${BASE_URL}/${id}`, data);
        return response.data;
    },

    /**
     * Delete a supplier
     */
    async delete(id: number): Promise<ApiResponse> {
        const response: AxiosResponse<ApiResponse> = await axios.delete(`${BASE_URL}/${id}`);
        return response.data;
    },

    /**
     * Get purchase history for a supplier
     */
    async getPurchaseHistory(id: number): Promise<PurchaseOrder[]> {
        const response: AxiosResponse<PurchaseOrder[]> = await axios.get(
            `${BASE_URL}/${id}/purchase-history`
        );
        return response.data;
    },

    /**
     * Get performance metrics for a supplier
     */
    async getPerformanceMetrics(id: number): Promise<SupplierPerformanceMetrics> {
        const response: AxiosResponse<SupplierPerformanceMetrics> = await axios.get(
            `${BASE_URL}/${id}/performance`
        );
        return response.data;
    },

    /**
     * Update supplier performance rating
     */
    async updateRating(id: number, data: UpdateSupplierRatingPayload): Promise<Supplier> {
        const response: AxiosResponse<Supplier> = await axios.post(
            `${BASE_URL}/${id}/rating`,
            data
        );
        return response.data;
    },
};

export default supplierApi;
