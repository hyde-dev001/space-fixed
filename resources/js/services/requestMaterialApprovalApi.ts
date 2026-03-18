import axios, { AxiosResponse } from 'axios';
import {
    ApproveStockRequestPayload,
    PaginatedResponse,
    RejectStockRequestPayload,
    RequestStockDetailsPayload,
    StockRequestApproval,
    StockRequestFilters,
} from '@/types/procurement';

const BASE_URL = '/api/erp/inventory/request-material-approvals';

const unwrapStockRequest = (payload: any): StockRequestApproval => {
    if (payload?.stock_request) {
        return payload.stock_request as StockRequestApproval;
    }

    if (payload?.data?.stock_request) {
        return payload.data.stock_request as StockRequestApproval;
    }

    return payload as StockRequestApproval;
};

export const requestMaterialApprovalApi = {
    async getAll(filters?: StockRequestFilters): Promise<PaginatedResponse<StockRequestApproval>> {
        const response: AxiosResponse<PaginatedResponse<StockRequestApproval>> = await axios.get(BASE_URL, {
            params: filters,
        });

        return response.data;
    },

    async approve(id: number, data?: ApproveStockRequestPayload): Promise<StockRequestApproval> {
        const response: AxiosResponse<any> = await axios.post(`${BASE_URL}/${id}/approve`, data || {});
        return unwrapStockRequest(response.data);
    },

    async reject(id: number, data: RejectStockRequestPayload): Promise<StockRequestApproval> {
        const response: AxiosResponse<any> = await axios.post(`${BASE_URL}/${id}/reject`, data);
        return unwrapStockRequest(response.data);
    },

    async requestDetails(id: number, data: RequestStockDetailsPayload): Promise<StockRequestApproval> {
        const response: AxiosResponse<any> = await axios.post(`${BASE_URL}/${id}/request-details`, data);
        return unwrapStockRequest(response.data);
    },
};

export default requestMaterialApprovalApi;