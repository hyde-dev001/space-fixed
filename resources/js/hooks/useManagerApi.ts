import { useQuery, UseQueryResult } from '@tanstack/react-query';

/**
 * Manager Dashboard Stats Interface
 */
interface ManagerDashboardStats {
  totalSales: number;
  salesChange: number;
  totalRepairs: number;
  pendingJobOrders: number;
  dateRange?: {
    key: string;
    label: string;
    start: string;
    end: string;
    previous_start: string;
    previous_end: string;
    timezone: string;
  };
  kpiBreakdown?: {
    retail: {
      completed_orders: number;
      pending_orders: number;
      period_revenue: number;
    };
    repair: {
      completed_jobs: number;
      pending_jobs: number;
      closed_rejected: number;
    };
    combined: {
      completed_work_items_in_period: number;
      pending_work_queue: number;
    };
  };
  kpiSemantics?: {
    totalSales: string;
    totalRepairs: string;
    pendingJobOrders: string;
  };
  businessCapabilities?: {
    businessType: string;
    canRetail: boolean;
    canRepair: boolean;
  };
  activeStaff: number;
  pendingApprovals: number;
  monthlyRevenue: Array<{
    month: string;
    revenue: number;
  }>;
  approvalSummary?: {
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
  recentActivities?: Array<{
    id: number;
    user_name: string;
    action: string;
    entity_type: string;
    entity_id: number;
    description: string;
    timestamp: string;
    time_ago: string;
  }>;
  lastUpdated?: string;
  snapshot: {
    captured_at: string;
    range: ManagerDashboardRange;
  };
  current_state: ManagerDashboardCurrentState;
  period_metrics: ManagerDashboardPeriodMetrics;
  signals: ManagerDashboardSignal[];
  freshness: {
    captured_at: string;
    stale_after_seconds: number;
  };
}

interface ManagerDashboardRange {
  key: string;
  label: string;
  start: string;
  end: string;
  previous_start: string;
  previous_end: string;
  comparison: {
    start: string;
    end: string;
  };
  timezone: string;
}

interface ManagerDashboardCurrentState {
  job_orders: {
    open: number;
    pending_unassigned: number;
    reassignment_required: number;
  };
  repair_jobs: {
    active: number;
    reassignment_required: number;
    pending_manager_review: number;
  };
  approvals: {
    leave: number;
    suspension: number;
    repair_review: number;
    total: number;
  };
  staff: {
    active: number;
    unavailable_with_active_work: number;
  };
  inventory: ManagerInventoryMetrics;
}

interface ManagerDashboardPeriodMetrics {
  range: ManagerDashboardRange;
  orders: {
    received: number;
    completed: number;
  };
  repairs: {
    received: number;
    completed: number;
    rejected: number;
  };
  revenue: {
    current: number;
    previous: number;
    trend: ManagerDashboardTrend;
  };
  trends: {
    orders: ManagerDashboardTrend;
    repairs: ManagerDashboardTrend;
  };
}

interface ManagerDashboardTrend {
  percent: number;
  previous: number;
  baseline_available: boolean;
  direction: 'increase' | 'decrease' | 'flat';
}

interface ManagerDashboardSignal {
  id: string;
  type: string;
  reference: string | null;
  status: string;
  severity: 'critical' | 'warning' | 'info' | string;
  age_days: number;
  created_at: string;
  responsible: {
    type: string;
    label: string;
  };
  waiting_on: {
    type: string;
    label: string;
  };
  next_action: string;
  href: string;
}

interface ManagerInventoryItem {
  id: number;
  source_type?: string;
  source_id?: number;
  name: string;
  sku: string;
  category: string;
  quantity: number;
  price: number;
  status: 'In Stock' | 'Low Stock' | 'Out of Stock' | string;
  image: string | null;
  last_updated: string | null;
}

interface ManagerInventoryMetrics {
  total_items: number;
  total_quantity: number;
  low_stock_count: number;
  out_of_stock_count: number;
}

interface ManagerInventoryOverviewResponse {
  items: {
    data: ManagerInventoryItem[];
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
  };
  metrics: ManagerInventoryMetrics;
  categories: string[];
  snapshot: {
    captured_at: string;
    scope: string;
  };
  last_updated_at: string;
}

interface ManagerInventoryOverviewFilters {
  search?: string;
  category?: string;
  status?: string;
  page?: number;
  per_page?: number;
}

interface ManagerOrder {
  id: number;
  order_number: string;
  customer_name: string;
  status: string;
  assigned_staff: {
    id: number;
    name: string;
    status: string;
  } | null;
  age_minutes: number;
  overdue: boolean;
  lock_state: 'locked' | 'claimable' | string;
  assignment_state: 'assigned' | 'unassigned' | 'reassignment_required' | string;
  reassignment_reason_code: string | null;
  reassignment_reason_label: string | null;
  next_action: string;
  created_at: string | null;
  updated_at: string | null;
}

interface ManagerOrderReplacement {
  id: number;
  name: string;
  email: string;
  workload: number;
}

interface ManagerOrderListResponse {
  data: {
    data: ManagerOrder[];
    current_page: number;
    from: number | null;
    last_page: number;
    next_page_url: string | null;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
  };
  last_updated_at: string;
}

interface ManagerOrderFilters {
  status?: string;
  assignment_state?: string;
  handler_id?: string;
  date_from?: string;
  date_to?: string;
  overdue?: boolean;
  page?: number;
  per_page?: number;
}

interface ManagerRepairJob {
  id: number;
  request_id: string;
  customer_name: string;
  shoe_type: string | null;
  brand: string | null;
  status: string;
  display_status: string;
  assigned_repairer: {
    id: number;
    name: string;
    status: string;
  } | null;
  repairer_workload: number;
  age_minutes: number;
  overdue: boolean;
  assignment_state: 'assigned' | 'awaiting_assignment' | 'reassignment_required' | 'pending_manager_review' | string;
  review_state: 'none' | 'pending_manager_review' | 'pending_owner_review' | string;
  rejection_reason: string | null;
  rejection_reason_category: string | null;
  reassignment_reason_code: string | null;
  reassignment_reason_label: string | null;
  requires_owner_approval: boolean;
  next_action: string;
  created_at: string | null;
  updated_at: string | null;
}

interface ManagerRepairerOption {
  id: number;
  name: string;
  email: string;
  workload: number;
}

interface ManagerRepairListResponse {
  data: {
    data: ManagerRepairJob[];
    current_page: number;
    from: number | null;
    last_page: number;
    next_page_url: string | null;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
  };
  last_updated_at: string;
}

interface ManagerRepairFilters {
  status?: string;
  assignment_state?: string;
  repairer_id?: string;
  review_pending?: boolean;
  search?: string;
  date_from?: string;
  date_to?: string;
  overdue?: boolean;
  page?: number;
  per_page?: number;
}

/**
 * Shop-scoped staff workload row.
 */
interface ManagerStaffWorkload {
  id: number;
  name: string;
  email: string;
  position: string;
  role: string;
  status: string;
  availability_state: string;
  active_orders: number;
  active_repairs: number;
  overdue_work: number;
  period_orders: number;
  period_completed_orders: number;
  period_repairs: number;
  period_completed_repairs: number;
  total_active_work: number;
  capacity: {
    active_work: number;
    limit: number | null;
    utilization_percent: number | null;
    state: string;
  };
  requires_order_reassignment: boolean;
  requires_repair_reassignment: boolean;
  reassignment_reason: string | null;
  next_action: string;
  last_updated_at: string | null;
  links: {
    orders: string;
    repairs: string;
  };
  period: {
    start: string;
    end: string;
  };
}

interface ManagerStaffWorkloadResponse {
  data: {
    data: ManagerStaffWorkload[];
    current_page: number;
    from: number | null;
    last_page: number;
    next_page_url: string | null;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
  };
  business_capabilities: {
    business_type: string;
    can_retail: boolean;
    can_repair: boolean;
  };
  period: {
    start: string;
    end: string;
  };
  last_updated_at: string;
}

interface ManagerStaffWorkloadFilters {
  search?: string;
  role?: string;
  status?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

interface ManagerLeaveRequest {
  id: number;
  employee: {
    id: number | null;
    name: string;
    email: string;
    position: string;
    department: string;
  };
  leave_type: string;
  leave_type_label: string;
  start_date: string;
  end_date: string;
  no_of_days: number;
  reason: string;
  status: string;
  approval_stage: string;
  created_at: string | null;
  age_days: number;
  overdue: boolean;
  sla: {
    configured: boolean;
    minutes: number | null;
  };
  coverage_status: string;
  next_action: string;
  approved_by: number | null;
  approval_date: string | null;
  rejection_reason: string | null;
  approver_comments: string | null;
  history: Array<{
    actor_id: number;
    action: string;
    at: string | null;
    reason: string | null;
  }>;
}

interface ManagerLeaveApprovalResponse {
  data: ManagerLeaveRequest[];
  current_page: number;
  from: number | null;
  last_page: number;
  next_page_url: string | null;
  per_page: number;
  prev_page_url: string | null;
  to: number | null;
  total: number;
}

interface ManagerLeaveApprovalFilters {
  search?: string;
  status?: string;
  leave_type?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

interface ManagerSuspensionRequest {
  id: number;
  employee_id: number;
  name: string;
  email: string | null;
  position: string | null;
  reason: string;
  evidence: string | null;
  status: string;
  workflow_status: 'pending_manager' | 'pending_owner' | 'approved' | 'rejected_manager' | 'rejected_owner';
  approval_stage: 'manager' | 'owner' | 'complete';
  requested_at: string | null;
  requestedAt?: string | null;
  requested_by: string | null;
  manager_status: string | null;
  manager_note: string | null;
  manager_name: string | null;
  owner_status: string | null;
  owner_note: string | null;
  age_days: number;
  overdue: boolean;
  sla: {
    configured: boolean;
    minutes: number | null;
  };
  next_action: string;
  previous_decisions: Array<{
    stage: 'manager' | 'owner';
    status: string | null;
    actor_id: number | null;
    at: string | null;
    reason: string | null;
  }>;
}

interface ManagerSuspensionMetrics {
  pending: number;
  awaiting_owner: number;
  approved: number;
  rejected: number;
  total: number;
}

interface ManagerSuspensionApprovalResponse {
  data: {
    data: ManagerSuspensionRequest[];
    current_page: number;
    from: number | null;
    last_page: number;
    next_page_url: string | null;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
  };
  metrics: ManagerSuspensionMetrics;
}

interface ManagerSuspensionApprovalFilters {
  search?: string;
  status?: string;
  page?: number;
  per_page?: number;
}

/**
 * Legacy staff performance shape retained for existing consumers.
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
 * Fetch manager dashboard statistics
 */
async function fetchDashboardStats(range = 'last_30_days'): Promise<ManagerDashboardStats> {
  const query = new URLSearchParams({ range });
  const response = await fetch(`/api/manager/dashboard/stats?${query.toString()}`, {
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

async function fetchManagerInventoryOverview(
  filters: ManagerInventoryOverviewFilters = {},
): Promise<ManagerInventoryOverviewResponse> {
  const response = await fetch(`/api/manager/inventory-overview${managerWorkloadQuery(filters)}`, {
    method: 'GET',
    headers: managerApiHeaders(),
    credentials: 'include',
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.error || payload.message || 'Failed to fetch inventory overview');
    Object.assign(error, { status: response.status, code: payload.code });
    throw error;
  }

  return payload;
}

async function fetchManagerOrders(
  filters: ManagerOrderFilters = {},
): Promise<ManagerOrderListResponse> {
  const response = await fetch(`/api/manager/orders${managerWorkloadQuery(filters)}`, {
    method: 'GET',
    headers: managerApiHeaders(),
    credentials: 'include',
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.error || payload.message || 'Failed to fetch job orders');
    Object.assign(error, { status: response.status, code: payload.code });
    throw error;
  }

  return payload;
}

async function fetchManagerRepairJobs(
  filters: ManagerRepairFilters = {},
): Promise<ManagerRepairListResponse> {
  const response = await fetch(`/api/manager/repairs${managerWorkloadQuery(filters)}`, {
    method: 'GET',
    headers: managerApiHeaders(),
    credentials: 'include',
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.error || payload.message || 'Failed to fetch repair jobs');
    Object.assign(error, { status: response.status, code: payload.code });
    throw error;
  }

  return payload;
}

function managerApiHeaders(): HeadersInit {
  return {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
  };
}

function managerWorkloadQuery(filters: ManagerStaffWorkloadFilters): string {
  const query = new URLSearchParams();

  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      query.set(key, String(value));
    }
  });

  const serialized = query.toString();
  return serialized ? `?${serialized}` : '';
}

/**
 * Fetch the canonical staff workload response.
 */
async function fetchStaffWorkload(
  filters: ManagerStaffWorkloadFilters = {},
): Promise<ManagerStaffWorkloadResponse> {
  const response = await fetch(`/api/manager/staff-workload${managerWorkloadQuery(filters)}`, {
    method: 'GET',
    headers: managerApiHeaders(),
    credentials: 'include',
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new Error(errorData.error || errorData.message || 'Failed to fetch staff workload');
  }

  return response.json();
}

async function fetchManagerLeaveApprovals(
  filters: ManagerLeaveApprovalFilters = {},
): Promise<ManagerLeaveApprovalResponse> {
  const response = await fetch(`/api/hr/leave-requests${managerWorkloadQuery(filters)}`, {
    method: 'GET',
    headers: managerApiHeaders(),
    credentials: 'include',
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new Error(errorData.error || errorData.message || 'Failed to fetch leave approvals');
  }

  return response.json();
}

async function fetchManagerSuspensionApprovals(
  filters: ManagerSuspensionApprovalFilters = {},
): Promise<ManagerSuspensionApprovalResponse> {
  const response = await fetch(`/api/manager/suspension-requests${managerWorkloadQuery(filters)}`, {
    method: 'GET',
    headers: managerApiHeaders(),
    credentials: 'include',
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new Error(errorData.error || errorData.message || 'Failed to fetch suspension approvals');
  }

  return response.json();
}

export async function decideManagerLeaveRequest(
  id: number,
  decision: 'approve' | 'reject',
  reason?: string,
): Promise<{ leaveRequest: ManagerLeaveRequest }> {
  const response = await fetch(`/api/hr/leave-requests/${id}/${decision}`, {
    method: 'POST',
    headers: managerApiHeaders(),
    credentials: 'include',
    body: JSON.stringify(reason ? { reason } : {}),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.error || payload.message || 'Failed to update leave request');
    Object.assign(error, { status: response.status, code: payload.code });
    throw error;
  }

  return payload;
}

export async function decideManagerSuspensionRequest(
  id: number,
  decision: 'approve' | 'reject',
  reason?: string,
): Promise<{ request: ManagerSuspensionRequest }> {
  const response = await fetch(`/api/manager/suspension-requests/${id}/review`, {
    method: 'POST',
    headers: managerApiHeaders(),
    credentials: 'include',
    body: JSON.stringify({
      action: decision,
      ...(reason ? { note: reason } : {}),
    }),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.error || payload.message || 'Failed to update suspension request');
    Object.assign(error, { status: response.status, code: payload.code });
    throw error;
  }

  return payload;
}

/**
 * Fetch staff performance metrics for legacy consumers.
 */
async function fetchStaffPerformance(): Promise<StaffPerformance[]> {
  const response = await fetchStaffWorkload({ per_page: 100 });

  return response.data.data.map((staff) => ({
    id: staff.id,
    name: staff.name,
    email: staff.email,
    position: staff.position,
    total_jobs: staff.period_orders + staff.period_repairs,
    completed_jobs: staff.period_completed_orders + staff.period_completed_repairs,
    pending_jobs: staff.active_orders + staff.active_repairs,
    total_revenue: 0,
  }));
}

/**
 * Hook to fetch manager dashboard stats with auto-refresh
 * Refreshes every 30 seconds to keep data current
 */
export function useManagerStats(range = 'last_30_days'): UseQueryResult<ManagerDashboardStats, Error> {
  return useQuery({
    queryKey: ['manager-stats', range],
    queryFn: () => fetchDashboardStats(range),
    refetchInterval: 30000, // Refresh every 30 seconds
    staleTime: 20000, // Consider data stale after 20 seconds
    retry: 2,
    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 10000),
  });
}

/**
 * Hook for the Manager's shop-scoped inventory overview.
 */
export function useManagerInventoryOverview(
  filters: ManagerInventoryOverviewFilters = {},
  enabled = true,
): UseQueryResult<ManagerInventoryOverviewResponse, Error> {
  return useQuery({
    queryKey: ['manager-inventory-overview', filters],
    queryFn: () => fetchManagerInventoryOverview(filters),
    enabled,
    staleTime: 30000,
    retry: 2,
  });
}

/**
 * Hook for the Manager's shop-scoped Job Orders queue.
 */
export function useManagerOrders(
  filters: ManagerOrderFilters = {},
): UseQueryResult<ManagerOrderListResponse, Error> {
  return useQuery({
    queryKey: ['manager-orders', filters],
    queryFn: () => fetchManagerOrders(filters),
    refetchInterval: 30000,
    staleTime: 20000,
    retry: 2,
  });
}

/**
 * Hook for the Manager's complete repair workload queue.
 */
export function useManagerRepairJobs(
  filters: ManagerRepairFilters = {},
): UseQueryResult<ManagerRepairListResponse, Error> {
  return useQuery({
    queryKey: ['manager-repair-jobs', filters],
    queryFn: () => fetchManagerRepairJobs(filters),
    refetchInterval: 30000,
    staleTime: 20000,
    retry: 2,
  });
}

export async function fetchManagerOrderReplacements(
  id: number,
): Promise<ManagerOrderReplacement[]> {
  const response = await fetch(`/api/manager/orders/${id}/eligible-replacements`, {
    method: 'GET',
    headers: managerApiHeaders(),
    credentials: 'include',
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(payload.error || payload.message || 'Failed to load eligible staff');
  }

  return payload.data ?? [];
}

export async function reassignManagerOrder(
  id: number,
  replacementStaffId: number,
  reason: string,
): Promise<{ success: boolean; message: string; data: ManagerOrder }> {
  const response = await fetch(`/api/manager/orders/${id}/reassign`, {
    method: 'POST',
    headers: managerApiHeaders(),
    credentials: 'include',
    body: JSON.stringify({ replacement_staff_id: replacementStaffId, reason }),
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.error || payload.message || 'Order reassignment was not completed');
    Object.assign(error, { status: response.status, code: payload.code, errors: payload.errors });
    throw error;
  }

  return payload;
}

export async function fetchManagerRepairerOptions(
  id: number,
): Promise<ManagerRepairerOption[]> {
  const response = await fetch(`/api/manager/repairs/${id}/eligible-repairers`, {
    method: 'GET',
    headers: managerApiHeaders(),
    credentials: 'include',
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(payload.error || payload.message || 'Failed to load eligible repairers');
  }

  return payload.data ?? [];
}

export async function reassignManagerRepair(
  id: number,
  replacementRepairerId: number,
  reason: string,
): Promise<{ success: boolean; message: string; data: ManagerRepairJob }> {
  return postManagerRepairDecision(`/api/manager/repairs/${id}/reassign`, {
    replacement_repairer_id: replacementRepairerId,
    reason,
  });
}

export async function finalRejectManagerRepair(
  id: number,
  reason: string,
): Promise<{ success: boolean; message: string; data: ManagerRepairJob }> {
  return postManagerRepairDecision(`/api/manager/repairs/${id}/final-reject`, { reason });
}

export async function forwardManagerRepairToOwner(
  id: number,
  reason: string,
): Promise<{ success: boolean; message: string; data: ManagerRepairJob }> {
  return postManagerRepairDecision(`/api/manager/repairs/${id}/forward-to-owner`, { reason });
}

async function postManagerRepairDecision(
  url: string,
  body: Record<string, string | number>,
): Promise<{ success: boolean; message: string; data: ManagerRepairJob }> {
  const response = await fetch(url, {
    method: 'POST',
    headers: managerApiHeaders(),
    credentials: 'include',
    body: JSON.stringify(body),
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.error || payload.message || 'Repair decision was not completed');
    Object.assign(error, { status: response.status, code: payload.code, errors: payload.errors });
    throw error;
  }

  return payload;
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
 * Hook for the Manager Staff & Workload page.
 */
export function useManagerStaffWorkload(
  filters: ManagerStaffWorkloadFilters = {},
): UseQueryResult<ManagerStaffWorkloadResponse, Error> {
  return useQuery({
    queryKey: ['manager-staff-workload', filters],
    queryFn: () => fetchStaffWorkload(filters),
    refetchInterval: 60000,
    staleTime: 30000,
    retry: 2,
  });
}

/**
 * Hook for the Manager leave approval queue.
 */
export function useManagerLeaveApprovals(
  filters: ManagerLeaveApprovalFilters = {},
): UseQueryResult<ManagerLeaveApprovalResponse, Error> {
  return useQuery({
    queryKey: ['manager-leave-approvals', filters],
    queryFn: () => fetchManagerLeaveApprovals(filters),
    refetchInterval: 60000,
    staleTime: 30000,
    retry: 2,
  });
}

/**
 * Hook for the Manager suspension approval queue.
 */
export function useManagerSuspensionApprovals(
  filters: ManagerSuspensionApprovalFilters = {},
): UseQueryResult<ManagerSuspensionApprovalResponse, Error> {
  return useQuery({
    queryKey: ['manager-suspension-approvals', filters],
    queryFn: () => fetchManagerSuspensionApprovals(filters),
    refetchInterval: 60000,
    staleTime: 30000,
    retry: 2,
  });
}

/**
 * Export types for use in components
 */
export type {
  ManagerDashboardStats,
  ManagerDashboardRange,
  ManagerDashboardCurrentState,
  ManagerDashboardPeriodMetrics,
  ManagerDashboardTrend,
  ManagerDashboardSignal,
  ManagerInventoryItem,
  ManagerInventoryMetrics,
  ManagerInventoryOverviewResponse,
  ManagerInventoryOverviewFilters,
  ManagerOrder,
  ManagerOrderReplacement,
  ManagerOrderListResponse,
  ManagerOrderFilters,
  ManagerRepairJob,
  ManagerRepairerOption,
  ManagerRepairListResponse,
  ManagerRepairFilters,
  ManagerStaffWorkload,
  ManagerStaffWorkloadFilters,
  ManagerStaffWorkloadResponse,
  ManagerLeaveRequest,
  ManagerLeaveApprovalResponse,
  ManagerLeaveApprovalFilters,
  ManagerSuspensionRequest,
  ManagerSuspensionMetrics,
  ManagerSuspensionApprovalResponse,
  ManagerSuspensionApprovalFilters,
  StaffPerformance,
};
