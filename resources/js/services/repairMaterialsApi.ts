import axios, { AxiosResponse } from "axios";

export interface RepairMaterialInventoryItem {
  id: number;
  name: string;
  sku: string;
  category: string;
  available_quantity: number;
  unit?: string | null;
  reorder_level?: number | null;
  price?: number | string | null;
  images?: Array<{
    id: number;
    inventory_item_id: number;
    image_path?: string | null;
    is_thumbnail?: boolean;
  }>;
}

export interface RepairMaterialUsage {
  id: number;
  repair_request_id: number;
  inventory_item_id: number;
  quantity_used: number;
  unit_price?: number;
  line_total?: number;
  notes?: string | null;
  used_by?: number | null;
  used_at: string;
  stock_movement_id?: number | null;
  inventory_item?: RepairMaterialInventoryItem;
  user?: {
    id: number;
    name?: string | null;
  };
}

export interface RepairMaterialRequest {
  id: number;
  request_number: string;
  inventory_item_id: number;
  repair_request_id?: number | null;
  product_name: string;
  sku_code: string;
  quantity_needed: number;
  requested_size?: string | null;
  priority: "high" | "medium" | "low";
  request_source?: "manual" | "repair";
  status: "pending" | "accepted" | "rejected" | "needs_details";
  notes?: string | null;
  requested_date: string;
  requester?: {
    id: number;
    name?: string | null;
  };
  inventory_item?: RepairMaterialInventoryItem;
}

interface RepairStocksOverviewResponse {
  success: boolean;
  data: RepairMaterialInventoryItem[];
  metrics: {
    total_items: number;
    low_stock_count: number;
    out_of_stock_count: number;
  };
}

interface RepairUsageResponse {
  success: boolean;
  message?: string;
  data: {
    repair_id: number;
    repair_status: string;
    usages: RepairMaterialUsage[];
    materials: RepairMaterialInventoryItem[];
    summary?: {
      base_total: number;
      materials_total: number;
      final_total: number;
    };
  };
}

interface MaterialRequestsResponse {
  success: boolean;
  message?: string;
  data: RepairMaterialRequest[];
}

interface RepairUsageMeta {
  stock_status: "ok" | "low_stock" | "out_of_stock";
  remaining_quantity: number;
  reorder_level: number;
  warnings: string[];
  pricing?: {
    base_total: number;
    materials_total: number;
    final_total: number;
  };
  auto_reorder: {
    triggered: boolean;
    request_id?: number;
    request_number?: string;
    quantity_needed?: number;
    existing_request_number?: string;
  };
}

interface GenericResponse<T = unknown, M = Record<string, unknown>> {
  success: boolean;
  message: string;
  data: T;
  meta?: M;
}

const BASE_URL = "/api/repairer";

const repairMaterialsApi = {
  async getStocksOverview(params?: {
    search?: string;
    status?: "In Stock" | "Low Stock" | "Out of Stock";
    category?: string;
  }): Promise<RepairStocksOverviewResponse> {
    const response: AxiosResponse<RepairStocksOverviewResponse> = await axios.get(`${BASE_URL}/materials`, {
      params,
    });
    return response.data;
  },

  async getMyMaterialRequests(params?: {
    status?: string;
    repair_request_id?: number;
  }): Promise<MaterialRequestsResponse> {
    const response: AxiosResponse<MaterialRequestsResponse> = await axios.get(`${BASE_URL}/material-requests`, {
      params,
    });
    return response.data;
  },

  async createMaterialRequest(payload: {
    inventory_item_id: number;
    quantity_needed: number;
    priority: "high" | "medium" | "low";
    requested_size?: string;
    notes?: string;
    repair_request_id?: number;
  }): Promise<GenericResponse<RepairMaterialRequest>> {
    const response: AxiosResponse<GenericResponse<RepairMaterialRequest>> = await axios.post(
      `${BASE_URL}/material-requests`,
      payload
    );
    return response.data;
  },

  async createBulkMaterialRequest(payload: {
    materials: Array<{
      inventory_item_id: number;
      quantity_needed: number;
      priority: "high" | "medium" | "low";
      requested_size?: string;
      notes?: string;
    }>;
    repair_request_id?: number;
    batch_notes?: string;
  }): Promise<GenericResponse<{
    created: RepairMaterialRequest[];
    failed: Array<{ index: number; message: string }>;
    total_created: number;
    total_failed: number;
  }>> {
    const response: AxiosResponse<
      GenericResponse<{
        created: RepairMaterialRequest[];
        failed: Array<{ index: number; message: string }>;
        total_created: number;
        total_failed: number;
      }>
    > = await axios.post(`${BASE_URL}/material-requests/bulk`, payload);
    return response.data;
  },

  async getRepairUsage(repairId: number): Promise<RepairUsageResponse> {
    const response: AxiosResponse<RepairUsageResponse> = await axios.get(`${BASE_URL}/repairs/${repairId}/materials`);
    return response.data;
  },

  async logRepairUsage(
    repairId: number,
    payload: {
      inventory_item_id: number;
      quantity_used: number;
      notes?: string;
    }
  ): Promise<GenericResponse<RepairMaterialUsage, RepairUsageMeta>> {
    const response: AxiosResponse<GenericResponse<RepairMaterialUsage, RepairUsageMeta>> = await axios.post(
      `${BASE_URL}/repairs/${repairId}/materials`,
      payload
    );
    return response.data;
  },

  async removeRepairUsage(repairId: number, usageId: number): Promise<GenericResponse<null>> {
    const response: AxiosResponse<GenericResponse<null>> = await axios.delete(
      `${BASE_URL}/repairs/${repairId}/materials/${usageId}`
    );
    return response.data;
  },
};

export default repairMaterialsApi;
