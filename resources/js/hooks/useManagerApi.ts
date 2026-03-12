import { useQuery, UseQueryResult } from '@tanstack/react-query';

/**
 * Manager Dashboard Stats Interface
 */
interface ManagerDashboardStats {
  totalSales: number;
  salesChange: number;
  totalRepairs: number;
  pendingJobOrders: number;
  activeStaff: number;
  pendingApprovals: number;
  monthlyRevenue: Array<{
    month: string;
    revenue: number;
  }>;
  approvalSummary: {
    expenses: {
      count: number;
      total_amount: number;
    };
    leave_requests: {
      count: number;
      details: Array<{
        id: number;
        leave_type: string;
        start_date: string;
        end_date: string;
        no_of_days: number;
        reason: string;
        created_at: string;
        employee_id: number;
        employee_name: string;
        employee_email: string;
        employee_position: string;
        days_pending: number;
      }>;
    };
  };
  recentActivities: Array<{
    id: number;
    user_name: string;
    action: string;
    entity_type: string;
    entity_id: number;
    description: string;
    timestamp: string;
    time_ago: string;
  }>;
  lastUpdated: string;
}

/**
 * Staff Performance Interface
 */
interface StaffPerformance {
  id: number;
  name: string;
  email: string;
  position: string;
  total_jobs: number;
  completed_jobs: number;
  pending_jobs: number;
  total_revenue: number;
}

/**
 * Analytics Interface
 */
interface ManagerAnalytics {
  ordersByStatus: Array<{
    status: string;
    count: number;
  }>;
  topProducts: Array<{
    product: string;
    order_count: number;
    total_revenue: number;
  }>;
  recentActivities: Array<{
    id: string;
    customer: string;
    product: string;
    status: string;
    total: string;
    created_at: string;
  }>;
}

/**
 * Fetch manager dashboard statistics
 */
async function fetchDashboardStats(): Promise<ManagerDashboardStats> {
  const response = await fetch('/api/manager/dashboard/stats', {
    method: 'GET',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
    },
    credentials: 'include',
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new Error(errorData.error || errorData.message || 'Failed to fetch dashboard stats');
  }

  return response.json();
}

/**
 * Fetch staff performance metrics
 */
async function fetchStaffPerformance(): Promise<StaffPerformance[]> {
  const response = await fetch('/api/manager/staff-performance', {
    method: 'GET',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
    },
    credentials: 'include',
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new Error(errorData.error || errorData.message || 'Failed to fetch staff performance');
  }

  return response.json();
}

/**
 * Fetch analytics data
 */
async function fetchAnalytics(): Promise<ManagerAnalytics> {
  const response = await fetch('/api/manager/analytics', {
    method: 'GET',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
    },
    credentials: 'include',
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new Error(errorData.error || errorData.message || 'Failed to fetch analytics');
  }

  return response.json();
}

/**
 * Hook to fetch manager dashboard stats with auto-refresh
 * Refreshes every 30 seconds to keep data current
 */
export function useManagerStats(): UseQueryResult<ManagerDashboardStats, Error> {
  return useQuery({
    queryKey: ['manager-stats'],
    queryFn: fetchDashboardStats,
    refetchInterval: 30000, // Refresh every 30 seconds
    staleTime: 20000, // Consider data stale after 20 seconds
    retry: 2,
    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 10000),
  });
}

/**
 * Hook to fetch staff performance metrics
 */
export function useStaffPerformance(): UseQueryResult<StaffPerformance[], Error> {
  return useQuery({
    queryKey: ['staff-performance'],
    queryFn: fetchStaffPerformance,
    refetchInterval: 60000, // Refresh every minute
    staleTime: 45000,
    retry: 2,
  });
}

/**
 * Hook to fetch analytics data
 */
export function useManagerAnalytics(): UseQueryResult<ManagerAnalytics, Error> {
  return useQuery({
    queryKey: ['manager-analytics'],
    queryFn: fetchAnalytics,
    refetchInterval: 45000, // Refresh every 45 seconds
    staleTime: 30000,
    retry: 2,
  });
}

/**
 * Export types for use in components
 */
export type {
  ManagerDashboardStats,
  StaffPerformance,
  ManagerAnalytics,
};
