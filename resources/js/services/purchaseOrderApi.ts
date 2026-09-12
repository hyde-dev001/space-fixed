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
    PurchaseOrderReceipt,
    CreatePurchaseOrderReceiptPayload,
    CreatePostPaymentIssuePayload,
    SupplierAdjustment,
} from '@/types/procurement';

const BASE_URL = '/api/erp/procurement/purchase-orders';
const unwrap = <T>(payload: { data: T } | T): T => (payload as { data?: T }).data ?? payload as T;

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
        return unwrap(response.data);
    },

    /**
     * Create a new purchase order from approved PR
     */
    async create(data: CreatePurchaseOrderPayload): Promise<PurchaseOrder> {
        const response: AxiosResponse<PurchaseOrder> = await axios.post(BASE_URL, data);
        return unwrap(response.data);
    },

    /**
     * Update an existing purchase order
     */
    async update(id: number, data: UpdatePurchaseOrderPayload): Promise<PurchaseOrder> {
        const response: AxiosResponse<PurchaseOrder> = await axios.put(`${BASE_URL}/${id}`, data);
        return unwrap(response.data);
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
        return unwrap(response.data);
    },

    /**
     * Send purchase order to supplier
     */
    async sendToSupplier(id: number): Promise<PurchaseOrder> {
        const response: AxiosResponse<PurchaseOrder> = await axios.post(
            `${BASE_URL}/${id}/send-to-supplier`
        );
        return unwrap(response.data);
    },

    /**
     * Cancel a purchase order
     */
    async cancel(id: number, data: CancelPurchaseOrderPayload): Promise<PurchaseOrder> {
        const response: AxiosResponse<PurchaseOrder> = await axios.post(
            `${BASE_URL}/${id}/cancel`,
            data
        );
        return unwrap(response.data);
    },

    async getReceipts(id: number): Promise<PurchaseOrderReceipt[]> {
        const response = await axios.get(`${BASE_URL}/${id}/receipts`);
        return unwrap(response.data);
    },

    async receive(id: number, data: CreatePurchaseOrderReceiptPayload): Promise<PurchaseOrderReceipt> {
        const hasEvidence = data.items.some((item) => (item.defect_evidence?.length ?? 0) > 0);
        const payload = hasEvidence ? new FormData() : data;

        if (payload instanceof FormData) {
            payload.append('idempotency_key', data.idempotency_key);
            if (data.received_at) payload.append('received_at', data.received_at);
            if (data.notes) payload.append('notes', data.notes);
            data.items.forEach((item, index) => {
                const prefix = `items[${index}]`;
                payload.append(`${prefix}[purchase_order_item_id]`, String(item.purchase_order_item_id));
                payload.append(`${prefix}[received_quantity]`, String(item.received_quantity));
                payload.append(`${prefix}[defective_quantity]`, String(item.defective_quantity));
                if (item.replacement_for_adjustment_id) {
                    payload.append(`${prefix}[replacement_for_adjustment_id]`, String(item.replacement_for_adjustment_id));
                }
                if (item.reason_category) payload.append(`${prefix}[reason_category]`, item.reason_category);
                if (item.inventory_notes) payload.append(`${prefix}[inventory_notes]`, item.inventory_notes);
                item.size_quantities?.forEach((size, sizeIndex) => {
                    payload.append(`${prefix}[size_quantities][${sizeIndex}][inventory_size_id]`, String(size.inventory_size_id));
                    payload.append(`${prefix}[size_quantities][${sizeIndex}][received_quantity]`, String(size.received_quantity));
                    payload.append(`${prefix}[size_quantities][${sizeIndex}][defective_quantity]`, String(size.defective_quantity));
                });
                item.defect_evidence?.forEach((file) => payload.append(`${prefix}[defect_evidence][]`, file, file.name));
            });
        }

        const response = await axios.post(`${BASE_URL}/${id}/receipts`, payload);
        return unwrap(response.data);
    },

    async voidReceipt(id: number, receiptId: number, reason: string): Promise<PurchaseOrderReceipt> {
        const response = await axios.post(`${BASE_URL}/${id}/receipts/${receiptId}/void`, { reason });
        return unwrap(response.data);
    },

    async getSupplierAdjustments(): Promise<SupplierAdjustment[]> {
        const response = await axios.get('/api/erp/procurement/supplier-adjustments');
        return unwrap(response.data);
    },

    async reportPostPaymentIssue(
        purchaseOrderId: number,
        receiptId: number,
        receiptItemId: number,
        data: CreatePostPaymentIssuePayload,
    ): Promise<SupplierAdjustment> {
        const formData = new FormData();
        formData.append('idempotency_key', data.idempotency_key);
        formData.append('reported_quantity', String(data.reported_quantity));
        formData.append('reason_category', data.reason_category);
        formData.append('inventory_notes', data.inventory_notes);
        data.defect_evidence.forEach((file) => formData.append('defect_evidence[]', file, file.name));
        const response = await axios.post(
            `${BASE_URL}/${purchaseOrderId}/receipts/${receiptId}/items/${receiptItemId}/post-payment-issues`,
            formData,
        );
        return unwrap(response.data);
    },

    async submitSupplierRefundProof(
        adjustmentId: number,
        data: {
            expected_refund_amount: string;
            supplier_reported_refund_amount?: string;
            supplier_reported_refund_reference?: string;
            supplier_reported_refund_date?: string;
            procurement_notes?: string;
            supplier_refund_proof?: File;
        },
    ): Promise<SupplierAdjustment> {
        const formData = new FormData();
        formData.append('expected_refund_amount', data.expected_refund_amount);
        if (data.supplier_reported_refund_amount) formData.append('supplier_reported_refund_amount', data.supplier_reported_refund_amount);
        if (data.supplier_reported_refund_reference) formData.append('supplier_reported_refund_reference', data.supplier_reported_refund_reference);
        if (data.supplier_reported_refund_date) formData.append('supplier_reported_refund_date', data.supplier_reported_refund_date);
        if (data.procurement_notes) formData.append('procurement_notes', data.procurement_notes);
        if (data.supplier_refund_proof) formData.append('supplier_refund_proof', data.supplier_refund_proof, data.supplier_refund_proof.name);

        const response = await axios.post(`/api/erp/procurement/supplier-adjustments/${adjustmentId}/supplier-refund-proof`, formData);
        return unwrap(response.data);
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
