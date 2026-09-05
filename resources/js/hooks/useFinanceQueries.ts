import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useFinanceApi } from './useFinanceApi';

export interface Invoice {
  id: string;
  reference: string;
  customer_id?: string | null;
  customer_name: string;
  customer_email?: string;
  date: string;
  due_date?: string;
  total: number;
  tax_amount: number;
  status: 'draft' | 'sent' | 'paid' | 'void' | 'overdue' | 'posted' | 'cancelled' | 'refunded';
  payment_state?: {
    paid_amount: string;
    remaining_balance: string;
    status: 'unpaid' | 'partially_paid' | 'paid';
    source_owner: 'finance' | 'operational';
    integrity_warnings: string[];
  };
  notes?: string;
  items?: InvoiceItem[];
  deleted_at?: string | null;
}

export interface InvoiceItem {
  id?: string;
  invoice_id?: string;
  description: string;
  quantity: number;
  unit_price: number;
  tax_rate: number;
  amount: number;
}

export interface Expense {
  id: string;
  reference: string;
  date: string;
  due_date?: string | null;
  vendor?: string | null;
  category: string;
  amount: number;
  status: 'draft' | 'submitted' | 'approved' | 'rejected' | 'posted';
  description?: string;
  receipt_url?: string;
  approval_status?: string;
  approved_by?: string;
  approved_at?: string;
  settlement_state?: {
    approval_status: string;
    paid_amount: string;
    outstanding_balance: string;
    status: 'unpaid' | 'partially_paid' | 'paid';
    integrity_warnings: string[];
    settlements: Array<{
      id: number;
      entry_type: 'settlement' | 'reversal';
      amount: string;
      payment_method: string;
      reference?: string | null;
      paid_at?: string | null;
      source: string;
      source_reference?: string | null;
      reverses_settlement_id?: number | null;
      reversal_reason?: string | null;
    }>;
  };
}

type ExpenseApprovalInput = {
  expenseId: string;
  approvalNotes?: string;
};

export const queryKeys = {
  invoices: ['finance', 'invoices'] as const,
  invoice: (id: string) => ['finance', 'invoices', id] as const,
  expenses: ['finance', 'expenses'] as const,
  expense: (id: string) => ['finance', 'expenses', id] as const,
  taxRates: ['finance', 'tax-rates'] as const,
  approvals: {
    pending: ['finance', 'approvals', 'pending'] as const,
    history: ['finance', 'approvals', 'history'] as const,
  },
};

export function useInvoices(filters?: { archived?: boolean }) {
  const api = useFinanceApi();

  return useQuery({
    queryKey: filters ? [...queryKeys.invoices, filters] : queryKeys.invoices,
    queryFn: async () => {
      const params = new URLSearchParams();
      if (filters?.archived !== undefined) {
        params.append('archived', filters.archived ? '1' : '0');
      }
      const query = params.toString();
      const response = await api.get(query ? `/api/finance/invoices?${query}` : '/api/finance/invoices');
      if (!response.ok) throw new Error(response.error || 'Failed to load invoices');
      const data = response.data?.data || response.data;
      return (Array.isArray(data) ? data : []) as Invoice[];
    },
  });
}

export function useInvoice(invoiceId: string | null, enabled = true) {
  const api = useFinanceApi();

  return useQuery({
    queryKey: invoiceId ? queryKeys.invoice(invoiceId) : ['finance', 'invoices', 'null'],
    queryFn: async () => {
      if (!invoiceId) return null;
      const response = await api.get(`/api/finance/invoices/${invoiceId}`);
      if (!response.ok) throw new Error(response.error || 'Failed to load invoice');
      return response.data as Invoice;
    },
    enabled: enabled && !!invoiceId,
  });
}

export function useCreateInvoice() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: Partial<Invoice>) => {
      const response = await api.post('/api/finance/invoices', data);
      if (!response.ok) throw new Error(response.error || 'Failed to create invoice');
      return response.data?.data || response.data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.invoices }),
  });
}

export function useExpenses(filters?: {
  status?: string;
  category?: string;
  vendor?: string;
  dateFrom?: string;
  dateTo?: string;
  search?: string;
  sort?: string;
  archived?: boolean;
}) {
  const api = useFinanceApi();

  return useQuery({
    queryKey: filters ? [...queryKeys.expenses, filters] : queryKeys.expenses,
    queryFn: async () => {
      const params = new URLSearchParams();
      if (filters?.status) params.append('filter[status]', filters.status);
      if (filters?.category) params.append('filter[category]', filters.category);
      if (filters?.vendor) params.append('filter[vendor]', filters.vendor);
      if (filters?.dateFrom) params.append('filter[date_from]', filters.dateFrom);
      if (filters?.dateTo) params.append('filter[date_to]', filters.dateTo);
      if (filters?.search) params.append('filter[search_all]', filters.search);
      if (filters?.sort) params.append('sort', filters.sort);
      if (filters?.archived !== undefined) params.append('archived', filters.archived ? '1' : '0');
      const query = params.toString();
      const response = await api.get(query ? `/api/finance/expenses?${query}` : '/api/finance/expenses');
      if (!response.ok) throw new Error(response.error || 'Failed to load expenses');
      const data = response.data?.data || response.data;
      return (Array.isArray(data) ? data : []) as Expense[];
    },
  });
}

export function useCreateExpense() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: Partial<Expense>) => {
      const response = await api.post('/api/finance/expenses', data);
      if (!response.ok) throw new Error(response.error || 'Failed to create expense');
      return response.data?.data || response.data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.expenses }),
  });
}

export function useApproveExpense() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ expenseId, approvalNotes }: ExpenseApprovalInput) => {
      const response = await api.post(`/api/finance/expenses/${expenseId}/approve`, {
        approval_notes: approvalNotes || undefined,
      });
      if (!response.ok) throw new Error(response.error || 'Failed to approve expense');
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.expenses });
      queryClient.invalidateQueries({ queryKey: queryKeys.approvals.pending });
    },
  });
}

export function useRejectExpense() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ expenseId, approvalNotes }: ExpenseApprovalInput) => {
      const response = await api.post(`/api/finance/expenses/${expenseId}/reject`, {
        approval_notes: approvalNotes,
      });
      if (!response.ok) throw new Error(response.error || 'Failed to reject expense');
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.expenses });
      queryClient.invalidateQueries({ queryKey: queryKeys.approvals.pending });
    },
  });
}

export function useTaxRates() {
  const api = useFinanceApi();

  return useQuery({
    queryKey: queryKeys.taxRates,
    queryFn: async () => {
      const response = await api.get('/api/finance/tax-rates');
      if (!response.ok) throw new Error(response.error || 'Failed to load tax rates');
      const data = response.data?.data || response.data;
      return Array.isArray(data) ? data : [];
    },
  });
}
