import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import type {
  ManagerOrderFilters,
  ManagerOrderListResponse,
  ManagerRepairFilters,
  ManagerRepairListResponse,
} from "./useManagerApi";

const ownerApiHeaders = (): HeadersInit => ({
  Accept: "application/json",
  "Content-Type": "application/json",
  "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "",
});

const ownerQuery = (filters: Record<string, unknown>): string => {
  const query = new URLSearchParams();

  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") {
      query.set(key, String(value));
    }
  });

  const serialized = query.toString();
  return serialized ? `?${serialized}` : "";
};

const fetchOwnerProjection = async <T>(url: string, message: string): Promise<T> => {
  const response = await fetch(url, {
    method: "GET",
    headers: ownerApiHeaders(),
    credentials: "include",
  });
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const error = new Error(payload.error || payload.message || message);
    Object.assign(error, { status: response.status, code: payload.code });
    throw error;
  }

  return payload as T;
};

export function useShopOwnerOrders(
  filters: ManagerOrderFilters = {},
): UseQueryResult<ManagerOrderListResponse, Error> {
  return useQuery({
    queryKey: ["shop-owner-operations-orders", filters],
    queryFn: () => fetchOwnerProjection<ManagerOrderListResponse>(
      `/api/shop-owner/erp/operations/orders${ownerQuery(filters)}`,
      "Failed to fetch job orders",
    ),
    refetchInterval: 30000,
    staleTime: 20000,
    retry: 2,
  });
}

export function useShopOwnerRepairJobs(
  filters: ManagerRepairFilters = {},
): UseQueryResult<ManagerRepairListResponse, Error> {
  return useQuery({
    queryKey: ["shop-owner-operations-repair-jobs", filters],
    queryFn: () => fetchOwnerProjection<ManagerRepairListResponse>(
      `/api/shop-owner/erp/operations/repairs${ownerQuery(filters)}`,
      "Failed to fetch repair jobs",
    ),
    refetchInterval: 30000,
    staleTime: 20000,
    retry: 2,
  });
}
