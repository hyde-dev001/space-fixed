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
  status: "draft" | "sent" | "paid" | "overdue" | "cancelled";
  payment_date?: string | null;
  payment_method?: string | null;
  job_order_id?: number | null;
  job_reference?: string | null;
  job_order?: {
    id: number;
    customer: string;
    product: string;
    status: string;
    total: string;
    created_at: string;
  } | null;
}

type TabFilter = "all" | "draft" | "sent" | "paid" | "overdue";

const Invoice: React.FC = () => {
  const page = usePage();
  const user = page.props.auth?.user as any;
  const auth = page.props.auth as any;
  const api = useFinanceApi();
  
  const [selectedTab, setSelectedTab] = useState<TabFilter>("all");
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedInvoices, setSelectedInvoices] = useState<string[]>([]);
  const [currentPage, setCurrentPage] = useState(1);
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
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
    const { value: formValues } = await Swal.fire({
      title: 'Mark as Paid',
      html: `
        <div class="space-y-4">
          <div class="text-left">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Payment Date</label>
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
      confirmButtonText: 'Mark as Paid',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#10b981',
      reverseButtons: true,
      preConfirm: () => {
        const paymentDate = (document.getElementById('payment-date') as HTMLInputElement)?.value;
        const paymentMethod = (document.getElementById('payment-method') as HTMLSelectElement)?.value;
        
        if (!paymentDate) {
          Swal.showValidationMessage('Please select a payment date');
          return false;
        }
        
        return {
          payment_date: paymentDate,
          payment_method: paymentMethod,
        };
      }
    });

    if (formValues) {
      setMarkingPaidId(invoiceId);
      try {
        const response = await api.post(`/api/finance/invoices/${invoiceId}/mark-paid`, formValues);

        if (!response.ok) {
          throw new Error(response.error || 'Failed to mark invoice as paid');
        }

        refetchInvoices();
        await Swal.fire('Success!', 'Invoice marked as paid.', 'success');
      } catch (error) {
        await Swal.fire('Error', error instanceof Error ? error.message : 'Failed to mark invoice as paid', 'error');
      } finally {
        setMarkingPaidId(null);
      }
    }
  };

  // React Query hooks - automatically handle loading, caching, refetching
  const { data: invoices = [], isLoading: loading, refetch: refetchInvoices } = useInvoices();
  const postInvoiceMutation = usePostInvoice();

  // Filter invoices based on tab and search
  const filteredInvoices = useMemo(() => {
    return invoices.filter((invoice) => {
      const matchesTab =
        selectedTab === "all" ||
        invoice.status === selectedTab;

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
  }, [selectedTab, searchTerm]);

  // Calculate statistics from invoice data
  const stats = useMemo(() => {
    const total = invoices.length;
    const sent = invoices.filter((inv) => inv.status === "sent").length;
    const paid = invoices.filter((inv) => inv.status === "paid").length;
    const draft = invoices.filter((inv) => inv.status === "draft").length;
    const overdue = invoices.filter((inv) => inv.status === "overdue").length;
    
    const totalRevenue = invoices
      .filter((inv) => inv.status === "paid")
      .reduce((sum, inv) => sum + (typeof inv.total === 'string' ? parseFloat(inv.total) : inv.total), 0);
      
    const pendingRevenue = invoices
      .filter((inv) => inv.status === "sent" || inv.status === "overdue")
      .reduce((sum, inv) => sum + (typeof inv.total === 'string' ? parseFloat(inv.total) : inv.total), 0);

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
      overdue: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400",
      cancelled: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400",
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

  const handleCreateInvoice = () => {
    router.visit('/finance?section=create-invoice');
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
          title="Total Revenue"
          value={`₱${stats.totalRevenue.toLocaleString()}`}
          icon={CurrencyDollarIcon}
          color="success"
          description="From paid invoices"
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
              <button className="flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                <FunnelIcon className="size-5" />
                <span className="hidden sm:inline text-sm font-medium">Filter</span>
              </button>

              {/* Export Button */}
              <button className="flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                <ArrowDownTrayIcon className="size-5" />
                <span className="hidden sm:inline text-sm font-medium">Export</span>
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
                        ₱{invoice.total}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      {getApprovalStatusBadge(false, invoice.status)}
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
                        {invoice.status === 'draft' && (
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
                        {(invoice.status === 'sent' || invoice.status === 'overdue') && (
                          <button 
                            onClick={() => handleMarkAsPaid(invoice.id)}
                            disabled={markingPaidId === invoice.id}
                            className="p-2 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            title={markingPaidId === invoice.id ? "Processing..." : "Mark as Paid"}
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
                        {invoice.status === 'draft' && (
                          <button 
                            className="p-2 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                            title="Delete Invoice"
                          >
                            <TrashIcon className="size-5 text-red-600 dark:text-red-400" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
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
                      selectedInvoice?.status || "draft"
                    )}`}
                  >
                    {selectedInvoice ? (selectedInvoice.status.charAt(0).toUpperCase() + selectedInvoice.status.slice(1)) : "Unknown"}
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
                      <tr className="border-b border-gray-200 dark:border-gray-700">
                        <td className="py-2.5">
                          <p className="text-xs font-medium text-gray-900 dark:text-white">Professional Services</p>
                          <p className="text-[10px] text-gray-500 dark:text-gray-400">VAT 12%</p>
                        </td>
                        <td className="text-center py-2.5 text-xs text-gray-900 dark:text-white">1</td>
                        <td className="text-right py-2.5 text-xs text-gray-900 dark:text-white">₱{(Number(selectedInvoice.total) / 1.12).toFixed(2)}</td>
                        <td className="text-right py-2.5 text-xs font-semibold text-gray-900 dark:text-white">₱{(Number(selectedInvoice.total) / 1.12).toFixed(2)}</td>
                      </tr>
                      <tr className="border-b border-gray-200 dark:border-gray-700">
                        <td className="py-2.5">
                          <p className="text-xs font-medium text-gray-900 dark:text-white">Implementation</p>
                          <p className="text-[10px] text-gray-500 dark:text-gray-400">VAT 12%</p>
                        </td>
                        <td className="text-center py-2.5 text-xs text-gray-900 dark:text-white">1</td>
                        <td className="text-right py-2.5 text-xs text-gray-900 dark:text-white">₱{((Number(selectedInvoice.total) * 0.3) / 1.12).toFixed(2)}</td>
                        <td className="text-right py-2.5 text-xs font-semibold text-gray-900 dark:text-white">₱{((Number(selectedInvoice.total) * 0.3) / 1.12).toFixed(2)}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                {/* Totals Section */}
                <div className="space-y-2 pt-3 border-t-2 border-gray-900 dark:border-gray-300">
                  <div className="flex justify-between items-center">
                    <p className="text-xs text-gray-700 dark:text-gray-300">Subtotal:</p>
                    <p className="text-xs font-semibold text-gray-900 dark:text-white">₱{(Number(selectedInvoice.total) / 1.12).toFixed(2)}</p>
                  </div>
                  <div className="flex justify-between items-center">
                    <p className="text-xs text-gray-700 dark:text-gray-300">VAT 12%:</p>
                    <p className="text-xs font-semibold text-gray-900 dark:text-white">₱{(Number(selectedInvoice.total) - (Number(selectedInvoice.total) / 1.12)).toFixed(2)}</p>
                  </div>
                  <div className="flex justify-between items-center pt-2 border-t border-gray-300 dark:border-gray-600">
                    <p className="text-sm font-bold text-gray-900 dark:text-white">Total:</p>
                    <p className="text-xl font-bold text-gray-900 dark:text-white">₱{Number(selectedInvoice.total).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
                  </div>
                </div>

                {/* Action Buttons */}
                <div className="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700 flex gap-2">
                  <button
                    onClick={() => {
                      // TODO: Implement PDF download
                    }}
                    className="p-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors"
                    title="Download PDF"
                  >
                    <ArrowDownTrayIcon className="size-4" />
                  </button>
                  <button
                    onClick={() => {
                      // TODO: Implement email sending
                    }}
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
