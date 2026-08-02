/**
 * Supplier API Service
 * 
 * Handles all API calls for Supplier management and procurement-specific operations
 */

import axios, { AxiosResponse } from 'axios';
import {
    Supplier,
    SupplierFilters,
    CreateSupplierPayload,
    UpdateSupplierPayload,
    PaginatedResponse,
    ApiResponse,
} from '@/types/procurement';

const BASE_URL = '/api/erp/procurement/suppliers';
const unwrap = <T>(payload: { data: T } | T): T => (payload as { data?: T }).data ?? payload as T;

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
        return unwrap(response.data);
    },

    /**
     * Create a new supplier
     */
    async create(data: CreateSupplierPayload): Promise<Supplier> {
        const response: AxiosResponse<Supplier> = await axios.post(BASE_URL, data);
        return unwrap(response.data);
    },

    /**
     * Update an existing supplier
     */
    async update(id: number, data: UpdateSupplierPayload): Promise<Supplier> {
        const response: AxiosResponse<Supplier> = await axios.put(`${BASE_URL}/${id}`, data);
        return unwrap(response.data);
    },

    /**
     * Archive a supplier
     */
    async delete(id: number): Promise<ApiResponse> {
        const response: AxiosResponse<ApiResponse> = await axios.delete(`${BASE_URL}/${id}`);
        return response.data;
    },

    /**
     * Restore an archived supplier
     */
    async restore(id: number): Promise<ApiResponse> {
        const response: AxiosResponse<ApiResponse> = await axios.post(`${BASE_URL}/${id}/restore`);
        return response.data;
    },

};

export default supplierApi;
