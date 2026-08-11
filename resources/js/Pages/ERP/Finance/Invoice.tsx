import React, { useState, useMemo } from "react";
import { createPortal } from "react-dom";
import { usePage, router } from '@inertiajs/react';
import { useFinanceApi } from "../../../hooks/useFinanceApi";
import { useInvoices, usePostInvoice } from "../../../hooks/useFinanceQueries";
import { getApprovalStatusBadge } from "./InlineApprovalUtils";
import Swal from "sweetalert2";

// Loading Spinner Component
const LoadingSpinner: React.FC<{ message?: string }> = ({ message = "Loading invoices..." }) => (
  <div className="flex flex-col items-center justify-center py-16 gap-4">
    <div className="relative w-12 h-12">
      <div className="absolute inset-0 rounded-full border-4 border-gray-300 dark:border-gray-600"></div>
      <div className="absolute inset-0 rounded-full border-4 border-transparent border-t-blue-500 border-r-blue-500 animate-spin"></div>
    </div>
    <p className="text-sm font-medium text-gray-600 dark:text-gray-300">{message}</p>
  </div>
);

// Portal wrapper to ensure modals sit at the document level
const ModalPortal: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  if (typeof document === "undefined") return null;
  return createPortal(children, document.body);
};

type MetricCardProps = {
  title: string;
  value: number | string;
  change?: number;
  changeType?: "increase" | "decrease";
  description?: string;
  color?: "success" | "error" | "warning" | "info";
  icon: React.FC<{ className?: string }>;
};

// Icons
const MagnifyingGlassIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
  </svg>
);

const FunnelIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
  </svg>
);

const ArrowDownTrayIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
  </svg>
);

const EllipsisVerticalIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z" />
  </svg>
);

const ChevronLeftIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
  </svg>
);

const ChevronRightIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
  </svg>
);

const DocumentTextIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
  </svg>
);

const CheckCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const XCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const DocumentIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
  </svg>
);

const CurrencyDollarIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const ArrowUpIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

const EyeIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const PencilIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
  </svg>
);

const TrashIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
  </svg>
);

const ArchiveBoxIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v1A2.5 2.5 0 0117.5 11h-11A2.5 2.5 0 014 8.5v-1z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 11v6.5A2.5 2.5 0 009.5 20h5a2.5 2.5 0 002.5-2.5V11" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 15h4" />
  </svg>
);

const ArchiveRestoreIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v1A2.5 2.5 0 0117.5 11h-11A2.5 2.5 0 014 8.5v-1z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 11v6.5A2.5 2.5 0 009.5 20h5a2.5 2.5 0 002.5-2.5V11" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 14l-2-2-2 2" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 12v4" />
  </svg>
);

const PlusIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
  </svg>
);

const BriefcaseIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
  </svg>
);

// Professional Metric Card Component
const MetricCard: React.FC<MetricCardProps> = ({
  title,
  value,
  change,
  changeType,
  icon: Icon,
  color,
  description,
}) => {
  const getColorClasses = () => {
    switch (color) {
      case "success": return "from-green-500 to-emerald-600";
      case "error": return "from-red-500 to-rose-600";
      case "warning": return "from-yellow-500 to-orange-600";
      case "info": return "from-blue-500 to-indigo-600";
      default: return "from-gray-500 to-gray-600";
    }
  };

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
      <div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
      <div className="relative">
        <div className="flex items-center justify-between mb-4">
          <div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
            <Icon className="text-white size-7 drop-shadow-sm" />
          </div>
          {change !== undefined && (
            <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
              changeType === "increase"
                ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
            }`}>
              {changeType === "increase" ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
              {Math.abs(change)}%
            </div>
          )}
        </div>
        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {typeof value === 'number' ? value.toLocaleString() : value}
          </h3>
          {description && <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>}
        </div>
      </div>
    </div>
  );
};

interface Invoice {
  id: string;
  reference: string;
  date: string;
  due_date: string | null;
  customer_name: string;
  total: number | string;
  status: "draft" | "sent" | "paid" | "overdue" | "cancelled" | "refunded";
  payment_date?: string | null;
  payment_method?: string | null;
  payment_state?: {
    paid_amount: string;
    remaining_balance: string;
    status: "unpaid" | "partially_paid" | "paid";
    source_owner: "finance" | "operational";
    integrity_warnings: string[];
  };
  job_order_id?: number | null;
  job_reference?: string | null;
  tax_amount?: number | string | null;
  items?: InvoiceLineItem[];
  meta?: {
    subtotal_amount?: number | string | null;
    shipping_fee?: number | string | null;
    vat_amount?: number | string | null;
    grand_total?: number | string | null;
    [key: string]: unknown;
  } | null;
  job_order?: {
    id: number;
    customer: string;
    product: string;
    status: string;
    payment_status?: string | null;
    total: string;
    total_amount?: number | string | null;
    shipping_fee?: number | string | null;
    vat_amount?: number | string | null;
    vat_rate?: number | string | null;
    grand_total?: number | string | null;
    created_at: string;
  } | null;
  deleted_at?: string | null;
}

interface InvoiceLineItem {
  description: string;
  quantity: number | string;
  unit_price: number | string;
  amount: number | string;
  tax_rate?: number | string | null;
}

type TabFilter = "all" | "draft" | "sent" | "paid" | "overdue" | "refunded";

const parseAmount = (value: unknown): number => {
  const numericValue = Number(value);
  return Number.isFinite(numericValue) ? numericValue : 0;
};

const isShippingItem = (item: InvoiceLineItem): boolean => {
  return /shipping/i.test(item.description || '') && parseAmount(item.tax_rate) === 0;
};

const formatPeso = (value: number): string => {
  return `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

type InvoiceDisplayStatus = Invoice['status'] | 'archived';

const getEffectiveInvoiceStatus = (invoice: Invoice): InvoiceDisplayStatus => {
  if (invoice.deleted_at) {
    return 'archived';
  }

  if ((invoice.job_order?.payment_status || '').toLowerCase() === 'refunded') {
    return 'refunded';
  }

  return invoice.status;
};

const Invoice: React.FC = () => {
  const page = usePage();
  const user = page.props.auth?.user as any;
  const auth = page.props.auth as any;
  const ownerMode = page.props.ownerMode === true || auth?.erpActor?.ownerMode === true;
  const api = useFinanceApi();
  
  const [selectedTab, setSelectedTab] = useState<TabFilter>("all");
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedInvoices, setSelectedInvoices] = useState<string[]>([]);
  const [currentPage, setCurrentPage] = useState(1);
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
  const [showArchived, setShowArchived] = useState(false);
  const [sendingInvoiceId, setSendingInvoiceId] = useState<string | null>(null);
  const [markingPaidId, setMarkingPaidId] = useState<string | null>(null);
  const [jobStatusFilter, setJobStatusFilter] = useState<string>("");
  const [hasJobFilter, setHasJobFilter] = useState<string>("all");
  const itemsPerPage = 10;

  const handleSendInvoice = async (invoiceId: string) => {
    const result = await Swal.fire({
      title: 'Send Invoice?',
      text: 'This will mark the invoice as sent to the customer.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, send it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2563eb',
      reverseButtons: true,
    });

    if (!result.isConfirmed) return;

    setSendingInvoiceId(invoiceId);
    try {
      const response = await api.post(`/api/finance/invoices/${invoiceId}/send`);

      if (!response.ok) {
        throw new Error(response.error || 'Failed to send invoice');
      }

      refetchInvoices();
      await Swal.fire('Sent!', 'Invoice has been sent to customer.', 'success');
    } catch (error) {
      await Swal.fire('Error', error instanceof Error ? error.message : 'Failed to send invoice', 'error');
    } finally {
      setSendingInvoiceId(null);
    }
  };

  const handleMarkAsPaid = async (invoiceId: string) => {
    const currentInvoice = invoices.find((invoice) => String(invoice.id) === String(invoiceId));
    const defaultAmount = currentInvoice?.payment_state?.remaining_balance ?? currentInvoice?.total ?? 0;
    const { value: formValues } = await Swal.fire({
      title: 'Record Payment',
      html: `
        <div class="space-y-4">
          <div class="text-left">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Amount</label>
            <input id="payment-amount" type="number" min="0.01" step="0.01" class="swal2-input w-full" value="${defaultAmount}" style="margin: 0; width: 100%;" />
          </div>
          <div class="text-left">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Received Date</label>
            <input id="payment-date" type="date" class="swal2-input w-full" value="${new Date().toISOString().split('T')[0]}" style="margin: 0; width: 100%;" />
          </div>
          <div class="text-left">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Payment Method</label>
            <select id="payment-method" class="swal2-input w-full" style="margin: 0; width: 100%;">
              <option value="cash">Cash</option>
              <option value="bank_transfer">Bank Transfer</option>
              <option value="check">Check</option>
              <option value="gcash">GCash</option>
              <option value="maya">Maya</option>
              <option value="paypal">PayPal</option>
              <option value="other">Other</option>
            </select>
          </div>
        </div>
      `,
      showCancelButton: true,
      confirmButtonText: 'Record Payment',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#10b981',
      reverseButtons: true,
      preConfirm: () => {
        const paymentDate = (document.getElementById('payment-date') as HTMLInputElement)?.value;
        const paymentMethod = (document.getElementById('payment-method') as HTMLSelectElement)?.value;
        const paymentAmount = (document.getElementById('payment-amount') as HTMLInputElement)?.value;
        
        if (!paymentDate) {
          Swal.showValidationMessage('Please select a payment date');
          return false;
        }

        if (!paymentAmount || Number(paymentAmount) <= 0) {
          Swal.showValidationMessage('Please enter a payment amount greater than zero');
          return false;
        }
        
        return {
          amount: paymentAmount,
          received_at: paymentDate,
          payment_method: paymentMethod,
          idempotency_key: `${invoiceId}-${Date.now()}`,
        };
      }
    });

    if (formValues) {
      setMarkingPaidId(invoiceId);
      try {
        const response = await api.post(`/api/finance/invoices/${invoiceId}/payments`, formValues);

        if (!response.ok) {
          throw new Error(response.error || 'Failed to record payment');
        }

        refetchInvoices();
        await Swal.fire('Success!', 'Payment recorded.', 'success');
      } catch (error) {
        await Swal.fire('Error', error instanceof Error ? error.message : 'Failed to record payment', 'error');
      } finally {
        setMarkingPaidId(null);
      }
    }
  };

  // React Query hooks - automatically handle loading, caching, refetching
  const { data: invoices = [], isLoading: loading, refetch: refetchInvoices } = useInvoices({ archived: showArchived });
  const postInvoiceMutation = usePostInvoice();

  // Filter invoices based on tab and search
  const filteredInvoices = useMemo(() => {
    return invoices.filter((invoice) => {
      const effectiveStatus = getEffectiveInvoiceStatus(invoice);
      const matchesTab =
        selectedTab === "all" ||
        effectiveStatus === selectedTab;

      const matchesSearch =
        (invoice.reference || "").toLowerCase().includes((searchTerm || "").toLowerCase()) ||
        (invoice.customer_name || "").toLowerCase().includes((searchTerm || "").toLowerCase());

      // Job status filter
      const matchesJobStatus = 
        !jobStatusFilter || 
        (invoice.job_order && invoice.job_order.status === jobStatusFilter);

      // Has job filter
      const matchesHasJob = 
        hasJobFilter === "all" ||
        (hasJobFilter === "true" && invoice.job_order_id) ||
        (hasJobFilter === "false" && !invoice.job_order_id);

      return matchesTab && matchesSearch && matchesJobStatus && matchesHasJob;
    });
  }, [invoices, selectedTab, searchTerm, jobStatusFilter, hasJobFilter]);

  // Pagination
  const totalPages = Math.ceil(filteredInvoices.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedInvoices = filteredInvoices.slice(startIndex, endIndex);

  // Reset to page 1 when filters change
  React.useEffect(() => {
    setCurrentPage(1);
  }, [selectedTab, searchTerm, jobStatusFilter, hasJobFilter]);

  React.useEffect(() => {
    setCurrentPage(1);
    setSelectedInvoices([]);
  }, [showArchived]);

  const resolveInvoiceRevenueAmount = (invoice: Invoice): number => {
    const metaSubtotal = parseAmount(invoice.meta?.subtotal_amount);
    if (metaSubtotal > 0) {
      return metaSubtotal;
    }

    const jobSubtotal = parseAmount(invoice.job_order?.total_amount ?? invoice.job_order?.total);
    if (jobSubtotal > 0) {
      return jobSubtotal;
    }

    const grossTotal = parseAmount(invoice.total);
    const vatAmount = parseAmount(invoice.meta?.vat_amount ?? invoice.tax_amount ?? invoice.job_order?.vat_amount);
    const fallbackNet = grossTotal - vatAmount;

    return fallbackNet > 0 ? fallbackNet : grossTotal;
  };

  // Calculate statistics from invoice data
  const stats = useMemo(() => {
    const total = invoices.length;
    const sent = invoices.filter((inv) => getEffectiveInvoiceStatus(inv) === "sent").length;
    const paid = invoices.filter((inv) => getEffectiveInvoiceStatus(inv) === "paid").length;
    const draft = invoices.filter((inv) => inv.status === "draft").length;
    const overdue = invoices.filter((inv) => getEffectiveInvoiceStatus(inv) === "overdue").length;
    
    const totalRevenue = invoices
      .filter((inv) => getEffectiveInvoiceStatus(inv) === "paid")
      .reduce((sum, inv) => sum + resolveInvoiceRevenueAmount(inv), 0);
      
    const pendingRevenue = invoices
      .filter((inv) => {
        const effectiveStatus = getEffectiveInvoiceStatus(inv);
        return effectiveStatus === "sent" || effectiveStatus === "overdue";
      })
      .reduce((sum, inv) => sum + resolveInvoiceRevenueAmount(inv), 0);

    return {
      total,
      sent,
      paid,
      draft,
      overdue,
      totalRevenue,
      pendingRevenue,
    };
  }, [invoices]);

  const handleSelectAll = async (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.checked) {
      const result = await Swal.fire({
        title: 'Select all invoices?',
        text: `This will select all ${paginatedInvoices.length} invoice(s) on this page.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, select all',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#2563eb',
        reverseButtons: true,
      });
      
      if (result.isConfirmed) {
        setSelectedInvoices(paginatedInvoices.map((inv) => inv.id));
      }
    } else {
      setSelectedInvoices([]);
    }
  };

  const handleSelectInvoice = async (id: string) => {
    const isSelected = selectedInvoices.includes(id);
    
    if (isSelected) {
      // Deselecting - no confirmation needed
      setSelectedInvoices((prev) => prev.filter((i) => i !== id));
    } else {
      // Selecting - ask for confirmation
      const invoice = paginatedInvoices.find(inv => inv.id === id);
      const result = await Swal.fire({
        title: 'Select this invoice?',
        text: invoice ? `Invoice ${invoice.reference} - ${invoice.customer_name}` : 'Select this invoice',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, select',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#2563eb',
        reverseButtons: true,
      });
      
      if (result.isConfirmed) {
        setSelectedInvoices((prev) => [...prev, id]);
      }
    }
  };

  const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      draft: "bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400",
      sent: "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400",
      posted: "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400",
      paid: "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400",
      refunded: "bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-400",
      overdue: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400",
      cancelled: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400",
      archived: "bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400",
    };
    return colors[status] || "bg-gray-100 text-gray-800";
  };

  const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    return date.toLocaleDateString("en-US", {
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  };

  const resolveInvoiceSubtotal = (invoice: Invoice): number => {
    const metaSubtotal = parseAmount(invoice.meta?.subtotal_amount);
    if (metaSubtotal > 0) {
      return metaSubtotal;
    }

    const jobSubtotal = parseAmount(invoice.job_order?.total_amount ?? invoice.job_order?.total);
    if (jobSubtotal > 0 && parseAmount(invoice.job_order?.shipping_fee) > 0) {
      return jobSubtotal;
    }

    if (invoice.items?.length) {
      return invoice.items
        .filter((item) => !isShippingItem(item))
        .reduce((sum, item) => sum + (parseAmount(item.quantity) * parseAmount(item.unit_price)), 0);
    }

    return parseAmount(invoice.total);
  };

  const resolveInvoiceShippingFee = (invoice: Invoice): number => {
    const metaShipping = parseAmount(invoice.meta?.shipping_fee);
    if (metaShipping > 0) {
      return metaShipping;
    }

    return parseAmount(invoice.job_order?.shipping_fee);
  };

  const resolveInvoiceVatAmount = (invoice: Invoice): number => {
    const metaVat = parseAmount(invoice.meta?.vat_amount);
    if (metaVat > 0) {
      return metaVat;
    }

    const storedTax = parseAmount(invoice.tax_amount);
    if (storedTax > 0) {
      return storedTax;
    }

    const jobVat = parseAmount(invoice.job_order?.vat_amount);
    if (jobVat > 0) {
      return jobVat;
    }

    if (invoice.items?.length) {
      return invoice.items.reduce((sum, item) => {
        if (isShippingItem(item)) {
          return sum;
        }

        const lineSubtotal = parseAmount(item.quantity) * parseAmount(item.unit_price);
        const lineAmount = parseAmount(item.amount);
        return sum + Math.max(0, lineAmount - lineSubtotal);
      }, 0);
    }

    return 0;
  };

  const resolveInvoiceGrandTotal = (invoice: Invoice): number => {
    const metaGrandTotal = parseAmount(invoice.meta?.grand_total);
    if (metaGrandTotal > 0) {
      return metaGrandTotal;
    }

    const jobGrandTotal = parseAmount(invoice.job_order?.grand_total);
    if (jobGrandTotal > 0) {
      return jobGrandTotal;
    }

    const subtotal = resolveInvoiceSubtotal(invoice);
    const shipping = resolveInvoiceShippingFee(invoice);
    const vat = resolveInvoiceVatAmount(invoice);
    const fallbackTotal = subtotal + shipping + vat;

    return fallbackTotal > 0 ? fallbackTotal : parseAmount(invoice.total);
  };

  const hasActiveFilters =
    selectedTab !== "all" ||
    searchTerm.trim().length > 0 ||
    jobStatusFilter !== "" ||
    hasJobFilter !== "all";

  const handleResetFilters = async () => {
    if (!hasActiveFilters) {
      await Swal.fire('No Active Filters', 'Nothing to reset.', 'info');
      return;
    }

    setSelectedTab("all");
    setSearchTerm("");
    setJobStatusFilter("");
    setHasJobFilter("all");
    setCurrentPage(1);

    await Swal.fire('Filters Cleared', 'Showing all invoices again.', 'success');
  };

  const escapeCsvValue = (value: unknown): string => {
    const stringValue = String(value ?? '');
    if (/[",\n]/.test(stringValue)) {
      return `"${stringValue.replace(/"/g, '""')}"`;
    }
    return stringValue;
  };

  const handleExportInvoices = async () => {
    if (!filteredInvoices.length) {
      await Swal.fire('No Data', 'There are no invoices to export.', 'info');
      return;
    }

    const headers = [
      'Invoice Reference',
      'Customer',
      'Date',
      'Due Date',
      'Status',
      'Subtotal',
      'Shipping Fee',
      'VAT',
      'Grand Total',
    ];

    const rows = filteredInvoices.map((invoice) => {
      const subtotal = resolveInvoiceSubtotal(invoice).toFixed(2);
      const shipping = resolveInvoiceShippingFee(invoice).toFixed(2);
      const vat = resolveInvoiceVatAmount(invoice).toFixed(2);
      const grandTotal = resolveInvoiceGrandTotal(invoice).toFixed(2);
      const status = getEffectiveInvoiceStatus(invoice);

      return [
        invoice.reference,
        invoice.customer_name,
        invoice.date,
        invoice.due_date || '',
        status,
        subtotal,
        shipping,
        vat,
        grandTotal,
      ].map(escapeCsvValue).join(',');
    });

    const csvContent = [headers.join(','), ...rows].join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const dateSuffix = new Date().toISOString().slice(0, 10);

    link.href = url;
    link.download = `invoices-${dateSuffix}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);

    await Swal.fire('Export Complete', 'Invoice CSV has been downloaded.', 'success');
  };

  const handleArchiveInvoice = async (invoice: Invoice) => {
    const result = await Swal.fire({
      title: 'Archive Invoice?',
      text: `This will move ${invoice.reference} to the archived list.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, archive it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#7c3aed',
      reverseButtons: true,
    });

    if (!result.isConfirmed) return;

    try {
      const response = await api.delete(`/api/finance/invoices/${invoice.id}`);

      if (!response.ok) {
        throw new Error(response.error || 'Failed to archive invoice');
      }

      if (selectedInvoice?.id === invoice.id) {
        setSelectedInvoice(null);
        setIsViewModalOpen(false);
      }

      refetchInvoices();
      setSelectedInvoices((prev) => prev.filter((id) => id !== invoice.id));
      await Swal.fire('Archived', 'Invoice moved to archives.', 'success');
    } catch (error) {
      await Swal.fire('Error', error instanceof Error ? error.message : 'Failed to archive invoice', 'error');
    }
  };

  const handleRestoreInvoice = async (invoice: Invoice) => {
    const result = await Swal.fire({
      title: 'Restore Invoice?',
      text: `This will return ${invoice.reference} to the active list.`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, restore it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2563eb',
      reverseButtons: true,
    });

    if (!result.isConfirmed) return;

    try {
      const response = await api.post(`/api/finance/invoices/${invoice.id}/restore`);

      if (!response.ok) {
        throw new Error(response.error || 'Failed to restore invoice');
      }

      refetchInvoices();
      setSelectedInvoices((prev) => prev.filter((id) => id !== invoice.id));
      await Swal.fire('Restored', 'Invoice returned to the active list.', 'success');
    } catch (error) {
      await Swal.fire('Error', error instanceof Error ? error.message : 'Failed to restore invoice', 'error');
    }
  };

  const handleDownloadInvoicePdf = async (invoice: Invoice) => {
    const lineRows = (invoice.items || []).map((item) => {
      const qty = parseAmount(item.quantity);
      const unitPrice = parseAmount(item.unit_price);
      const amount = parseAmount(item.amount);
      return `<tr>
        <td style="padding:8px;border-bottom:1px solid #e5e7eb;">${item.description || '-'}</td>
        <td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:center;">${qty}</td>
        <td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;">${formatPeso(unitPrice)}</td>
        <td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;">${formatPeso(amount)}</td>
      </tr>`;
    }).join('');

    const printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) {
      await Swal.fire('Popup Blocked', 'Please allow popups to download/print invoices.', 'warning');
      return;
    }

    const subtotal = formatPeso(resolveInvoiceSubtotal(invoice));
    const shipping = formatPeso(resolveInvoiceShippingFee(invoice));
    const vat = formatPeso(resolveInvoiceVatAmount(invoice));
    const grandTotal = formatPeso(resolveInvoiceGrandTotal(invoice));

    printWindow.document.write(`
      <html>
        <head>
          <title>${invoice.reference}</title>
        </head>
        <body style="font-family: Arial, sans-serif; padding: 24px; color: #111827;">
          <h1 style="margin: 0 0 8px;">Invoice ${invoice.reference}</h1>
          <p style="margin: 0 0 16px;">Customer: ${invoice.customer_name}</p>
          <p style="margin: 0 0 16px;">Date: ${invoice.date} | Due: ${invoice.due_date || 'N/A'}</p>
          <table style="width:100%; border-collapse:collapse; margin-bottom:16px;">
            <thead>
              <tr>
                <th style="text-align:left; padding:8px; border-bottom:2px solid #111827;">Description</th>
                <th style="text-align:center; padding:8px; border-bottom:2px solid #111827;">Qty</th>
                <th style="text-align:right; padding:8px; border-bottom:2px solid #111827;">Unit Price</th>
                <th style="text-align:right; padding:8px; border-bottom:2px solid #111827;">Amount</th>
              </tr>
            </thead>
            <tbody>${lineRows || '<tr><td colspan="4" style="padding:8px;">No line items</td></tr>'}</tbody>
          </table>
          <div style="max-width:320px; margin-left:auto;">
            <p style="display:flex; justify-content:space-between; margin:6px 0;"><span>Subtotal</span><strong>${subtotal}</strong></p>
            <p style="display:flex; justify-content:space-between; margin:6px 0;"><span>Shipping</span><strong>${shipping}</strong></p>
            <p style="display:flex; justify-content:space-between; margin:6px 0;"><span>VAT</span><strong>${vat}</strong></p>
            <p style="display:flex; justify-content:space-between; margin:10px 0 0; border-top:1px solid #d1d5db; padding-top:10px;"><span>Total</span><strong>${grandTotal}</strong></p>
          </div>
        </body>
      </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
  };

  const handleModalSendEmail = async (invoice: Invoice) => {
    const status = getEffectiveInvoiceStatus(invoice);
    if (status !== 'draft') {
      await Swal.fire('Already Processed', 'This invoice has already been sent or finalized.', 'info');
      return;
    }

    await handleSendInvoice(invoice.id);
  };

  const handleCreateInvoice = () => {
    router.visit(ownerMode ? '/shop-owner/erp/finance/create-invoice' : '/finance?section=create-invoice');
  };

  return (
    <div className="space-y-6">
      {loading ? (
        <LoadingSpinner message="Loading invoices..." />
      ) : (
        <>
          {/* Header */}
          <div className="flex justify-between items-start">
            <div>
              <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Invoices</h1>
              <p className="text-gray-600 dark:text-gray-400 mt-2">Your most recent invoices list</p>
            </div>
            <div className="flex gap-3">
              <button
                onClick={handleCreateInvoice}
                className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 shadow-sm"
          >
            <PlusIcon className="size-5 mr-2" />
            Create Invoice
          </button>
        </div>
      </div>

      {/* Metrics */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <MetricCard
          title="Total Invoices"
          value={stats.total}
          icon={DocumentTextIcon}
          color="info"
          description="All time invoices"
        />
        <MetricCard
          title="Paid Invoices"
          value={stats.paid}
          icon={CheckCircleIcon}
          color="success"
          description="Received payments"
        />
        <MetricCard
          title="Pending Invoices"
          value={stats.sent + stats.overdue}
          icon={DocumentIcon}
          color="warning"
          description="Awaiting payment"
        />
        <MetricCard
          title="Net Revenue (Excl. VAT)"
          value={`₱${stats.totalRevenue.toLocaleString()}`}
          icon={CurrencyDollarIcon}
          color="success"
          description="From paid invoices before VAT"
        />
      </div>

      {/* Main Content */}
      <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
        {/* Tabs, Search, and Actions */}
        <div className="p-6 border-b border-gray-200 dark:border-gray-800">
          <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            {/* Tabs */}
            <div className="flex items-center gap-2">
              <button
                onClick={() => setSelectedTab("all")}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  selectedTab === "all"
                    ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                    : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                }`}
              >
                All Invoices
              </button>
              <button
                onClick={() => setSelectedTab("sent")}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  selectedTab === "sent"
                    ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                    : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                }`}
              >
                Sent
              </button>
              <button
                onClick={() => setSelectedTab("paid")}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  selectedTab === "paid"
                    ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                    : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                }`}
              >
                Paid
              </button>
              <button
                onClick={() => setSelectedTab("draft")}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  selectedTab === "draft"
                    ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                    : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                }`}
              >
                Draft
              </button>
              <button
                onClick={() => setSelectedTab("refunded")}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  selectedTab === "refunded"
                    ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                    : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                }`}
              >
                Refunded
              </button>
            </div>

            {/* Search and Actions */}
            <div className="flex items-center gap-3 flex-wrap">
              {/* Search */}
              <div className="relative flex-1 lg:flex-initial">
                <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 size-5 text-gray-400" />
                <input
                  type="text"
                  placeholder="Search..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full lg:w-64 pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300"
                />
              </div>

              {/* Job Type Filter */}
              <select
                value={hasJobFilter}
                onChange={(e) => setHasJobFilter(e.target.value)}
                className="px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300"
                title="Filter by invoice source"
              >
                <option value="all">All Sources</option>
                <option value="true">Job Orders</option>
                <option value="false">Manual Entry</option>
              </select>

              {/* Job Status Filter */}
              <select
                value={jobStatusFilter}
                onChange={(e) => setJobStatusFilter(e.target.value)}
                className="px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300"
                title="Filter by job order status"
                disabled={hasJobFilter === "false"}
              >
                <option value="">All Job Statuses</option>
                <option value="pending">Pending</option>
                <option value="in_progress">In Progress</option>
                <option value="completed">Completed</option>
                <option value="shipped">Shipped</option>
                <option value="delivered">Delivered</option>
              </select>

              {/* Filter Button */}
              <button
                onClick={handleResetFilters}
                className="flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
              >
                <FunnelIcon className="size-5" />
                <span className="hidden sm:inline text-sm font-medium">Filter</span>
              </button>

              {/* Export Button */}
              <button
                onClick={handleExportInvoices}
                className="flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
              >
                <ArrowDownTrayIcon className="size-5" />
                <span className="hidden sm:inline text-sm font-medium">Export</span>
              </button>

              <button
                onClick={() => setShowArchived((prev) => !prev)}
                className={`flex items-center gap-2 px-4 py-2 border rounded-lg transition-colors ${
                  showArchived
                    ? 'border-purple-300 bg-purple-50 text-purple-700 dark:border-purple-700 dark:bg-purple-900/20 dark:text-purple-300'
                    : 'border-gray-300 bg-white text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'
                }`}
              >
                <ArchiveBoxIcon className="size-5" />
                <span className="hidden sm:inline text-sm font-medium">
                  {showArchived ? 'Show Active' : 'Show Archived'}
                </span>
              </button>
            </div>
          </div>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-800">
              <tr>
                <th className="px-6 py-4 text-left">
                  <input
                    type="checkbox"
                    checked={
                      paginatedInvoices.length > 0 &&
                      selectedInvoices.length === paginatedInvoices.length
                    }
                    onChange={handleSelectAll}
                    className="rounded border-gray-300 dark:border-gray-700 text-blue-600 focus:ring-blue-500"
                  />
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                  Invoice Number
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                  Customer
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                  Creation Date
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                  Due Date
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                  Total
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                  Status
                </th>
                <th className="px-6 py-4 text-center text-sm font-semibold text-gray-700 dark:text-gray-300">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {paginatedInvoices.length > 0 ? (
                paginatedInvoices.map((invoice) => (
                  (() => {
                    const effectiveStatus = getEffectiveInvoiceStatus(invoice);
                    return (
                  <tr
                    key={invoice.id}
                    className="hover:bg-gray-50 dark:hover:bg-gray-900/50 transition-colors"
                  >
                    <td className="px-6 py-4">
                      <input
                        type="checkbox"
                        checked={selectedInvoices.includes(invoice.id)}
                        onChange={() => handleSelectInvoice(invoice.id)}
                        className="rounded border-gray-300 dark:border-gray-700 text-blue-600 focus:ring-blue-500"
                      />
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex flex-col gap-1.5">
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-medium text-gray-900 dark:text-white">
                            {invoice.reference}
                          </span>
                          {invoice.job_order && (
                            <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400 rounded-full border border-blue-300 dark:border-blue-700">
                              <BriefcaseIcon className="size-3" />
                              Job #{invoice.job_order.id}
                            </span>
                          )}
                        </div>
                        {invoice.job_order && (
                          <div className="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <span className="truncate max-w-[150px]" title={invoice.job_order.product}>
                              {invoice.job_order.product}
                            </span>
                            <span>•</span>
                            <span className={`font-medium capitalize ${
                              invoice.job_order.status === 'completed' || invoice.job_order.status === 'delivered'
                                ? 'text-green-600 dark:text-green-400'
                                : invoice.job_order.status === 'in_progress'
                                ? 'text-yellow-600 dark:text-yellow-400'
                                : 'text-gray-600 dark:text-gray-400'
                            }`}>
                              {invoice.job_order.status.replace('_', ' ')}
                            </span>
                          </div>
                        )}
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className="text-sm text-gray-900 dark:text-white">{invoice.customer_name}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="text-sm text-gray-600 dark:text-gray-400">
                        {formatDate(invoice.date)}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="text-sm text-gray-600 dark:text-gray-400">
                        {invoice.due_date ? formatDate(invoice.due_date) : '-'}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="text-sm font-medium text-gray-900 dark:text-white">
                        {formatPeso(resolveInvoiceGrandTotal(invoice))}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      {getApprovalStatusBadge(false, effectiveStatus)}
                    </td>
                    <td className="px-6 py-4 text-center">
                      <div className="flex justify-center gap-2">
                        <button 
                          onClick={() => {
                            setSelectedInvoice(invoice);
                            setIsViewModalOpen(true);
                          }}
                          className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                          title="View Invoice"
                        >
                          <EyeIcon className="size-5 text-blue-600 dark:text-blue-400" />
                        </button>
                        {effectiveStatus === 'draft' && (
                          <button 
                            onClick={() => handleSendInvoice(invoice.id)}
                            disabled={sendingInvoiceId === invoice.id}
                            className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            title={sendingInvoiceId === invoice.id ? "Sending..." : "Send Invoice"}
                          >
                            {sendingInvoiceId === invoice.id ? (
                              <div className="size-5 relative">
                                <div className="absolute inset-0 rounded-full border-2 border-gray-300"></div>
                                <div className="absolute inset-0 rounded-full border-2 border-transparent border-t-blue-600 animate-spin"></div>
                              </div>
                            ) : (
                              <svg className="size-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                              </svg>
                            )}
                          </button>
                        )}
                        {(effectiveStatus === 'sent' || effectiveStatus === 'overdue') && !invoice.job_order_id && (
                          <button 
                            onClick={() => handleMarkAsPaid(invoice.id)}
                            disabled={markingPaidId === invoice.id}
                            className="p-2 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            title={markingPaidId === invoice.id ? "Processing..." : "Record Payment"}
                          >
                            {markingPaidId === invoice.id ? (
                              <div className="size-5 relative">
                                <div className="absolute inset-0 rounded-full border-2 border-gray-300"></div>
                                <div className="absolute inset-0 rounded-full border-2 border-transparent border-t-green-600 animate-spin"></div>
                              </div>
                            ) : (
                              <CheckCircleIcon className="size-5 text-green-600 dark:text-green-400" />
                            )}
                          </button>
                        )}
                        {showArchived ? (
                          <button 
                            onClick={() => handleRestoreInvoice(invoice)}
                            className="p-2 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg transition-colors"
                            title="Restore Invoice"
                          >
                            <ArchiveRestoreIcon className="size-5 text-purple-600 dark:text-purple-400" />
                          </button>
                        ) : (
                          !invoice.deleted_at && (
                            <button 
                              onClick={() => handleArchiveInvoice(invoice)}
                              className="p-2 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg transition-colors"
                              title="Archive Invoice"
                            >
                              <ArchiveBoxIcon className="size-5 text-purple-600 dark:text-purple-400" />
                            </button>
                          )
                        )}
                      </div>
                    </td>
                  </tr>
                    );
                  })()
                ))
              ) : (
                <tr>
                  <td colSpan={8} className="px-6 py-12 text-center">
                    <p className="text-gray-500 dark:text-gray-400">No invoices found</p>
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {filteredInvoices.length > 0 && (
          <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-800">
            <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
              <div className="text-sm text-gray-700 dark:text-gray-300">
                Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                <span className="font-medium">{Math.min(endIndex, filteredInvoices.length)}</span> of{" "}
                <span className="font-medium">{filteredInvoices.length}</span>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                  disabled={currentPage === 1}
                  className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                  title="Previous page"
                >
                  <ChevronLeftIcon className="size-5" />
                </button>

                {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => {
                  // Show first page, last page, current page, and one page before/after current
                  if (
                    page === 1 ||
                    page === totalPages ||
                    (page >= currentPage - 1 && page <= currentPage + 1)
                  ) {
                    return (
                      <button
                        key={page}
                        onClick={() => setCurrentPage(page)}
                        className={`min-w-[40px] px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                          currentPage === page
                            ? "bg-blue-600 text-white"
                            : "border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                        }`}
                      >
                        {page}
                      </button>
                    );
                  } else if (page === currentPage - 2 || page === currentPage + 2) {
                    return (
                      <span key={page} className="px-2 text-gray-500">
                        ...
                      </span>
                    );
                  }
                  return null;
                })}

                <button
                  onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
                  disabled={currentPage === totalPages}
                  className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                  title="Next page"
                >
                  <ChevronRightIcon className="size-5" />
                </button>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* View Invoice Modal */}
      {isViewModalOpen && selectedInvoice && (
        <ModalPortal>
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
            <div className="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
              {/* Close Button */}
              <button
                onClick={() => setIsViewModalOpen(false)}
                aria-label="Close invoice modal"
                title="Close"
                className="absolute top-4 right-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors z-10"
              >
                <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>

              <div className="p-6">
                {/* Invoice Header */}
                <div className="text-center mb-5">
                  <h1 className="text-2xl font-bold text-gray-900 dark:text-white mb-1">INVOICE</h1>
                  <p className="text-sm font-semibold text-gray-700 dark:text-gray-300">{selectedInvoice?.reference || "N/A"}</p>
                </div>

                {/* Status Badge */}
                <div className="flex justify-center mb-5">
                  <span
                    className={`inline-flex px-4 py-1 text-xs font-semibold rounded-full ${getStatusColor(
                      getEffectiveInvoiceStatus(selectedInvoice) || "draft"
                    )}`}
                  >
                    {selectedInvoice
                      ? (() => {
                          const status = getEffectiveInvoiceStatus(selectedInvoice);
                          return status.charAt(0).toUpperCase() + status.slice(1);
                        })()
                      : "Unknown"}
                  </span>
                </div>

                {/* Invoice Details Grid */}
                <div className="grid grid-cols-2 gap-4 mb-5 pb-4 border-b border-gray-200 dark:border-gray-700">
                  <div>
                    <p className="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Invoice Date</p>
                    <p className="text-sm font-medium text-gray-900 dark:text-white">{new Date(selectedInvoice.date).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' })}</p>
                  </div>
                  <div>
                    <p className="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Due Date</p>
                    <p className="text-sm font-medium text-gray-900 dark:text-white">{selectedInvoice.due_date ? new Date(selectedInvoice.due_date).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' }) : 'N/A'}</p>
                  </div>
                  <div>
                    <p className="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Terms</p>
                    <p className="text-sm font-medium text-gray-900 dark:text-white">Net 15</p>
                  </div>
                  <div>
                    <p className="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Currency</p>
                    <p className="text-sm font-medium text-gray-900 dark:text-white">PHP</p>
                  </div>
                </div>

                {/* Bill To Section */}
                <div className="mb-5 pb-4 border-b border-gray-200 dark:border-gray-700">
                  <p className="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Bill To</p>
                  <div className="bg-gray-50 dark:bg-gray-900/30 rounded-lg p-3">
                    <p className="text-base font-bold text-gray-900 dark:text-white mb-0.5">{selectedInvoice.customer_name}</p>
                    <p className="text-xs text-gray-600 dark:text-gray-400">billing@{(selectedInvoice.customer_name || '').toLowerCase().replace(/\s+/g, '')}.com</p>
                  </div>
                </div>

                {/* Invoice Items Table */}
                <div className="mb-4">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b-2 border-gray-900 dark:border-gray-300">
                        <th className="text-left py-2 text-[10px] font-bold text-gray-900 dark:text-white uppercase tracking-wider">Description</th>
                        <th className="text-center py-2 text-[10px] font-bold text-gray-900 dark:text-white uppercase tracking-wider">Qty</th>
                        <th className="text-right py-2 text-[10px] font-bold text-gray-900 dark:text-white uppercase tracking-wider">Unit Price</th>
                        <th className="text-right py-2 text-[10px] font-bold text-gray-900 dark:text-white uppercase tracking-wider">Amount</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(selectedInvoice.items?.length ?? 0) > 0 ? (
                        selectedInvoice.items?.map((item, index) => (
                          <tr key={`${item.description}-${index}`} className="border-b border-gray-200 dark:border-gray-700">
                            <td className="py-2.5">
                              <p className="text-xs font-medium text-gray-900 dark:text-white">{item.description}</p>
                              <p className="text-[10px] text-gray-500 dark:text-gray-400">
                                {isShippingItem(item)
                                  ? 'Shipping'
                                  : item.tax_rate !== null && item.tax_rate !== undefined
                                    ? `VAT ${parseAmount(item.tax_rate)}%`
                                    : 'VAT not applied'}
                              </p>
                            </td>
                            <td className="text-center py-2.5 text-xs text-gray-900 dark:text-white">{parseAmount(item.quantity)}</td>
                            <td className="text-right py-2.5 text-xs text-gray-900 dark:text-white">{formatPeso(parseAmount(item.unit_price))}</td>
                            <td className="text-right py-2.5 text-xs font-semibold text-gray-900 dark:text-white">{formatPeso(parseAmount(item.amount))}</td>
                          </tr>
                        ))
                      ) : (
                        <tr className="border-b border-gray-200 dark:border-gray-700">
                          <td className="py-2.5" colSpan={4}>
                            <p className="text-xs text-gray-500 dark:text-gray-400">No line items found for this invoice.</p>
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>

                {/* Totals Section */}
                <div className="space-y-2 pt-3 border-t-2 border-gray-900 dark:border-gray-300">
                  <div className="flex justify-between items-center">
                    <p className="text-xs text-gray-700 dark:text-gray-300">Subtotal:</p>
                    <p className="text-xs font-semibold text-gray-900 dark:text-white">{formatPeso(resolveInvoiceSubtotal(selectedInvoice))}</p>
                  </div>
                  <div className="flex justify-between items-center">
                    <p className="text-xs text-gray-700 dark:text-gray-300">Shipping Fee:</p>
                    <p className="text-xs font-semibold text-gray-900 dark:text-white">{formatPeso(resolveInvoiceShippingFee(selectedInvoice))}</p>
                  </div>
                  <div className="flex justify-between items-center">
                    <p className="text-xs text-gray-700 dark:text-gray-300">VAT:</p>
                    <p className="text-xs font-semibold text-gray-900 dark:text-white">{formatPeso(resolveInvoiceVatAmount(selectedInvoice))}</p>
                  </div>
                  <div className="flex justify-between items-center pt-2 border-t border-gray-300 dark:border-gray-600">
                    <p className="text-sm font-bold text-gray-900 dark:text-white">Total:</p>
                    <p className="text-xl font-bold text-gray-900 dark:text-white">{formatPeso(resolveInvoiceGrandTotal(selectedInvoice))}</p>
                  </div>
                </div>

                {/* Action Buttons */}
                <div className="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700 flex gap-2">
                  <button
                    onClick={() => handleDownloadInvoicePdf(selectedInvoice)}
                    className="p-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors"
                    title="Download PDF"
                  >
                    <ArrowDownTrayIcon className="size-4" />
                  </button>
                  {!selectedInvoice.deleted_at ? (
                    <button
                      onClick={() => handleArchiveInvoice(selectedInvoice)}
                      className="flex-1 px-3 py-2.5 border-2 border-purple-300 dark:border-purple-700 bg-white dark:bg-gray-800 hover:bg-purple-50 dark:hover:bg-purple-900/20 text-purple-700 dark:text-purple-300 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2"
                    >
                      <ArchiveBoxIcon className="size-4" />
                      Archive
                    </button>
                  ) : (
                    <button
                      onClick={() => handleRestoreInvoice(selectedInvoice)}
                      className="flex-1 px-3 py-2.5 border-2 border-purple-300 dark:border-purple-700 bg-white dark:bg-gray-800 hover:bg-purple-50 dark:hover:bg-purple-900/20 text-purple-700 dark:text-purple-300 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2"
                    >
                      <ArchiveRestoreIcon className="size-4" />
                      Restore
                    </button>
                  )}
                  <button
                    onClick={() => handleModalSendEmail(selectedInvoice)}
                    className="flex-1 px-3 py-2.5 border-2 border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2"
                  >
                    <svg className="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    Send Email
                  </button>
                  <button
                    onClick={() => setIsViewModalOpen(false)}
                    className="px-3 py-2.5 border-2 border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium transition-colors"
                  >
                    Close
                  </button>
                </div>
              </div>
            </div>
          </div>
        </ModalPortal>
      )}
      </>
      )}
    </div>
  );
};

export default Invoice;
