import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useFinanceApi } from '../hooks/useFinanceApi';

// Types
export interface Account {
  id: string;
  code: string;
  name: string;
  type: 'Asset' | 'Liability' | 'Equity' | 'Revenue' | 'Expense';
  normal_balance: 'Debit' | 'Credit';
  group: string;
  balance: number | string;
  active: boolean;
  parent_id?: string | null;
  updated_at?: string;
}

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
  status: 'draft' | 'sent' | 'paid' | 'void' | 'overdue';
  journal_entry_id?: string;
  notes?: string;
  items?: InvoiceItem[];
}

export interface InvoiceItem {
  id?: string;
  invoice_id?: string;
  description: string;
  quantity: number;
  unit_price: number;
  tax_rate: number;
  amount: number;
  account_id: string;
}

export interface Expense {
  id: string;
  reference: string;
  date: string;
  vendor: string;
  category: string;
  amount: number;
  status: 'draft' | 'pending' | 'approved' | 'rejected' | 'posted';
  description?: string;
  receipt_url?: string;
  account_id?: string;
  journal_entry_id?: string;
  approval_status?: string;
  approved_by?: string;
  approved_at?: string;
}

export interface JournalEntry {
  id: string;
  reference: string;
  date: string;
  description: string;
  status: 'draft' | 'posted' | 'reversed';
  total_debit: number;
  total_credit: number;
  lines: JournalLine[];
  posted_at?: string;
  reversed_at?: string;
}

export interface JournalLine {
  id?: string;
  journal_entry_id?: string;
  account_id: string;
  account?: Account;
  debit: number;
  credit: number;
  description?: string;
}

// Query Keys
export const queryKeys = {
  accounts: ['finance', 'accounts'] as const,
  accountLedger: (id: string) => ['finance', 'accounts', id, 'ledger'] as const,
  invoices: ['finance', 'invoices'] as const,
  invoice: (id: string) => ['finance', 'invoices', id] as const,
  expenses: ['finance', 'expenses'] as const,
  expense: (id: string) => ['finance', 'expenses', id] as const,
  journalEntries: ['finance', 'journal-entries'] as const,
  journalEntry: (id: string) => ['finance', 'journal-entries', id] as const,
  budgets: ['finance', 'budgets'] as const,
  taxRates: ['finance', 'tax-rates'] as const,
  approvals: {
    pending: ['finance', 'approvals', 'pending'] as const,
    history: ['finance', 'approvals', 'history'] as const,
  },
};

// ============================================================================
// ACCOUNTS HOOKS
// ============================================================================

/**
 * Fetch all accounts with caching
 * This will be shared across all components - fetched once and cached
 */
export function useAccounts() {
  const api = useFinanceApi();
  
  return useQuery({
    queryKey: queryKeys.accounts,
    queryFn: async () => {
      const response = await api.get('/api/finance/session/accounts');
      if (!response.ok) {
        throw new Error(response.error || 'Failed to load accounts');
      }
      const data = response.data?.data || response.data;
      return Array.isArray(data) ? data : [] as Account[];
    },
  });
}

/**
 * Fetch account ledger
 */
export function useAccountLedger(accountId: string | null, enabled = true) {
  const api = useFinanceApi();
  
  return useQuery({
    queryKey: accountId ? queryKeys.accountLedger(accountId) : ['finance', 'accounts', 'null', 'ledger'],
    queryFn: async () => {
      if (!accountId) return [];
      const response = await api.get(`/api/finance/accounts/${accountId}/ledger`);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to load ledger');
      }
      return response.data?.ledger || response.data || [];
    },
    enabled: enabled && !!accountId,
  });
}

/**
 * Create account mutation
 */
export function useCreateAccount() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (data: Partial<Account>) => {
      const response = await api.post('/api/finance/session/accounts', data);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to create account');
      }
      return response.data?.data || response.data;
    },
    onSuccess: () => {
      // Invalidate and refetch accounts
      queryClient.invalidateQueries({ queryKey: queryKeys.accounts });
    },
  });
}

// ============================================================================
// INVOICES HOOKS
// ============================================================================

/**
 * Fetch all invoices with caching
 */
export function useInvoices() {
  const api = useFinanceApi();
  
  return useQuery({
    queryKey: queryKeys.invoices,
    queryFn: async () => {
      const response = await api.get('/api/finance/invoices');
      if (!response.ok) {
        throw new Error(response.error || 'Failed to load invoices');
      }
      const data = response.data?.data || response.data;
      return Array.isArray(data) ? data : [] as Invoice[];
    },
  });
}

/**
 * Fetch single invoice
 */
export function useInvoice(invoiceId: string | null, enabled = true) {
  const api = useFinanceApi();
  
  return useQuery({
    queryKey: invoiceId ? queryKeys.invoice(invoiceId) : ['finance', 'invoices', 'null'],
    queryFn: async () => {
      if (!invoiceId) return null;
      const response = await api.get(`/api/finance/invoices/${invoiceId}`);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to load invoice');
      }
      return response.data;
    },
    enabled: enabled && !!invoiceId,
  });
}

/**
 * Create invoice mutation
 */
export function useCreateInvoice() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (data: Partial<Invoice>) => {
      const response = await api.post('/api/finance/session/invoices', data);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to create invoice');
      }
      return response.data?.data || response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.invoices });
    },
  });
}

/**
 * Post invoice to ledger
 */
export function usePostInvoice() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (invoiceId: string) => {
      const response = await api.post(`/api/finance/invoices/${invoiceId}/post`);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to post invoice');
      }
      return response.data;
    },
    onSuccess: (_data, invoiceId) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.invoices });
      queryClient.invalidateQueries({ queryKey: queryKeys.invoice(invoiceId) });
      queryClient.invalidateQueries({ queryKey: queryKeys.accounts });
    },
  });
}

// ============================================================================
// EXPENSES HOOKS
// ============================================================================

/**
 * Fetch all expenses with caching
 * Now supports Query Builder filtering and sorting:
 * - filter[status], filter[category], filter[vendor]
 * - filter[date_from], filter[date_to]
 * - sort=date, sort=-amount, etc.
 */
export function useExpenses(filters?: {
  status?: string;
  category?: string;
  vendor?: string;
  dateFrom?: string;
  dateTo?: string;
  search?: string;
  sort?: string;
}) {
  const api = useFinanceApi();
  
  return useQuery({
    queryKey: filters ? [...queryKeys.expenses, filters] : queryKeys.expenses,
    queryFn: async () => {
      let url = '/api/finance/expenses';
      
      // Build query parameters using Query Builder format
      if (filters) {
        const params = new URLSearchParams();
        if (filters.status) params.append('filter[status]', filters.status);
        if (filters.category) params.append('filter[category]', filters.category);
        if (filters.vendor) params.append('filter[vendor]', filters.vendor);
        if (filters.dateFrom) params.append('filter[date_from]', filters.dateFrom);
        if (filters.dateTo) params.append('filter[date_to]', filters.dateTo);
        if (filters.search) params.append('filter[search_all]', filters.search);
        if (filters.sort) params.append('sort', filters.sort);
        
        const queryString = params.toString();
        if (queryString) url += `?${queryString}`;
      }
      
      const response = await api.get(url);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to load expenses');
      }
      const data = response.data?.data || response.data;
      return Array.isArray(data) ? data : [] as Expense[];
    },
  });
}

/**
 * Create expense mutation
 */
export function useCreateExpense() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (data: Partial<Expense>) => {
      const response = await api.post('/api/finance/session/expenses', data);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to create expense');
      }
      return response.data?.data || response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.expenses });
    },
  });
}

/**
 * Approve expense mutation
 */
export function useApproveExpense() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (expenseId: string) => {
      const response = await api.post(`/api/finance/expenses/${expenseId}/approve`);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to approve expense');
      }
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.expenses });
      queryClient.invalidateQueries({ queryKey: queryKeys.approvals.pending });
    },
  });
}

// ============================================================================
// JOURNAL ENTRIES HOOKS
// ============================================================================

/**
 * Fetch all journal entries with caching
 */
export function useJournalEntries() {
  const api = useFinanceApi();
  
  return useQuery({
    queryKey: queryKeys.journalEntries,
    queryFn: async () => {
      const response = await api.get('/api/finance/session/journal-entries');
      if (!response.ok) {
        throw new Error(response.error || 'Failed to load journal entries');
      }
      const data = response.data?.data || response.data;
      return Array.isArray(data) ? data : [] as JournalEntry[];
    },
  });
}

/**
 * Create journal entry mutation
 */
export function useCreateJournalEntry() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (data: Partial<JournalEntry>) => {
      const response = await api.post('/api/finance/session/journal-entries', data);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to create journal entry');
      }
      return response.data?.data || response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.journalEntries });
    },
  });
}

/**
 * Post journal entry
 */
export function usePostJournalEntry() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (entryId: string) => {
      const response = await api.post(`/api/finance/journal-entries/${entryId}/post`);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to post journal entry');
      }
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.journalEntries });
      queryClient.invalidateQueries({ queryKey: queryKeys.accounts });
    },
  });
}

// ============================================================================
// BUDGETS HOOKS
// ============================================================================

/**
 * Fetch all budgets with caching
 */
export function useBudgets() {
  const api = useFinanceApi();
  
  return useQuery({
    queryKey: queryKeys.budgets,
    queryFn: async () => {
      const response = await api.get('/api/finance/session/budgets');
      if (!response.ok) {
        throw new Error(response.error || 'Failed to load budgets');
      }
      const data = response.data?.data || response.data;
      return Array.isArray(data) ? data : [];
    },
  });
}

/**
 * Create budget mutation
 */
export function useCreateBudget() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (budgetData: any) => {
      const response = await api.post('/api/finance/session/budgets', budgetData);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to create budget');
      }
      return response.data?.data || response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.budgets });
    },
  });
}

/**
 * Update budget mutation
 */
export function useUpdateBudget() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, ...budgetData }: any) => {
      const response = await api.patch(`/api/finance/budgets/${id}`, budgetData);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to update budget');
      }
      return response.data?.data || response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.budgets });
    },
  });
}

/**
 * Delete budget mutation
 */
export function useDeleteBudget() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: string) => {
      const response = await api.delete(`/api/finance/budgets/${id}`);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to delete budget');
      }
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.budgets });
    },
  });
}

/**
 * Fetch budget variance report
 */
export function useBudgetVariance(startDate?: string, endDate?: string) {
  const api = useFinanceApi();
  
  return useQuery({
    queryKey: ['finance', 'budgets', 'variance', startDate, endDate],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (startDate) params.append('start_date', startDate);
      if (endDate) params.append('end_date', endDate);
      
      const response = await api.get(`/api/finance/budgets/variance?${params}`);
      if (!response.ok) {
        throw new Error(response.error || 'Failed to load variance report');
      }
      return response.data;
    },
  });
}

/**
 * Fetch budget utilization summary
 */
export function useBudgetUtilization() {
  const api = useFinanceApi();
  
  return useQuery({
    queryKey: ['finance', 'budgets', 'utilization'],
    queryFn: async () => {
      const response = await api.get('/api/finance/session/budgets/utilization');
      if (!response.ok) {
        throw new Error(response.error || 'Failed to load utilization data');
      }
      return response.data?.data || response.data;
    },
  });
}

/**
 * Sync budget actuals from expenses
 */
export function useSyncBudgetActuals() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, startDate, endDate }: { id: string; startDate?: string; endDate?: string }) => {
      const params = new URLSearchParams();
      if (startDate) params.append('start_date', startDate);
      if (endDate) params.append('end_date', endDate);
      
      const response = await api.post(`/api/finance/budgets/${id}/sync-actuals?${params}`, {});
      if (!response.ok) {
        throw new Error(response.error || 'Failed to sync actuals');
      }
      return response.data?.data || response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.budgets });
    },
  });
}

// ============================================================================
// TAX RATES HOOKS
// ============================================================================

/**
 * Fetch all tax rates with caching
 */
export function useTaxRates() {
  const api = useFinanceApi();
  
  return useQuery({
    queryKey: queryKeys.taxRates,
    queryFn: async () => {
      const response = await api.get('/api/finance/session/tax-rates');
      if (!response.ok) {
        // Tax rates are optional - return empty array on error
        return [];
      }
      return response.data || [];
    },
  });
}

// ============================================================================
// APPROVAL WORKFLOW HOOKS
// ============================================================================

/**
 * Fetch pending approvals
 */
export function usePendingApprovals() {
  const api = useFinanceApi();
  
  return useQuery({
    queryKey: queryKeys.approvals.pending,
    queryFn: async () => {
      const response = await api.get('/api/finance/session/approvals/pending');
      if (!response.ok) {
        throw new Error(response.error || 'Failed to load pending approvals');
      }
      // Handle various response formats
      const data = response.data?.approvals || response.data?.data || response.data;
      return Array.isArray(data) ? data : [];
    },
  });
}

/**
 * Approve transaction
 */
export function useApproveTransaction() {
  const api = useFinanceApi();
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, type }: { id: string; type: string }) => {
      const response = await api.post(`/api/finance/approvals/${id}/approve`, { type });
      if (!response.ok) {
        throw new Error(response.error || 'Failed to approve transaction');
      }
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.approvals.pending });
      queryClient.invalidateQueries({ queryKey: queryKeys.approvals.history });
      queryClient.invalidateQueries({ queryKey: queryKeys.expenses });
      queryClient.invalidateQueries({ queryKey: queryKeys.invoices });
    },
  });
}
