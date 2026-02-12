import React, { useMemo, useState } from "react";
import Swal from "sweetalert2";
import Chart from "react-apexcharts";
import { ApexOptions } from "apexcharts";
import { usePage } from '@inertiajs/react';
import { useFinanceApi } from "../../../hooks/useFinanceApi";
import { useExpenses, useTaxRates, useCreateExpense } from "../../../hooks/useFinanceQueries";
import { InlineApprovalActions, getApprovalStatusBadge, ApprovalLimitInfo } from "./InlineApprovalUtils";

// Loading Spinner Component
const LoadingSpinner: React.FC<{ message?: string }> = ({ message = "Loading expenses..." }) => (
  <div className="flex flex-col items-center justify-center py-16 gap-4">
    <div className="relative w-12 h-12">
      <div className="absolute inset-0 rounded-full border-4 border-gray-300 dark:border-gray-600"></div>
      <div className="absolute inset-0 rounded-full border-4 border-transparent border-t-blue-500 border-r-blue-500 animate-spin"></div>
    </div>
    <p className="text-sm font-medium text-gray-600 dark:text-gray-300">{message}</p>
  </div>
);

type Expense = {
  id: string;
  date: string;
  category: string;
  description: string;
  amount: number | string;
  vendor: string;
  status: "draft" | "submitted" | "approved" | "posted" | "rejected";
  reference?: string;
  tax_amount?: number | string;
  journal_entry_id?: number;
  approval_notes?: string | null;
  receipt_path?: string | null;
  receipt_original_name?: string | null;
  receipt_mime_type?: string | null;
  receipt_size?: number | null;
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

const WalletIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h14a2 2 0 002-2v-5z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 12h.01" />
  </svg>
);

const CheckIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
  </svg>
);

const ClockIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const TrendingUpIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 17l6-6 4 4 7-7" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 7h7v7" />
  </svg>
);

const EyeIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const EditIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
    />
  </svg>
);

const SearchIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
  </svg>
);

const PlusIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
  </svg>
);

const DotsIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zm0 6a.75.75 0 110-1.5.75.75 0 010 1.5zm0 6a.75.75 0 110-1.5.75.75 0 010 1.5z" />
  </svg>
);

const TrashIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
  </svg>
);

const MetricCard: React.FC<MetricCardProps> = ({
  title,
  value,
  change,
  changeType,
  icon: Icon,
  color = "info",
  description,
}) => {
  const getColorClasses = () => {
    switch (color) {
      case "success":
        return "from-green-500 to-emerald-600";
      case "error":
        return "from-red-500 to-rose-600";
      case "warning":
        return "from-yellow-500 to-orange-600";
      case "info":
      default:
        return "from-blue-500 to-indigo-600";
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

          {change !== undefined && changeType && (
            <div
              className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
                changeType === "increase"
                  ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                  : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
              }`}
            >
              {changeType === "increase" ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
              {Math.abs(change)}%
            </div>
          )}
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {typeof value === "number" ? value.toLocaleString() : value}
          </h3>
          {description && <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>}
        </div>
      </div>
    </div>
  );
};

import { canApproveExpenses } from "../../../utils/permissions";

const normalizeExpense = (expense: Expense) => ({
  ...expense,
  amount: Number(expense.amount) || 0,
  tax_amount: Number(expense.tax_amount) || 0,
});

const Expense: React.FC = () => {
  const page = usePage();
  const user = page.props.auth?.user as any;
  const auth = page.props.auth as any;
  const api = useFinanceApi();
  
  // React Query hooks - automatically handle loading, caching, refetching
  const { data: expensesData = [], isLoading, refetch: refetchExpenses } = useExpenses();
  const { data: taxRates = [], isLoading: isLoadingTaxRates } = useTaxRates();
  const createExpenseMutation = useCreateExpense();
  
  // Normalize expenses data
  const expenses = useMemo(() => 
    expensesData.map(normalizeExpense),
    [expensesData]
  );
  
  const [statusFilter, setStatusFilter] = useState<"all" | Expense["status"]>("all");
  const [searchTerm, setSearchTerm] = useState("");
  const [isViewOpen, setIsViewOpen] = useState(false);
  const [isEditOpen, setIsEditOpen] = useState(false);
  const [isAddOpen, setIsAddOpen] = useState(false);
  const [activeExpense, setActiveExpense] = useState<Expense | null>(null);
  const [editForm, setEditForm] = useState({
    date: "",
    category: "",
    description: "",
    vendor: "",
    amount: 0,
  });
  const [addForm, setAddForm] = useState({
    date: "",
    category: "",
    description: "",
    vendor: "",
    amount: 0,
    tax_rate_id: "",
    tax_amount: 0,
  });
  const [receiptFile, setReceiptFile] = useState<File | null>(null);
  const [receiptPreview, setReceiptPreview] = useState<string | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 10;

  const filteredExpenses = useMemo(() => {
    return expenses.filter((expense) => {
      const matchesStatus = statusFilter === "all" ? true : expense.status === statusFilter;
      const matchesSearch =
        (expense.category || "").toLowerCase().includes(searchTerm.toLowerCase()) ||
        (expense.description || "").toLowerCase().includes(searchTerm.toLowerCase()) ||
        (expense.vendor || "").toLowerCase().includes(searchTerm.toLowerCase());

      return matchesStatus && matchesSearch;
    });
  }, [expenses, searchTerm, statusFilter]);

  // Pagination logic
  const totalPages = Math.ceil(filteredExpenses.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedExpenses = filteredExpenses.slice(startIndex, endIndex);

  // Reset to page 1 when filters change
  React.useEffect(() => {
    setCurrentPage(1);
  }, [searchTerm, statusFilter]);

  const stats = useMemo(() => {
    const total = expenses.reduce((sum, exp) => sum + (Number(exp.amount) || 0), 0);
    const approvedTotal = expenses
      .filter((exp) => exp.status === "approved")
      .reduce((sum, exp) => sum + (Number(exp.amount) || 0), 0);
    const pendingTotal = expenses
      .filter((exp) => exp.status === "submitted")
      .reduce((sum, exp) => sum + (Number(exp.amount) || 0), 0);
    const avgExpense = expenses.length ? total / expenses.length : 0;

    return {
      total,
      approvedTotal,
      pendingTotal,
      avgExpense,
    };
  }, [expenses]);

  // Check if user can approve expenses
  const canUserApprove = () => {
    return canApproveExpenses(auth);
  };

  const canUserPost = () => {
    // Posting typically requires manager-level permissions
    return canApproveExpenses(auth);
  };

  const categoryBreakdown = useMemo(() => {
    const grouped: Record<string, number> = {};

    expenses.forEach((expense) => {
      grouped[expense.category] = (grouped[expense.category] || 0) + (Number(expense.amount) || 0);
    });

    return Object.entries(grouped).map(([category, amount]) => ({ category, amount }));
  }, [expenses]);

  const chartOptions: ApexOptions = {
    colors: ["#eb2525"],
    chart: {
      fontFamily: "Outfit, sans-serif",
      type: "bar",
      height: 420,
      toolbar: {
        show: false,
      },
    },
    plotOptions: {
      bar: {
        horizontal: false,
        columnWidth: "25%",
        borderRadius: 6,
        borderRadiusApplication: "end",
      },
    },
    dataLabels: {
      enabled: false,
    },
    stroke: {
      show: true,
      width: 4,
      colors: ["transparent"],
    },
    xaxis: {
      categories: categoryBreakdown.map((item) => item.category),
      axisBorder: { show: false },
      axisTicks: { show: false },
    },
    yaxis: {
      labels: {
        formatter: (val) => `₱${val.toLocaleString()}`,
      },
    },
    grid: {
      padding: { bottom: -10, top: 0 },
      yaxis: { lines: { show: true } },
    },
    tooltip: {
      y: {
        formatter: (val) => `₱${val.toLocaleString()}`,
      },
    },
  };

  const chartSeries = [
    {
      name: "Expense",
      data: categoryBreakdown.map((item) => item.amount),
    },
  ];

  const categoryColors = [
    "bg-blue-100 text-blue-700",
    "bg-emerald-100 text-emerald-700",
    "bg-amber-100 text-amber-700",
    "bg-indigo-100 text-indigo-700",
    "bg-rose-100 text-rose-700",
    "bg-slate-100 text-slate-700",
  ];

  const getCategoryColor = (index: number) => categoryColors[index % categoryColors.length];

  const getStatusColor = (status: Expense["status"]) => {
    switch (status) {
      case "approved":
        return "bg-green-100 text-green-700";
      case "submitted":
        return "bg-yellow-100 text-yellow-700";
      case "posted":
        return "bg-emerald-100 text-emerald-700";
      case "draft":
        return "bg-slate-100 text-slate-700";
      case "rejected":
        return "bg-red-100 text-red-700";
      default:
        return "bg-gray-100 text-gray-800";
    }
  };

  const formatCurrency = (value: number | string) => `₱${Number(value || 0).toLocaleString()}`;

  const openViewModal = (expense: Expense) => {
    setActiveExpense(expense);
    setIsViewOpen(true);
  };

  const closeViewModal = () => {
    setActiveExpense(null);
    setIsViewOpen(false);
  };

  const openEditModal = (expense: Expense) => {
    setActiveExpense(expense);
    setEditForm({
      date: expense.date,
      category: expense.category,
      description: expense.description,
      vendor: expense.vendor,
      amount: Number(expense.amount),
      tax_rate_id: "",
      tax_amount: Number(expense.tax_amount) || 0,
    });
    setIsEditOpen(true);
  };

  const closeEditModal = () => {
    setActiveExpense(null);
    setIsEditOpen(false);
  };

  const calculateTax = (amount: number, taxRateId: string) => {
    if (!amount || !taxRateId) return 0;
    
    const taxRate = taxRates.find((t: any) => t.id.toString() === taxRateId);
    if (!taxRate) return 0;
    
    if (taxRate.type === 'fixed') {
      return Number(taxRate.fixed_amount) || 0;
    }
    
    // Percentage-based tax
    return Math.round((amount * taxRate.rate) / 100 * 100) / 100;
  };

  const openAddModal = () => {
    setAddForm({
      date: "",
      category: "",
      description: "",
      vendor: "",
      amount: 0,
      tax_rate_id: "",
      tax_amount: 0,
    });
    setReceiptFile(null);
    setReceiptPreview(null);
    setIsAddOpen(true);
  };

  const closeAddModal = () => {
    setReceiptFile(null);
    setReceiptPreview(null);
    setIsAddOpen(false);
  };

  const handleReceiptChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      // Validate file type
      const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
      if (!allowedTypes.includes(file.type)) {
        Swal.fire({
          title: "Invalid File Type",
          text: "Please upload a JPG, PNG, or PDF file",
          icon: "error",
        });
        return;
      }
      
      // Validate file size (10MB max)
      if (file.size > 10 * 1024 * 1024) {
        Swal.fire({
          title: "File Too Large",
          text: "Please upload a file smaller than 10MB",
          icon: "error",
        });
        return;
      }
      
      setReceiptFile(file);
      
      // Create preview for images
      if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onloadend = () => {
          setReceiptPreview(reader.result as string);
        };
        reader.readAsDataURL(file);
      } else {
        setReceiptPreview(null);
      }
    }
  };

  const handleSaveAdd = async () => {
    // guard: ensure required fields are filled
    if (!addForm.date || !addForm.category.trim() || !addForm.vendor.trim() || !(addForm.amount > 0)) {
      Swal.fire({
        title: "Incomplete",
        text: "Please complete all required fields before adding an expense.",
        icon: "warning",
        confirmButtonText: "OK",
      });
      return;
    }
    try {
      const formData = new FormData();
      formData.append('date', addForm.date);
      formData.append('category', addForm.category);
      formData.append('description', addForm.description);
      formData.append('vendor', addForm.vendor);
      formData.append('amount', addForm.amount.toString());
      formData.append('tax_amount', addForm.tax_amount.toString());
      formData.append('status', 'submitted');
      
      if (receiptFile) {
        formData.append('receipt', receiptFile);
      }

      const response = await fetch('/api/finance/session/expenses', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: formData,
        credentials: 'include',
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to add expense');
      }

      const data = await response.json();
      const exp = normalizeExpense(data?.data ?? data);
      // React Query will automatically refetch on next render
      refetchExpenses();
      closeAddModal();
      Swal.fire({
        title: "Added",
        text: "Expense has been added successfully",
        icon: "success",
        timer: 1200,
        showConfirmButton: false,
      });
    } catch (err) {
      Swal.fire({
        title: "Error",
        text: "Failed to add expense.",
        icon: "error",
      });
    }
  };

  const isAddFormValid = React.useMemo(() => {
    return Boolean(addForm.date && addForm.category.trim() && addForm.vendor.trim() && addForm.amount > 0);
  }, [addForm]);

  const handleSaveEdit = async () => {
    if (!activeExpense) return;
    try {
      const response = await api.patch(`/api/finance/session/expenses/${activeExpense.id}`, editForm);
      if (!response.ok) throw new Error(response.error || 'Failed to update expense');
      const updatedExpense = normalizeExpense(response.data?.data ?? response.data);
      // React Query will automatically refetch
      refetchExpenses();
      closeEditModal();
      Swal.fire({
        title: "Updated",
        text: "Expense has been updated successfully",
        icon: "success",
        timer: 1200,
        showConfirmButton: false,
      });
    } catch (err) {
      Swal.fire({
        title: "Error",
        text: "Failed to update expense.",
        icon: "error",
      });
    }
  };

  const handleDeleteExpense = (id: string) => {
    Swal.fire({
      title: "Delete Expense",
      text: "Are you sure you want to delete this expense?",
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Yes, delete",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#dc2626",
      cancelButtonColor: "#6b7280",
    }).then((result) => {
      if (result.isConfirmed) {
        (async () => {
          try {
            const response = await api.delete(`/api/finance/session/expenses/${id}`);
            if (!response.ok) throw new Error(response.error || 'Failed to delete expense');
            // React Query will automatically refetch
            refetchExpenses();
            Swal.fire({
              title: "Deleted",
              text: "Expense has been deleted",
              icon: "success",
              timer: 1200,
              showConfirmButton: false,
            });
          } catch (err) {
            Swal.fire({
              title: "Error",
              text: "Failed to delete expense.",
              icon: "error",
            });
          }
        })();
      }
    });
  };

  const confirmAndUpdateStatus = async (status: Expense["status"]) => {
    if (!activeExpense) return;

    const action = status === "approved" ? "Approve" : "Reject";
    const result = await Swal.fire({
      title: `${action} expense?`,
      text: `${activeExpense.category} — ${formatCurrency(Number(activeExpense.amount) || 0)}`,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: action,
      cancelButtonText: "Cancel",
      confirmButtonColor: status === "approved" ? "#059669" : "#e11d48",
      cancelButtonColor: "#6b7280",
      reverseButtons: true,
      focusCancel: true,
      input: status === 'rejected' ? 'textarea' : undefined,
      inputPlaceholder: status === 'rejected' ? 'Reason for rejection (optional)' : undefined,
    });

    if (result.isConfirmed) {
      try {
        const expenseId = activeExpense.id;
        const endpoint = status === 'approved' ? 'approve' : 'reject';
        const url = `/api/finance/session/expenses/${expenseId}/${endpoint}`;
        
        const response = await api.post(url, { approval_notes: result.value || null });
        
        if (!response.ok) {
          throw new Error(response.error || `Failed to ${action}`);
        }
        
        const updated = normalizeExpense(response.data?.data ?? response.data);
        // React Query will automatically refetch
        refetchExpenses();
        setActiveExpense((prev) => (prev ? updated : prev));
        setIsViewOpen(false);
        setActiveExpense(null);

        await Swal.fire({
          title: `Expense ${action.toLowerCase()}ed`,
          icon: "success",
          timer: 1200,
          showConfirmButton: false,
        });
      } catch (err) {
        Swal.fire({
          title: "Error",
          text: `Failed to ${action.toLowerCase()} expense. Check console for details.`,
          icon: "error",
        });
      }
    }
  };

  const handlePostToLedger = async () => {
    if (!activeExpense) return;

    const result = await Swal.fire({
      title: "Post to Ledger?",
      html: `
        <div class="text-left space-y-2">
          <p><strong>Expense:</strong> ${activeExpense.category}</p>
          <p><strong>Amount:</strong> ${formatCurrency(Number(activeExpense.amount) || 0)}</p>
          <p class="text-sm text-gray-600 mt-4">This will create a journal entry:</p>
          <ul class="text-sm text-gray-600 list-disc ml-5">
            <li>Debit: Expense Account (5000)</li>
            <li>Credit: Accounts Payable (2000)</li>
          </ul>
          <p class="text-sm text-red-600 mt-4">⚠️ This action cannot be undone!</p>
        </div>
      `,
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Post to Ledger",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#2563eb",
      cancelButtonColor: "#6b7280",
      reverseButtons: true,
    });

    if (result.isConfirmed) {
      try {
        const expenseId = activeExpense.id;
        const url = `/api/finance/session/expenses/${expenseId}/post`;
        
        const response = await api.post(url);
        
        if (!response.ok) {
          throw new Error(response.error || `Failed to post to ledger`);
        }
        
        const updated = normalizeExpense(response.data?.data ?? response.data);
        // React Query will automatically refetch
        refetchExpenses();
        setActiveExpense(null);
        setIsViewOpen(false);

        await Swal.fire({
          title: "Posted to Ledger!",
          html: `
            <div class="text-left space-y-2">
              <p class="text-green-600 font-semibold">✓ Journal Entry Created</p>
              <p class="text-green-600 font-semibold">✓ Account Balances Updated</p>
              <p class="text-sm text-gray-600 mt-4">Check the Journal Entries page to view the transaction.</p>
            </div>
          `,
          icon: "success",
          timer: 3000,
          showConfirmButton: true,
        });
      } catch (err) {
        Swal.fire({
          title: "Error",
          text: err instanceof Error ? err.message : "Failed to post expense to ledger.",
          icon: "error",
        });
      }
    }
  };

  return (
    <>
      <div className="space-y-6">
        {isLoading ? (
          <LoadingSpinner message="Loading expenses..." />
        ) : (
          <>
            <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
              <div>
                <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Expense Management</h1>
                <p className="text-gray-600 dark:text-gray-400 mt-2">
                  Track, approve, and analyze team spending across the ERP suite.
                </p>
              </div>
              <div className="flex items-center gap-3">
                <button 
                  onClick={openAddModal}
                  className="inline-flex items-center px-4 py-2 rounded-lg text-white bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600 shadow-sm transition-colors">
                  <PlusIcon className="size-5 mr-2" />
                  Add Expense
                </button>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <MetricCard
          title="Total Spend"
          value={formatCurrency(stats.total)}
          change={6.2}
          changeType="increase"
          icon={WalletIcon}
          color="info"
          description="Month to date spend"
        />
        <MetricCard
          title="Approved"
          value={formatCurrency(stats.approvedTotal)}
          change={3.1}
          changeType="increase"
          icon={CheckIcon}
          color="success"
          description="Cleared for payment"
        />
        <MetricCard
          title="Pending"
          value={formatCurrency(stats.pendingTotal)}
          change={2.4}
          changeType="decrease"
          icon={ClockIcon}
          color="warning"
          description="Awaiting review"
        />
        <MetricCard
          title="Average Expense"
          value={formatCurrency(Math.round(stats.avgExpense))}
          change={1.8}
          changeType="increase"
          icon={TrendingUpIcon}
          color="info"
          description="Per submitted record"
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-6 shadow-sm">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Expense by Category</h3>
          </div>
          <div className="max-w-full overflow-x-auto custom-scrollbar">
            <div className="-ml-5 min-w-[640px] xl:min-w-full pl-2">
              <Chart options={chartOptions} series={chartSeries} type="bar" height={420} />
            </div>
          </div>
        </div>

        <div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-6 shadow-sm space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Summary</h3>
          </div>

          <div className="space-y-3">
            {categoryBreakdown.map((item, idx) => {
              const share = stats.total ? Math.round((item.amount / stats.total) * 1000) / 10 : 0;

              return (
                <div
                  key={item.category}
                  className="flex items-center justify-between rounded-xl border border-gray-100 dark:border-gray-800 px-3 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                >
                  <div className="flex items-center gap-3">
                    <div className={`flex h-10 w-10 items-center justify-center rounded-full text-sm font-semibold ${getCategoryColor(idx)}`}>
                      {item.category.charAt(0)}
                    </div>
                    <div className="space-y-0.5">
                      <p className="text-sm font-semibold text-gray-900 dark:text-white">{item.category}</p>
                      <p className="text-xs text-gray-500 dark:text-gray-400">Share of spend · {share}%</p>
                    </div>
                  </div>

                  <span className="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-800 px-3 py-1 text-sm font-semibold text-gray-800 dark:text-gray-200">
                    {formatCurrency(item.amount)}
                  </span>
                </div>
              );
            })}
          </div>

          <div className="pt-3 border-t border-gray-200 dark:border-gray-800">
            <div className="flex items-center justify-between">
              <p className="text-sm text-gray-600 dark:text-gray-400">Total</p>
              <p className="text-sm font-semibold text-gray-900 dark:text-white">{formatCurrency(stats.total)}</p>
            </div>
          </div>
        </div>
      </div>

      <div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">
        <div className="p-6 border-b border-gray-200 dark:border-gray-800 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex items-center gap-2">
            {["all", "submitted", "approved", "posted", "rejected"].map((tab) => (
              <button
                key={tab}
                onClick={() => setStatusFilter(tab as Expense["status"] | "all")}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  statusFilter === tab
                    ? "bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400"
                    : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800"
                }`}
              >
                {tab === "all"
                  ? "All"
                  : tab === "submitted"
                  ? "Pending"
                  : `${tab.charAt(0).toUpperCase()}${tab.slice(1)}`}
              </button>
            ))}
          </div>

          <div className="flex items-center w-full lg:w-auto">
            <div className="relative flex-1 lg:flex-initial lg:w-72">
              <SearchIcon className="absolute left-3 top-1/2 -translate-y-1/2 size-5 text-gray-400 dark:text-gray-500" />
              <input
                type="text"
                placeholder="Search category, vendor, note"
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="w-full pl-10 pr-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
              />
            </div>
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800">
                <th className="text-left py-3 px-4 text-xs font-semibold text-gray-600 dark:text-gray-400">Date</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-gray-600 dark:text-gray-400">Category</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-gray-600 dark:text-gray-400">Description</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-gray-600 dark:text-gray-400">Vendor</th>
                <th className="text-right py-3 px-6 text-xs font-semibold text-gray-600 dark:text-gray-400">Amount</th>
                <th className="text-left py-3 px-6 text-xs font-semibold text-gray-600 dark:text-gray-400">Status</th>
                <th className="text-center py-3 px-4 text-xs font-semibold text-gray-600 dark:text-gray-400">Actions</th>
              </tr>
            </thead>
            <tbody>
              {paginatedExpenses.map((expense) => (
                <tr key={expense.id} className="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800">
                  <td className="py-4 px-4 text-sm text-gray-700 dark:text-gray-300">{expense.date}</td>
                  <td className="py-4 px-4 text-sm font-semibold text-gray-900 dark:text-white">{expense.category}</td>
                  <td className="py-4 px-4 text-sm text-gray-700 dark:text-gray-300">{expense.description}</td>
                  <td className="py-4 px-4 text-sm text-gray-700 dark:text-gray-300">{expense.vendor}</td>
                  <td className="py-4 px-6 text-right text-sm font-semibold text-gray-900 dark:text-white">{formatCurrency(expense.amount)}</td>
                  <td className="py-4 px-6">
                    {getApprovalStatusBadge(false, expense.status)}
                  </td>
                  <td className="py-4 px-4 text-sm text-gray-700 dark:text-gray-300">
                    <div className="flex items-center justify-center gap-2">
                      <InlineApprovalActions
                        transactionId={expense.id}
                        transactionType="expense"
                        requiresApproval={false}
                        status={expense.status}
                        amount={Number(expense.amount)}
                        userRole={user?.role}
                        userApprovalLimit={user?.approval_limit}
                        onApprovalSuccess={() => {
                          // Reload expenses after approval
                          window.location.reload();
                        }}
                      />
                      <button
                        className="inline-flex items-center justify-center w-9 h-9 rounded-lg text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-colors"
                        aria-label="View expense"
                        title="View details"
                        onClick={() => openViewModal(expense)}
                      >
                        <EyeIcon className="size-5" />
                      </button>
                      {expense.status === "approved" && canUserPost() && (
                        <button
                          className="inline-flex items-center justify-center w-9 h-9 rounded-lg text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 hover:bg-green-50 dark:hover:bg-green-900/30 transition-colors"
                          aria-label="Post to ledger"
                          title="Post approved expense to journal"
                          onClick={() => {
                            setActiveExpense(expense);
                            // Delay to ensure state is updated before calling handler
                            setTimeout(() => handlePostToLedger(), 0);
                          }}
                        >
                          <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                          </svg>
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
          </>
        )}
      </div>

      {isViewOpen && activeExpense && (
        <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
          <div className="w-full max-w-lg rounded-2xl bg-white dark:bg-gray-900 shadow-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-800">
              <div>
                <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Expense</p>
                <h4 className="text-lg font-semibold text-gray-900 dark:text-white">{activeExpense.category}</h4>
              </div>
              <button
                className="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300"
                aria-label="Close"
                onClick={closeViewModal}
              >
                ✕
              </button>
            </div>

            <div className="px-6 py-4 space-y-3">
              <div className="flex justify-between text-sm text-gray-700 dark:text-gray-300">
                <span className="text-gray-500 dark:text-gray-400">Date</span>
                <span className="font-semibold">{activeExpense.date}</span>
              </div>
              <div className="flex justify-between text-sm text-gray-700 dark:text-gray-300">
                <span className="text-gray-500 dark:text-gray-400">Vendor</span>
                <span className="font-semibold">{activeExpense.vendor}</span>
              </div>
              <div className="flex justify-between text-sm text-gray-700 dark:text-gray-300">
                <span className="text-gray-500 dark:text-gray-400">Amount</span>
                <span className="font-semibold">{formatCurrency(activeExpense.amount)}</span>
              </div>
              <div className="flex justify-between text-sm text-gray-700 dark:text-gray-300">
                <span className="text-gray-500 dark:text-gray-400">Description</span>
                <span className="text-right max-w-[60%] text-gray-800 dark:text-gray-200">{activeExpense.description}</span>
              </div>
              <div className="flex justify-between text-sm text-gray-700 dark:text-gray-300 items-center">
                <span className="text-gray-500 dark:text-gray-400">Status</span>
                <span className={`px-3 py-1 rounded-full text-xs font-semibold ${getStatusColor(activeExpense.status)}`}>
                  {activeExpense.status.charAt(0).toUpperCase() + activeExpense.status.slice(1)}
                </span>
              </div>
              
              {activeExpense.receipt_path && (
                <div className="flex flex-col gap-2 text-sm text-gray-700 dark:text-gray-300">
                  <span className="text-gray-500 dark:text-gray-400">Receipt Attachment</span>
                  <div className="flex items-center gap-2">
                    <span className="flex-1 text-sm font-medium text-blue-600 dark:text-blue-400 truncate">
                      {activeExpense.receipt_original_name}
                    </span>
                    <button
                      onClick={() => window.open(`/api/finance/session/expenses/${activeExpense.id}/receipt/download`, '_blank')}
                      className="px-3 py-1 text-xs bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors"
                    >
                      Download
                    </button>
                  </div>
                </div>
              )}
            </div>

            <div className="flex flex-col gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800">
              {activeExpense.status === "approved" && (
                <div className="flex items-center gap-2 p-3 rounded-lg bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800">
                  <svg className="size-5 text-blue-600 dark:text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <span className="text-sm text-blue-800 dark:text-blue-200">Click "Post to Ledger" to create journal entry</span>
                </div>
              )}
              <div className="flex items-center justify-end gap-3">
                <button
                className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                onClick={closeViewModal}
              >
                Close
                </button>
                {activeExpense.status === "submitted" && canUserApprove() && (
                  <>
                    <button
                      className="px-4 py-2 rounded-lg bg-blue-600 dark:bg-blue-500 text-white hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors"
                      onClick={() => {
                        closeViewModal();
                        openEditModal(activeExpense);
                      }}
                    >
                      Edit
                    </button>
                    <button
                      className="px-4 py-2 rounded-lg bg-emerald-600 dark:bg-emerald-500 text-white hover:bg-emerald-700 dark:hover:bg-emerald-600 transition-colors"
                      onClick={() => confirmAndUpdateStatus("approved")}
                    >
                      Approve
                    </button>
                    <button
                      className="px-4 py-2 rounded-lg bg-rose-600 dark:bg-rose-500 text-white hover:bg-rose-700 dark:hover:bg-rose-600 transition-colors"
                      onClick={() => confirmAndUpdateStatus("rejected")}
                    >
                      Reject
                    </button>
                  </>
                )}
                {activeExpense.status === "approved" && canUserPost() && (
                  <button
                    className="px-4 py-2 rounded-lg bg-green-600 dark:bg-green-500 text-white hover:bg-green-700 dark:hover:bg-green-600 transition-colors flex items-center gap-2 font-semibold shadow-lg"
                    onClick={handlePostToLedger}
                  >
                    <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Post to Ledger
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {isEditOpen && activeExpense && (
        <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
          <div className="w-full max-w-lg rounded-2xl bg-white dark:bg-gray-900 shadow-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-800">
              <div>
                <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Edit Expense</p>
                <h4 className="text-lg font-semibold text-gray-900 dark:text-white">{activeExpense.category}</h4>
              </div>
              <button
                className="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300"
                aria-label="Close"
                onClick={closeEditModal}
              >
                ✕
              </button>
            </div>

            <div className="px-6 py-4 space-y-4">
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Date</label>
                <input
                  type="date"
                  value={editForm.date}
                  onChange={(e) => setEditForm({ ...editForm, date: e.target.value })}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                <input
                  type="text"
                  value={editForm.category}
                  onChange={(e) => setEditForm({ ...editForm, category: e.target.value })}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                <textarea
                  value={editForm.description}
                  onChange={(e) => setEditForm({ ...editForm, description: e.target.value })}
                  rows={3}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Vendor</label>
                <input
                  type="text"
                  value={editForm.vendor}
                  onChange={(e) => setEditForm({ ...editForm, vendor: e.target.value })}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Amount</label>
                <input
                  type="number"
                  value={editForm.amount}
                  onChange={(e) => setEditForm({ ...editForm, amount: parseFloat(e.target.value) || 0 })}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800">
              <button
                className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                onClick={closeEditModal}
              >
                Cancel
              </button>
              <button
                className="px-4 py-2 rounded-lg bg-blue-600 dark:bg-blue-500 text-white hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors"
                onClick={handleSaveEdit}
              >
                Save Changes
              </button>
            </div>
          </div>
        </div>
      )}

      {isAddOpen && (
        <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
          <div className="w-full max-w-lg max-h-[90vh] rounded-2xl bg-white dark:bg-gray-900 shadow-2xl border border-gray-200 dark:border-gray-800 overflow-hidden flex flex-col">
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-800 flex-shrink-0">
              <div>
                <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">New Expense</p>
                <h4 className="text-lg font-semibold text-gray-900 dark:text-white">Add Expense</h4>
              </div>
              <button
                className="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300"
                aria-label="Close"
                onClick={closeAddModal}
              >
                ✕
              </button>
            </div>

            <div className="px-6 py-4 space-y-4 overflow-y-auto flex-1">
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Date</label>
                <input
                  type="date"
                  value={addForm.date}
                  onChange={(e) => setAddForm({ ...addForm, date: e.target.value })}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                <input
                  type="text"
                  value={addForm.category}
                  onChange={(e) => setAddForm({ ...addForm, category: e.target.value })}
                  placeholder="e.g., Office Supplies, Travel, Software"
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                <textarea
                  value={addForm.description}
                  onChange={(e) => setAddForm({ ...addForm, description: e.target.value })}
                  placeholder="Enter expense details"
                  rows={3}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Vendor</label>
                <input
                  type="text"
                  value={addForm.vendor}
                  onChange={(e) => setAddForm({ ...addForm, vendor: e.target.value })}
                  placeholder="e.g., Company Name"
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Amount</label>
                <input
                  type="number"
                  value={addForm.amount}
                  onChange={(e) => {
                    const amount = parseFloat(e.target.value) || 0;
                    setAddForm({ 
                      ...addForm, 
                      amount,
                      tax_amount: addForm.tax_rate_id ? calculateTax(amount, addForm.tax_rate_id) : 0
                    });
                  }}
                  placeholder="0.00"
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
              </div>
              
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Tax Rate (Optional)</label>
                <select
                  value={addForm.tax_rate_id}
                  onChange={(e) => {
                    const taxRateId = e.target.value;
                    setAddForm({ 
                      ...addForm, 
                      tax_rate_id: taxRateId,
                      tax_amount: taxRateId ? calculateTax(addForm.amount, taxRateId) : 0
                    });
                  }}
                  disabled={isLoadingTaxRates}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                >
                  <option value="">No Tax</option>
                  {taxRates.map((tax: any) => (
                    <option key={tax.id} value={tax.id}>
                      {tax.name} - {tax.rate}% {tax.is_inclusive ? '(Inclusive)' : ''}
                    </option>
                  ))}
                </select>
              </div>
              
              {addForm.tax_amount > 0 && (
                <div className="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                  <div className="flex justify-between text-sm">
                    <span className="text-gray-600 dark:text-gray-400">Subtotal:</span>
                    <span className="font-medium text-gray-900 dark:text-white">{formatCurrency(addForm.amount)}</span>
                  </div>
                  <div className="flex justify-between text-sm mt-1">
                    <span className="text-gray-600 dark:text-gray-400">Tax:</span>
                    <span className="font-medium text-gray-900 dark:text-white">{formatCurrency(addForm.tax_amount)}</span>
                  </div>
                  <div className="flex justify-between text-sm font-semibold mt-2 pt-2 border-t border-blue-300 dark:border-blue-700">
                    <span className="text-gray-900 dark:text-white">Total:</span>
                    <span className="text-blue-600 dark:text-blue-400">{formatCurrency(addForm.amount + addForm.tax_amount)}</span>
                  </div>
                </div>
              )}
              
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Receipt Attachment (Optional)</label>
                <input
                  type="file"
                  accept=".jpg,.jpeg,.png,.pdf"
                  onChange={handleReceiptChange}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:file:bg-blue-900/30 dark:file:text-blue-400"
                />
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  Accepted formats: JPG, PNG, PDF (Max 10MB)
                </p>
                
                {receiptPreview && (
                  <div className="mt-2">
                    <img src={receiptPreview} alt="Receipt preview" className="max-h-48 rounded-lg border border-gray-300 dark:border-gray-700" />
                  </div>
                )}
                
                {receiptFile && (
                  <div className="flex items-center gap-2 p-2 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                    <svg className="size-5 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span className="text-sm text-green-700 dark:text-green-300 font-medium truncate flex-1">
                      {receiptFile.name}
                    </span>
                    <button
                      onClick={() => {
                        setReceiptFile(null);
                        setReceiptPreview(null);
                      }}
                      className="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300"
                    >
                      ✕
                    </button>
                  </div>
                )}
              </div>
            </div>

            <div className="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800 flex-shrink-0">
              <button
                className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                onClick={closeAddModal}
              >
                Cancel
              </button>
              <button
                disabled={!isAddFormValid}
                className={`px-4 py-2 rounded-lg bg-blue-600 dark:bg-blue-500 text-white transition-colors ${!isAddFormValid ? "opacity-50 cursor-not-allowed hover:bg-blue-600 dark:hover:bg-blue-500" : "hover:bg-blue-700 dark:hover:bg-blue-600"}`}
                onClick={handleSaveAdd}
              >
                Add Expense
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default Expense;
